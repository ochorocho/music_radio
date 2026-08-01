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
use OCP\ISession;
use Psr\Log\LoggerInterface;

/**
 * A stretch of a channel's programme, as one continuous MP3.
 *
 * Several tracks arrive as one body with no seam the browser needs help crossing, which is
 * the entire point: with an iPhone locked there is no JavaScript to help it. Every part was
 * prepared to the same encoding by {@see \OCA\MusicRadio\Service\BroadcastLibrary}, so the
 * frames concatenate without the decoder reconfiguring half way through.
 *
 * Sent as fast as the connection takes it, *not* paced at playback speed. Pacing would hold
 * a PHP worker for the listener's whole session against a pool of eight; sending flat out
 * holds one for a second or two and leaves the browser with half an hour of audio.
 */
class ProgrammeStreamResponse extends Response implements ICallbackResponse {

	/** Matches AudioStreamResponse: big enough to be efficient, small enough to abort promptly. */
	private const CHUNK_SIZE = 512 * 1024;

	/**
	 * @param list<ProgrammeSpan> $spans in the order they are to be heard
	 */
	public function __construct(
		private array $spans,
		private ISession $session,
		private LoggerInterface $logger,
	) {
		parent::__construct();

		$length = 0;
		foreach ($this->spans as $span) {
			$length += $span->length;
		}

		$this->setStatus(Http::STATUS_OK);
		$this->addHeader('Content-Type', 'audio/mpeg');
		$this->addHeader('Content-Length', (string)$length);
		$this->addHeader('Content-Disposition', 'inline');
		$this->addHeader('X-Content-Type-Options', 'nosniff');

		// No ranges. This is not a file — it is a view of a programme computed from a
		// position that moves, and a byte offset into it means nothing once the answer has
		// changed. Saying so plainly stops a media element trying to seek within it and
		// getting a body that no longer lines up with what it asked for; seeking is done by
		// asking for a different position instead.
		$this->addHeader('Accept-Ranges', 'none');

		// The opposite of the per-track stream, which is cacheable with an ETag. Two
		// requests a second apart are legitimately different audio, so nothing here may be
		// kept or revalidated.
		$this->cacheFor(0);
	}

	public function callback(IOutput $output): void {
		// Nextcloud holds an exclusive lock on the session for the length of a request.
		// This one can run for a few seconds over a slow connection, and everything else
		// the listener's browser is doing would queue behind it. Nothing below needs it.
		$this->session->close();

		foreach ($this->spans as $span) {
			if (!$this->send($output, $span)) {
				return;
			}
		}
	}

	/**
	 * @return bool whether to carry on with the remaining spans
	 */
	private function send(IOutput $output, ProgrammeSpan $span): bool {
		$handle = @fopen($span->path, 'rb');
		if ($handle === false) {
			// Prepared a moment ago and gone now — the cache was cleared mid-request, or
			// the disk filled. Dropping this track and continuing is better than ending the
			// broadcast: the listener loses one song rather than the rest of the half hour.
			$this->logger->warning('A prepared track vanished mid-broadcast', [
				'app' => 'music_radio',
				'trackId' => $span->trackId,
			]);

			return true;
		}

		try {
			if ($span->start > 0 && fseek($handle, $span->start) !== 0) {
				return true;
			}

			$remaining = $span->length;
			while ($remaining > 0 && !feof($handle)) {
				// A listener who closed the tab is not owed the rest of the half hour, and
				// the worker they are holding is one of eight.
				if (connection_aborted() !== CONNECTION_NORMAL) {
					return false;
				}

				$chunk = fread($handle, (int)min(self::CHUNK_SIZE, $remaining));
				if ($chunk === false || $chunk === '') {
					break;
				}

				$output->setOutput($chunk);
				$remaining -= strlen($chunk);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Programme stream failed', [
				'app' => 'music_radio',
				'trackId' => $span->trackId,
				'exception' => $e,
			]);

			return false;
		} finally {
			fclose($handle);
		}

		return true;
	}
}
