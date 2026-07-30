<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Process\IProcessRunner;
use OCP\IAppConfig;
use OCP\IBinaryFinder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Finding yt-dlp and ffmpeg, and saying so when they are not there.
 *
 * The app does not ship yt-dlp, and cannot: `occ integrity:sign-app` hashes every file in
 * a release, so a downloader that updates itself would break the instance's integrity
 * check the first time it did its job. It is also about 38 MB per architecture, and it
 * goes stale within weeks — YouTube changes something and an old copy simply stops
 * working. A binary frozen at release time would be broken for most of its life.
 *
 * So it is located instead, in this order:
 *
 *   1. a path an administrator set explicitly — always wins, including for pointing at a
 *      test double;
 *   2. the copy `occ music_radio:ytdlp:install` manages, which exists only because
 *      somebody deliberately installed it and which the app can update in place;
 *   3. whatever is on the system, via IBinaryFinder.
 *
 * The managed copy comes before the system one on purpose. A distribution's yt-dlp package
 * is frequently a year old, which for this program means reliably broken; a copy the app
 * installed is one an administrator chose and can refresh in a single command.
 */
class YtDlpLocator {

	public const CONFIG_YTDLP_PATH = 'ytdlp_path';
	public const CONFIG_FFMPEG_PATH = 'ffmpeg_path';
	public const CONFIG_VERSION = 'ytdlp_version';
	public const CONFIG_CHECKED_AT = 'ytdlp_checked_at';
	public const CONFIG_ENABLED = 'import_enabled';

	/**
	 * How long a successful detection is trusted.
	 *
	 * This matters more than it looks. status() is read on every page load to decide
	 * whether to offer the button, and forking a process to ask a binary its version would
	 * make that unthinkable. Cached, the common path is two config reads.
	 */
	private const RECHECK_AFTER_SECONDS = 6 * 3600;

	/** A version this old means YouTube has almost certainly moved on. */
	private const STALE_AFTER_DAYS = 90;

	/** yt-dlp releases are dated, which is what makes staleness knowable at all. */
	private const VERSION_PATTERN = '/^(\d{4})\.(\d{2})\.(\d{2})/';

	private const VERSION_TIMEOUT_SECONDS = 15;

	public function __construct(
		private IAppConfig $appConfig,
		private IBinaryFinder $binaryFinder,
		private IConfig $config,
		private IProcessRunner $runner,
		private Clock $clock,
		private LoggerInterface $logger,
	) {
	}

	public function status(bool $recheck = false): ToolStatus {
		if (!$this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_ENABLED, true)) {
			return ToolStatus::unavailable(ImportError::DISABLED);
		}

		// Asked first because it makes everything else moot, and because the answer is a
		// php.ini lookup rather than a filesystem walk.
		if (!$this->runner->isAvailable()) {
			return ToolStatus::unavailable(ImportError::PROCESS_DISABLED);
		}

		$ffmpegDir = $this->ffmpegDirectory();
		if ($ffmpegDir === null) {
			return ToolStatus::unavailable(ImportError::FFMPEG_MISSING);
		}

		$ytDlp = $this->ytDlpPath();
		if ($ytDlp === null) {
			return ToolStatus::unavailable(ImportError::YTDLP_MISSING);
		}

		$version = $this->version($ytDlp, $recheck);
		if ($version === null) {
			// Present, executable, and unable to say what it is — a truncated download or
			// a missing interpreter. Reported as missing rather than as working.
			return ToolStatus::unavailable(ImportError::YTDLP_MISSING);
		}

		return new ToolStatus(
			available: true,
			reason: null,
			ytDlpPath: $ytDlp,
			ytDlpVersion: $version,
			ffmpegDir: $ffmpegDir,
			outdated: self::isOutdated($version, $this->clock->nowSeconds()),
		);
	}

	/**
	 * The directory holding ffmpeg, which is what `--ffmpeg-location` wants.
	 *
	 * ffprobe has to be there too: yt-dlp's audio postprocessor uses both, and finding only
	 * ffmpeg would turn a clear "ffmpeg is missing" into a confusing mid-download failure.
	 */
	public function ffmpegDirectory(): ?string {
		$configured = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_FFMPEG_PATH);
		$ffmpeg = $configured !== ''
			? $configured
			: ($this->binaryFinder->findBinaryPath('ffmpeg') ?: null);

		if (!is_string($ffmpeg) || !$this->isRunnable($ffmpeg)) {
			return null;
		}

		$directory = dirname($ffmpeg);

		return $this->isRunnable($directory . '/ffprobe') ? $directory : null;
	}

	public function ytDlpPath(): ?string {
		foreach ($this->candidates() as $candidate) {
			if ($candidate !== '' && $this->isRunnable($candidate)) {
				return $candidate;
			}
		}

		return null;
	}

	/** Where `occ music_radio:ytdlp:install` puts its copy. */
	public function managedPath(): string {
		// Under the data directory rather than in appdata: appdata may be object storage,
		// which cannot hold something executable.
		return rtrim($this->config->getSystemValueString('datadirectory', ''), '/')
			. '/' . Application::APP_ID . '/bin/yt-dlp';
	}

	/**
	 * @return list<string>
	 */
	private function candidates(): array {
		return [
			$this->appConfig->getValueString(Application::APP_ID, self::CONFIG_YTDLP_PATH),
			$this->managedPath(),
			(string)($this->binaryFinder->findBinaryPath('yt-dlp') ?: ''),
		];
	}

	/**
	 * Ask the binary what it is, remembering the answer.
	 */
	public function version(?string $path = null, bool $recheck = false): ?string {
		$path ??= $this->ytDlpPath();
		if ($path === null) {
			return null;
		}

		$cached = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_VERSION);
		$checkedAt = $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_CHECKED_AT);
		$fresh = $this->clock->nowSeconds() - $checkedAt < self::RECHECK_AFTER_SECONDS;

		if (!$recheck && $cached !== '' && $fresh) {
			return $cached;
		}

		$version = self::parseVersion($this->askVersion($path));

		if ($version !== null) {
			$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_VERSION, $version);
			$this->appConfig->setValueInt(Application::APP_ID, self::CONFIG_CHECKED_AT, $this->clock->nowSeconds());
		}

		// A failed check falls back to what was known before rather than declaring the
		// feature broken: a momentarily busy server should not hide the button.
		return $version ?? ($cached !== '' ? $cached : null);
	}

	private function askVersion(string $path): string {
		try {
			$result = $this->runner->run(
				[$path, '--version'],
				sys_get_temp_dir(),
				['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C'],
				self::VERSION_TIMEOUT_SECONDS,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not ask yt-dlp for its version', [
				'app' => Application::APP_ID,
				'path' => $path,
				'exception' => $e,
			]);

			return '';
		}

		return $result->succeeded() ? $result->stdout : '';
	}

	public static function parseVersion(string $output): ?string {
		$first = trim(strtok($output, "\n") ?: '');

		return preg_match(self::VERSION_PATTERN, $first) === 1 ? $first : null;
	}

	/**
	 * yt-dlp's version *is* its release date, so how old a copy is can be read straight off
	 * it — no network call, no release feed.
	 */
	public static function isOutdated(string $version, int $nowSeconds): bool {
		if (preg_match(self::VERSION_PATTERN, $version, $matches) !== 1) {
			return true;
		}

		[, $year, $month, $day] = array_map('intval', $matches);

		// mktime() does not reject an impossible date, it rolls it over — `2026.13.45`
		// becomes February 2027 and would read as newer than today. A version string that
		// is not a real date means something is wrong with the binary, so it is treated
		// the same as an unreadable one.
		if (!checkdate($month, $day, $year)) {
			return true;
		}

		$released = mktime(0, 0, 0, $month, $day, $year);
		if ($released === false) {
			return true;
		}

		return $nowSeconds - $released > self::STALE_AFTER_DAYS * 86400;
	}

	private function isRunnable(string $path): bool {
		return $path !== '' && str_starts_with($path, '/') && is_file($path) && is_executable($path);
	}
}
