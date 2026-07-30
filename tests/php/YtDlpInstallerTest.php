<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\YtDlpInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Picking the wrong asset produces a file that downloads cleanly, passes its checksum, is
 * marked executable, and cannot run — so the matrix is worth spelling out.
 */
class YtDlpInstallerTest extends TestCase {

	/**
	 * @return array<string, array{string, string, bool, string|null, string|null}>
	 */
	public static function assetProvider(): array {
		return [
			// python3 present: one answer everywhere, whatever the CPU or libc.
			'python on arm64 glibc — this project\'s container' => ['Linux', 'aarch64', false, '3.13.5', 'yt-dlp'],
			'python on x86_64 glibc' => ['Linux', 'x86_64', false, '3.11.2', 'yt-dlp'],
			'python on musl' => ['Linux', 'x86_64', true, '3.12.0', 'yt-dlp'],
			'python on macOS' => ['Darwin', 'arm64', false, '3.12.0', 'yt-dlp'],
			'exactly the minimum python' => ['Linux', 'x86_64', false, '3.9', 'yt-dlp'],
			'python without a patch level' => ['Linux', 'aarch64', false, '3.13', 'yt-dlp'],

			// python too old to run the archive: fall back to a native build.
			'python 3.8 is too old' => ['Linux', 'x86_64', false, '3.8.10', 'yt-dlp_linux'],
			'python 2 is too old' => ['Linux', 'x86_64', false, '2.7.18', 'yt-dlp_linux'],

			// No python at all — the matrix that makes hard-coding one asset a bug.
			'no python, x86_64 glibc' => ['Linux', 'x86_64', false, null, 'yt-dlp_linux'],
			'no python, amd64 spelling' => ['Linux', 'amd64', false, null, 'yt-dlp_linux'],
			'no python, arm64 glibc' => ['Linux', 'aarch64', false, null, 'yt-dlp_linux_aarch64'],
			'no python, arm64 spelling' => ['Linux', 'arm64', false, null, 'yt-dlp_linux_aarch64'],
			'no python, x86_64 musl' => ['Linux', 'x86_64', true, null, 'yt-dlp_musllinux'],
			'no python, arm64 musl' => ['Linux', 'aarch64', true, null, 'yt-dlp_musllinux_aarch64'],
			'no python, macOS' => ['Darwin', 'arm64', false, null, 'yt-dlp_macos'],
			'architecture is matched case-insensitively' => ['Linux', 'X86_64', false, null, 'yt-dlp_linux'],

			// Nothing publishable.
			'no python, 32-bit ARM' => ['Linux', 'armv7l', false, null, null],
			'no python, 32-bit x86' => ['Linux', 'i686', false, null, null],
			'no python, an architecture nobody ships' => ['Linux', 'riscv64', false, null, null],
			'Windows' => ['Windows', 'x86_64', false, null, null],
			'something else entirely' => ['BSD', 'x86_64', false, null, null],
		];
	}

	#[DataProvider('assetProvider')]
	public function testAssetFor(string $os, string $machine, bool $musl, ?string $python, ?string $expected): void {
		self::assertSame($expected, YtDlpInstaller::assetFor($os, $machine, $musl, $python));
	}

	/**
	 * The specific mistake this whole method exists to prevent: on Apple Silicon, the
	 * asset whose name reads like the obvious choice is the one that cannot run.
	 */
	public function testTheObviousLinuxAssetIsNeverChosenForArm(): void {
		self::assertNotSame('yt-dlp_linux', YtDlpInstaller::assetFor('Linux', 'aarch64', false, null));
		self::assertNotSame('yt-dlp_linux', YtDlpInstaller::assetFor('Linux', 'aarch64', true, null));
	}

	public function testAGlibcBuildIsNeverChosenForMusl(): void {
		foreach (['x86_64', 'aarch64'] as $machine) {
			$asset = YtDlpInstaller::assetFor('Linux', $machine, true, null);
			self::assertIsString($asset);
			self::assertStringContainsString('musl', $asset);
		}
	}
}
