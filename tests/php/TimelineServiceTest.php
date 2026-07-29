<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Service\TimelineService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure timeline arithmetic: turning a programme position into "which track, how far
 * in". Everything the listeners hear is derived from this, so the boundary cases matter
 * more than the happy path.
 */
class TimelineServiceTest extends TestCase {

	private static function track(int $id, ?int $durationMs, bool $unavailable = false): Track {
		$track = new Track();
		$track->setId($id);
		$track->setDurationMs($durationMs);
		$track->setUnavailable($unavailable);

		return $track;
	}

	private static function channel(
		bool $paused,
		int $epochOffsetMs,
		int $startedAtMs,
		bool $loop = true,
	): Channel {
		$channel = new Channel();
		$channel->setId(1);
		$channel->setPaused($paused);
		$channel->setEpochOffsetMs($epochOffsetMs);
		$channel->setStartedAtMs($startedAtMs);
		$channel->setLoopEnabled($loop);
		$channel->setShuffle(false);

		return $channel;
	}

	// ------------------------------------------------------------------- playable()

	public function testPlayableExcludesTracksWithNoKnownDuration(): void {
		$tracks = [
			self::track(1, 1000),
			self::track(2, null),
			self::track(3, 2000),
		];

		$playable = TimelineService::playable($tracks);

		self::assertSame([1, 3], array_map(static fn (Track $t): int => $t->getId(), $playable));
	}

	public function testPlayableExcludesUnavailableTracks(): void {
		$tracks = [
			self::track(1, 1000),
			self::track(2, 5000, unavailable: true),
		];

		self::assertCount(1, TimelineService::playable($tracks));
	}

	public function testPlayableExcludesZeroLengthTracks(): void {
		// A zero-length track would occupy no span on the timeline, so locate() could
		// never resolve to it — it must not be in the list at all.
		self::assertSame([], TimelineService::playable([self::track(1, 0)]));
	}

	// ------------------------------------------------------------------- locate()

	/**
	 * @return array<string, array{int[], int, array{index: int, offsetMs: int}|null}>
	 */
	public static function locateProvider(): array {
		$durations = [10_000, 20_000, 5_000];

		return [
			'very start' => [$durations, 0, ['index' => 0, 'offsetMs' => 0]],
			'inside first' => [$durations, 4_321, ['index' => 0, 'offsetMs' => 4_321]],
			'last ms of first' => [$durations, 9_999, ['index' => 0, 'offsetMs' => 9_999]],
			'exact first boundary' => [$durations, 10_000, ['index' => 1, 'offsetMs' => 0]],
			'inside second' => [$durations, 25_000, ['index' => 1, 'offsetMs' => 15_000]],
			'exact second boundary' => [$durations, 30_000, ['index' => 2, 'offsetMs' => 0]],
			'last ms of programme' => [$durations, 34_999, ['index' => 2, 'offsetMs' => 4_999]],
			'exactly at the end' => [$durations, 35_000, null],
			'past the end' => [$durations, 99_999, null],
			'negative position' => [$durations, -1, null],
			'empty programme' => [[], 0, null],
		];
	}

	#[DataProvider('locateProvider')]
	public function testLocate(array $durations, int $position, ?array $expected): void {
		self::assertSame($expected, TimelineService::locate($durations, $position));
	}

	public function testPrefixAtIsTheStartOfEachTrack(): void {
		$durations = [10_000, 20_000, 5_000];

		self::assertSame(0, TimelineService::prefixAt($durations, 0));
		self::assertSame(10_000, TimelineService::prefixAt($durations, 1));
		self::assertSame(30_000, TimelineService::prefixAt($durations, 2));
		// Past the end clamps to the total rather than overrunning the array.
		self::assertSame(35_000, TimelineService::prefixAt($durations, 99));
	}

	// --------------------------------------------------------------------- wrap()

	/**
	 * @return array<string, array{int, int, int}>
	 */
	public static function wrapProvider(): array {
		return [
			'inside the cycle' => [4_000, 10_000, 4_000],
			'exactly one cycle' => [10_000, 10_000, 0],
			'several cycles in' => [35_000, 10_000, 5_000],
			// PHP's % keeps the dividend's sign, which would yield a negative programme
			// position if a clock ever ran backwards. It must wrap forwards instead.
			'negative position' => [-1_000, 10_000, 9_000],
			'negative multiple cycles' => [-25_000, 10_000, 5_000],
			'zero total' => [1_234, 0, 0],
		];
	}

	#[DataProvider('wrapProvider')]
	public function testWrap(int $position, int $total, int $expected): void {
		self::assertSame($expected, TimelineService::wrap($position, $total));
	}

	// ------------------------------------------------------------------ position()

	public function testPausedChannelIgnoresElapsedTime(): void {
		$service = $this->serviceWithoutMappers();
		$channel = self::channel(paused: true, epochOffsetMs: 7_500, startedAtMs: 1_000);

		// Wall clock has moved a long way; a paused channel must not have.
		self::assertSame(7_500, $service->rawPosition($channel, 900_000));
	}

	public function testPlayingChannelAdvancesWithTheClock(): void {
		$service = $this->serviceWithoutMappers();
		$channel = self::channel(paused: false, epochOffsetMs: 1_000, startedAtMs: 100_000);

		self::assertSame(6_000, $service->rawPosition($channel, 105_000));
	}

	public function testClockRunningBackwardsDoesNotRewindTheProgramme(): void {
		$service = $this->serviceWithoutMappers();
		$channel = self::channel(paused: false, epochOffsetMs: 4_000, startedAtMs: 100_000);

		// An NTP correction could put `now` behind the anchor. The programme must hold
		// its position rather than jump backwards.
		self::assertSame(4_000, $service->rawPosition($channel, 90_000));
	}

	public function testLoopingChannelWrapsIntoTheCurrentCycle(): void {
		$service = $this->serviceWithoutMappers();
		$channel = self::channel(paused: false, epochOffsetMs: 0, startedAtMs: 0, loop: true);
		$durations = [10_000, 20_000];

		self::assertSame(5_000, $service->position($channel, $durations, 35_000));
	}

	public function testNonLoopingChannelReportsAPositionPastTheEnd(): void {
		$service = $this->serviceWithoutMappers();
		$channel = self::channel(paused: false, epochOffsetMs: 0, startedAtMs: 0, loop: false);
		$durations = [10_000, 20_000];

		// Running past `total` is exactly how "ended" is detected, so it must NOT wrap.
		$position = $service->position($channel, $durations, 35_000);
		self::assertSame(35_000, $position);
		self::assertNull(TimelineService::locate($durations, $position));
	}

	public function testEmptyProgrammeNeverWraps(): void {
		$service = $this->serviceWithoutMappers();
		$channel = self::channel(paused: false, epochOffsetMs: 0, startedAtMs: 0, loop: true);

		self::assertSame(5_000, $service->position($channel, [], 5_000));
	}

	/**
	 * The mapper-free helpers are exercised directly; withPreservedPosition() has its own
	 * test class because it needs the persistence layer.
	 */
	private function serviceWithoutMappers(): TimelineService {
		return new TimelineService(
			$this->createMock(\OCA\MusicRadio\Db\ChannelMapper::class),
			$this->createMock(\OCA\MusicRadio\Db\TrackMapper::class),
			$this->createMock(\OCA\MusicRadio\Service\Clock::class),
		);
	}
}
