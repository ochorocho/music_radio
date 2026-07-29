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
		private Clock $clock,
		private ISecureRandom $secureRandom,
	) {
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
	 * @return array<string, mixed>
	 */
	public function buildState(Channel $channel, int $permissions, int $requestReceivedAtMs): array {
		$nowMs = $this->clock->nowMillis();

		$tracks = $this->trackMapper->findAllForChannelInPlayOrder($channel->getId(), $channel->getShuffle());
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
			'status' => $status,
			'loop' => $channel->getLoopEnabled(),
			'shuffle' => $channel->getShuffle(),
			'totalDurationMs' => $total,
			'trackCount' => count($tracks),
			'playableCount' => count($playable),
			'programmePositionMs' => $position,
			'current' => $current,
			'next' => $next,
			'permissions' => $permissions,
			'pollAfterMs' => $this->pollAfterMs($channel, $permissions),
			// Jellyfin-style clock handshake: with these three the client can estimate
			// its offset from server time and the round-trip delay, and keep the sample
			// with the lowest delay.
			'requestReceivedAtMs' => $requestReceivedAtMs,
			'serverTimeMs' => $nowMs,
			'responseSentAtMs' => $this->clock->nowMillis(),
		];
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
			}
			$channel->setShuffle($shuffle);
		}

		$channel->setStateVersion($channel->getStateVersion() + 1);
		$channel->setUpdatedAt($this->clock->nowSeconds());

		return $this->channelMapper->update($channel);
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

		$currentId = $located === null ? null : $playable[$located['index']]->getId();
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

		$order = 0;
		foreach ([...$pinned, ...$rest] as $track) {
			$order += 1000;
			$track->setShuffleOrder($order);
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
			$this->trackMapper->findAllForChannelInPlayOrder($channel->getId(), $channel->getShuffle()),
		);
	}

	/**
	 * @return int[]
	 */
	private function durationsOf(Channel $channel): array {
		return TimelineService::durations($this->playableOf($channel));
	}
}
