<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Db\Vote;
use OCA\MusicRadio\Db\VoteMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCP\AppFramework\Http;
use OCP\DB\Exception as DbException;

/**
 * Listeners asking for a track to come round sooner.
 *
 * The design turns on one fact about this app: **a channel is a single continuous
 * programme.** Position is a scalar in programme-milliseconds and the current track is
 * derived by walking durations — there is no stored "now playing" and no track-boundary
 * event to hang anything on. So the only way to make a track play sooner is to change the
 * order, and every order change re-anchors the timeline, bumps both version counters,
 * makes every listener refetch the playlist, and invalidates whatever the DJ has in
 * flight.
 *
 * That rules out the obvious implementation. Reordering on each vote would make a channel
 * with a handful of enthusiastic listeners permanently re-anchor itself. So the two halves
 * are separated:
 *
 * - **Casting a vote never touches the timeline.** It is one insert or one delete, and the
 *   new count comes straight back. Nothing reorders, no version moves, nobody refetches.
 * - **The order is recomputed separately**, at most once every {@see RECOMPUTE_EVERY_SECONDS},
 *   and never inside the window where clients have already committed to the next track.
 *
 * That second exclusion is the subtle one. `preloadNextIfDue` loads the next track into
 * the idle audio element {@see PRELOAD_LEAD_MS} before the current one ends, and
 * `advanceLocally()` crosses the boundary using that cached value before asking the server
 * anything. Reorder inside that window and listeners audibly play the wrong track for a
 * moment and then hard-seek. Outside it, reordering behind the playhead is invisible.
 *
 * What the recompute produces is the channel's **base order** — its author's arrangement,
 * or its shuffle — with the voted tracks lifted out and put back immediately behind
 * whatever is playing. Derived from the base every time, never from its own last answer:
 * see {@see orderFor} for the three bugs that came of doing it the other way, and
 * {@see Ordering::promoteVoted} for the ordering itself.
 */
class VoteService {

	/**
	 * How often the running order may actually change.
	 *
	 * Long enough that a burst of votes produces one reorder rather than a dozen; short
	 * enough that voting still feels like it does something.
	 */
	public const RECOMPUTE_EVERY_SECONDS = 20;

	/**
	 * Mirrors PRELOAD_LEAD_MS in src/utils/syncConstants.js.
	 *
	 * Kept a little wider than the client's value: the cost of being slightly too cautious
	 * is a voted track arriving one place later, and the cost of being slightly too eager
	 * is every listener hearing the wrong track and then jumping.
	 */
	public const BOUNDARY_GUARD_MS = 20_000;

	/** Gaps between positions, so the column reads like the other two orderings. */
	private const ORDER_STEP = 1000;

	public function __construct(
		private VoteMapper $voteMapper,
		private TrackMapper $trackMapper,
		private ChannelMapper $channelMapper,
		private TimelineService $timelineService,
		private Clock $clock,
	) {
	}

	// -------------------------------------------------------------- casting

	/**
	 * Vote for a track, or take the vote back.
	 *
	 * Idempotent in both directions by way of the unique index: a double-click that lands
	 * twice produces one vote, and withdrawing something already withdrawn is not an
	 * error. What comes back is the state after, so a client never has to guess.
	 *
	 * @return array{voted: bool, votes: int}
	 * @throws MusicRadioException
	 */
	public function toggle(Channel $channel, int $trackId, string $voterKey): array {
		if (!$channel->getAllowVoting()) {
			throw new MusicRadioException('Voting is off for this channel', Http::STATUS_FORBIDDEN);
		}

		$track = $this->requireVotableTrack($channel, $trackId);

		$voted = $this->voteMapper->withdraw($trackId, $voterKey)
			? false
			: $this->cast($channel, $track, $voterKey);

		// So everybody else's page finds out. A vote changes no ordering by itself, so it
		// moves no counter that would make listeners refetch — which meant, until this
		// existed, that a vote was invisible to everyone but the person who cast it.
		$this->bumpVoteVersion($channel);

		// Deliberately outside the timeline. See the class docblock: this is the whole
		// reason voting does not re-anchor the broadcast on every press.
		$this->recomputeIfDue($channel);

		return [
			'voted' => $voted,
			'votes' => $this->voteMapper->countsForChannel($channel->getId())[$trackId] ?? 0,
		];
	}

	/**
	 * Move the counter clients watch for new vote counts.
	 *
	 * Its own counter rather than `playlistVersion`, which would re-anchor the timeline and
	 * drop every listener to a three-second poll for a minute — for a change that did not
	 * alter what is playing.
	 */
	private function bumpVoteVersion(Channel $channel): void {
		$channel->setVoteVersion((int)$channel->getVoteVersion() + 1);

		// Also marks the channel as recently changed, which is what pollAfterMs reads to
		// decide how often listeners ask. Without it a vote was picked up on the *idle*
		// interval — correct, but up to ten seconds later, which reads as "the counts do
		// not update" rather than "they update shortly".
		//
		// Safe because `updatedAt` feeds nothing else: it is not the timeline anchor and
		// not a concurrency token, only the poll interval and a displayed timestamp.
		$channel->setUpdatedAt($this->clock->nowSeconds());

		$this->channelMapper->update($channel);
	}

	/**
	 * @throws MusicRadioException
	 */
	private function requireVotableTrack(Channel $channel, int $trackId): Track {
		try {
			$track = $this->trackMapper->find($trackId, $channel->getId());
		} catch (\Throwable) {
			throw new MusicRadioException('No such track on this channel', Http::STATUS_NOT_FOUND);
		}

		// Voting for something that cannot play is not meaningful — it would sit at the
		// top of the order and never be reached, and its votes would never be spent.
		if (!$track->isPlayable()) {
			throw new MusicRadioException('That track is not in the rotation', Http::STATUS_CONFLICT);
		}

		return $track;
	}

	private function cast(Channel $channel, Track $track, string $voterKey): bool {
		$vote = new Vote();
		$vote->setChannelId($channel->getId());
		$vote->setTrackId($track->getId());
		$vote->setVoterKey($voterKey);
		$vote->setCreatedAt($this->clock->nowSeconds());

		try {
			$this->voteMapper->insert($vote);
		} catch (DbException $e) {
			// The unique index doing its job: two requests raced and the other one won.
			// The outcome the caller wanted is the outcome they have.
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}

		return true;
	}

	// -------------------------------------------------------------- reading

	/**
	 * The voting state of a channel, for rendering the playlist.
	 *
	 * Two queries for the whole channel rather than one per track — every row needs both
	 * its count and whether this person is one of them.
	 *
	 * @return array{counts: array<int, int>, mine: list<int>}
	 */
	public function stateFor(Channel $channel, ?string $voterKey): array {
		if (!$channel->getAllowVoting()) {
			return ['counts' => [], 'mine' => []];
		}

		return [
			'counts' => $this->voteMapper->countsForChannel($channel->getId()),
			'mine' => $voterKey === null ? [] : $this->voteMapper->trackIdsVotedForBy($channel->getId(), $voterKey),
		];
	}

	// ---------------------------------------------------------- reordering

	/**
	 * Rewrite the running order, if now is a moment when that is safe and useful.
	 *
	 * Returns whether it did, which is only of interest to tests and the periodic job —
	 * callers on the request path do not care and must not wait on it.
	 *
	 * Runs on shuffled channels too. It used to refuse, on the reasoning that shuffle is
	 * an instruction to randomise and a request for a particular track next contradicts
	 * it. They do not contradict: the shuffle decides the order of everything nobody has
	 * asked for, and it is still doing that job while a request jumps the queue. The
	 * refusal meant every vote cast on a shuffled channel was accepted, counted, shown on
	 * screen and then silently ignored.
	 */
	public function recomputeIfDue(Channel $channel, bool $force = false): bool {
		if (!$channel->getAllowVoting()) {
			return false;
		}

		$now = $this->clock->nowSeconds();
		if (!$force && $now - $channel->getVoteOrderedAt() < self::RECOMPUTE_EVERY_SECONDS) {
			return false;
		}

		$tally = $this->voteMapper->tallyForChannel($channel->getId());
		$spent = false;

		$inForce = $this->trackMapper->findAllForChannelInPlayOrder($channel);
		$playable = TimelineService::playable($inForce);
		$durations = TimelineService::durations($playable);
		$located = $durations === []
			? null
			: TimelineService::locate($durations, $this->timelineService->position($channel, $durations));

		$current = $located === null ? null : $playable[$located['index']];

		// The reward is spent when the track reaches the front. There is no boundary event
		// to do this on, and this is the one place that reliably knows what is playing.
		if ($current !== null && ($tally[$current->getId()]['votes'] ?? 0) > 0) {
			$this->voteMapper->clearForTrack($current->getId());
			unset($tally[$current->getId()]);
			// Those counts were on everybody's screen a moment ago; say that they are gone.
			$this->bumpVoteVersion($channel);
			$spent = true;
		}

		$order = $this->orderFor($channel, $inForce, $current, $this->pinnedNext($playable, $located), $tally);
		if ($order === null) {
			// Nothing moved, but votes may still have been spent above — that is a real
			// change and the caller should hear about it.
			$this->stampOrderedAt($channel, $now);

			return $spent;
		}
		$this->apply($channel, $order, $now);

		return true;
	}

	/**
	 * The track behind the playing one that a rewrite must not move, if there is one.
	 *
	 * When the boundary is close enough that clients have already loaded the next track
	 * into their idle audio element, that track is spoken for: `advanceLocally()` crosses
	 * the boundary using the cached value before asking the server anything, so moving it
	 * makes every listener briefly play the wrong thing and then hard-seek.
	 *
	 * Pinning rather than refusing, which is what this did first and was wrong. The guard
	 * is twenty seconds and a track can easily be shorter than that — a channel of
	 * two-minute songs would have reordered happily while a channel of ten-second jingles
	 * could never be reordered at all, because every moment in it is close to a boundary.
	 * Pinning costs a voted track one place in the queue; refusing cost the whole feature
	 * on short tracks.
	 *
	 * @param list<Track> $playable
	 * @param array{index: int, offsetMs: int}|null $located
	 */
	private function pinnedNext(array $playable, ?array $located): ?Track {
		if ($located === null) {
			return null;
		}

		$current = $playable[$located['index']];

		if ((int)$current->getDurationMs() - $located['offsetMs'] > self::BOUNDARY_GUARD_MS) {
			// Nobody has preloaded anything yet; the whole tail is free to move.
			return null;
		}

		// Wrapping to the top when the current track is the last one: on a looping channel
		// that really is what plays next, and on one that is ending there is nothing after
		// this to protect anyway.
		$next = $playable[$located['index'] + 1] ?? ($playable[0] ?? null);

		return $next === null || $next->getId() === $current->getId() ? null : $next;
	}

	/**
	 * The order votes are asking for, or null if it is the order already in force.
	 *
	 * Built from the channel's **base** order — the author's arrangement, or the shuffle —
	 * and not from the running order this last produced. That is the difference between a
	 * running order and a drifting one, and three separate faults came out of getting it
	 * the other way round:
	 *
	 *  - Every track boundary rewrote the whole order even on a channel with no votes at
	 *    all, because the playing track was rotated to index 0 each time. That bumped
	 *    `playlist_version` and sent every listener back for the track list, for nothing.
	 *  - The author's arrangement was unrecoverable: after a few votes `vote_order` bore
	 *    no relation to it, and it could only be got back by turning voting off.
	 *  - `vote_order` had to be maintained by every path that touched a playlist, and it
	 *    was not — it defaulted to zero, so switching voting on dropped the whole playlist
	 *    into row-id order, and a newly added track went straight to the front of the
	 *    queue ahead of tracks people had actually voted for.
	 *
	 * Recomputing from the base makes all three impossible rather than fixed: with no
	 * votes the answer *is* the base order, so a channel nobody votes on writes nothing.
	 *
	 * @param list<Track> $inForce the order currently being broadcast
	 * @param array<int, array{votes: int, firstAt: int}> $tally
	 * @return list<Track>|null
	 */
	private function orderFor(
		Channel $channel,
		array $inForce,
		?Track $current,
		?Track $pinnedNext,
		array $tally,
	): ?array {
		$order = Ordering::promoteVoted(
			$this->trackMapper->findAllForChannelInBaseOrder($channel),
			$current === null ? null : (int)$current->getId(),
			$pinnedNext === null ? null : (int)$pinnedNext->getId(),
			$tally,
		);

		$ids = static fn (array $tracks): array => array_map(static fn (Track $t): int => (int)$t->getId(), $tracks);

		// Compared against what is actually being broadcast, not against the base it was
		// derived from. Otherwise the first recompute after voting is switched on decides it
		// has nothing to do, and the channel goes on playing whatever `vote_order` happened
		// to hold — which, before it had ever been written, was row-id order.
		return $ids($inForce) === $ids($order) ? null : $order;
	}

	/**
	 * Write the new running order.
	 *
	 * Through the timeline guard rather than round it. The bespoke version this replaces
	 * rotated the playing track to index 0 and set the anchor to its offset within itself,
	 * which does preserve the position but also rewrites the order on every boundary
	 * whether or not anybody voted. withPreservedPosition() makes the weaker and correct
	 * promise — the same track, at the same offset, wherever it now sits — so a track can
	 * be pulled forward from behind the playhead without the order having to be rotated to
	 * accommodate it.
	 *
	 * @param list<Track> $order
	 */
	private function apply(Channel $channel, array $order, int $now): void {
		$updated = $this->timelineService->withPreservedPosition($channel, function () use ($channel, $order): void {
			$position = 0;
			foreach ($order as $track) {
				$position += self::ORDER_STEP;
				$this->trackMapper->updateOrder(
					(int)$track->getId(),
					$channel->getId(),
					TrackMapper::ORDER_VOTE,
					$position,
				);
			}
		});

		$updated->setVoteOrderedAt($now);
		$updated->setUpdatedAt($now);
		$this->channelMapper->update($updated);
	}

	private function stampOrderedAt(Channel $channel, int $now): void {
		$channel->setVoteOrderedAt($now);
		$this->channelMapper->update($channel);
	}

	// ------------------------------------------------------------ tidying up

	/**
	 * Rows nothing points at any more, and rows too old to still mean anything.
	 *
	 * Removing a track does not cascade, and a channel nobody has voted on for weeks
	 * should not have its old votes spring back into effect the next time it plays.
	 *
	 * @return array{orphaned: int, stale: int}
	 */
	public function sweep(int $maxAgeSeconds): array {
		return [
			'orphaned' => $this->voteMapper->deleteOrphaned(),
			'stale' => $this->voteMapper->deleteOlderThan($this->clock->nowSeconds() - $maxAgeSeconds),
		];
	}
}
