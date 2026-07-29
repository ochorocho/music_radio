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
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\PlaybackService;
use OCA\MusicRadio\Service\TimelineService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reading the broadcast state, and being the DJ.
 *
 * The state payload is what every listener's player is driven from, so the arithmetic in
 * it — which track, how far in, when did it start — is worth pinning down precisely.
 */
class PlaybackServiceTest extends TestCase {

	private const NOW_MS = 1_000_000;

	private ChannelMapper&MockObject $channelMapper;
	private TrackMapper&MockObject $trackMapper;
	private PlaybackService $service;
	private TimelineService $timeline;

	protected function setUp(): void {
		parent::setUp();

		$this->channelMapper = $this->createMock(ChannelMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->channelMapper->method('update')->willReturnArgument(0);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowMillis')->willReturn(self::NOW_MS);
		$clock->method('nowSeconds')->willReturn((int)(self::NOW_MS / 1000));

		$this->timeline = new TimelineService($this->channelMapper, $this->trackMapper, $clock);
		$this->service = new PlaybackService(
			$this->channelMapper,
			$this->trackMapper,
			$this->timeline,
			$clock,
			$this->createMock(ISecureRandom::class),
		);
	}

	private static function track(int $id, int $durationMs, string $title = 'Track'): Track {
		$track = new Track();
		$track->setId($id);
		$track->setDurationMs($durationMs);
		$track->setUnavailable(false);
		$track->setTitle($title);

		return $track;
	}

	private static function channel(bool $paused, int $epochOffsetMs, int $elapsedMs = 0, bool $loop = true): Channel {
		$channel = new Channel();
		$channel->setId(1);
		$channel->setPaused($paused);
		$channel->setEpochOffsetMs($epochOffsetMs);
		$channel->setStartedAtMs(self::NOW_MS - $elapsedMs);
		$channel->setLoopEnabled($loop);
		$channel->setShuffle(false);
		$channel->setStateVersion(1);
		$channel->setPlaylistVersion(1);
		$channel->setUpdatedAt(0);

		return $channel;
	}

	/**
	 * @param Track[] $tracks
	 */
	private function withPlaylist(array $tracks): void {
		$this->trackMapper->method('findAllForChannelInPlayOrder')->willReturn($tracks);
		$this->trackMapper->method('findAllForChannel')->willReturn($tracks);
	}

	// -------------------------------------------------------------- state payload

	public function testStateReportsTheCurrentTrackAndOffset(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		// 15s into a 30s programme: 5s into the second track.
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 15_000);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertSame(PlaybackService::STATUS_PLAYING, $state['status']);
		self::assertSame(2, $state['current']['trackId']);
		self::assertSame(1, $state['current']['index']);
		self::assertSame(5_000, $state['current']['offsetMs']);
		self::assertSame(20_000, $state['current']['durationMs']);
		self::assertSame(15_000, $state['current']['endsInMs']);
	}

	/**
	 * The client derives its playback position from `startedAtMs` plus its own measured
	 * clock offset, so this has to be the instant the track began — not the instant the
	 * response was built.
	 */
	public function testStateReportsWhenTheCurrentTrackBegan(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 15_000);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertSame(self::NOW_MS - 5_000, $state['current']['startedAtMs']);
	}

	public function testStateNamesTheTrackComingNext(): void {
		$this->withPlaylist([self::track(1, 10_000, 'First'), self::track(2, 20_000, 'Second')]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 1_000);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertSame(2, $state['next']['trackId']);
		self::assertSame('Second', $state['next']['title']);
	}

	public function testTheLastTrackOfALoopingChannelIsFollowedByTheFirst(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		// 25s in: 15s into the last track.
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 25_000, loop: true);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertSame(1, $state['next']['trackId']);
	}

	public function testTheLastTrackOfANonLoopingChannelHasNothingAfterIt(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 25_000, loop: false);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertNull($state['next']);
	}

	public function testAnEmptyChannelReportsNothingPlaying(): void {
		$this->withPlaylist([]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 5_000);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertSame(PlaybackService::STATUS_EMPTY, $state['status']);
		self::assertNull($state['current']);
	}

	public function testAPausedChannelReportsWhereItStopped(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: true, epochOffsetMs: 12_000, elapsedMs: 900_000);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertSame(PlaybackService::STATUS_PAUSED, $state['status']);
		// Wall-clock time passing must not move a paused broadcast.
		self::assertSame(2, $state['current']['trackId']);
		self::assertSame(2_000, $state['current']['offsetMs']);
	}

	public function testANonLoopingChannelThatRanOutReportsEnded(): void {
		$this->withPlaylist([self::track(1, 10_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 15_000, loop: false);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertSame(PlaybackService::STATUS_ENDED, $state['status']);
		self::assertNull($state['current']);
	}

	public function testTracksWithoutAKnownLengthAreCountedButNotBroadcast(): void {
		$unprobed = new Track();
		$unprobed->setId(9);
		$unprobed->setDurationMs(null);
		$unprobed->setUnavailable(false);

		$this->withPlaylist([self::track(1, 10_000), $unprobed]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 1_000);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS);

		self::assertSame(2, $state['trackCount']);
		self::assertSame(1, $state['playableCount']);
		self::assertSame(10_000, $state['totalDurationMs']);
	}

	public function testTheClockHandshakeFieldsArePresentAndOrdered(): void {
		$this->withPlaylist([self::track(1, 10_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0);

		$state = $this->service->buildState($channel, Permission::LISTEN, self::NOW_MS - 5);

		// The client needs all three to estimate its offset and the round-trip delay.
		self::assertSame(self::NOW_MS - 5, $state['requestReceivedAtMs']);
		self::assertSame(self::NOW_MS, $state['serverTimeMs']);
		self::assertGreaterThanOrEqual($state['requestReceivedAtMs'], $state['responseSentAtMs']);
	}

	/**
	 * Listeners poll slowly at rest and briefly faster after a change, so a skip reaches
	 * them in seconds without paying for fast polling all the time.
	 */
	public function testPollingSpeedsUpJustAfterAChange(): void {
		$this->withPlaylist([self::track(1, 10_000)]);

		$idle = self::channel(paused: false, epochOffsetMs: 0);
		$idle->setUpdatedAt(0);
		self::assertSame(
			PlaybackService::POLL_IDLE_MS,
			$this->service->buildState($idle, Permission::LISTEN, self::NOW_MS)['pollAfterMs'],
		);

		$justChanged = self::channel(paused: false, epochOffsetMs: 0);
		$justChanged->setUpdatedAt((int)(self::NOW_MS / 1000));
		self::assertSame(
			PlaybackService::POLL_ACTIVE_MS,
			$this->service->buildState($justChanged, Permission::LISTEN, self::NOW_MS)['pollAfterMs'],
		);
	}

	public function testWhoeverDrivesPlaybackPollsFastest(): void {
		$this->withPlaylist([self::track(1, 10_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0);

		$state = $this->service->buildState($channel, Permission::ALL, self::NOW_MS);

		self::assertSame(PlaybackService::POLL_CONTROLLER_MS, $state['pollAfterMs']);
	}

	// ------------------------------------------------------------------- control

	public function testResumingContinuesFromWhereItWasPaused(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: true, epochOffsetMs: 12_000, elapsedMs: 900_000);

		$resumed = $this->service->play($channel);

		self::assertFalse($resumed->getPaused());
		self::assertSame(12_000, $resumed->getEpochOffsetMs());
		self::assertSame(self::NOW_MS, $resumed->getStartedAtMs());
	}

	public function testPausingFreezesTheCurrentPosition(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 15_000);

		$paused = $this->service->pause($channel);

		self::assertTrue($paused->getPaused());
		self::assertSame(15_000, $paused->getEpochOffsetMs());
	}

	/**
	 * A channel that has looped many times must not store an ever-growing offset, or
	 * resuming would land somewhere absurd.
	 */
	public function testPausingAfterSeveralLoopsStoresAPositionInsideTheProgramme(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		// 95s over a 30s programme: third cycle, 5s in.
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 95_000, loop: true);

		$paused = $this->service->pause($channel);

		self::assertSame(5_000, $paused->getEpochOffsetMs());
	}

	public function testSkippingGoesToTheStartOfTheNextTrack(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 3_000);

		$skipped = $this->service->next($channel);

		self::assertSame(10_000, $skipped->getEpochOffsetMs());
	}

	public function testSkippingPastTheEndOfALoopingChannelWrapsToTheStart(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 25_000, loop: true);

		$skipped = $this->service->next($channel);

		self::assertSame(0, $skipped->getEpochOffsetMs());
	}

	public function testPreviousRestartsTheTrackWhenItIsAlreadyUnderway(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		// 5s into the second track — past the restart threshold.
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 15_000);

		$result = $this->service->previous($channel);

		self::assertSame(10_000, $result->getEpochOffsetMs());
	}

	public function testPreviousGoesBackWhenTheTrackHasOnlyJustStarted(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		// 1s into the second track — inside the restart threshold.
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 11_000);

		$result = $this->service->previous($channel);

		self::assertSame(0, $result->getEpochOffsetMs());
	}

	public function testSeekingMovesWithinTheCurrentTrack(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 15_000);

		$result = $this->service->seek($channel, 8_000);

		// Start of track 2 (10 000) plus the requested offset.
		self::assertSame(18_000, $result->getEpochOffsetMs());
	}

	public function testSeekingBeyondTheTrackIsClampedToIt(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 15_000);

		$result = $this->service->seek($channel, 999_999);

		// Clamped to the last millisecond of track 2, never spilling into the next one.
		self::assertSame(10_000 + 19_999, $result->getEpochOffsetMs());
	}

	public function testJumpingToATrackStartsItFromTheBeginning(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000), self::track(3, 5_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 1_000);

		$result = $this->service->jumpTo($channel, 3);

		self::assertSame(30_000, $result->getEpochOffsetMs());
	}

	/**
	 * Choosing a track means "play this now". Re-anchoring a paused channel without
	 * starting it would leave the person who pressed play staring at silence.
	 */
	public function testJumpingToATrackPutsAPausedChannelOnAir(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		$channel = self::channel(paused: true, epochOffsetMs: 5_000);

		$result = $this->service->jumpTo($channel, 2);

		self::assertFalse($result->getPaused());
		self::assertSame(10_000, $result->getEpochOffsetMs());
	}

	/**
	 * The same track chosen again restarts it, rather than doing nothing.
	 */
	public function testJumpingToTheTrackAlreadyPlayingRestartsIt(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);
		// 15s in: 5s into track 2.
		$channel = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 15_000);

		$result = $this->service->jumpTo($channel, 2);

		self::assertSame(10_000, $result->getEpochOffsetMs());
	}

	public function testJumpingToATrackThatCannotBePlayedIsRefused(): void {
		$this->withPlaylist([self::track(1, 10_000)]);
		$channel = self::channel(paused: false, epochOffsetMs: 0);

		$this->expectException(\OCA\MusicRadio\Exception\MusicRadioException::class);
		$this->service->jumpTo($channel, 999);
	}

	public function testEveryControlActionAdvancesTheStateVersion(): void {
		$this->withPlaylist([self::track(1, 10_000), self::track(2, 20_000)]);

		$before = self::channel(paused: false, epochOffsetMs: 0, elapsedMs: 5_000);
		$version = $before->getStateVersion();

		// Listeners notice a change only because this number moved.
		self::assertGreaterThan($version, $this->service->next($before)->getStateVersion());
	}
}
