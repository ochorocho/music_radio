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
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Taking a file handed over by someone with no account and putting it on a channel.
 *
 * The file has to land in *somebody's* storage to be streamable later, and the only
 * person with a stake in the channel is its owner — so it goes into their music folder
 * and counts against their quota. That is a real cost to them, which is why uploading
 * is off unless the owner turns it on for a link, and why every limit below is checked
 * before a single byte is written.
 *
 * What is left in this class is only the part specific to a PHP file upload: deciding
 * whether the request actually delivered a complete file, and whether it is small enough
 * to bother with. Everything after that — sniffing the type from the bytes, reducing the
 * client's filename to something safe, checking the quota, writing the file and appending
 * the track — is shared with the server-side import path and lives in MusicLibrary.
 *
 * @see MusicLibrary
 */
class UploadService {

	/**
	 * A generous album track at a high bitrate is a few tens of megabytes; this leaves
	 * plenty of headroom while keeping a single anonymous request from filling a disk.
	 * PHP's own upload_max_filesize may of course be lower, and wins.
	 *
	 * MusicLibrary enforces the same ceiling on everything it stores. This check stays
	 * here as well so an oversized upload is refused with the status code that says why,
	 * before the file is handed on.
	 */
	private const MAX_UPLOAD_BYTES = MusicLibrary::MAX_TRACK_BYTES;

	public function __construct(
		private MusicLibrary $library,
		private VisitorIdentity $visitorIdentity,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Store an uploaded file in the channel owner's music folder and append it to the
	 * playlist.
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $upload one
	 *                                                                                                entry of $_FILES, as handed over by IRequest::getUploadedFile()
	 * @throws MusicRadioException when the upload is rejected; the message is meant for
	 *                             the person who tried to upload
	 */
	/**
	 * @param bool|null $requireApproval what the link this arrived through says. Passed in
	 *                                   rather than looked up: the share is known to the
	 *                                   controller, and a visitor key is not an account, so
	 *                                   it cannot be resolved from who added it.
	 */
	public function storeForChannel(
		Channel $channel,
		array $upload,
		?string $visitorKey = null,
		?bool $requireApproval = null,
	): Track {
		$tmpPath = $this->validateUpload($upload);

		$track = $this->library->ingest(
			$channel,
			$channel->getUserId(),
			$tmpPath,
			(string)($upload['name'] ?? ''),
			// Credited to the browser that sent it, so that browser can take it back
			// again. Without a key this falls back to the old anonymous sentinel and the
			// upload is nobody's, exactly as it used to be.
			$this->visitorIdentity->creditFor($visitorKey),
			null,
			$requireApproval === null ? null : !$requireApproval,
		);

		$this->logger->info('Track uploaded to a channel through a public link', [
			'app' => Application::APP_ID,
			'channelId' => $channel->getId(),
			'trackId' => $track->getId(),
			'fileId' => $track->getFileId(),
			'mimetype' => $track->getMimetype(),
			'size' => $track->getSize(),
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

	private function describeUploadError(int $error): string {
		return match ($error) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $this->l10n->t('That file is too large'),
			UPLOAD_ERR_PARTIAL => $this->l10n->t('The upload did not finish, please try again'),
			UPLOAD_ERR_NO_FILE => $this->l10n->t('No file was uploaded'),
			default => $this->l10n->t('The upload failed, please try again'),
		};
	}
}
