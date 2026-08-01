<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

/**
 * Building the command line for yt-dlp.
 *
 * A separate class, pure and static, for one reason: the flags below are security
 * decisions, and putting them here lets a unit test assert the exact list. If someone
 * later drops `--ignore-config` or moves the URL out of the last position, a test fails
 * rather than a server quietly becoming reachable from a text field.
 *
 * Two passes are built, not one:
 *
 * - **probe()** asks yt-dlp what a video *is* without downloading it. It costs about a
 *   second and buys the difference between "that video is private" and "the import
 *   failed" — the private, geo-blocked, age-gated, live and too-long cases are all
 *   knowable up front, and are much harder to read reliably out of a failed download's
 *   stderr. It also yields the title, so the queue row can be named immediately instead
 *   of showing an eleven-character id.
 * - **download()** does the work, having already established that the work is worth doing.
 *
 * @see YoutubeUrl for why the URL passed in here can be trusted
 */
final class YtDlpArgv {

	/** The requested output: MP3 at a constant 128 kbit/s, which ffmpeg produces. */
	public const AUDIO_FORMAT = 'mp3';
	public const AUDIO_BITRATE = '128K';

	/**
	 * The fixed stem every download writes to.
	 *
	 * yt-dlp chooses only the extension. Letting it build a name out of metadata would
	 * mean a video title deciding a path, and `%(title)s` does not restrict separators.
	 * The human-readable name is applied later, by MusicLibrary, where it is sanitised.
	 */
	public const OUTPUT_STEM = 'audio';

	/**
	 * Progress is asked for in a shape that is trivial to parse and stable across
	 * releases, rather than scraped from the human-readable bar.
	 */
	public const PROGRESS_PREFIX = 'mrprogress';
	private const PROGRESS_TEMPLATE = self::PROGRESS_PREFIX . ':%(progress.downloaded_bytes)s %(progress.total_bytes_estimate)s %(progress.total_bytes)s';

	/**
	 * How far along a download is, from one line of yt-dlp's stdout.
	 *
	 * Lives here because this class defines the template being parsed; the two have to
	 * change together.
	 *
	 * Both size fields are asked for because either can be the string `NA`. Which one is
	 * populated depends on the stream: a progressive file knows its length up front, while
	 * a fragmented one only has an estimate — and a small file can report `NA` for the
	 * estimate and a real number for the total, which is what a live run of this actually
	 * did. Preferring whichever is numeric is the difference between a working progress bar
	 * and a division by zero.
	 *
	 * @return float|null a fraction between 0 and 1, or null when this line says nothing
	 *                    useful about progress
	 */
	public static function parseProgress(string $line): ?float {
		if (!str_starts_with($line, self::PROGRESS_PREFIX . ':')) {
			return null;
		}

		$fields = preg_split('/\s+/', trim(substr($line, strlen(self::PROGRESS_PREFIX) + 1))) ?: [];
		if (count($fields) < 3) {
			return null;
		}

		$downloaded = self::numeric($fields[0]);
		// The estimate first: for a fragmented stream it is the only one populated.
		$total = self::numeric($fields[1]) ?? self::numeric($fields[2]);

		if ($downloaded === null || $total === null || $total <= 0) {
			return null;
		}

		return min(1.0, $downloaded / $total);
	}

	private static function numeric(string $field): ?float {
		return is_numeric($field) ? (float)$field : null;
	}

	/**
	 * @return list<string>
	 */
	public static function probe(
		string $ytDlp,
		string $canonicalUrl,
		?string $proxy = null,
		?string $jsRuntime = null,
	): array {
		return [
			...self::safetyFlags($ytDlp),
			...self::networkFlags($proxy),
			...self::javascriptFlags($jsRuntime),

			// Ask, do not fetch.
			'--simulate',
			'--skip-download',
			// One JSON document on stdout describing the video: duration, live status,
			// availability, age limit, title. Everything the pre-flight checks need.
			'--dump-single-json',

			'--',
			$canonicalUrl,
		];
	}

	/**
	 * @param string $ffmpegDir the directory holding ffmpeg and ffprobe
	 * @param int $maxDurationSeconds refused before any bytes flow
	 * @param int $maxFilesizeBytes ceiling on the *source* stream; the produced file is
	 *                              measured again afterwards, because a transcode can
	 *                              land either side of this
	 * @param string|null $jsRuntime a {@see JsRuntime::spec()}; without one it is this pass
	 *                               that gets refused, the probe above having succeeded
	 * @return list<string>
	 */
	public static function download(
		string $ytDlp,
		string $canonicalUrl,
		string $tmpDir,
		string $ffmpegDir,
		int $maxDurationSeconds,
		int $maxFilesizeBytes,
		?string $proxy = null,
		?string $jsRuntime = null,
	): array {
		return [
			...self::safetyFlags($ytDlp),
			...self::networkFlags($proxy),
			...self::javascriptFlags($jsRuntime),

			// --- limits, applied before and during the download -----------------
			// Checked against the metadata, so an over-long video costs nothing.
			'--match-filter', 'duration < ' . $maxDurationSeconds,
			// Stops mid-stream rather than filling a disk.
			'--max-filesize', (string)$maxFilesizeBytes,

			// --- what to fetch --------------------------------------------------
			// An audio-only stream where one exists; the container does not matter
			// because it is transcoded regardless.
			'-f', 'bestaudio/best',

			// --- what to produce -----------------------------------------------
			'--extract-audio',
			'--audio-format', self::AUDIO_FORMAT,
			// A bitrate rather than a 0-10 quality, so this is ffmpeg's -b:a 128k.
			'--audio-quality', self::AUDIO_BITRATE,
			// Writes the title and uploader as ID3. This is what makes AudioProbe return a
			// real title and artist instead of falling back to the filename, so it is
			// functional rather than decorative.
			'--embed-metadata',
			// Do not take the file's timestamp from the upload date: a track added today
			// dated 2009 sorts oddly in Files and confuses the filecache for no gain.
			'--no-mtime',
			// ffmpeg is located by us and named explicitly. The child gets a minimal PATH,
			// so leaving this to a lookup would be a different failure on every host.
			'--ffmpeg-location', $ffmpegDir,

			// --- where it lands -------------------------------------------------
			// Everything, including intermediate files, inside the per-import directory.
			'--paths', 'home:' . $tmpDir,
			'--paths', 'temp:' . $tmpDir,
			'-o', self::OUTPUT_STEM . '.%(ext)s',
			// The metadata that named the file, kept for the title. Read and then thrown
			// away with the directory.
			'--write-info-json',

			// --- progress -------------------------------------------------------
			// Each update on its own line; without this yt-dlp rewrites one line with \r
			// and there is nothing to read incrementally.
			'--newline',
			'--progress-template', self::PROGRESS_TEMPLATE,

			'--',
			$canonicalUrl,
		];
	}

	/**
	 * Flags that make a run predictable, on both passes.
	 *
	 * @return list<string>
	 */
	private static function safetyFlags(string $ytDlp): array {
		return [
			$ytDlp,

			// A yt-dlp config file anywhere it looks — /etc, the home directory, the
			// working directory — can add any option, including --exec. The whole command
			// line has to be the one written here and nothing else.
			'--ignore-config',
			'--no-config-locations',

			// Never run a command. Redundant given --ignore-config, and kept anyway:
			// this is the flag whose absence would matter most.
			'--no-exec',

			// yt-dlp caches to ~/.cache by default. The web user's home may be
			// unwritable, shared between vhosts, or absent; nothing should outlive the
			// temporary directory.
			'--no-cache-dir',

			// A watch URL can carry &list=. The canonicaliser already strips it, so this
			// is the second lock on the same door — one link must never become 200
			// downloads.
			'--no-playlist',

			// A fresh directory every time, so a partial file from a previous run cannot
			// be resumed or overwritten. Either happening would mean a bug elsewhere.
			'--no-continue',
			'--no-overwrites',

			// Captured stderr is read by a classifier and written to a log; escape codes
			// help neither.
			'--no-color',
		];
	}

	/**
	 * Lend yt-dlp an engine for YouTube's own JavaScript.
	 *
	 * On both passes, because the metadata pass needs it too on some videos and asking for
	 * it costs nothing when it is not — the flag enables a runtime, it does not invoke one.
	 *
	 * yt-dlp enables `deno` by default and looks for it on PATH. That default is why this
	 * looks superfluous and is not: the child's PATH is the minimal one this app hands it,
	 * and `node` — the runtime a Nextcloud host is actually likely to have — is not enabled
	 * unless it is named here.
	 *
	 * Nothing is passed when the server has no runtime at all. `--js-runtimes` with an
	 * absent binary is an error yt-dlp refuses to start on, which would turn "the download
	 * will probably fail" into "nothing works, including the parts that did".
	 *
	 * @param string|null $jsRuntime a {@see JsRuntime::spec()}
	 * @return list<string>
	 */
	private static function javascriptFlags(?string $jsRuntime): array {
		if ($jsRuntime === null || $jsRuntime === '') {
			return [];
		}

		return ['--js-runtimes', $jsRuntime];
	}

	/**
	 * @return list<string>
	 */
	private static function networkFlags(?string $proxy): array {
		$flags = [
			// Bounded failure rather than a process that hangs until the runner kills it.
			'--socket-timeout', '20',
			'--retries', '3',
			'--fragment-retries', '3',
		];

		// Many Nextcloud installs cannot reach the internet directly. The child has no
		// inherited environment, so an http_proxy variable would not reach it anyway —
		// the server's configured proxy has to be passed explicitly.
		if ($proxy !== null && $proxy !== '') {
			$flags[] = '--proxy';
			$flags[] = $proxy;
		}

		// Deliberately absent: --geo-bypass. A video blocked in this server's region is
		// reported as blocked, not worked around.

		return $flags;
	}
}
