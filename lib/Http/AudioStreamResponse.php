<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\ISession;
use Psr\Log\LoggerInterface;

/**
 * Streams an audio file, honouring HTTP range requests.
 */
class AudioStreamResponse extends Response implements ICallbackResponse {

	/**
	 * Copy in chunks rather than reading the requested span into memory: a listener
	 * seeking to the start of a long file would otherwise ask for tens of megabytes in
	 * one allocation.
	 */
	private const CHUNK_SIZE = 512 * 1024;

	public function __construct(
		private File $file,
		private ByteRange $range,
		private ISession $session,
		private LoggerInterface $logger,
	) {
		parent::__construct();

		$size = $this->file->getSize();

		// Advertised on every response, including 200 and 416 — this is how a browser
		// learns it is allowed to seek at all.
		$this->addHeader('Accept-Ranges', 'bytes');
		$this->addHeader('X-Content-Type-Options', 'nosniff');

		if ($this->range->isUnsatisfiable()) {
			// 416 carries `bytes * /size` so the client can retry against the real length.
			$this->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
			$this->addHeader('Content-Range', $this->range->contentRange($size));
			$this->addHeader('Content-Length', '0');

			return;
		}

		$this->addHeader('Content-Type', $this->file->getMimeType());
		$this->addHeader('Content-Length', (string)$this->range->length());
		// Inline: this is played in a media element, never downloaded as an attachment.
		$this->addHeader('Content-Disposition', 'inline');

		if ($this->range->isPartial()) {
			$this->setStatus(Http::STATUS_PARTIAL_CONTENT);
			$this->addHeader('Content-Range', $this->range->contentRange($size));
		} else {
			// A whole-file response must not carry Content-Range at all.
			$this->setStatus(Http::STATUS_OK);
		}
	}

	public function callback(IOutput $output): void {
		// Nextcloud holds an exclusive lock on the PHP session for the duration of a
		// request. A listener keeps a stream open for the length of a track, so without
		// this every other request that user makes — the whole UI — would block behind
		// it. Nothing below needs the session.
		$this->session->close();

		if ($this->range->isUnsatisfiable()) {
			return;
		}

		$handle = null;
		try {
			$handle = $this->file->fopen('rb');
			if ($handle === false) {
				$output->setHttpResponseCode(Http::STATUS_NOT_FOUND);

				return;
			}

			if ($this->range->start > 0 && fseek($handle, $this->range->start) !== 0) {
				// Some external storages return a non-seekable stream. Reading forward
				// would work but silently costs a full download per seek, so fail loudly
				// instead of appearing to work.
				$output->setHttpResponseCode(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);

				return;
			}

			$remaining = $this->range->length();
			while ($remaining > 0 && !feof($handle)) {
				// Stop as soon as the listener goes away — a radio channel can have many
				// of them, and a worker that keeps pushing bytes at a closed connection
				// is a worker nobody else can use.
				if (connection_aborted() !== CONNECTION_NORMAL) {
					break;
				}

				$chunk = fread($handle, (int)min(self::CHUNK_SIZE, $remaining));
				if ($chunk === false || $chunk === '') {
					break;
				}

				$output->setOutput($chunk);
				$remaining -= strlen($chunk);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Audio stream failed', [
				'app' => 'music_radio',
				'fileId' => $this->file->getId(),
				'exception' => $e,
			]);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}
}
