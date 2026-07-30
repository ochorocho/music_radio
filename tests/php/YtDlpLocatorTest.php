<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\YtDlpLocator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The path-probing half of the locator needs a filesystem and is covered end to end; these
 * are the two pure decisions it makes, which are worth pinning because both are easy to get
 * subtly wrong and neither fails loudly.
 */
class YtDlpLocatorTest extends TestCase {

	/** 2026-07-30, the date the surrounding work was done. */
	private const NOW = 1_785_369_600;

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function versionProvider(): array {
		return [
			'what the real binary prints' => ["2026.07.04\n", '2026.07.04'],
			'no trailing newline' => ['2026.07.04', '2026.07.04'],
			'a nightly build' => ["2026.07.04.232045\n", '2026.07.04.232045'],
			'surrounded by whitespace' => ["  2026.07.04  \n", '2026.07.04'],
			'only the first line counts' => ["2026.07.04\nsome trailing noise\n", '2026.07.04'],

			'an error instead of a version' => ["/bin/sh: yt-dlp: not found\n", null],
			'a python traceback' => ["Traceback (most recent call last):\n  File \"…\"\n", null],
			'a version that is not a date' => ["1.2.3\n", null],
			'empty' => ['', null],
			'whitespace only' => ["  \n", null],
			// A truncated download that happens to start with digits must not pass.
			'a partial date' => ["2026.07\n", null],
		];
	}

	#[DataProvider('versionProvider')]
	public function testParseVersion(string $output, ?string $expected): void {
		self::assertSame($expected, YtDlpLocator::parseVersion($output));
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function outdatedProvider(): array {
		return [
			'released today' => ['2026.07.30', false],
			'a few weeks old' => ['2026.07.04', false],
			'just inside the window' => ['2026.05.02', false],
			'just outside the window' => ['2026.04.01', true],
			'a year old, which for yt-dlp is ancient' => ['2025.07.30', true],

			// Anything unreadable is treated as stale: erring towards "tell the admin to
			// update" is harmless, while erring the other way hides the actual cause of
			// every failed import.
			'unparseable' => ['not-a-version', true],
			'empty' => ['', true],
			'a plausible but impossible date' => ['2026.13.45', true],
		];
	}

	#[DataProvider('outdatedProvider')]
	public function testIsOutdated(string $version, bool $expected): void {
		self::assertSame($expected, YtDlpLocator::isOutdated($version, self::NOW));
	}

	public function testAFutureVersionIsNotOutdated(): void {
		// Clock skew, or a nightly newer than this server's idea of today.
		self::assertFalse(YtDlpLocator::isOutdated('2026.12.31', self::NOW));
	}
}
