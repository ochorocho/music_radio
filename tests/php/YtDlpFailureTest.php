<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Process\ProcessResult;
use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\YtDlpFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YtDlpFailureTest extends TestCase {

	/**
	 * The warning yt-dlp emits on every single run. It is in most fixtures below precisely
	 * because it must never be what gets matched.
	 */
	private const JS_WARNING = 'WARNING: [youtube] No supported JavaScript runtime could be found. Only deno is enabled by default; to use another runtime add  --js-runtimes RUNTIME[:PATH]  to your command/config. YouTube extraction without a JS runtime has been deprecated, and some formats may be missing. See  https://github.com/yt-dlp/yt-dlp/wiki/EJS  for details on installing one';

	private static function failed(string $stderr, int $exitCode = 1, string $stdout = ''): ProcessResult {
		return new ProcessResult($exitCode, $stdout, $stderr, false, false);
	}

	// ------------------------------------------------------------- successes

	public function testAGoodRunWithAFileIsNotAFailure(): void {
		$result = new ProcessResult(0, 'some output', self::JS_WARNING, false, false);

		self::assertNull(YtDlpFailure::classify($result, producedFile: true));
	}

	public function testAGoodRunWithNoFileIsStillAFailure(): void {
		$result = new ProcessResult(0, '', self::JS_WARNING, false, false);

		self::assertSame(ImportError::NO_AUDIO, YtDlpFailure::classify($result, producedFile: false));
	}

	// --------------------------------------------------- the runner's verdict

	public function testATimeoutBeatsAnythingInStderr(): void {
		$result = new ProcessResult(143, '', 'ERROR: Private video', true, false);

		self::assertSame(ImportError::TIMED_OUT, YtDlpFailure::classify($result, false));
	}

	public function testACancellationBeatsATimeout(): void {
		$result = new ProcessResult(143, '', '', true, true);

		self::assertSame(ImportError::CANCELLED, YtDlpFailure::classify($result, false));
	}

	// ------------------------------------------------------ the exit-0 filter

	/**
	 * Taken verbatim from a real run: a 19-second video against `--match-filter
	 * 'duration < 5'`. Note the exit code — this is the case that would be misread as
	 * success by anything that trusts it.
	 */
	public function testAFilteredVideoExitsZeroAndIsStillTooLong(): void {
		$stdout = "[youtube] Extracting URL: https://www.youtube.com/watch?v=jNQXAC9IVRw\n"
			. "[youtube] jNQXAC9IVRw: Downloading webpage\n"
			. "[download] Me at the zoo does not pass filter (duration < 5), skipping ..\n";

		$result = new ProcessResult(0, $stdout, self::JS_WARNING, false, false);

		self::assertSame(ImportError::TOO_LONG, YtDlpFailure::classify($result, producedFile: false));
	}

	// ----------------------------------------------------- stderr taxonomy

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function stderrProvider(): array {
		return [
			// Verbatim from a real run against a nonexistent id.
			'a video that does not exist' => [
				'ERROR: [youtube] aaaaaaaaaaa: Video unavailable',
				ImportError::VIDEO_UNAVAILABLE,
			],
			'a removed video' => [
				'ERROR: [youtube] abc: This video has been removed by the uploader',
				ImportError::VIDEO_UNAVAILABLE,
			],
			'a private video' => [
				'ERROR: [youtube] abc: Private video. Sign in if you\'ve been granted access to this video',
				ImportError::VIDEO_PRIVATE,
			],
			'an age-restricted video' => [
				'ERROR: [youtube] abc: Sign in to confirm your age. This video may be inappropriate for some users.',
				ImportError::AGE_RESTRICTED,
			],
			'a bot check' => [
				'ERROR: [youtube] abc: Sign in to confirm you\'re not a bot. Use --cookies-from-browser or --cookies.',
				ImportError::BOT_CHECK,
			],
			'a members-only video' => [
				'ERROR: [youtube] abc: Join this channel to get access to members-only content',
				ImportError::MEMBERS_ONLY,
			],
			'a geo-blocked video' => [
				'ERROR: [youtube] abc: The uploader has not made this video available in your country',
				ImportError::GEO_BLOCKED,
			],
			'an upcoming premiere' => [
				'ERROR: [youtube] abc: This live event will begin in 3 hours.',
				ImportError::LIVE_STREAM,
			],
			'a file over the size limit' => [
				'ERROR: File is larger than max-filesize (310000000 bytes > 300000000 bytes)',
				ImportError::TOO_LARGE,
			],
			'a broken extractor' => [
				'ERROR: [youtube] abc: nsig extraction failed: Some formats may be missing',
				ImportError::DOWNLOADER_OUTDATED,
			],
			'an extractor asking to be reported' => [
				'ERROR: [youtube] abc: Unable to extract player response; please report this issue on https://github.com/yt-dlp/yt-dlp/issues',
				ImportError::DOWNLOADER_OUTDATED,
			],
			'no network' => [
				'ERROR: unable to download video data: <urlopen error [Errno -3] Temporary failure in name resolution>',
				ImportError::NETWORK,
			],
			'a refused connection' => [
				'ERROR: Unable to download webpage: <urlopen error [Errno 111] Connection refused>',
				ImportError::NETWORK,
			],
			'something nobody anticipated' => [
				'ERROR: [youtube] abc: Something entirely new went wrong',
				ImportError::UNKNOWN,
			],
		];
	}

	#[DataProvider('stderrProvider')]
	public function testClassifiesRealStderr(string $stderr, string $expected): void {
		self::assertSame($expected, YtDlpFailure::classify(self::failed($stderr), false));
	}

	/**
	 * The same fixtures again, this time with the noise a real run actually carries. Every
	 * one must still land on the same code.
	 */
	#[DataProvider('stderrProvider')]
	public function testTheJavascriptWarningNeverChangesTheVerdict(string $stderr, string $expected): void {
		$noisy = self::JS_WARNING . "\n" . $stderr;

		self::assertSame($expected, YtDlpFailure::classify(self::failed($noisy), false));
	}

	public function testTheJavascriptWarningAloneIsNotAnError(): void {
		// Only a warning, a failing exit code, and no file: nothing here identifies a
		// cause, so it must not be dressed up as one.
		self::assertSame(
			ImportError::UNKNOWN,
			YtDlpFailure::classify(self::failed(self::JS_WARNING), false),
		);
	}

	public function testOutputFromSomethingOtherThanYtDlpIsStillRead(): void {
		// A crash has no ERROR: prefix, so the whole of stderr is the fallback.
		self::assertSame(
			ImportError::NETWORK,
			YtDlpFailure::classify(self::failed('curl: (6) Could not resolve host'), false),
		);
	}

	// ------------------------------------------------------------- the probe

	public function testProbeFailuresUseTheSameTaxonomy(): void {
		$result = self::failed('ERROR: [youtube] abc: Private video');

		self::assertSame(ImportError::VIDEO_PRIVATE, YtDlpFailure::classifyProbe($result));
	}

	public function testASuccessfulProbeHasNothingToExplain(): void {
		$result = new ProcessResult(0, '{"id":"abc"}', self::JS_WARNING, false, false);

		self::assertNull(YtDlpFailure::classifyProbe($result));
	}

	// ------------------------------------------------- what yt-dlp actually said

	/**
	 * The reason, without the extractor tag and the video id the row already holds.
	 */
	public function testTheDetailIsTheReasonWithoutItsPrefix(): void {
		$result = self::failed(self::JS_WARNING . "\nERROR: [youtube] dQw4w9WgXcQ: Requested format is not available");

		self::assertSame('Requested format is not available', YtDlpFailure::detail($result));
	}

	/** yt-dlp omits both when it fails before choosing an extractor. */
	public function testTheDetailCopesWithNoPrefixAtAll(): void {
		$result = self::failed('ERROR: Unable to rename file');

		self::assertSame('Unable to rename file', YtDlpFailure::detail($result));
	}

	/**
	 * A server's filesystem layout is not the requester's business, and postprocessing
	 * failures quote temporary directories freely.
	 */
	public function testPathsAreStrippedOutOfTheDetail(): void {
		$result = self::failed(
			'ERROR: Postprocessing: ffmpeg not found at /var/www/html/nextcloud/data/tmp/xyz/audio.mp3',
		);

		$detail = YtDlpFailure::detail($result);

		self::assertNotNull($detail);
		self::assertStringNotContainsString('/var/www', $detail);
		self::assertStringNotContainsString('/data/tmp', $detail);
		self::assertStringContainsString('ffmpeg not found', $detail);
	}

	/** The warning on every run is not an error, and must never become the detail. */
	public function testTheStandingWarningIsNeverTheDetail(): void {
		self::assertNull(YtDlpFailure::detail(self::failed(self::JS_WARNING)));
	}

	public function testTheDetailIsCappedSoARowCannotBecomeALogFile(): void {
		$result = self::failed('ERROR: ' . str_repeat('a very long complaint ', 60));

		$detail = YtDlpFailure::detail($result);

		self::assertNotNull($detail);
		self::assertLessThanOrEqual(240, mb_strlen($detail));
	}

	/**
	 * A link survives the path stripper.
	 *
	 * The two patterns overlap — `//www.youtube.com/watch` looks exactly like a path — so
	 * without holding URLs aside first, a message naming the video came back as
	 * "https:/…?v=abc". A URL is safe to show anyway: it is the one that was asked for.
	 */
	public function testAUrlIsNotMistakenForAPath(): void {
		$result = self::failed('ERROR: Unsupported URL: https://www.youtube.com/watch?v=dQw4w9WgXcQ');

		self::assertSame(
			'Unsupported URL: https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			YtDlpFailure::detail($result),
		);
	}

	/** And a path in the same line still goes, URL or no URL. */
	public function testAPathGoesEvenWhenALinkIsPresent(): void {
		$result = self::failed(
			'ERROR: could not write /var/www/html/nextcloud/data/tmp/x.mp3 for https://youtu.be/dQw4w9WgXcQ',
		);

		$detail = YtDlpFailure::detail($result);

		self::assertNotNull($detail);
		self::assertStringNotContainsString('/var/www', $detail);
		self::assertStringContainsString('https://youtu.be/dQw4w9WgXcQ', $detail);
	}

	/** Only the first: yt-dlp repeats itself, and the first line is the cause. */
	public function testOnlyTheFirstErrorLineIsKept(): void {
		$result = self::failed("ERROR: Video unavailable\nERROR: and then everything else broke");

		self::assertSame('Video unavailable', YtDlpFailure::detail($result));
	}
}
