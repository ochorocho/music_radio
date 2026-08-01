<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Process\IProcessRunner;
use OCP\Files\File;
use OCP\IConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * A copy of every track in one uniform encoding, so the programme can be served as a
 * single continuous stream.
 *
 * The reason this exists is iOS. With the screen locked the page's timers are suspended,
 * so nothing of ours runs when a track ends — no code can load the next one. The only way
 * the music continues is if the audio the browser is *already holding* runs on into the
 * next track, which means the boundary has to disappear from the audio itself: one stream,
 * many tracks, no seam the browser needs help crossing.
 *
 * Concatenated MP3 frames only decode cleanly if they agree. A stream that changes sample
 * rate half way makes the decoder reconfigure mid-playback, which is the thing that stops
 * iOS. The library here is already mixed — 44.1 kHz for most of it, 48 kHz for the rest,
 * because uploads are stored exactly as given and yt-dlp inherits whatever YouTube served
 * — so agreement has to be manufactured.
 *
 * **The owner's own file is never touched.** Somebody who uploads a FLAC keeps a FLAC; the
 * derived copy lives beside the managed yt-dlp under the app's data directory. That costs
 * roughly a megabyte a minute, and buys something beyond concatenation: the source no
 * longer has to be MP3 at all, so anything ffmpeg can read becomes broadcastable.
 */
class BroadcastLibrary {

	/**
	 * The one encoding everything is made to agree on.
	 *
	 * 44.1 kHz because that is what most of the library already is, so most tracks
	 * transcode without resampling.
	 *
	 * **Constant bitrate is not a preference.** It is what makes a byte offset a linear
	 * function of time — `bytes = ms × 16` at 128 kbit/s — which is how the segment
	 * endpoint starts half way through a track without decoding a single frame to find
	 * out where it is. A variable-bitrate copy would need a seek table and a parser.
	 */
	public const SAMPLE_RATE = 44100;
	public const CHANNELS = 2;
	public const BITRATE_KBPS = 128;

	/** Bytes of audio per millisecond at the profile above. 128000 / 8 / 1000. */
	public const BYTES_PER_MS = 16;

	/** Generous: a long track on a busy server, but not so long that a wedged ffmpeg lives forever. */
	private const TIMEOUT_SECONDS = 600;

	public function __construct(
		private YtDlpLocator $locator,
		private IProcessRunner $runner,
		private ITempManager $tempManager,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Where a track's broadcast copy lives, whether or not it has been built.
	 *
	 * Under the data directory rather than in appdata, for the same reason
	 * {@see YtDlpLocator::managedPath()} is: appdata may be object storage, and this has to
	 * be a real file that ffmpeg can write and PHP can `fseek`.
	 */
	public function pathFor(int $trackId): string {
		return $this->directory() . '/' . $trackId . '.mp3';
	}

	public function directory(): string {
		return rtrim($this->config->getSystemValueString('datadirectory', ''), '/')
			. '/' . Application::APP_ID . '/broadcast';
	}

	/**
	 * Whether a usable copy already exists.
	 *
	 * Compared against the source's modification time, so replacing a file under a track —
	 * which the app allows, the file id being what it tracks — rebuilds rather than
	 * broadcasting the old audio for ever.
	 */
	public function isBuilt(Track $track, File $source): bool {
		$path = $this->pathFor($track->getId());

		return is_file($path)
			&& filesize($path) > 0
			&& filemtime($path) >= $source->getMTime();
	}

	/**
	 * Build the copy if it is missing or stale, and return its path.
	 *
	 * @throws MusicRadioException when the copy cannot be produced
	 */
	public function ensure(Track $track, File $source): string {
		$path = $this->pathFor($track->getId());
		if ($this->isBuilt($track, $source)) {
			return $path;
		}

		$this->build($track, $source, $path);

		return $path;
	}

	/**
	 * @throws MusicRadioException
	 */
	private function build(Track $track, File $source, string $target): void {
		$ffmpegDir = $this->locator->ffmpegDirectory();
		if ($ffmpegDir === null) {
			throw new MusicRadioException('ffmpeg is needed to prepare audio for broadcast, and was not found');
		}
		if (!$this->runner->isAvailable()) {
			throw new MusicRadioException('This server does not permit running external programs');
		}

		$directory = dirname($target);
		if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
			throw new MusicRadioException('Could not create ' . $directory);
		}

		// ffmpeg needs a path it can open, and a Nextcloud file may be on storage that has
		// none. Copying through a stream is the one approach that works for every backend.
		$input = $this->copyToTemp($source);
		// Written aside and moved into place, so a build that dies half way cannot leave a
		// truncated file that `isBuilt` would then accept.
		$staging = $target . '.new';

		try {
			$result = $this->runner->run(
				$this->argv($ffmpegDir, $input, $staging),
				dirname($staging),
				[],
				self::TIMEOUT_SECONDS,
			);

			if (!$result->succeeded() || !is_file($staging) || filesize($staging) === 0) {
				@unlink($staging);
				$this->logger->warning('Could not prepare a track for broadcast', [
					'app' => Application::APP_ID,
					'trackId' => $track->getId(),
					'exitCode' => $result->exitCode,
					'stderr' => substr($result->stderr, -2000),
				]);

				throw new MusicRadioException('That track could not be prepared for broadcast');
			}

			if (!@rename($staging, $target)) {
				@unlink($staging);
				throw new MusicRadioException('Could not move the prepared audio into place');
			}
		} finally {
			@unlink($input);
		}
	}

	/**
	 * @return list<string>
	 */
	private function argv(string $ffmpegDir, string $input, string $output): array {
		return [
			rtrim($ffmpegDir, '/') . '/ffmpeg',
			'-nostdin',
			'-hide_banner',
			'-loglevel', 'error',
			'-y',
			'-i', $input,
			// No cover art. A picture stream in an MP3 becomes an ID3 frame, and this
			// output has to be nothing but audio frames.
			'-vn',
			'-map_metadata', '-1',
			'-map', '0:a:0',
			'-ac', (string)self::CHANNELS,
			'-ar', (string)self::SAMPLE_RATE,
			'-c:a', 'libmp3lame',
			'-b:a', self::BITRATE_KBPS . 'k',
			// The Xing/LAME header is written as a *frame* at the head of the file. Harmless
			// in a file played on its own, and not harmless at all when files are
			// concatenated: every join would carry a frame of encoder bookkeeping into the
			// middle of the stream. Suppressing it is what makes the copies joinable.
			'-write_xing', '0',
			'-id3v2_version', '0',
			'-f', 'mp3',
			$output,
		];
	}

	/**
	 * @throws MusicRadioException
	 */
	private function copyToTemp(File $source): string {
		$temp = $this->tempManager->getTemporaryFile('.src');
		if ($temp === false) {
			throw new MusicRadioException('Could not create a temporary file');
		}

		$in = $source->fopen('rb');
		if ($in === false) {
			throw new MusicRadioException('Could not read the track');
		}

		$out = @fopen($temp, 'wb');
		if ($out === false) {
			fclose($in);
			throw new MusicRadioException('Could not write a temporary copy of the track');
		}

		try {
			stream_copy_to_stream($in, $out);
		} finally {
			fclose($in);
			fclose($out);
		}

		return $temp;
	}

	/**
	 * Forget a track's copy — after the track is removed, or its file replaced.
	 */
	public function forget(int $trackId): void {
		@unlink($this->pathFor($trackId));
	}
}
