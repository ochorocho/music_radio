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

/**
 * The broadcast timeline.
 *
 * A channel's playlist is treated as one continuous programme: the playable tracks
 * concatenated end to end. A channel stores only an anchor — the programme position
 * (`epoch_offset_ms`) that was current at a wall-clock instant (`started_at_ms`) — and
 * everything else is derived:
 *
 *     raw = paused ? epoch_offset_ms : epoch_offset_ms + (now - started_at_ms)
 *     P   = loop ? raw mod total : raw
 *
 * Keeping the current track derived rather than stored means there is exactly one
 * source of truth, so nothing can drift out of agreement with anything else.
 *
 * The subtle part is that editing the playlist moves the programme underneath a
 * position that is already ticking. Appending changes `total` and therefore where the
 * loop wraps; removing a track earlier in the order shifts every later track backwards;
 * a duration arriving from the probe inserts a track into the middle of the timeline.
 * Left alone, every one of those makes listeners jump. withPreservedPosition() is the
 * single wrapper that prevents it — see its docblock.
 */
class TimelineService {

	public function __construct(
		private ChannelMapper $channelMapper,
		private TrackMapper $trackMapper,
		private Clock $clock,
	) {
	}

	// ---------------------------------------------------------------- pure helpers

	/**
	 * The tracks that take part in the programme, in broadcast order. A track with no
	 * known duration cannot be placed on the timeline, and an unreadable one would
	 * broadcast silence, so both are skipped.
	 *
	 * @param Track[] $tracks
	 * @return Track[]
	 */
	public static function playable(array $tracks): array {
		return array_values(array_filter($tracks, static fn (Track $t): bool => $t->isPlayable()));
	}

	/**
	 * @param Track[] $playable
	 * @return int[]
	 */
	public static function durations(array $playable): array {
		return array_map(static fn (Track $t): int => (int)$t->getDurationMs(), $playable);
	}

	/**
	 * @param int[] $durations
	 */
	public static function total(array $durations): int {
		return array_sum($durations);
	}

	/**
	 * Programme position at which the track at $index starts.
	 *
	 * @param int[] $durations
	 */
	public static function prefixAt(array $durations, int $index): int {
		$sum = 0;
		for ($i = 0; $i < $index && $i < count($durations); $i++) {
			$sum += $durations[$i];
		}

		return $sum;
	}

	/**
	 * Resolve a programme position to a track index and the offset into that track.
	 * Returns null when the position falls outside the programme — an empty playlist,
	 * or a non-looping channel that has run past the end.
	 *
	 * @param int[] $durations
	 * @return array{index: int, offsetMs: int}|null
	 */
	public static function locate(array $durations, int $position): ?array {
		if ($durations === [] || $position < 0) {
			return null;
		}

		$cursor = 0;
		foreach ($durations as $index => $duration) {
			if ($position < $cursor + $duration) {
				return ['index' => $index, 'offsetMs' => $position - $cursor];
			}
			$cursor += $duration;
		}

		return null;
	}

	/**
	 * Positive modulo. PHP's % keeps the sign of the dividend, which would produce a
	 * negative programme position if a client's anchor is ever ahead of server time.
	 */
	public static function wrap(int $position, int $total): int {
		if ($total <= 0) {
			return 0;
		}

		return (($position % $total) + $total) % $total;
	}

	// ------------------------------------------------------------ channel position

	/**
	 * The channel's raw programme position — unwrapped, so a non-looping channel that
	 * has finished reports a position past `total`, which is how "ended" is detected.
	 */
	public function rawPosition(Channel $channel, ?int $nowMs = null): int {
		if ($channel->getPaused()) {
			return $channel->getEpochOffsetMs();
		}

		$nowMs ??= $this->clock->nowMillis();
		$elapsed = $nowMs - $channel->getStartedAtMs();

		return $channel->getEpochOffsetMs() + max(0, $elapsed);
	}

	/**
	 * The channel's effective programme position, wrapped into the current cycle when
	 * the channel loops.
	 *
	 * @param int[] $durations
	 */
	public function position(Channel $channel, array $durations, ?int $nowMs = null): int {
		$raw = $this->rawPosition($channel, $nowMs);
		$total = self::total($durations);

		if ($channel->getLoopEnabled() && $total > 0) {
			return self::wrap($raw, $total);
		}

		return $raw;
	}

	// --------------------------------------------------------------- the guard rail

	/**
	 * Run a playlist mutation without moving what listeners are currently hearing.
	 *
	 * EVERY write that changes a channel's track set, their order, their durations or
	 * their availability must go through this. A bare UPDATE is a silent desync bug —
	 * and the non-obvious cases are the ones that bite:
	 *
	 *  - Appending looks harmless, but when the channel loops it grows `total` and so
	 *    moves the wrap boundary; every listener who had already wrapped jumps.
	 *  - A duration arriving from the async probe promotes a track from "not on the
	 *    timeline" to "on the timeline", shifting everything after it.
	 *
	 * The rule enforced here: the track being heard, and the offset into it, are the
	 * same before and after. The one deliberate exception is removing the track that is
	 * currently playing, which cuts to the start of whatever now follows it — the person
	 * who removed it meant to stop hearing it.
	 *
	 * @param callable():void $mutate
	 */
	public function withPreservedPosition(Channel $channel, callable $mutate): Channel {
		$nowMs = $this->clock->nowMillis();

		$before = $this->trackMapper->findAllForChannelInPlayOrder($channel);
		$playableBefore = self::playable($before);
		$durationsBefore = self::durations($playableBefore);
		$positionBefore = $this->position($channel, $durationsBefore, $nowMs);
		$anchor = self::locate($durationsBefore, $positionBefore);

		// The ordered ids as they were, so a removed anchor can fall through to whatever
		// followed it rather than to an arbitrary track.
		$idsBefore = array_map(static fn (Track $t): int => $t->getId(), $playableBefore);

		$mutate();

		$after = $this->trackMapper->findAllForChannelInPlayOrder($channel);
		$playableAfter = self::playable($after);
		$durationsAfter = self::durations($playableAfter);
		$totalAfter = self::total($durationsAfter);

		$indexById = [];
		foreach ($playableAfter as $index => $track) {
			$indexById[$track->getId()] = $index;
		}

		if ($anchor === null && $durationsBefore === []) {
			// The playlist was empty, so the channel was "playing" nothing. Time spent
			// against an empty playlist is not programme time — a channel left playing
			// and empty overnight must not skip hours into the first track someone adds.
			$newPosition = 0;
		} elseif ($anchor === null) {
			// The playlist was not empty but a non-looping channel had run past the end.
			// Clamp the old position into the new programme, so adding a track to a
			// finished channel picks up where it stopped rather than starting over.
			$newPosition = $totalAfter > 0 ? min($positionBefore, $totalAfter) : 0;
		} else {
			$anchorId = $idsBefore[$anchor['index']];

			if (isset($indexById[$anchorId])) {
				// The normal path: same track, same offset, wherever it now sits.
				$newPosition = self::prefixAt($durationsAfter, $indexById[$anchorId]) + $anchor['offsetMs'];
			} else {
				// The anchor track is gone. Cut to the start of the first track that
				// followed it and is still here; wrap to the top if none is.
				$newPosition = 0;
				for ($i = $anchor['index'] + 1; $i < count($idsBefore); $i++) {
					if (isset($indexById[$idsBefore[$i]])) {
						$newPosition = self::prefixAt($durationsAfter, $indexById[$idsBefore[$i]]);
						break;
					}
				}
			}
		}

		if ($totalAfter > 0) {
			$newPosition = max(0, min($newPosition, $totalAfter));
		} else {
			$newPosition = 0;
		}

		return $this->reanchor($channel, $newPosition, $nowMs, true);
	}

	/**
	 * Move the anchor to a programme position, as of now.
	 *
	 * @param bool $playlistChanged also bump playlist_version, so clients refetch the
	 *                              track list rather than just the (small) state payload
	 */
	public function reanchor(Channel $channel, int $position, ?int $nowMs = null, bool $playlistChanged = false): Channel {
		$nowMs ??= $this->clock->nowMillis();

		$channel->setEpochOffsetMs(max(0, $position));
		$channel->setStartedAtMs($nowMs);
		$channel->setStateVersion($channel->getStateVersion() + 1);
		if ($playlistChanged) {
			$channel->setPlaylistVersion($channel->getPlaylistVersion() + 1);
		}
		$channel->setUpdatedAt($this->clock->nowSeconds());

		return $this->channelMapper->update($channel);
	}
}
