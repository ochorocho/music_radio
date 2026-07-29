<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Taking a file handed over by someone with no account and putting it on a channel.
 *
 * The file has to land in *somebody's* storage to be streamable later, and the only
 * person with a stake in the channel is its owner — so it goes into their Music folder
 * and counts against their quota. That is a real cost to them, which is why uploading
 * is off unless the owner turns it on for a link, and why every limit below is checked
 * before a single byte is written.
 *
 * Nothing here trusts the client: not the filename, not the declared content type, not
 * the size. The name is reduced to a bare basename, the type is sniffed from the bytes,
 * and the size is measured rather than read off the request.
 */
class UploadService {

	/**
	 * A generous album track at a high bitrate is a few tens of megabytes; this leaves
	 * plenty of headroom while keeping a single anonymous request from filling a disk.
	 * PHP's own upload_max_filesize may of course be lower, and wins.
	 */
	private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

	private const AUDIO_MIME_PREFIX = 'audio/';

	/** Where uploads land in the channel owner's files. */
	private const MUSIC_FOLDER = 'Music';

	public function __construct(
		private IRootFolder $rootFolder,
		private IMimeTypeDetector $mimeTypeDetector,
		private TrackService $trackService,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Store an uploaded file in the channel owner's Music folder and append it to the
	 * playlist.
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $upload one
	 *                                                                                                entry of $_FILES, as handed over by IRequest::getUploadedFile()
	 * @throws MusicRadioException when the upload is rejected; the message is meant for
	 *                             the person who tried to upload
	 */
	public function storeForChannel(Channel $channel, array $upload): Track {
		$tmpPath = $this->validateUpload($upload);
		$mimetype = $this->sniffAudioMimetype($tmpPath);
		$name = $this->safeFilename($upload['name'] ?? '', $mimetype);

		$folder = $this->musicFolderOf($channel->getUserId());
		$this->assertFitsInQuota($folder, (int)($upload['size'] ?? 0));

		$file = $this->writeFile($folder, $name, $tmpPath);

		try {
			$result = $this->trackService->add(
				$channel,
				$channel->getUserId(),
				[$file->getId()],
				[],
				Track::ADDED_BY_PUBLIC_LINK,
			);
		} catch (\Throwable $e) {
			// The file only exists to be a track. If it could not become one, leaving it
			// behind would quietly grow the owner's Music folder with orphans.
			$this->discard($file);
			throw $e;
		}

		$track = $result['added'][0] ?? null;
		if ($track === null) {
			$this->discard($file);
			throw new MusicRadioException($this->l10n->t('That file could not be added to the channel'));
		}

		$this->logger->info('Track uploaded to a channel through a public link', [
			'app' => 'music_radio',
			'channelId' => $channel->getId(),
			'trackId' => $track->getId(),
			'fileId' => $file->getId(),
			'mimetype' => $mimetype,
			'size' => $file->getSize(),
		]);

		return $track;
	}

	// ------------------------------------------------------------------ guards

	/**
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $upload
	 * @return string the temporary path, once the upload is known to be complete
	 * @throws MusicRadioException
	 */
	private function validateUpload(array $upload): string {
		$error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($error !== UPLOAD_ERR_OK) {
			throw new MusicRadioException($this->describeUploadError($error));
		}

		$tmpPath = (string)($upload['tmp_name'] ?? '');
		// A path that PHP did not itself receive would let a caller name any file on the
		// server as their "upload".
		if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
			throw new MusicRadioException($this->l10n->t('No file was uploaded'));
		}

		// Measured, not taken from the request: Content-Length is the client's claim.
		$size = filesize($tmpPath);
		if ($size === false || $size <= 0) {
			throw new MusicRadioException($this->l10n->t('That file is empty'));
		}
		if ($size > self::MAX_UPLOAD_BYTES) {
			throw new MusicRadioException($this->l10n->t('That file is too large'), Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}

		return $tmpPath;
	}

	/**
	 * Decide what the file actually is from its first bytes.
	 *
	 * The browser's declared Content-Type and the extension are both attacker-chosen, so
	 * neither is consulted: an .mp3 that is really a PHP script must not end up in
	 * somebody's files because it was labelled convincingly.
	 *
	 * @throws MusicRadioException
	 */
	private function sniffAudioMimetype(string $tmpPath): string {
		$detected = $this->mimeTypeDetector->detectContent($tmpPath);

		if (!str_starts_with($detected, self::AUDIO_MIME_PREFIX)) {
			throw new MusicRadioException($this->l10n->t('Only audio files can be uploaded'));
		}

		return $detected;
	}

	/**
	 * Reduce whatever the client called the file to something safe to create.
	 *
	 * Only the basename survives, so `../../` and absolute paths cannot escape the Music
	 * folder.
	 *
	 * @throws MusicRadioException when no audio extension can be settled on
	 */
	private function safeFilename(string $given, string $mimetype): string {
		$given = str_replace('\\', '/', $given);

		$base = pathinfo($given, PATHINFO_FILENAME);
		// Control characters, slashes and the characters Windows and Nextcloud both
		// refuse; collapsed rather than dropped so words do not run together.
		$base = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $base) ?? '';
		$base = trim(preg_replace('/\s+/u', ' ', $base) ?? '');
		// A name of only dots is a directory reference, not a file.
		$base = trim($base, '.');

		if ($base === '') {
			$base = $this->l10n->t('Uploaded track');
		}

		// Leave room for the " (2)" that getNonExistingName() may append, and for the
		// extension, inside the 255-byte limit filesystems impose on a single name.
		return mb_strcut($base, 0, 200) . $this->extensionFor($given, $mimetype);
	}

	/**
	 * Settle on an extension whose *name*-derived type is audio.
	 *
	 * This is not cosmetic. Nextcloud types a stored file by its name, not its bytes, so
	 * an upload saved as `song.bin` would be `application/octet-stream` from then on and
	 * would be refused by the playlist as not-audio. The client's own extension is kept
	 * when it maps to audio — that is what keeps `.m4a` from turning into `.mp4` — and
	 * otherwise one is derived from what the bytes turned out to be.
	 *
	 * @throws MusicRadioException
	 */
	private function extensionFor(string $given, string $mimetype): string {
		$givenExtension = strtolower(pathinfo($given, PATHINFO_EXTENSION));
		if (preg_match('/^[a-z0-9]{1,8}$/', $givenExtension) === 1
			&& str_starts_with($this->mimeTypeDetector->detectPath('x.' . $givenExtension), self::AUDIO_MIME_PREFIX)) {
			return '.' . $givenExtension;
		}

		foreach ($this->mimeTypeDetector->getAllMappings() as $extension => $types) {
			// Numeric-looking extensions arrive as ints, and the `_comment*` keys are not
			// extensions at all.
			$extension = (string)$extension;
			if (str_starts_with($extension, '_') || $types[0] !== $mimetype) {
				continue;
			}

			return '.' . $extension;
		}

		throw new MusicRadioException($this->l10n->t('That audio format is not supported'));
	}

	/**
	 * @throws MusicRadioException
	 */
	private function musicFolderOf(string $ownerId): Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder($ownerId);
		} catch (\Throwable $e) {
			$this->logger->error('Could not open the channel owner\'s files for an upload', [
				'app' => 'music_radio',
				'owner' => $ownerId,
				'exception' => $e,
			]);
			throw new MusicRadioException(
				$this->l10n->t('Uploads are not possible on this channel right now'),
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		try {
			$node = $userFolder->nodeExists(self::MUSIC_FOLDER)
				? $userFolder->get(self::MUSIC_FOLDER)
				: $userFolder->newFolder(self::MUSIC_FOLDER);
		} catch (NotPermittedException|StorageNotAvailableException $e) {
			$this->logger->error('Could not reach the channel owner\'s Music folder', [
				'app' => 'music_radio',
				'owner' => $ownerId,
				'exception' => $e,
			]);
			throw new MusicRadioException(
				$this->l10n->t('Uploads are not possible on this channel right now'),
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		// A *file* called Music would make newFile() below fail in a way nobody could act
		// on, so say plainly that there is nowhere to put this.
		if (!$node instanceof Folder) {
			throw new MusicRadioException(
				$this->l10n->t('Uploads are not possible on this channel right now'),
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		return $node;
	}

	/**
	 * The owner's quota is the real ceiling on this feature — the upload is charged to
	 * them, not to whoever sent it.
	 *
	 * @throws MusicRadioException
	 */
	private function assertFitsInQuota(Folder $folder, int $size): void {
		$free = $folder->getFreeSpace();

		// SPACE_UNLIMITED, SPACE_UNKNOWN and SPACE_NOT_COMPUTED are all negative
		// sentinels rather than sizes; none of them is a reason to refuse.
		if ($free < 0) {
			return;
		}

		if ($size > $free) {
			throw new MusicRadioException(
				$this->l10n->t('There is not enough space left on this channel'),
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			);
		}
	}

	/**
	 * @throws MusicRadioException
	 */
	private function writeFile(Folder $folder, string $name, string $tmpPath): File {
		$handle = fopen($tmpPath, 'rb');
		if ($handle === false) {
			throw new MusicRadioException(
				$this->l10n->t('That file could not be read'),
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		try {
			// Never overwrite: the owner's own library shares this folder, and a stranger
			// replacing `Favourite song.mp3` would be data loss.
			//
			// Caught as Throwable rather than NotPermittedException alone: writing goes
			// all the way down to the storage backend, and an external storage being
			// unreachable or a file being locked must read as "could not save it", not as
			// an unhandled error page.
			return $folder->newFile($folder->getNonExistingName($name), $handle);
		} catch (\Throwable $e) {
			$this->logger->error('Could not write an uploaded track into the owner\'s files', [
				'app' => 'music_radio',
				'exception' => $e,
			]);
			throw new MusicRadioException(
				$this->l10n->t('That file could not be saved'),
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}

	/**
	 * Roll back a written file. Best effort — an upload that failed to become a track has
	 * already produced an error for the caller, and failing to clean up must not replace
	 * that error with a less useful one.
	 */
	private function discard(File $file): void {
		try {
			$file->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove an upload that failed to become a track', [
				'app' => 'music_radio',
				'fileId' => $file->getId(),
				'exception' => $e,
			]);
		}
	}

	private function describeUploadError(int $error): string {
		return match ($error) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->l10n->t('That file is too large'),
			UPLOAD_ERR_PARTIAL => $this->l10n->t('The upload did not finish, please try again'),
			UPLOAD_ERR_NO_FILE => $this->l10n->t('No file was uploaded'),
			default => $this->l10n->t('The upload failed, please try again'),
		};
	}
}
