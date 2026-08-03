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
use OCA\MusicRadio\Service\PlaybackService;
use OCA\MusicRadio\Service\TimelineService;
use OCA\MusicRadio\Service\VoteService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Drawing, redrawing and putting away a shuffle.
 *
 * A shuffle here is materialised rather than applied per listener — everyone hears one
 * broadcast, so the randomness has to be decided once, server-side, and written down. That
 * makes *when* it is redrawn a question with an answer, and the answer used to be "never":
 * a looping channel played the identical sequence every cycle until somebody toggled the
 * switch by hand, which from the second cycle on is indistinguishable from not shuffling.
 */
class ShuffleTest extends TestCase {

	private const NOW_MS = 1_000_000;
	private const NOW_S = 1_000;

	private ChannelMapper&MockObject $channelMapper;
	private TrackMapper&MockObject $trackMapper;
	private VoteService&MockObject $voteService;
	private PlaybackService $service;

	/** @var list<array{int, int, int}> track id, shuffle_order, vote_order, as written */
	private array $shuffled = [];
	/** @var list<array{int, string, int}> track id, column, position, as written */
	private array $orders = [];

	protected function setUp(): void {
		parent::setUp();

		$this->channelMapper = $this->createMock(ChannelMapper::class);
		$this->trackMapper = $this->createMock(TrackMapper::class);
		$this->voteService = $this->createMock(VoteService::class);

		$this->channelMapper->method('update')->willReturnArgument(0);
		$this->trackMapper->method('update')->willReturnCallback(function (Track $track): Track {
			$this->shuffled[] = [
				(int)$track->getId(),
				(int)$track->getShuffleOrder(),
				(int)$track->getVoteOrder(),
			];

			return $track;
		});
		$this->trackMapper->method('updateOrder')
			->willReturnCallback(function (int $trackId, int $channelId, string $column, int $position): void {
				$this->orders[] = [$trackId, $column, $position];
			});

		$clock = $this->createMock(Clock::class);
		$clock->method('nowMillis')->willReturn(self::NOW_MS);
		$clock->method('nowSeconds')->willReturn(self::NOW_S);

		$random = $this->createMock(ISecureRandom::class);
		// Fixed seed, so the draw is reproducible and a failure means the ordering rules
		// changed rather than the dice.
		$random->method('generate')->willReturn('123456789');

		$this->service = new PlaybackService(
			$this->channelMapper,
			$this->trackMapper,
			new TimelineService($this->channelMapper, $this->trackMapper, $clock),
			$this->voteService,
			$clock,
			$random,
		);
	}

	private static function track(int $id, int $durationMs = 10_000, ?string $artist = null): Track {
		$track = new Track();
		$track->setId($id);
		$track->setDurationMs($durationMs);
		$track->setUnavailable(false);
		$track->setDisabled(false);
		$track->setArtist($artist);

		return $track;
	}

	private static function channel(
		int $elapsedMs,
		bool $shuffle = true,
		bool $loop = true,
		bool $paused = false,
		bool $allowVoting = false,
	): Channel {
		$channel = new Channel();
		$channel->setId(1);
		$channel->setPaused($paused);
		$channel->setEpochOffsetMs(0);
		$channel->setStartedAtMs(self::NOW_MS - $elapsedMs);
		$channel->setLoopEnabled($loop);
		$channel->setShuffle($shuffle);
		$channel->setAllowVoting($allowVoting);
		$channel->setStateVersion(1);
		$channel->setPlaylistVersion(1);
		$channel->setUpdatedAt(0);

		return $channel;
	}

	/** @param Track[] $tracks */
	private function playlist(array $tracks): void {
		$this->trackMapper->method('findAllForChannelInPlayOrder')->willReturn($tracks);
		$this->trackMapper->method('findAllForChannelInBaseOrder')->willReturn($tracks);
		$this->trackMapper->method('findAllForChannel')->willReturn($tracks);
	}

	/** @return list<int> the ids in the order they were written */
	private function drawnOrder(): array {
		return array_map(static fn (array $row): int => $row[0], $this->shuffled);
	}

	// ------------------------------------------------------- redrawing on the wrap

	/**
	 * Three ten-second tracks make a thirty-second programme. Thirty-five seconds in, the
	 * channel has been round once — so the next cycle gets a new draw rather than a repeat
	 * of the one before.
	 */
	public function testALoopingChannelRedrawsWhenItComesRound(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);

		$this->service->maintainRunningOrder(self::channel(35_000));

		self::assertNotSame([], $this->shuffled, 'the cycle completed and nothing was redrawn');
		self::assertCount(3, $this->drawnOrder());
	}

	public function testItDoesNotRedrawPartWayThroughACycle(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);

		$this->service->maintainRunningOrder(self::channel(25_000));

		self::assertSame([], $this->shuffled);
	}

	/**
	 * A paused channel's position is frozen, so once it is parked past the end of its
	 * programme every single poll would redraw the playlist underneath whoever paused it.
	 */
	public function testAPausedChannelIsLeftAlone(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);
		$channel = self::channel(35_000, paused: true);
		$channel->setEpochOffsetMs(35_000);

		$this->service->maintainRunningOrder($channel);

		self::assertSame([], $this->shuffled);
	}

	/**
	 * Without looping the programme ends rather than coming round, so there is no next
	 * cycle to draw for.
	 */
	public function testANonLoopingChannelIsLeftAlone(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);

		$this->service->maintainRunningOrder(self::channel(35_000, loop: false));

		self::assertSame([], $this->shuffled);
	}

	public function testAChannelThatIsNotShufflingIsLeftAlone(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);

		$this->service->maintainRunningOrder(self::channel(35_000, shuffle: false));

		self::assertSame([], $this->shuffled);
	}

	public function testAnEmptyChannelIsLeftAlone(): void {
		$this->playlist([]);

		$this->service->maintainRunningOrder(self::channel(35_000));

		self::assertSame([], $this->shuffled);
	}

	/**
	 * Whatever is playing at the wrap keeps its place at the head of the new cycle, so a
	 * two-track channel has exactly one track left to arrange and would redraw the order it
	 * already had — while telling every listener to refetch the track list for it, once per
	 * cycle, for as long as the channel was left running.
	 */
	public function testAChannelWithNothingToArrangeIsLeftAlone(): void {
		$this->playlist([self::track(1), self::track(2)]);
		$channel = self::channel(25_000);

		$this->service->maintainRunningOrder($channel);

		self::assertSame([], $this->shuffled);
		self::assertSame(1, $channel->getPlaylistVersion());
	}

	/**
	 * The redraw happens under a playhead that is already moving, so it has to leave the
	 * listener where they are: the track playing at the moment of the wrap stays playing,
	 * at the same point in it, and becomes the head of the new cycle.
	 */
	public function testTheRedrawDoesNotInterruptWhatIsPlaying(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);
		// 34s into a 30s programme: 4s into the first track of the second cycle.
		$channel = self::channel(34_000);

		$this->service->maintainRunningOrder($channel);

		self::assertSame(1, $this->drawnOrder()[0], 'the playing track must stay at the front');
		self::assertSame(4_000, $channel->getEpochOffsetMs());
		self::assertSame(self::NOW_MS, $channel->getStartedAtMs());
	}

	public function testTheRedrawTellsListenersToRefetchTheList(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);
		$channel = self::channel(35_000);

		$this->service->maintainRunningOrder($channel);

		self::assertSame(2, $channel->getPlaylistVersion());
		self::assertGreaterThan(1, $channel->getStateVersion());
	}

	/**
	 * Having come round and redrawn, the channel is at the top of a fresh cycle — so the
	 * very next poll must not decide it has come round again and redraw once more.
	 */
	public function testItDoesNotRedrawAgainImmediately(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);
		$channel = self::channel(35_000);

		$this->service->maintainRunningOrder($channel);
		$drawn = count($this->shuffled);

		$this->service->maintainRunningOrder($channel);

		self::assertCount($drawn, $this->shuffled);
	}

	// ------------------------------------------------------------- what it draws

	public function testTheDrawKeepsTheSameBandApart(): void {
		$this->playlist([
			self::track(1, 10_000, 'The National'),
			self::track(2, 10_000, 'The National'),
			self::track(3, 10_000, 'The National'),
			self::track(4, 10_000, 'Interpol'),
			self::track(5, 10_000, 'Slipknot'),
			self::track(6, 10_000, 'TOOL'),
		]);
		// Switching shuffle on from off, with nothing playing, so no track is pinned and the
		// whole list is free to move.
		$channel = self::channel(0, shuffle: false, loop: false, paused: true);

		$this->service->updateSettings($channel, loop: null, shuffle: true);
		self::assertCount(6, $this->shuffled, 'nothing was drawn');

		$byId = [1 => 'The National', 2 => 'The National', 3 => 'The National',
			4 => 'Interpol', 5 => 'Slipknot', 6 => 'TOOL'];
		$drawn = array_map(static fn (int $id): string => $byId[$id], $this->drawnOrder());

		for ($i = 1; $i < count($drawn); $i++) {
			self::assertNotSame($drawn[$i - 1], $drawn[$i], 'two by the same band in a row');
		}
	}

	/**
	 * The running order is what actually plays on a channel that takes votes, so a fresh
	 * draw has to land there too. Writing only `shuffle_order` left such a channel playing
	 * the previous order until the vote recompute next came due.
	 */
	public function testTheDrawReachesTheOrderThatIsActuallyPlayed(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);

		$this->service->maintainRunningOrder(self::channel(35_000, allowVoting: true));

		self::assertCount(3, $this->shuffled);
		foreach ($this->shuffled as [$id, $shuffleOrder, $voteOrder]) {
			self::assertSame($shuffleOrder, $voteOrder, "track {$id} was drawn but not played");
		}
	}

	// -------------------------------------------------------------- putting it away

	/**
	 * Turning shuffle off used to clear the flag and nothing else. `sort_order` survives a
	 * shuffle untouched so the base order came back on its own — but on a channel that
	 * takes votes it is the running order that is played, and that still held the shuffle.
	 * The sound and the screen both disagreed with the switch that had just been flipped.
	 */
	public function testTurningShuffleOffRestoresTheArrangedOrder(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);
		$channel = self::channel(5_000, allowVoting: true);

		$this->service->updateSettings($channel, loop: null, shuffle: false);

		self::assertFalse($channel->getShuffle());
		self::assertSame(
			[[1, TrackMapper::ORDER_VOTE, 1000], [2, TrackMapper::ORDER_VOTE, 2000], [3, TrackMapper::ORDER_VOTE, 3000]],
			$this->orders,
		);
		self::assertSame(2, $channel->getPlaylistVersion(), 'listeners were not told to refetch');
	}

	// ------------------------------------------------------------------- ordering

	/**
	 * The reshuffle rewrites the base order and the vote recompute derives the running
	 * order from it, so they have to happen in that order — the other way round spends a
	 * cycle playing votes promoted into an order that no longer exists.
	 */
	public function testVotesAreRecomputedAfterTheRedraw(): void {
		$this->playlist([self::track(1), self::track(2), self::track(3)]);
		$channel = self::channel(35_000, allowVoting: true);

		$seen = null;
		$this->voteService->expects(self::once())
			->method('recomputeIfDue')
			->willReturnCallback(function () use (&$seen): bool {
				$seen = count($this->shuffled);

				return false;
			});

		$this->service->maintainRunningOrder($channel);

		self::assertSame(3, $seen, 'the votes were recomputed before the redraw landed');
	}

	public function testAChannelWithoutVotingSkipsTheRecompute(): void {
		$this->playlist([self::track(1), self::track(2)]);

		$this->voteService->expects(self::never())->method('recomputeIfDue');

		$this->service->maintainRunningOrder(self::channel(5_000, allowVoting: false));
	}
}
