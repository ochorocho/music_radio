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
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCP\AppFramework\Http;
use OCP\Security\ISecureRandom;

/**
 * The broadcast: reading its live state, and being the DJ.
 *
 * Everything a listener needs is derived here from the channel's anchor, so the client
 * never has to reason about the playlist at all — it is told which track is playing and
 * when that track started, and only has to correct for the offset between its clock and
 * the server's.
 */
class PlaybackService {

	/** How often a listener should poll while nothing is changing. */
	public const POLL_IDLE_MS = 10_000;
	/** …and just after a change, so a skip reaches everyone quickly. */
	public const POLL_ACTIVE_MS = 3_000;
	/** How long "just after a change" lasts. */
	private const RECENTLY_CHANGED_SECONDS = 60;
	/** Someone who can drive playback needs their own controls to feel immediate. */
	public const POLL_CONTROLLER_MS = 2_500;

	/**
	 * Pressing "previous" this far into a track restarts it instead of going back —
	 * the behaviour every music player has.
	 */
	private const PREVIOUS_RESTART_THRESHOLD_MS = 3_000;

	public const STATUS_EMPTY = 'empty';
	public const STATUS_PAUSED = 'paused';
	public const STATUS_PLAYING = 'playing';
	public const STATUS_ENDED = 'ended';

	public function __construct(
		private ChannelMapper $channelMapper,
		private TrackMapper $trackMapper,
		private TimelineService $timelineService,
		private VoteService $voteService,
		private Clock $clock,
		private ISecureRandom $secureRandom,
	) {
	}

	// -------------------------------------------------------------- maintenance

	/**
	 * Keep the running order current. Called from the state endpoints, signed-in and
	 * anonymous alike, before the state is built.
	 *
	 * Both things below need to happen while a channel plays and neither has an event to
	 * hang itself on: a channel is one continuous programme, so there is no track boundary
	 * and no end-of-cycle for anything to fire on. The poll is the one thing that reliably
	 * happens.
	 *
	 * What it costs a poll: nothing on a channel that neither shuffles nor takes votes,
	 * both halves being decided by flags already loaded. A shuffled looping channel adds
	 * one indexed read of its tracks, because whether it has come round can only be
	 * answered against the length of its programme. A channel that takes votes adds a
	 * second read and a tally, at most once every {@see VoteService::RECOMPUTE_EVERY_SECONDS}
	 * however many people are listening.
	 *
	 * Order matters. The reshuffle rewrites the base order, and the vote recompute derives
	 * the running order from it — the other way round would spend a cycle playing votes
	 * promoted into an order that no longer exists.
	 */
	public function maintainRunningOrder(Channel $channel): Channel {
		$channel = $this->reshuffleIfCycleComplete($channel);

		if ($channel->getAllowVoting()) {
			$this->voteService->recomputeIfDue($channel);
		}

		return $channel;
	}

	/**
	 * Draw a new order when a looping channel comes back round to the top.
	 *
	 * A shuffle used to be drawn once, when somebody switched shuffle on, and never again
	 * — so a looping channel played the identical sequence every cycle for as long as it
	 * was left running. That is a shuffled playlist, not shuffle, and it is the loudest
	 * part of "shuffle does not work properly": the second time round is indistinguishable
	 * from having it turned off.
	 *
	 * The wrap is detected rather than signalled. `rawPosition` keeps counting past the
	 * end of the programme while `position` wraps it, so raw exceeding the total is
	 * exactly the statement "this channel has been round". Every re-anchor resets raw to
	 * wherever the playhead actually is, so this stays true after a vote reorder or an
	 * edit — those move the wrap nearer, correctly, because they moved the playhead.
	 */
	private function reshuffleIfCycleComplete(Channel $channel): Channel {
		if (!$channel->getShuffle() || !$channel->getLoopEnabled() || $channel->getPaused()) {
			// Not looping means the programme ends rather than coming round, and a paused
			// channel would otherwise redraw on every poll once its frozen position was
			// past the end.
			return $channel;
		}

		$playable = $this->playableOf($channel);
		$total = TimelineService::total(TimelineService::durations($playable));
		if ($total <= 0 || $this->timelineService->rawPosition($channel) < $total) {
			return $channel;
		}

		// Fewer than three and there is nothing left to arrange. Whatever is playing at the
		// wrap keeps its place at the head of the new cycle, so a two-track channel would
		// redraw the order it already had — and tell every listener to refetch the track
		// list for it, once per cycle, indefinitely.
		if (count($playable) < 3) {
			return $channel;
		}

		$this->materialiseShuffle($channel);
		$channel->setStateVersion($channel->getStateVersion() + 1);
		$channel->setUpdatedAt($this->clock->nowSeconds());

		return $this->channelMapper->update($channel);
	}

	// ------------------------------------------------------------------ reading

	/**
	 * The live state of a channel.
	 *
	 * Deliberately a pure read — it never writes. A channel with listeners gets this
	 * called every few seconds by every one of them, and taking a write lock on that
	 * path would be self-inflicted contention. "Ended" is therefore derived rather than
	 * persisted.
	 *
	 * @param int $requestReceivedAtMs when this request began, for the client's clock
	 *                                 offset estimate
	 * @param int|null $listenerCount how many people are tuned in, counted by
	 *                                {@see ListenerPresence} in the controller — this
	 *                                method stays a pure read and only decides who is
	 *                                allowed to see the number. Null means nobody counted.
	 * @param bool|null $maySeeListeners whether this viewer's share publishes the figure,
	 *                                   resolved by {@see PermissionService::shareRulesFor}.
	 *                                   Passed in for the same reason as the count itself:
	 *                                   working it out needs the share, and looking that up
	 *                                   here would make this a read that queries.
	 * @return array<string, mixed>
	 */
	public function buildState(
		Channel $channel,
		int $permissions,
		int $requestReceivedAtMs,
		?int $listenerCount = null,
		?bool $maySeeListeners = null,
	): array {
		$nowMs = $this->clock->nowMillis();

		$tracks = $this->trackMapper->findAllForChannelInPlayOrder($channel);
		$playable = TimelineService::playable($tracks);
		$durations = TimelineService::durations($playable);
		$total = TimelineService::total($durations);

		$raw = $this->timelineService->rawPosition($channel, $nowMs);
		$position = $this->timelineService->position($channel, $durations, $nowMs);
		$located = TimelineService::locate($durations, $position);

		$status = $this->status($channel, $total, $raw, $located !== null);

		$current = null;
		$next = null;
		if ($located !== null) {
			$track = $playable[$located['index']];
			$current = [
				'trackId' => $track->getId(),
				'index' => $located['index'],
				'offsetMs' => $located['offsetMs'],
				'durationMs' => (int)$track->getDurationMs(),
				'title' => $track->getTitle(),
				'artist' => $track->getArtist(),
				// The instant this track began, on the server's clock. The client adds
				// its measured clock offset and derives its own playback position from
				// this — which is what keeps every listener together.
				'startedAtMs' => $nowMs - $located['offsetMs'],
				'endsInMs' => (int)$track->getDurationMs() - $located['offsetMs'],
			];

			$nextTrack = $this->nextTrack($playable, $located['index'], $channel->getLoopEnabled());
			if ($nextTrack !== null) {
				$next = [
					'trackId' => $nextTrack->getId(),
					'durationMs' => (int)$nextTrack->getDurationMs(),
					'title' => $nextTrack->getTitle(),
					'artist' => $nextTrack->getArtist(),
				];
			}
		}

		return [
			'channelId' => $channel->getId(),
			'stateVersion' => $channel->getStateVersion(),
			'playlistVersion' => $channel->getPlaylistVersion(),
			// Moves when somebody votes. Separate from playlistVersion on purpose: a vote
			// must not re-anchor the timeline or drop everyone to a fast poll.
			'voteVersion' => (int)$channel->getVoteVersion(),
			'status' => $status,
			'loop' => $channel->getLoopEnabled(),
			'shuffle' => $channel->getShuffle(),
			'totalDurationMs' => $total,
			'trackCount' => count($tracks),
			'playableCount' => count($playable),
			'programmePositionMs' => $position,
			// Whether the programme endpoint will hand over the whole thing at once, for
			// the element to loop by itself. Only an offer: the client checks that what it
			// actually received is a full lap before looping it, because a lap the server
			// could not complete would otherwise be repeated as though it were the
			// programme. See ProgrammeStreamService::lap().
			'programmeLoops' => $channel->getLoopEnabled()
				&& $total > 0
				&& $total <= ProgrammeStreamService::BUDGET_MS,
			'current' => $current,
			'next' => $next,
			'permissions' => $permissions,
			'listenerCount' => self::visibleListenerCount($permissions, $listenerCount, $maySeeListeners),
			'pollAfterMs' => $this->pollAfterMs($channel, $permissions),
			// Jellyfin-style clock handshake: with these three the client can estimate
			// its offset from server time and the round-trip delay, and keep the sample
			// with the lowest delay.
			'requestReceivedAtMs' => $requestReceivedAtMs,
			'serverTimeMs' => $nowMs,
			'responseSentAtMs' => $this->clock->nowMillis(),
		];
	}

	/**
	 * Who gets told how many people are listening.
	 *
	 * Whoever manages the channel always does — that is what the number was asked for. For
	 * everyone else it is the share they came through that decides, so an owner can publish
	 * the figure to the people they named while keeping it from a link handed to strangers.
	 *
	 * Withheld as null rather than as zero: the two are told apart by the page, and a
	 * channel that genuinely has nobody listening should not look like one that is
	 * keeping it to itself.
	 */
	private static function visibleListenerCount(int $permissions, ?int $count, ?bool $maySee): ?int {
		if ($count === null) {
			return null;
		}

		if (($permissions & Permission::MANAGE) !== 0) {
			return $count;
		}

		return $maySee === true ? $count : null;
	}

	private function status(Channel $channel, int $total, int $raw, bool $located): string {
		if ($total <= 0) {
			return self::STATUS_EMPTY;
		}
		if ($channel->getPaused()) {
			return self::STATUS_PAUSED;
		}
		if (!$located && !$channel->getLoopEnabled() && $raw >= $total) {
			return self::STATUS_ENDED;
		}

		return self::STATUS_PLAYING;
	}

	/**
	 * @param Track[] $playable
	 */
	private function nextTrack(array $playable, int $index, bool $loop): ?Track {
		if (isset($playable[$index + 1])) {
			return $playable[$index + 1];
		}

		return $loop ? ($playable[0] ?? null) : null;
	}

	/**
	 * Server-driven polling interval.
	 *
	 * Listeners poll slowly at rest — track progression is computed locally, so polling
	 * only has to notice *changes*. Right after one, everyone speeds up briefly so a
	 * skip propagates in seconds rather than tens of seconds, then settles back down.
	 */
	private function pollAfterMs(Channel $channel, int $permissions): int {
		if (Permission::has($permissions, Permission::CONTROL)) {
			return self::POLL_CONTROLLER_MS;
		}

		$changedSecondsAgo = $this->clock->nowSeconds() - $channel->getUpdatedAt();

		return $changedSecondsAgo <= self::RECENTLY_CHANGED_SECONDS
			? self::POLL_ACTIVE_MS
			: self::POLL_IDLE_MS;
	}

	// ------------------------------------------------------------------ control

	/**
	 * Start (or resume) the broadcast.
	 */
	public function play(Channel $channel): Channel {
		if (!$channel->getPaused()) {
			return $channel;
		}

		// The anchor already holds the position it was paused at; restarting the clock
		// from now is all that is needed.
		$channel->setPaused(false);

		return $this->timelineService->reanchor($channel, $channel->getEpochOffsetMs());
	}

	/**
	 * Freeze the broadcast where it is.
	 */
	public function pause(Channel $channel): Channel {
		if ($channel->getPaused()) {
			return $channel;
		}

		// Store the position it had reached, wrapped into the current cycle, so resuming
		// picks up exactly there rather than replaying an accumulated offset.
		$position = $this->timelineService->position($channel, $this->durationsOf($channel));
		$channel->setPaused(true);

		return $this->timelineService->reanchor($channel, $position);
	}

	/**
	 * Jump to the start of the next track.
	 */
	public function next(Channel $channel): Channel {
		$playable = $this->playableOf($channel);
		$durations = TimelineService::durations($playable);
		if ($durations === []) {
			throw new MusicRadioException('This channel has nothing to play', Http::STATUS_CONFLICT);
		}

		$located = TimelineService::locate($durations, $this->timelineService->position($channel, $durations));
		$index = $located === null ? 0 : $located['index'] + 1;

		if ($index >= count($durations)) {
			if (!$channel->getLoopEnabled()) {
				// Skipping past the last track of a non-looping channel ends it.
				return $this->timelineService->reanchor($channel, TimelineService::total($durations));
			}
			$index = 0;
		}

		return $this->timelineService->reanchor($channel, TimelineService::prefixAt($durations, $index));
	}

	/**
	 * Go back — or restart the current track, if it only just started.
	 */
	public function previous(Channel $channel): Channel {
		$playable = $this->playableOf($channel);
		$durations = TimelineService::durations($playable);
		if ($durations === []) {
			throw new MusicRadioException('This channel has nothing to play', Http::STATUS_CONFLICT);
		}

		$located = TimelineService::locate($durations, $this->timelineService->position($channel, $durations));
		if ($located === null) {
			return $this->timelineService->reanchor($channel, 0);
		}

		// Standard media-player behaviour: part-way through a track, "previous" means
		// "start this one again".
		if ($located['offsetMs'] > self::PREVIOUS_RESTART_THRESHOLD_MS) {
			return $this->timelineService->reanchor($channel, TimelineService::prefixAt($durations, $located['index']));
		}

		$index = $located['index'] - 1;
		if ($index < 0) {
			$index = $channel->getLoopEnabled() ? count($durations) - 1 : 0;
		}

		return $this->timelineService->reanchor($channel, TimelineService::prefixAt($durations, $index));
	}

	/**
	 * Move to a position within the track that is currently playing.
	 */
	public function seek(Channel $channel, int $offsetMs): Channel {
		$playable = $this->playableOf($channel);
		$durations = TimelineService::durations($playable);
		if ($durations === []) {
			throw new MusicRadioException('This channel has nothing to play', Http::STATUS_CONFLICT);
		}

		$located = TimelineService::locate($durations, $this->timelineService->position($channel, $durations));
		$index = $located['index'] ?? 0;

		$clamped = max(0, min($offsetMs, $durations[$index] - 1));

		return $this->timelineService->reanchor($channel, TimelineService::prefixAt($durations, $index) + $clamped);
	}

	/**
	 * Start a specific track from the beginning.
	 */
	public function jumpTo(Channel $channel, int $trackId): Channel {
		$playable = $this->playableOf($channel);
		$durations = TimelineService::durations($playable);

		foreach ($playable as $index => $track) {
			if ($track->getId() === $trackId) {
				// Picking a track means "play this now", so a paused channel goes on air
				// rather than silently re-anchoring and staying quiet.
				$channel->setPaused(false);

				return $this->timelineService->reanchor($channel, TimelineService::prefixAt($durations, $index));
			}
		}

		throw new MusicRadioException('That track cannot be played right now', Http::STATUS_NOT_FOUND);
	}

	/**
	 * Change looping and shuffling.
	 *
	 * Shuffle has to be agreed on by everyone — a per-listener shuffle would not be one
	 * broadcast — so a shuffled order is generated once, server-side, and stored.
	 */
	public function updateSettings(Channel $channel, ?bool $loop, ?bool $shuffle): Channel {
		if ($loop !== null) {
			$channel->setLoopEnabled($loop);
		}

		if ($shuffle !== null && $shuffle !== $channel->getShuffle()) {
			if ($shuffle) {
				$this->materialiseShuffle($channel);
			} else {
				$this->restoreAuthorOrder($channel);
			}
			$channel->setShuffle($shuffle);
		}

		$channel->setStateVersion($channel->getStateVersion() + 1);
		$channel->setUpdatedAt($this->clock->nowSeconds());

		return $this->channelMapper->update($channel);
	}

	/**
	 * Put a channel back into the order somebody arranged it in.
	 *
	 * The counterpart to materialiseShuffle, and it has to exist rather than the flag
	 * simply being cleared. `sort_order` survives a shuffle untouched, so the *base* order
	 * comes back on its own — but on a channel that takes votes it is `vote_order` that is
	 * played, and that still holds the shuffle until something rewrites it. Clearing the
	 * flag alone left such a channel playing its shuffle, and told no client to refetch
	 * the list, so both the sound and the screen disagreed with the switch that had just
	 * been flipped.
	 *
	 * Inside the timeline guard, and the flag is cleared inside the closure so the guard's
	 * "after" reading is taken against the order being restored to.
	 */
	private function restoreAuthorOrder(Channel $channel): void {
		$this->timelineService->withPreservedPosition($channel, function () use ($channel): void {
			$channel->setShuffle(false);

			$position = 0;
			foreach ($this->trackMapper->findAllForChannel($channel->getId()) as $track) {
				$position += 1000;
				$this->trackMapper->updateOrder(
					(int)$track->getId(),
					$channel->getId(),
					TrackMapper::ORDER_VOTE,
					$position,
				);
			}
		});
	}

	/**
	 * Write a shuffled ordering into the rows, keeping whatever is playing at the front
	 * so switching to shuffle does not interrupt it. The author order (`sort_order`) is
	 * never touched, so turning shuffle off restores it exactly.
	 */
	private function materialiseShuffle(Channel $channel): void {
		$playable = $this->playableOf($channel);
		$durations = TimelineService::durations($playable);
		$located = $durations === []
			? null
			: TimelineService::locate($durations, $this->timelineService->position($channel, $durations));

		$current = $located === null ? null : $playable[$located['index']];
		$currentId = $current?->getId();
		$offsetInTrack = $located['offsetMs'] ?? 0;

		$all = $this->trackMapper->findAllForChannel($channel->getId());

		$pinned = [];
		$rest = [];
		foreach ($all as $track) {
			if ($track->getId() === $currentId) {
				$pinned[] = $track;
			} else {
				$rest[] = $track;
			}
		}

		// Fisher-Yates with a fresh seed, so re-shuffling gives a different order.
		$seed = (int)$this->secureRandom->generate(9, ISecureRandom::CHAR_DIGITS);
		$random = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
		$rest = $random->shuffleArray($rest);

		// …then relaxed so the same band does not come up twice running. A uniform draw is
		// not what anybody means by shuffle — see Ordering::spreadArtists. The pinned track
		// is passed as the predecessor so the relaxation covers the seam it creates.
		$rest = Ordering::spreadArtists($rest, $current?->getArtist());

		$order = 0;
		foreach ([...$pinned, ...$rest] as $track) {
			$order += 1000;
			$track->setShuffleOrder($order);
			// The running order follows the new base immediately. Without this a shuffled
			// channel that also takes votes would keep playing the previous order until the
			// vote recompute next came due, which on a quiet channel is up to twenty
			// seconds of the shuffle everyone was just told about not happening.
			$track->setVoteOrder($order);
			$this->trackMapper->update($track);
		}

		$channel->setShuffleSeed($seed);

		// The programme has been rewritten underneath the anchor, so put the listener
		// back where they were: the current track is now first, at the same offset.
		$channel->setShuffle(true);
		$channel->setEpochOffsetMs($currentId === null ? 0 : $offsetInTrack);
		$channel->setStartedAtMs($this->clock->nowMillis());
		$channel->setPlaylistVersion($channel->getPlaylistVersion() + 1);
	}

	/**
	 * @return Track[]
	 */
	private function playableOf(Channel $channel): array {
		return TimelineService::playable(
			$this->trackMapper->findAllForChannelInPlayOrder($channel),
		);
	}

	/**
	 * @return int[]
	 */
	private function durationsOf(Channel $channel): array {
		return TimelineService::durations($this->playableOf($channel));
	}
}
