<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\YoutubeUrl;
use OCA\MusicRadio\Service\YtDlpArgv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * These assertions exist so that a security property stays a property rather than becoming
 * an intention. Each one names a flag whose removal would be invisible in review and
 * consequential in production.
 */
class YtDlpArgvTest extends TestCase {

	private const YTDLP = '/usr/local/bin/yt-dlp';
	private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
	private const TMP = '/tmp/music-radio-import-1';
	private const FFMPEG_DIR = '/usr/bin';

	/**
	 * @return list<string>
	 */
	private static function download(): array {
		return YtDlpArgv::download(
			self::YTDLP,
			self::URL,
			self::TMP,
			self::FFMPEG_DIR,
			maxDurationSeconds: 5400,
			maxFilesizeBytes: 314572800,
		);
	}

	/**
	 * @return array<string, array{list<string>}>
	 */
	public static function bothPassesProvider(): array {
		return [
			'the probe pass' => [YtDlpArgv::probe(self::YTDLP, self::URL)],
			'the download pass' => [self::download()],
		];
	}

	// ------------------------------------------------------- structural rules

	#[DataProvider('bothPassesProvider')]
	public function testTheBinaryIsFirst(array $argv): void {
		self::assertSame(self::YTDLP, $argv[0]);
	}

	/**
	 * The URL must be last and must be preceded by `--`. Anything after `--` is a
	 * positional argument no matter what it looks like, which is the last line of defence
	 * if the canonicaliser is ever weakened.
	 */
	#[DataProvider('bothPassesProvider')]
	public function testTheUrlIsLastAndPrecededByTheOptionTerminator(array $argv): void {
		self::assertSame(self::URL, array_pop($argv));
		self::assertSame('--', array_pop($argv));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function requiredFlagProvider(): array {
		return [
			'config files must not be read' => ['--ignore-config'],
			'config locations must not be searched' => ['--no-config-locations'],
			'commands must never be run' => ['--no-exec'],
			'nothing may be cached outside the temp dir' => ['--no-cache-dir'],
			'a playlist must not be expanded' => ['--no-playlist'],
			'a partial file must not be resumed' => ['--no-continue'],
			'nothing may be overwritten' => ['--no-overwrites'],
		];
	}

	#[DataProvider('requiredFlagProvider')]
	public function testEveryPassCarriesTheSafetyFlag(string $flag): void {
		self::assertContains($flag, YtDlpArgv::probe(self::YTDLP, self::URL), $flag . ' is missing from the probe pass');
		self::assertContains($flag, self::download(), $flag . ' is missing from the download pass');
	}

	public function testGeoRestrictionsAreNotBypassed(): void {
		self::assertNotContains('--geo-bypass', self::download());
		self::assertNotContains('--geo-bypass-country', self::download());
	}

	// ------------------------------------------------------- the requested output

	public function testTheOutputIsMp3At128Kbit(): void {
		$argv = self::download();

		self::assertContains('--extract-audio', $argv);
		self::assertSame('mp3', $argv[array_search('--audio-format', $argv, true) + 1]);
		self::assertSame('128K', $argv[array_search('--audio-quality', $argv, true) + 1]);
	}

	public function testMetadataIsEmbeddedSoTagsCanBeReadBack(): void {
		// Not cosmetic: without this, AudioProbe has no tags to read and every imported
		// track is titled after its filename.
		self::assertContains('--embed-metadata', self::download());
	}

	public function testTheOutputTemplateIsAFixedStem(): void {
		$argv = self::download();
		$template = $argv[array_search('-o', $argv, true) + 1];

		self::assertSame('audio.%(ext)s', $template);
		// The only thing yt-dlp gets to decide is the extension. A title in here would be
		// a video title choosing a path.
		self::assertStringNotContainsString('%(title)', $template);
		self::assertStringNotContainsString('/', $template);
	}

	public function testEverythingIsWrittenInsideTheImportDirectory(): void {
		$argv = self::download();

		self::assertContains('home:' . self::TMP, $argv);
		self::assertContains('temp:' . self::TMP, $argv);
	}

	public function testFfmpegIsNamedExplicitly(): void {
		$argv = self::download();

		self::assertSame(self::FFMPEG_DIR, $argv[array_search('--ffmpeg-location', $argv, true) + 1]);
	}

	// ------------------------------------------------------------------ limits

	public function testTheDurationLimitIsApplied(): void {
		$argv = self::download();

		self::assertSame('duration < 5400', $argv[array_search('--match-filter', $argv, true) + 1]);
	}

	public function testTheSizeLimitIsApplied(): void {
		$argv = self::download();

		self::assertSame('314572800', $argv[array_search('--max-filesize', $argv, true) + 1]);
	}

	// -------------------------------------------------------------- the probe

	public function testTheProbeDownloadsNothing(): void {
		$argv = YtDlpArgv::probe(self::YTDLP, self::URL);

		self::assertContains('--simulate', $argv);
		self::assertContains('--skip-download', $argv);
		self::assertContains('--dump-single-json', $argv);
		self::assertNotContains('--extract-audio', $argv);
	}

	// ------------------------------------------------------------------ proxy

	public function testTheProxyIsPassedWhenConfigured(): void {
		$argv = YtDlpArgv::probe(self::YTDLP, self::URL, 'http://proxy.example:3128');

		self::assertSame('http://proxy.example:3128', $argv[array_search('--proxy', $argv, true) + 1]);
	}

	public function testNoProxyFlagWhenNoneIsConfigured(): void {
		self::assertNotContains('--proxy', YtDlpArgv::probe(self::YTDLP, self::URL, null));
		self::assertNotContains('--proxy', YtDlpArgv::probe(self::YTDLP, self::URL, ''));
	}

	// ------------------------------------------------------- JavaScript runtime

	/**
	 * Both passes, because the metadata pass needs a runtime for some videos too — and
	 * because a probe that quietly used a different set of clients than the download would
	 * make the pre-flight checks describe a video the download never sees.
	 */
	public function testTheJavascriptRuntimeIsNamedOnBothPasses(): void {
		$probe = YtDlpArgv::probe(self::YTDLP, self::URL, null, 'node:/usr/local/bin/node');
		$download = YtDlpArgv::download(
			self::YTDLP,
			self::URL,
			self::TMP,
			self::FFMPEG_DIR,
			maxDurationSeconds: 5400,
			maxFilesizeBytes: 314572800,
			proxy: null,
			jsRuntime: 'node:/usr/local/bin/node',
		);

		self::assertSame('node:/usr/local/bin/node', $probe[array_search('--js-runtimes', $probe, true) + 1]);
		self::assertSame('node:/usr/local/bin/node', $download[array_search('--js-runtimes', $download, true) + 1]);
	}

	/**
	 * Naming a runtime that is not there is an option error, and yt-dlp exits on it before
	 * doing anything — which would turn "the download will probably fail" into "the probe
	 * fails too, and says something about command-line syntax".
	 */
	public function testNoRuntimeFlagWhenTheServerHasNone(): void {
		self::assertNotContains('--js-runtimes', YtDlpArgv::probe(self::YTDLP, self::URL, null, null));
		self::assertNotContains('--js-runtimes', YtDlpArgv::probe(self::YTDLP, self::URL, null, ''));
		self::assertNotContains('--js-runtimes', self::download());
	}

	// ----------------------------------------------------------------- cookies

	public function testTheCookieFileIsNamedOnBothPasses(): void {
		$jar = self::TMP . '/cookies.txt';
		$probe = YtDlpArgv::probe(self::YTDLP, self::URL, null, null, $jar);
		$download = YtDlpArgv::download(
			self::YTDLP,
			self::URL,
			self::TMP,
			self::FFMPEG_DIR,
			maxDurationSeconds: 5400,
			maxFilesizeBytes: 314572800,
			proxy: null,
			jsRuntime: null,
			cookieFile: $jar,
		);

		self::assertSame($jar, $probe[array_search('--cookies', $probe, true) + 1]);
		self::assertSame($jar, $download[array_search('--cookies', $download, true) + 1]);
	}

	public function testNoCookieFlagWhenTheOwnerStoredNone(): void {
		self::assertNotContains('--cookies', YtDlpArgv::probe(self::YTDLP, self::URL));
		self::assertNotContains('--cookies', self::download());
	}

	/**
	 * Reads a browser profile off local disk, chosen by a config value. There is no browser
	 * on a server, and no reason to let a setting point a file read at one.
	 */
	public function testTheBrowserProfileReaderIsNeverUsed(): void {
		self::assertNotContains('--cookies-from-browser', YtDlpArgv::probe(self::YTDLP, self::URL));
		self::assertNotContains('--cookies-from-browser', self::download());
	}

	// --------------------------------------------------------------- progress

	/**
	 * @return array<string, array{string, float|null}>
	 */
	public static function progressProvider(): array {
		return [
			// The shape a real run produced: no estimate, but a known total.
			'no estimate, real total' => ['mrprogress:252182 NA 252182', 1.0],
			'a fragmented stream, estimate only' => ['mrprogress:500 1000 NA', 0.5],
			'both known, estimate wins' => ['mrprogress:250 1000 2000', 0.25],
			'nothing downloaded yet' => ['mrprogress:0 1000 NA', 0.0],
			'past the estimate is still capped' => ['mrprogress:1500 1000 NA', 1.0],
			'fractional byte counts' => ['mrprogress:512.0 1024.0 NA', 0.5],

			'neither size known' => ['mrprogress:500 NA NA', null],
			'a zero total is not a total' => ['mrprogress:500 0 0', null],
			'too few fields' => ['mrprogress:500 NA', null],
			'not a progress line' => ['[youtube] Extracting URL: https://…', null],
			'the prefix alone' => ['mrprogress:', null],
			'empty' => ['', null],
			'a line that merely mentions the prefix' => ['note: mrprogress:1 2 3', null],
		];
	}

	#[DataProvider('progressProvider')]
	public function testParseProgress(string $line, ?float $expected): void {
		$actual = YtDlpArgv::parseProgress($line);

		if ($expected === null) {
			self::assertNull($actual);
		} else {
			self::assertNotNull($actual);
			self::assertSame($expected, round($actual, 6));
		}
	}

	public function testTheProgressTemplateAsksForBothSizeFields(): void {
		$argv = self::download();
		$template = $argv[array_search('--progress-template', $argv, true) + 1];

		// parseProgress() depends on both being present, because either can be NA.
		self::assertStringContainsString('total_bytes_estimate', $template);
		self::assertStringContainsString('total_bytes)', $template);
		self::assertStringContainsString('downloaded_bytes', $template);
		self::assertStringStartsWith(YtDlpArgv::PROGRESS_PREFIX . ':', $template);
	}

	public function testProgressNeedsNewlineModeToBeReadableAtAll(): void {
		// Without --newline yt-dlp rewrites a single line with \r and the runner never sees
		// a complete line to hand over.
		self::assertContains('--newline', self::download());
	}

	// ------------------------------------------------- the end-to-end property

	/**
	 * The property that ties this class to YoutubeUrl: whatever someone pasted, no element
	 * of the command line carries any of it. The only route from input to argv is an
	 * eleven-character id inside a rebuilt URL.
	 */
	public function testNothingFromAHostileInputReachesTheCommandLine(): void {
		$hostile = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&evil=--exec%3Drm%20-rf%20/&list=PLnope';

		$id = YoutubeUrl::videoId($hostile);
		self::assertNotNull($id);

		$argv = YtDlpArgv::download(
			self::YTDLP,
			YoutubeUrl::canonical($id),
			self::TMP,
			self::FFMPEG_DIR,
			maxDurationSeconds: 5400,
			maxFilesizeBytes: 314572800,
		);

		foreach ($argv as $element) {
			self::assertStringNotContainsString('evil', $element);
			self::assertStringNotContainsString('rm -rf', $element);
			self::assertStringNotContainsString('list=', $element);
		}

		self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $argv[array_key_last($argv)]);
	}
}
