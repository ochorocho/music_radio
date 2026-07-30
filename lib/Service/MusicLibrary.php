<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCP\AppFramework\Http;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Turning a file the server is holding on disk into a track on a channel.
 *
 * Both ways of getting audio onto a channel that do not start from a file the adder
 * already owns end up here: an anonymous upload through a public link, and a download the
 * server fetched itself. In both cases we hold a path in a temporary directory and need it
 * to become a real, streamable file in a real user's storage — charged to their quota,
 * typed as audio, and named something that cannot escape the folder it belongs in.
 *
 * Writing the file and appending the track are one operation on purpose. A file that
 * failed to become a track has to be removed again, or the owner's music folder quietly
 * grows orphans; keeping the write and the rollback in one place is what stops each caller
 * from having to remember that.
 *
 * Nothing here trusts the caller. The name is reduced to a bare basename, the type is
 * sniffed from the bytes rather than read off a header or an extension, and the size is
 * measured rather than declared. That is deliberately paranoid: the anonymous-upload path
 * means the bytes and the name can come from someone with no account at all.
 *
 * @see UploadService for the public-link upload path
 * @see YoutubeImportService for the server-side download path
 */
class MusicLibrary {

	/**
	 * A generous album track at a high bitrate is a few tens of megabytes; this leaves
	 * plenty of headroom while keeping one request from filling a disk. PHP's own
	 * upload_max_filesize may of course be lower, and wins on the upload path.
	 */
	public const MAX_TRACK_BYTES = 100 * 1024 * 1024;

	/** Where audio lands when the owner has not said otherwise. */
	public const DEFAULT_FOLDER = 'Music';

	/** Per-user config key holding the folder name, relative to their files root. */
	public const CONFIG_FOLDER = 'download_folder';

	private const AUDIO_MIME_PREFIX = 'audio/';

	public function __construct(
		private IRootFolder $rootFolder,
		private IMimeTypeDetector $mimeTypeDetector,
		private TrackService $trackService,
		private IUserConfig $userConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Store a local file in someone's music folder and append it to a channel.
	 *
	 * @param string $storageOwnerId whose folder, whose quota, whose folder preference.
	 *                               Not necessarily whoever asked for this: a public-link
	 *                               upload has no account to charge, and an import is
	 *                               filed with the channel rather than with the importer.
	 * @param string $localPath a path on the server's own filesystem. The caller owns it;
	 *                          this only reads it, and never removes it.
	 * @param string $suggestedName what to call it — sanitised, not trusted
	 * @param string $addedBy who to credit in the playlist
	 * @param int|null $durationHintMs used only if the probe cannot read the file's headers
	 * @throws MusicRadioException when the file is rejected; the message is meant to be
	 *                             shown to whoever tried to add it
	 */
	public function ingest(
		Channel $channel,
		string $storageOwnerId,
		string $localPath,
		string $suggestedName,
		string $addedBy,
		?int $durationHintMs = null,
	): Track {
		$file = $this->store($storageOwnerId, $localPath, $suggestedName);

		try {
			$result = $this->trackService->add(
				$channel,
				$storageOwnerId,
				[$file->getId()],
				$durationHintMs === null ? [] : [$file->getId() => $durationHintMs],
				$addedBy,
			);
		} catch (\Throwable $e) {
			// The file only exists to be a track. If it could not become one, leaving it
			// behind would quietly grow the owner's music folder with orphans.
			$this->discard($file);
			throw $e;
		}

		$track = $result['added'][0] ?? null;
		if ($track === null) {
			$this->discard($file);
			throw new MusicRadioException($this->l10n->t('That file could not be added to the channel'));
		}

		return $track;
	}

	/**
	 * Store a local file in $ownerId's music folder.
	 *
	 * Sniffs, names, quota-checks and writes, in that order — every guard runs before a
	 * byte is written. Never overwrites: the owner's own library shares this folder, and
	 * replacing `Favourite song.mp3` would be data loss.
	 *
	 * @throws MusicRadioException
	 */
	private function store(string $ownerId, string $localPath, string $suggestedName): File {
		$size = $this->measure($localPath);
		$mimetype = $this->sniffAudioMimetype($localPath);
		$name = $this->safeFilename($suggestedName, $mimetype);

		$folder = $this->folderOf($ownerId);
		$this->assertFitsInQuota($folder, $size);

		return $this->write($folder, $name, $localPath);
	}

	/**
	 * The folder store() writes into, created if it is not there yet.
	 *
	 * @throws MusicRadioException
	 */
	private function folderOf(string $ownerId): Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder($ownerId);
		} catch (\Throwable $e) {
			$this->logger->error('Could not open a user\'s files to store audio in', [
				'app' => Application::APP_ID,
				'owner' => $ownerId,
				'exception' => $e,
			]);
			throw $this->unavailable();
		}

		$relative = $this->configuredFolder($ownerId);

		try {
			$node = $userFolder->nodeExists($relative)
				? $userFolder->get($relative)
				: $userFolder->newFolder($relative);
		} catch (NotPermittedException|StorageNotAvailableException $e) {
			$this->logger->error('Could not reach a user\'s music folder', [
				'app' => Application::APP_ID,
				'owner' => $ownerId,
				'folder' => $relative,
				'exception' => $e,
			]);
			throw $this->unavailable();
		}

		// A *file* by that name would make newFile() below fail in a way nobody could act
		// on, so say plainly that there is nowhere to put this.
		if (!$node instanceof Folder) {
			throw $this->unavailable();
		}

		return $node;
	}

	/**
	 * Roll back a file store() created. Best effort — whatever went wrong afterwards has
	 * already produced an error for the caller, and failing to clean up must not replace
	 * that error with a less useful one.
	 */
	private function discard(File $file): void {
		try {
			$file->delete();
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove a stored file that failed to become a track', [
				'app' => Application::APP_ID,
				'fileId' => $file->getId(),
				'exception' => $e,
			]);
		}
	}

	// ------------------------------------------------------------------ guards

	/**
	 * Where this user wants audio to land, as a path relative to their files root.
	 *
	 * Reduce whatever is stored to something safe to hand to Folder::get(), falling back
	 * to the default rather than failing — a mangled preference should not make adding
	 * music impossible.
	 *
	 * This runs on *read*, and that is the point. The setting is also validated where it
	 * is written, but a value already in the database, or one put there by some other
	 * means, is still a user-controlled string on its way into a filesystem call. Keeping
	 * the check here means the guarantee does not depend on which code path wrote it.
	 *
	 * Pure and static so every rejection is unit-testable without a server.
	 */
	public static function sanitiseFolderPath(string $configured): string {
		$configured = trim(str_replace('\\', '/', $configured), " \t\n\r\0\x0B/");
		if ($configured === '') {
			return self::DEFAULT_FOLDER;
		}

		$segments = explode('/', $configured);

		// Deep enough for `Media/Music/Imports`, shallow enough that a pasted absolute
		// path or a runaway value cannot build a tree.
		if (count($segments) > 4) {
			return self::DEFAULT_FOLDER;
		}

		foreach ($segments as $segment) {
			// '' catches a doubled slash; whitespace-only is not a name anybody meant; a
			// segment of only dots is `.`, `..` or another directory reference.
			if (trim($segment) === '' || trim($segment, " \t.") === '') {
				return self::DEFAULT_FOLDER;
			}
			// Control characters and the characters Windows and Nextcloud both refuse.
			// Rejected outright rather than stripped: silently writing to a folder with a
			// different name than the one someone typed is worse than ignoring the value.
			if (preg_match('/[\x00-\x1F\x7F:*?"<>|]/u', $segment) === 1) {
				return self::DEFAULT_FOLDER;
			}
			if (mb_strlen($segment) > 100) {
				return self::DEFAULT_FOLDER;
			}
		}

		return $configured;
	}

	private function configuredFolder(string $ownerId): string {
		return self::sanitiseFolderPath($this->userConfig->getValueString(
			$ownerId,
			Application::APP_ID,
			self::CONFIG_FOLDER,
			self::DEFAULT_FOLDER,
		));
	}

	/**
	 * @return int the size, once the file is known to be readable and worth storing
	 * @throws MusicRadioException
	 */
	private function measure(string $localPath): int {
		$size = is_file($localPath) ? filesize($localPath) : false;

		if ($size === false || $size <= 0) {
			throw new MusicRadioException($this->l10n->t('That file is empty'));
		}
		if ($size > self::MAX_TRACK_BYTES) {
			throw new MusicRadioException(
				$this->l10n->t('That file is too large'),
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			);
		}

		return $size;
	}

	/**
	 * Decide what the file actually is from its first bytes.
	 *
	 * A declared Content-Type and an extension are both caller-chosen, so neither is
	 * consulted: an .mp3 that is really a PHP script must not end up in somebody's files
	 * because it was labelled convincingly.
	 *
	 * @throws MusicRadioException
	 */
	private function sniffAudioMimetype(string $localPath): string {
		$detected = $this->mimeTypeDetector->detectContent($localPath);

		if (!str_starts_with($detected, self::AUDIO_MIME_PREFIX)) {
			throw new MusicRadioException($this->l10n->t('Only audio files can be uploaded'));
		}

		return $detected;
	}

	/**
	 * Reduce whatever the caller wants the file called to something safe to create.
	 *
	 * Only the basename survives, so `../../` and absolute paths cannot escape the music
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
	 * would be refused by the playlist as not-audio. The caller's own extension is kept
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
	 * The owner's quota is the real ceiling on this feature — the file is charged to them,
	 * not to whoever asked for it.
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
	private function write(Folder $folder, string $name, string $localPath): File {
		$handle = fopen($localPath, 'rb');
		if ($handle === false) {
			throw new MusicRadioException(
				$this->l10n->t('That file could not be read'),
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		try {
			// Caught as Throwable rather than NotPermittedException alone: writing goes
			// all the way down to the storage backend, and an external storage being
			// unreachable or a file being locked must read as "could not save it", not as
			// an unhandled error page.
			return $folder->newFile($folder->getNonExistingName($name), $handle);
		} catch (\Throwable $e) {
			$this->logger->error('Could not write audio into a user\'s files', [
				'app' => Application::APP_ID,
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
	 * One message for every way the owner's storage can be unreachable.
	 *
	 * Whoever is adding music can do nothing about a broken storage or a `Music` file
	 * where a folder should be, and telling them which it was would describe the owner's
	 * files to someone who may not be the owner.
	 */
	private function unavailable(): MusicRadioException {
		return new MusicRadioException(
			$this->l10n->t('Uploads are not possible on this channel right now'),
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}
}
