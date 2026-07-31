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
	 */
	public function recomputeIfDue(Channel $channel, bool $force = false): bool {
		if (!$channel->getAllowVoting() || $channel->getShuffle()) {
			// Shuffle is a deliberate instruction to randomise; honouring both at once is
			// not a coherent thing to do. See TrackMapper::findAllForChannelInPlayOrder.
			return false;
		}

		$now = $this->clock->nowSeconds();
		if (!$force && $now - $channel->getVoteOrderedAt() < self::RECOMPUTE_EVERY_SECONDS) {
			return false;
		}

		$counts = $this->voteMapper->countsForChannel($channel->getId());
		$spent = false;

		$playable = TimelineService::playable($this->trackMapper->findAllForChannelInPlayOrder($channel));
		$durations = TimelineService::durations($playable);
		$located = $durations === []
			? null
			: TimelineService::locate($durations, $this->timelineService->position($channel, $durations));

		$current = $located === null ? null : $playable[$located['index']];

		// The reward is spent when the track reaches the front. There is no boundary event
		// to do this on, and this is the one place that reliably knows what is playing.
		if ($current !== null && ($counts[$current->getId()] ?? 0) > 0) {
			$this->voteMapper->clearForTrack($current->getId());
			unset($counts[$current->getId()]);
			// Those counts were on everybody's screen a moment ago; say that they are gone.
			$this->bumpVoteVersion($channel);
			$spent = true;
		}

		$order = $this->orderFor($channel, $this->pinned($playable, $located), $counts);
		if ($order === null) {
			// Nothing moved, but votes may still have been spent above — that is a real
			// change and the caller should hear about it.
			$this->stampOrderedAt($channel, $now);

			return $spent;
		}
		$this->apply($channel, $order, $current, $located['offsetMs'] ?? 0, $now);

		return true;
	}

	/**
	 * The tracks a rewrite must not move.
	 *
	 * Always what is playing. And, when the boundary is close enough that clients have
	 * already loaded the next track into their idle audio element, that one too:
	 * `advanceLocally()` crosses the boundary using that cached value before asking the
	 * server anything, so moving it makes every listener briefly play the wrong thing and
	 * then hard-seek.
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
	 * @return list<Track>
	 */
	private function pinned(array $playable, ?array $located): array {
		if ($located === null) {
			return [];
		}

		$current = $playable[$located['index']];
		$pinned = [$current];

		$remaining = (int)$current->getDurationMs() - $located['offsetMs'];
		if ($remaining > self::BOUNDARY_GUARD_MS) {
			// Nobody has preloaded anything yet; the whole tail is free to move.
			return $pinned;
		}

		// Wrapping to the top when the current track is the last one: on a looping channel
		// that really is what plays next, and on one that is ending there is nothing after
		// this to protect anyway.
		$next = $playable[$located['index'] + 1] ?? ($playable[0] ?? null);
		if ($next !== null && $next->getId() !== $current->getId()) {
			$pinned[] = $next;
		}

		return $pinned;
	}

	/**
	 * The order votes are asking for, or null if it is the order already in force.
	 *
	 * Most-voted first, behind whatever is pinned — see {@see pinned()}. Ties keep their
	 * existing relative order, which is what stops an unvoted channel from churning: with
	 * no votes at all this is exactly the current order, and returns null.
	 *
	 * @param list<Track> $pinnedTracks in the order they must keep
	 * @param array<int, int> $counts
	 * @return list<Track>|null
	 */
	private function orderFor(Channel $channel, array $pinnedTracks, array $counts): ?array {
		$all = $this->trackMapper->findAllForChannelInPlayOrder($channel);

		$pinnedIds = [];
		foreach ($pinnedTracks as $track) {
			$pinnedIds[$track->getId()] = true;
		}

		$pinned = [];
		$rest = [];
		foreach ($all as $track) {
			if (isset($pinnedIds[$track->getId()])) {
				$pinned[] = $track;
			} else {
				$rest[] = $track;
			}
		}

		// Pinned rows keep the order pinned() asked for, not the order they happen to sit
		// in: "current, then next" is the whole point, and on a wrapped playlist the next
		// track is the one at the top.
		usort($pinned, static function (Track $a, Track $b) use ($pinnedTracks): int {
			$rank = static function (Track $track) use ($pinnedTracks): int {
				foreach ($pinnedTracks as $index => $candidate) {
					if ($candidate->getId() === $track->getId()) {
						return $index;
					}
				}

				return PHP_INT_MAX;
			};

			return $rank($a) <=> $rank($b);
		});

		// usort is not stable across every PHP configuration this may run on, so the
		// existing index is carried into the comparison as the tiebreak. Without it,
		// tracks with equal votes could swap places on every recompute — which would
		// re-anchor the timeline forever on a channel nobody is voting on.
		$indexed = [];
		foreach ($rest as $index => $track) {
			$indexed[] = ['track' => $track, 'index' => $index];
		}

		usort($indexed, static function (array $a, array $b) use ($counts): int {
			$byVotes = ($counts[$b['track']->getId()] ?? 0) <=> ($counts[$a['track']->getId()] ?? 0);

			return $byVotes !== 0 ? $byVotes : $a['index'] <=> $b['index'];
		});

		$order = [...$pinned, ...array_map(static fn (array $row): Track => $row['track'], $indexed)];

		$before = array_map(static fn (Track $t): int => $t->getId(), $all);
		$after = array_map(static fn (Track $t): int => $t->getId(), $order);

		return $before === $after ? null : $order;
	}

	/**
	 * Write the new order and put the listener back where they were.
	 *
	 * Follows materialiseShuffle exactly, which is the app's existing template for a
	 * wholesale reorder that preserves position: the current track becomes index 0 and
	 * the anchor is set to its offset within itself, so the programme is rewritten
	 * underneath the playhead without the playhead moving.
	 *
	 * @param list<Track> $order
	 */
	private function apply(Channel $channel, array $order, ?Track $current, int $offsetInTrack, int $now): void {
		$position = 0;
		foreach ($order as $track) {
			$position += 1000;
			$track->setVoteOrder($position);
			$this->trackMapper->update($track);
		}

		$channel->setEpochOffsetMs($current === null ? 0 : $offsetInTrack);
		$channel->setStartedAtMs($this->clock->nowMillis());
		$channel->setPlaylistVersion($channel->getPlaylistVersion() + 1);
		$channel->setStateVersion($channel->getStateVersion() + 1);
		$channel->setVoteOrderedAt($now);
		$channel->setUpdatedAt($now);

		$this->channelMapper->update($channel);
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
