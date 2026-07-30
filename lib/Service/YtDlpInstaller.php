<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Process\IProcessRunner;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Fetching a copy of yt-dlp for this server.
 *
 * Run from `occ music_radio:ytdlp:install`, never automatically. Downloading and marking
 * something executable is not a thing an app should do on its own initiative, and an
 * administrator typing the command is the consent.
 *
 * On the choice of asset: the obvious `yt-dlp_linux` is a ~38 MB PyInstaller bundle, and
 * there is one per architecture and libc — picking the wrong one produces a file that
 * exists, is executable, and cannot run. The plain `yt-dlp` asset is a ~3 MB zipimport
 * archive with a `#!/usr/bin/env python3` shebang: the same program, any CPU, any libc, a
 * thirteenth of the download. It is preferred whenever a usable python3 is present, which
 * on a machine already running Nextcloud it usually is.
 */
class YtDlpInstaller {

	private const RELEASE_BASE = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/';
	private const CHECKSUMS = self::RELEASE_BASE . 'SHA2-256SUMS';

	/** The zipimport archive needs at least this to run. */
	private const MIN_PYTHON = '3.9';

	private const DOWNLOAD_TIMEOUT_SECONDS = 300;

	public function __construct(
		private IClientService $clientService,
		private IProcessRunner $runner,
		private YtDlpLocator $locator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{path: string, version: string, asset: string}
	 * @throws MusicRadioException with a message meant for whoever ran the command
	 */
	public function install(bool $force = false): array {
		$target = $this->locator->managedPath();

		if (!$force && is_file($target)) {
			throw new MusicRadioException(
				'yt-dlp is already installed at ' . $target . '. Use --force to replace it.',
			);
		}

		$asset = self::assetFor(PHP_OS_FAMILY, php_uname('m'), $this->isMusl(), $this->pythonVersion());
		if ($asset === null) {
			throw new MusicRadioException(
				'No yt-dlp build is published for ' . PHP_OS_FAMILY . '/' . php_uname('m')
				. '. Install yt-dlp yourself and set its path with:'
				. ' occ config:app:set ' . Application::APP_ID . ' ' . YtDlpLocator::CONFIG_YTDLP_PATH . ' --value=/path/to/yt-dlp',
			);
		}

		$directory = dirname($target);
		if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
			throw new MusicRadioException('Could not create ' . $directory);
		}

		$binary = $this->download(self::RELEASE_BASE . $asset);
		$this->verify($binary, $asset);

		// Written under a temporary name and moved into place, so a download that dies
		// half way through cannot leave a truncated binary where a working one was.
		$staging = $target . '.new';
		if (@file_put_contents($staging, $binary) === false) {
			throw new MusicRadioException('Could not write to ' . $staging);
		}

		// Readable and executable by this user only. Nothing else has any business running
		// it, and it lives inside the data directory.
		@chmod($staging, 0700);

		$version = $this->confirmItRuns($staging, $asset);

		if (!@rename($staging, $target)) {
			@unlink($staging);
			throw new MusicRadioException('Could not move the downloaded yt-dlp into ' . $target);
		}

		$this->logger->info('Installed yt-dlp', [
			'app' => Application::APP_ID,
			'asset' => $asset,
			'version' => $version,
			'path' => $target,
		]);

		// Re-read rather than trusting what was just written, and refresh the cached
		// version so the feature becomes available without waiting out the check interval.
		$this->locator->version($target, recheck: true);

		return ['path' => $target, 'version' => $version, 'asset' => $asset];
	}

	/**
	 * Which release asset suits this machine.
	 *
	 * Pure and static so the whole matrix is testable without a network or a filesystem.
	 *
	 * @param string $osFamily PHP_OS_FAMILY
	 * @param string $machine php_uname('m')
	 * @param bool $musl whether the C library is musl rather than glibc
	 * @param string|null $pythonVersion the python3 on this machine, or null if there is none
	 * @return string|null null when yt-dlp publishes nothing that would run here
	 */
	public static function assetFor(string $osFamily, string $machine, bool $musl, ?string $pythonVersion): ?string {
		// One file, every architecture, every libc — as long as something can run it.
		if ($pythonVersion !== null && version_compare($pythonVersion, self::MIN_PYTHON, '>=')) {
			return 'yt-dlp';
		}

		if ($osFamily === 'Darwin') {
			return 'yt-dlp_macos';
		}

		if ($osFamily !== 'Linux') {
			// Windows builds exist, but nothing else in this app has ever been run there
			// and claiming support without having tried it would be a lie.
			return null;
		}

		return match (strtolower($machine)) {
			'x86_64', 'amd64' => $musl ? 'yt-dlp_musllinux' : 'yt-dlp_linux',
			'aarch64', 'arm64' => $musl ? 'yt-dlp_musllinux_aarch64' : 'yt-dlp_linux_aarch64',
			// Published only as a zip, which would need unpacking; python is the better
			// answer on a 32-bit ARM box anyway.
			default => null,
		};
	}

	private function download(string $url): string {
		try {
			$response = $this->clientService->newClient()->get($url, [
				'timeout' => self::DOWNLOAD_TIMEOUT_SECONDS,
			]);
		} catch (\Throwable $e) {
			throw new MusicRadioException('Could not download ' . $url . ': ' . $e->getMessage());
		}

		$body = $response->getBody();
		if (!is_string($body) || $body === '') {
			throw new MusicRadioException('Downloaded nothing from ' . $url);
		}

		return $body;
	}

	/**
	 * Check the download against the checksums published beside it.
	 *
	 * Worth being precise about what this does and does not buy. The checksum file comes
	 * from the same host over the same TLS connection as the asset, so it is not an
	 * independent signature — GitHub and TLS remain the trust anchor either way. What it
	 * does catch is a truncated or corrupted transfer and a mismatch between the file asked
	 * for and the file received, both of which would otherwise produce a binary that fails
	 * confusingly much later. Real signature verification would need the maintainers' GPG
	 * key and ext-gnupg.
	 *
	 * @throws MusicRadioException
	 */
	private function verify(string $binary, string $asset): void {
		$sums = $this->download(self::CHECKSUMS);
		$expected = null;

		foreach (preg_split('/\R/', $sums) ?: [] as $line) {
			$fields = preg_split('/\s+/', trim($line)) ?: [];
			if (count($fields) >= 2 && basename($fields[1]) === $asset) {
				$expected = strtolower($fields[0]);
				break;
			}
		}

		if ($expected === null) {
			throw new MusicRadioException('No published checksum for ' . $asset);
		}

		$actual = hash('sha256', $binary);
		if (!hash_equals($expected, $actual)) {
			throw new MusicRadioException(
				'The downloaded ' . $asset . ' does not match its published checksum, so it was discarded.',
			);
		}
	}

	/**
	 * Run the thing before trusting it.
	 *
	 * A file of the right size with the right checksum can still be unusable — the python
	 * archive needs an interpreter, and a PyInstaller build needs a matching libc. Finding
	 * that out now, with the old copy still in place, is much better than finding out
	 * during somebody's first import.
	 *
	 * @throws MusicRadioException
	 */
	private function confirmItRuns(string $path, string $asset): string {
		try {
			$result = $this->runner->run(
				[$path, '--version'],
				sys_get_temp_dir(),
				['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C'],
				60,
			);
		} catch (\Throwable $e) {
			@unlink($path);
			throw new MusicRadioException('The downloaded ' . $asset . ' could not be run: ' . $e->getMessage());
		}

		$version = YtDlpLocator::parseVersion($result->stdout);
		if ($version === null) {
			@unlink($path);
			throw new MusicRadioException(
				'The downloaded ' . $asset . ' did not report a version, so it was discarded. '
				. trim(substr($result->stderr, -300)),
			);
		}

		return $version;
	}

	/** @return string|null the python3 version, or null when there is no usable python3 */
	private function pythonVersion(): ?string {
		try {
			$result = $this->runner->run(
				['/usr/bin/env', 'python3', '--version'],
				sys_get_temp_dir(),
				['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C'],
				15,
			);
		} catch (\Throwable) {
			return null;
		}

		if (!$result->succeeded()) {
			return null;
		}

		// "Python 3.13.5"
		return preg_match('/(\d+\.\d+(\.\d+)?)/', $result->stdout . $result->stderr, $m) === 1 ? $m[1] : null;
	}

	private function isMusl(): bool {
		// Alpine and other musl systems have no /lib/ld-linux*; the loader is ld-musl-*.
		return glob('/lib/ld-musl-*') !== [] && glob('/lib/ld-musl-*') !== false;
	}
}
