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
use OCA\MusicRadio\Db\VoteMapper;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\TimelineService;
use OCA\MusicRadio\Service\VoteService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Votes changing the running order — and, far more importantly, *not* changing it.
 *
 * A channel is one continuous programme with no track-boundary event, so the only way to
 * honour a vote is to rewrite the order, and every rewrite re-anchors the timeline, bumps
 * both version counters and makes every listener refetch. The failure mode this class
 * exists to prevent is therefore not "voting does not work" but "voting works so eagerly
 * that the broadcast never settles" — which on a real channel sounds like the music
 * stuttering whenever anybody presses anything.
 *
 * Everything below is about when a reorder is allowed to happen at all.
 */
class VoteServiceTest extends TestCase {

	private const NOW_MS = 1_000_000_000;
	private const NOW_S = 1_000_000;

	private VoteMapper&MockObject $voteMapper;
	private TrackMapper&MockObject $trackMapper;
	private ChannelMapper&MockObject $channelMapper;
	private VoteService $service;

	/** @var list<array{int, int}> track id => vote_order, in the order they were written */
	private array $written = [];

	protected function setUp(): void {
		parent::setUp();

		$this->voteMapper = $this->createMock(VoteMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->channelMapper = $this->createMock(ChannelMapper::class);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowMillis')->willReturn(self::NOW_MS);
		$clock->method('nowSeconds')->willReturn(self::NOW_S);

		$this->channelMapper->method('update')->willReturnArgument(0);
		$this->trackMapper->method('update')->willReturnCallback(function (Track $track): Track {
			$this->written[] = [$track->getId(), $track->getVoteOrder()];

			return $track;
		});

		$this->service = new VoteService(
			$this->voteMapper,
			$this->trackMapper,
			$this->channelMapper,
			new TimelineService($this->channelMapper, $this->trackMapper, $clock),
			$clock,
		);
	}

	private static function track(int $id, int $durationMs): Track {
		$track = new Track();
		$track->setId($id);
		$track->setDurationMs($durationMs);
		$track->setUnavailable(false);
		$track->setDisabled(false);
		$track->setVoteOrder($id * 1000);

		return $track;
	}

	/**
	 * A channel playing, `elapsedMs` into its programme, that has never been reordered.
	 */
	private static function channel(int $elapsedMs, bool $allowVoting = true, bool $shuffle = false): Channel {
		$channel = new Channel();
		$channel->setId(1);
		$channel->setPaused(false);
		$channel->setEpochOffsetMs(0);
		$channel->setStartedAtMs(self::NOW_MS - $elapsedMs);
		$channel->setLoopEnabled(true);
		$channel->setShuffle($shuffle);
		$channel->setAllowVoting($allowVoting);
		$channel->setStateVersion(1);
		$channel->setPlaylistVersion(1);
		$channel->setVoteOrderedAt(0);

		return $channel;
	}

	/**
	 * @param Track[] $tracks
	 * @param array<int, int> $counts
	 */
	private function playlist(array $tracks, array $counts = []): void {
		$this->trackMapper->method('findAllForChannelInPlayOrder')->willReturn($tracks);
		$this->voteMapper->method('countsForChannel')->willReturn($counts);
	}

	/** @return list<int> the new order, as track ids */
	private function writtenOrder(): array {
		return array_map(static fn (array $row): int => $row[0], $this->written);
	}

	// ------------------------------------------------------- the reorder itself

	public function testAVotedTrackMovesToTheFrontOfWhatIsComing(): void {
		// 60s each; 10s in, so track 1 is playing and there is plenty of room.
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[3 => 2],
		);

		self::assertTrue($this->service->recomputeIfDue(self::channel(10_000)));
		// The playing track stays put; the voted one is next.
		self::assertSame([1, 3, 2], $this->writtenOrder());
	}

	/**
	 * The property that makes the whole approach safe: the rewrite happens *behind* the
	 * playhead. Whatever is playing must still be playing, at the same point in it.
	 */
	public function testTheTrackOnAirKeepsPlayingAtTheSameOffset(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[3 => 5],
		);
		$channel = self::channel(10_000);

		$this->service->recomputeIfDue($channel);

		// The current track is now index 0, and the anchor points 10s into it.
		self::assertSame(1, $this->written[0][0]);
		self::assertSame(10_000, $channel->getEpochOffsetMs());
		self::assertSame(self::NOW_MS, $channel->getStartedAtMs());
	}

	public function testMoreVotesComeSooner(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000), self::track(4, 60_000)],
			[2 => 1, 3 => 7, 4 => 3],
		);

		$this->service->recomputeIfDue(self::channel(10_000));

		self::assertSame([1, 3, 4, 2], $this->writtenOrder());
	}

	public function testAReorderMovesBothVersionCountersSoListenersRefetch(): void {
		// Three tracks, and the vote is for the last one — with only two, the voted track
		// is already next and there is correctly nothing to rewrite.
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[3 => 1],
		);
		$channel = self::channel(10_000);

		$this->service->recomputeIfDue($channel);

		self::assertSame(2, $channel->getPlaylistVersion());
		self::assertSame(2, $channel->getStateVersion());
	}

	// ------------------------------------------------------ when it must not run

	/**
	 * The subtle one. Clients load the next track into the idle audio element well before
	 * the current one ends and cross the boundary using that cached value — so the track
	 * that is *about* to play must not move, or every listener briefly plays the wrong
	 * thing and then jumps.
	 *
	 * Note what this does not say: the reorder still happens. Only the next track is off
	 * limits, and the voted track lands immediately behind it.
	 */
	public function testTheTrackAboutToPlayIsNotMovedOutFromUnderListeners(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000), self::track(4, 60_000)],
			[4 => 9],
		);

		// 55s into a 60s track: five seconds left, so track 2 is already loaded.
		self::assertTrue($this->service->recomputeIfDue(self::channel(55_000)));
		self::assertSame([1, 2, 4, 3], $this->writtenOrder());
	}

	/**
	 * The bug that made pinning the right answer instead of refusing.
	 *
	 * The guard is twenty seconds and a track can easily be shorter than that, so "is
	 * there room before the boundary?" is permanently false on a channel of short tracks —
	 * which used to mean voting silently did nothing there, for ever. Found by trying it
	 * against the test fixtures, which are three to eight seconds long.
	 */
	public function testVotingStillWorksOnTracksShorterThanTheGuard(): void {
		$this->playlist(
			[self::track(1, 3_000), self::track(2, 5_000), self::track(3, 8_000), self::track(4, 3_000)],
			[4 => 2],
		);

		self::assertTrue($this->service->recomputeIfDue(self::channel(1_000)));
		// Current and next are pinned; the voted track takes the first free place.
		self::assertSame([1, 2, 4, 3], $this->writtenOrder());
	}

	/**
	 * With room to spare, nothing but the playing track is protected — a voted track can
	 * come round next rather than next-but-one.
	 */
	public function testWithRoomToSpareOnlyThePlayingTrackIsPinned(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000), self::track(4, 60_000)],
			[4 => 9],
		);

		self::assertTrue($this->service->recomputeIfDue(self::channel(10_000)));
		self::assertSame([1, 4, 2, 3], $this->writtenOrder());
	}

	public function testTheGuardEndsWhereItSaysItDoes(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[3 => 9],
		);

		// Just outside the guard — 60s − 21s = 39s remaining — so the next track is not
		// yet spoken for and the voted one may take its place.
		self::assertTrue($this->service->recomputeIfDue(self::channel(21_000)));
		self::assertSame([1, 3, 2], $this->writtenOrder());
	}

	/**
	 * A burst of votes must produce one reorder, not one per vote. Without this a channel
	 * with a few enthusiastic listeners would re-anchor itself continuously.
	 */
	public function testAReorderDoesNotHappenAgainStraightAway(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000)],
			[2 => 1],
		);
		$channel = self::channel(10_000);
		$channel->setVoteOrderedAt(self::NOW_S - 5);

		self::assertFalse($this->service->recomputeIfDue($channel));
		self::assertSame([], $this->written);
	}

	public function testForcingIgnoresTheDebounceButNotTheBoundary(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[3 => 1],
		);
		$channel = self::channel(10_000);
		$channel->setVoteOrderedAt(self::NOW_S - 5);

		self::assertTrue($this->service->recomputeIfDue($channel, force: true));
	}

	/**
	 * With no votes the vote order is simply the order it already was, so there is nothing
	 * to write — and writing it anyway would re-anchor the timeline of every channel that
	 * has voting switched on, for ever, for no reason.
	 */
	public function testAnUnvotedChannelIsNeverReordered(): void {
		$this->playlist([self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)]);

		self::assertFalse($this->service->recomputeIfDue(self::channel(10_000)));
		self::assertSame([], $this->written);
	}

	/**
	 * Equal votes must not shuffle amongst themselves. If they did, every recompute would
	 * find "a different order" and re-anchor the broadcast indefinitely.
	 */
	public function testTracksWithEqualVotesKeepTheirRelativeOrder(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000), self::track(4, 60_000)],
			[2 => 3, 3 => 3, 4 => 3],
		);

		self::assertFalse($this->service->recomputeIfDue(self::channel(10_000)));
	}

	/**
	 * Found by a test fixture that was wrong: with the voted track already next, the
	 * computed order equals the current one and nothing is written. Worth keeping — it is
	 * the same guard that stops an unvoted channel churning, reached a different way.
	 */
	public function testVotingForWhatIsAlreadyNextChangesNothing(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[2 => 4],
		);

		self::assertFalse($this->service->recomputeIfDue(self::channel(10_000)));
		self::assertSame([], $this->written);
	}

	public function testVotingIsIgnoredWhileTheChannelIsShuffling(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000)],
			[2 => 9],
		);

		self::assertFalse($this->service->recomputeIfDue(self::channel(10_000, shuffle: true)));
	}

	public function testNothingHappensOnAChannelWithVotingOff(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000)],
			[2 => 9],
		);

		self::assertFalse($this->service->recomputeIfDue(self::channel(10_000, allowVoting: false)));
	}

	// ---------------------------------------------------------- spending votes

	/**
	 * There is no track-boundary event to hang this on, so the recompute — which is the
	 * one thing that reliably knows what is playing — does it. The reward is spent when
	 * the track reaches the front.
	 */
	public function testTheVotesOfTheTrackNowPlayingAreSpent(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[1 => 4, 3 => 2],
		);

		$this->voteMapper->expects(self::once())->method('clearForTrack')->with(1);

		$this->service->recomputeIfDue(self::channel(10_000));
	}

	public function testATrackNobodyVotedForSpendsNothing(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000)],
			[2 => 4],
		);

		$this->voteMapper->expects(self::never())->method('clearForTrack');

		$this->service->recomputeIfDue(self::channel(10_000));
	}

	/**
	 * A track whose votes were just spent must not still be treated as the most-voted
	 * thing on the channel — it would be pinned at the front and its votes cleared again
	 * on every recompute.
	 */
	public function testSpentVotesDoNotStillCountTowardsTheOrder(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[1 => 10, 3 => 1],
		);

		$this->service->recomputeIfDue(self::channel(10_000));

		// Track 1 is playing and keeps its place; 3 follows on its single remaining vote.
		self::assertSame([1, 3, 2], $this->writtenOrder());
	}

	// -------------------------------------------------- telling everyone else

	/**
	 * A vote deliberately moves nothing that would make listeners refetch — that is what
	 * keeps it from re-anchoring the broadcast. The consequence was that nobody but the
	 * voter ever saw it, so votes get a counter of their own.
	 */
	public function testCastingAVoteMovesTheVoteVersion(): void {
		$this->playlist([self::track(1, 60_000), self::track(2, 60_000)]);
		$this->trackMapper->method('find')->willReturn(self::track(2, 60_000));
		$this->voteMapper->method('withdraw')->willReturn(false);

		$channel = self::channel(10_000);
		$before = $channel->getVoteVersion();

		$this->service->toggle($channel, 2, 'alice');

		self::assertSame($before + 1, $channel->getVoteVersion());
	}

	public function testWithdrawingAVoteMovesItToo(): void {
		$this->playlist([self::track(1, 60_000), self::track(2, 60_000)]);
		$this->trackMapper->method('find')->willReturn(self::track(2, 60_000));
		$this->voteMapper->method('withdraw')->willReturn(true);

		$channel = self::channel(10_000);
		$before = $channel->getVoteVersion();

		$this->service->toggle($channel, 2, 'alice');

		self::assertSame($before + 1, $channel->getVoteVersion());
	}

	/**
	 * Spending a track's votes changes what every open page is showing, so it has to move
	 * the counter as well — otherwise the counts a listener can see stay on screen after
	 * the votes behind them are gone.
	 */
	public function testSpendingVotesMovesTheVoteVersion(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000), self::track(3, 60_000)],
			[1 => 4],
		);

		$channel = self::channel(10_000);
		$before = $channel->getVoteVersion();

		$this->service->recomputeIfDue($channel);

		self::assertSame($before + 1, $channel->getVoteVersion());
	}

	/**
	 * Votes are spent when the track reaches the front even if the order does not change —
	 * which is the ordinary case, since the track being spent is already playing. Reporting
	 * "nothing happened" there would be wrong.
	 */
	public function testSpendingCountsAsHavingDoneSomething(): void {
		$this->playlist(
			[self::track(1, 60_000), self::track(2, 60_000)],
			[1 => 3],
		);

		self::assertTrue($this->service->recomputeIfDue(self::channel(10_000)));
	}

	public function testAQuietChannelStillReportsNothingHappened(): void {
		$this->playlist([self::track(1, 60_000), self::track(2, 60_000)]);

		self::assertFalse($this->service->recomputeIfDue(self::channel(10_000)));
	}

	/**
	 * A vote has to reach the other people looking at the list reasonably quickly.
	 *
	 * `pollAfterMs` reads `updatedAt` to decide between the three-second and ten-second
	 * intervals, so a vote that left it alone was picked up on the idle one — correct, but
	 * slow enough to read as "the counts do not update at all".
	 */
	public function testAVoteMarksTheChannelRecentlyChangedSoOthersPollSooner(): void {
		$this->playlist([self::track(1, 60_000), self::track(2, 60_000)]);
		$this->trackMapper->method('find')->willReturn(self::track(2, 60_000));
		$this->voteMapper->method('withdraw')->willReturn(false);

		$channel = self::channel(10_000);
		$channel->setUpdatedAt(self::NOW_S - 3_600);

		$this->service->toggle($channel, 2, 'alice');

		self::assertSame(self::NOW_S, $channel->getUpdatedAt());
	}

	// ------------------------------------------------------------------ reading

	public function testAChannelWithVotingOffReportsNoVotes(): void {
		$this->voteMapper->expects(self::never())->method('countsForChannel');

		self::assertSame(
			['counts' => [], 'mine' => []],
			$this->service->stateFor(self::channel(0, allowVoting: false), 'alice'),
		);
	}

	public function testAnAnonymousReaderWithNoKeyHasVotedForNothing(): void {
		$this->voteMapper->method('countsForChannel')->willReturn([7 => 2]);
		$this->voteMapper->expects(self::never())->method('trackIdsVotedForBy');

		$state = $this->service->stateFor(self::channel(0), null);

		self::assertSame([7 => 2], $state['counts']);
		self::assertSame([], $state['mine']);
	}
}
