<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Http\ByteRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Range parsing, exhaustively. Getting this wrong does not fail loudly — it shows up as
 * "audio will not play in Safari" or "seeking jumps to the wrong place", so the edge
 * cases are worth pinning down individually.
 */
class ByteRangeTest extends TestCase {

	private const SIZE = 1000;

	/**
	 * @return array<string, array{string|null, int, string, int, int}>
	 */
	public static function parseProvider(): array {
		return [
			// No range at all: whole file, 200.
			'no header' => [null, self::SIZE, ByteRange::FULL, 0, 999],
			'empty header' => ['', self::SIZE, ByteRange::FULL, 0, 999],
			'whitespace only' => ['   ', self::SIZE, ByteRange::FULL, 0, 999],

			// A unit we do not understand must be ignored, not rejected (RFC 9110).
			'unknown unit' => ['items=0-10', self::SIZE, ByteRange::FULL, 0, 999],

			// Ordinary spans.
			'explicit span' => ['bytes=0-499', self::SIZE, ByteRange::PARTIAL, 0, 499],
			'mid-file span' => ['bytes=200-399', self::SIZE, ByteRange::PARTIAL, 200, 399],
			'open ended' => ['bytes=500-', self::SIZE, ByteRange::PARTIAL, 500, 999],
			'from zero open ended' => ['bytes=0-', self::SIZE, ByteRange::PARTIAL, 0, 999],
			'entire file explicitly' => ['bytes=0-999', self::SIZE, ByteRange::PARTIAL, 0, 999],

			// Safari opens every media element with this probe. If it is not answered
			// correctly the element never starts playing.
			'safari two byte probe' => ['bytes=0-1', self::SIZE, ByteRange::PARTIAL, 0, 1],
			'single byte' => ['bytes=0-0', self::SIZE, ByteRange::PARTIAL, 0, 0],
			'last byte' => ['bytes=999-999', self::SIZE, ByteRange::PARTIAL, 999, 999],

			// Suffix form: the LAST n bytes, not "up to byte n".
			'suffix' => ['bytes=-500', self::SIZE, ByteRange::PARTIAL, 500, 999],
			'suffix of one' => ['bytes=-1', self::SIZE, ByteRange::PARTIAL, 999, 999],
			'suffix longer than file' => ['bytes=-5000', self::SIZE, ByteRange::PARTIAL, 0, 999],
			'suffix exactly the file' => ['bytes=-1000', self::SIZE, ByteRange::PARTIAL, 0, 999],

			// An end past the file is clamped, not refused — every real server does this.
			'end past eof' => ['bytes=900-99999', self::SIZE, ByteRange::PARTIAL, 900, 999],

			// Only the first range is served; that is a legal partial response.
			'multiple ranges' => ['bytes=0-99,200-299', self::SIZE, ByteRange::PARTIAL, 0, 99],

			// Tolerated whitespace.
			'spaces around' => ['  bytes = 10-20 ', self::SIZE, ByteRange::PARTIAL, 10, 20],
			'uppercase unit' => ['BYTES=10-20', self::SIZE, ByteRange::PARTIAL, 10, 20],

			// Genuinely unsatisfiable.
			'start past eof' => ['bytes=1000-', self::SIZE, ByteRange::UNSATISFIABLE, 0, 0],
			'start well past eof' => ['bytes=99999-', self::SIZE, ByteRange::UNSATISFIABLE, 0, 0],
			'reversed' => ['bytes=500-100', self::SIZE, ByteRange::UNSATISFIABLE, 0, 0],
			'zero length suffix' => ['bytes=-0', self::SIZE, ByteRange::UNSATISFIABLE, 0, 0],
			'no numbers' => ['bytes=-', self::SIZE, ByteRange::UNSATISFIABLE, 0, 0],
			'garbage' => ['bytes=abc', self::SIZE, ByteRange::UNSATISFIABLE, 0, 0],
			'partial garbage' => ['bytes=10-xyz', self::SIZE, ByteRange::UNSATISFIABLE, 0, 0],

			// Nothing can be served out of an empty file.
			'empty file, open range' => ['bytes=0-', 0, ByteRange::UNSATISFIABLE, 0, 0],
			'empty file, suffix' => ['bytes=-10', 0, ByteRange::UNSATISFIABLE, 0, 0],
			'empty file, no header' => [null, 0, ByteRange::FULL, 0, 0],
		];
	}

	#[DataProvider('parseProvider')]
	public function testParse(?string $header, int $size, string $kind, int $start, int $end): void {
		$range = ByteRange::parse($header, $size);

		self::assertSame($kind, $range->kind, 'kind');
		if ($kind !== ByteRange::UNSATISFIABLE) {
			self::assertSame($start, $range->start, 'start');
			self::assertSame($end, $range->end, 'end');
		}
	}

	/**
	 * @return array<string, array{string|null, int, int}>
	 */
	public static function lengthProvider(): array {
		return [
			'whole file' => [null, self::SIZE, 1000],
			'first 500' => ['bytes=0-499', self::SIZE, 500],
			'single byte' => ['bytes=0-0', self::SIZE, 1],
			'safari probe' => ['bytes=0-1', self::SIZE, 2],
			'open ended' => ['bytes=500-', self::SIZE, 500],
			'suffix' => ['bytes=-250', self::SIZE, 250],
			'unsatisfiable' => ['bytes=5000-', self::SIZE, 0],
		];
	}

	#[DataProvider('lengthProvider')]
	public function testLength(?string $header, int $size, int $expected): void {
		self::assertSame($expected, ByteRange::parse($header, $size)->length());
	}

	public function testContentRangeForAPartialResponse(): void {
		$range = ByteRange::parse('bytes=200-399', self::SIZE);

		self::assertSame('bytes 200-399/1000', $range->contentRange(self::SIZE));
	}

	/**
	 * A 416 must tell the client the real length so it can retry sensibly.
	 */
	public function testContentRangeForAnUnsatisfiableRequest(): void {
		$range = ByteRange::parse('bytes=5000-', self::SIZE);

		self::assertSame('bytes */1000', $range->contentRange(self::SIZE));
	}

	/**
	 * Ranges are inclusive at both ends, so a span covering the whole file is
	 * `0-(size-1)` and its length is exactly the file size. Off-by-one here truncates
	 * every track by a byte, which browsers report as a corrupt stream.
	 */
	public function testFullFileRangeCoversEveryByte(): void {
		$range = ByteRange::parse('bytes=0-', self::SIZE);

		self::assertSame(0, $range->start);
		self::assertSame(self::SIZE - 1, $range->end);
		self::assertSame(self::SIZE, $range->length());
	}
}
