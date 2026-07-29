<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\TimelineService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The invariant that makes this a radio station rather than a shared playlist:
 *
 *   Editing the playlist must not change what listeners are currently hearing.
 *
 * Each test edits the playlist mid-broadcast and asserts that the same track is still
 * playing at the same offset afterwards. The one deliberate exception — removing the
 * track that is playing — is asserted too.
 *
 * These are the highest-value tests in the app: every failure here is a bug that
 * manifests as "the music jumped for everyone listening", which is close to impossible
 * to reproduce by hand.
 */
class PreservedPositionTest extends TestCase {

	private const NOW_MS = 1_000_000;

	private ChannelMapper&MockObject $channelMapper;
	private TrackMapper&MockObject $trackMapper;
	private TimelineService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->channelMapper = $this->createMock(ChannelMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowMillis')->willReturn(self::NOW_MS);
		$clock->method('nowSeconds')->willReturn((int)(self::NOW_MS / 1000));

		// update() is the persistence boundary; hand the entity straight back so the
		// assertions can read the re-anchored values off it.
		$this->channelMapper->method('update')->willReturnArgument(0);

		$this->service = new TimelineService($this->channelMapper, $this->trackMapper, $clock);
	}

	private static function track(int $id, int $durationMs): Track {
		$track = new Track();
		$track->setId($id);
		$track->setDurationMs($durationMs);
		$track->setUnavailable(false);

		return $track;
	}

	/**
	 * A channel playing, whose anchor was set `elapsedMs` ago.
	 */
	private static function playingChannel(int $elapsedMs, bool $loop = true): Channel {
		$channel = new Channel();
		$channel->setId(1);
		$channel->setPaused(false);
		$channel->setEpochOffsetMs(0);
		$channel->setStartedAtMs(self::NOW_MS - $elapsedMs);
		$channel->setLoopEnabled($loop);
		$channel->setShuffle(false);
		$channel->setStateVersion(1);
		$channel->setPlaylistVersion(1);

		return $channel;
	}

	/**
	 * Stub the playlist as it looks before and after the mutation.
	 *
	 * @param Track[] $before
	 * @param Track[] $after
	 */
	private function playlist(array $before, array $after): void {
		$this->trackMapper
			->method('findAllForChannelInPlayOrder')
			->willReturnOnConsecutiveCalls($before, $after);
	}

	/**
	 * Where a channel's anchor now points, expressed as (trackId, offset into track).
	 *
	 * @param Track[] $tracks
	 * @return array{trackId: int|null, offsetMs: int|null}
	 */
	private static function nowPlaying(Channel $channel, array $tracks): array {
		$playable = TimelineService::playable($tracks);
		$durations = TimelineService::durations($playable);

		// Read the anchor directly: re-anchoring always stamps started_at_ms = now, so
		// zero time has elapsed since.
		$located = TimelineService::locate($durations, $channel->getEpochOffsetMs());

		if ($located === null) {
			return ['trackId' => null, 'offsetMs' => null];
		}

		return [
			'trackId' => $playable[$located['index']]->getId(),
			'offsetMs' => $located['offsetMs'],
		];
	}

	// ---------------------------------------------------------------------------

	public function testAppendingDoesNotDisturbTheCurrentTrack(): void {
		$a = self::track(1, 10_000);
		$b = self::track(2, 20_000);
		$c = self::track(3, 30_000);

		// 15s in: 5s into track B.
		$this->playlist([$a, $b], [$a, $b, $c]);
		$channel = self::playingChannel(15_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 2, 'offsetMs' => 5_000], self::nowPlaying($result, [$a, $b, $c]));
	}

	/**
	 * The case that makes appending non-trivial. With looping on, adding a track grows
	 * the programme total, which moves the point at which the programme wraps. A
	 * listener who had already wrapped would jump if the anchor were left alone.
	 */
	public function testAppendingWhileLoopedAndPastTheFirstCycleDoesNotJump(): void {
		$a = self::track(1, 10_000);
		$b = self::track(2, 20_000);
		$c = self::track(3, 30_000);

		// 45s elapsed over a 30s programme => second cycle, 15s in => 5s into track B.
		$this->playlist([$a, $b], [$a, $b, $c]);
		$channel = self::playingChannel(45_000, loop: true);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 2, 'offsetMs' => 5_000], self::nowPlaying($result, [$a, $b, $c]));
	}

	public function testReorderingKeepsTheCurrentTrackPlayingAtTheSameOffset(): void {
		$a = self::track(1, 10_000);
		$b = self::track(2, 20_000);
		$c = self::track(3, 30_000);

		// 15s in: 5s into B. Afterwards B is first in the list.
		$this->playlist([$a, $b, $c], [$b, $c, $a]);
		$channel = self::playingChannel(15_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 2, 'offsetMs' => 5_000], self::nowPlaying($result, [$b, $c, $a]));
	}

	/**
	 * Removing something earlier in the playlist shrinks every prefix after it. Without
	 * re-anchoring, every listener would jump backwards by the removed track's length.
	 */
	public function testRemovingAnEarlierTrackDoesNotRewindListeners(): void {
		$a = self::track(1, 10_000);
		$b = self::track(2, 20_000);
		$c = self::track(3, 30_000);

		// 35s in: 5s into C.
		$this->playlist([$a, $b, $c], [$b, $c]);
		$channel = self::playingChannel(35_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 3, 'offsetMs' => 5_000], self::nowPlaying($result, [$b, $c]));
	}

	public function testRemovingALaterTrackDoesNotDisturbTheCurrentOne(): void {
		$a = self::track(1, 10_000);
		$b = self::track(2, 20_000);
		$c = self::track(3, 30_000);

		$this->playlist([$a, $b, $c], [$a, $b]);
		$channel = self::playingChannel(15_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 2, 'offsetMs' => 5_000], self::nowPlaying($result, [$a, $b]));
	}

	/**
	 * The deliberate exception: whoever removed the playing track meant to stop hearing
	 * it, so this cuts to the start of whatever now follows.
	 */
	public function testRemovingTheCurrentTrackCutsToTheStartOfTheNextOne(): void {
		$a = self::track(1, 10_000);
		$b = self::track(2, 20_000);
		$c = self::track(3, 30_000);

		$this->playlist([$a, $b, $c], [$a, $c]);
		$channel = self::playingChannel(15_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 3, 'offsetMs' => 0], self::nowPlaying($result, [$a, $c]));
	}

	public function testRemovingTheCurrentAndLastTrackWrapsToTheTop(): void {
		$a = self::track(1, 10_000);
		$b = self::track(2, 20_000);

		// 15s in: 5s into B, which is last. Nothing follows it.
		$this->playlist([$a, $b], [$a]);
		$channel = self::playingChannel(15_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 1, 'offsetMs' => 0], self::nowPlaying($result, [$a]));
	}

	/**
	 * A track whose duration was not known was not on the timeline at all. When the probe
	 * fills it in, the track is inserted into the programme and every prefix after it
	 * shifts — so this has to go through the same guard as an explicit edit.
	 */
	public function testADurationArrivingLateDoesNotShiftTheCurrentTrack(): void {
		$pending = self::track(1, 10_000);
		$b = self::track(2, 20_000);

		$unprobed = new Track();
		$unprobed->setId(9);
		$unprobed->setDurationMs(null);
		$unprobed->setUnavailable(false);

		$probed = self::track(9, 60_000);

		// Before: [pending(10s), unprobed(not on timeline), b(20s)] -> 15s in = 5s into B.
		// After: the middle track joins the programme, pushing B 60s later.
		$this->playlist([$pending, $unprobed, $b], [$pending, $probed, $b]);
		$channel = self::playingChannel(15_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 2, 'offsetMs' => 5_000], self::nowPlaying($result, [$pending, $probed, $b]));
	}

	public function testAddingToAnEmptyChannelStartsAtTheTop(): void {
		$a = self::track(1, 10_000);

		$this->playlist([], [$a]);
		$channel = self::playingChannel(5_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(0, $result->getEpochOffsetMs());
	}

	public function testEmptyingTheChannelResetsToZero(): void {
		$a = self::track(1, 10_000);

		$this->playlist([$a], []);
		$channel = self::playingChannel(5_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(0, $result->getEpochOffsetMs());
	}

	/**
	 * A non-looping channel that has run out resumes into the newly added material
	 * rather than staying silent.
	 */
	public function testAddingToAFinishedChannelResumesIntoTheNewTrack(): void {
		$a = self::track(1, 10_000);
		$b = self::track(2, 20_000);

		$this->playlist([$a], [$a, $b]);
		// 12s elapsed over a 10s non-looping programme: already ended.
		$channel = self::playingChannel(12_000, loop: false);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		self::assertSame(['trackId' => 2, 'offsetMs' => 2_000], self::nowPlaying($result, [$a, $b]));
	}

	public function testBothVersionCountersAdvanceOnAPlaylistChange(): void {
		$a = self::track(1, 10_000);

		$this->playlist([$a], [$a]);
		$channel = self::playingChannel(1_000);

		$result = $this->service->withPreservedPosition($channel, static function (): void {
		});

		// state_version tells listeners "refetch the small state payload"; the separate
		// playlist_version is what makes them refetch the larger track list.
		self::assertSame(2, $result->getStateVersion());
		self::assertSame(2, $result->getPlaylistVersion());
	}

	public function testTheMutationCallbackActuallyRuns(): void {
		$a = self::track(1, 10_000);
		$this->playlist([$a], [$a]);

		$ran = false;
		$this->service->withPreservedPosition(self::playingChannel(0), static function () use (&$ran): void {
			$ran = true;
		});

		self::assertTrue($ran);
	}
}
