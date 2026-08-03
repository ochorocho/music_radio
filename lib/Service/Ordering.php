<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Track;

/**
 * How a playlist becomes a running order.
 *
 * Two questions, both pure functions of a list of tracks, so both can be reasoned about
 * and tested without a channel, a clock or a database. Everything that actually writes an
 * order — PlaybackService for shuffle, VoteService for votes — decides *when* to reorder;
 * this decides *what* the order should be.
 */
final class Ordering {

	/**
	 * Nudge tracks by the same artist apart.
	 *
	 * A Fisher-Yates shuffle is uniform, and uniform is not what anybody means by shuffle:
	 * across twenty tracks a fair draw will happily deal four by the same band in a row,
	 * and every listener reads that as the shuffle being broken.
	 *
	 * The fix is a repair rather than a different algorithm. The draw stands; this walks
	 * it once and, wherever a track landed next to one by the same artist, swaps it with
	 * the nearest later track that resolves the clash without creating another. Nothing
	 * else moves. That matters — the obvious alternative, dealing the artist with the most
	 * tracks left at each slot, avoids adjacency perfectly but is barely random any more:
	 * it produces a near-deterministic round robin, and it herds every track whose ID3 tag
	 * failed to read into one clump at the end, because each of those counts as its own
	 * artist of size one.
	 *
	 * Best effort, and deliberately so. Six tracks by one band out of nine cannot be
	 * spread, and no swap exists for the last of them; the pass leaves that pair adjacent
	 * rather than shuffling the whole thing again to hide it.
	 *
	 * A track with no artist clashes with nothing. An unread tag is missing information,
	 * not a claim that two files are by the same person.
	 *
	 * @param list<Track> $tracks in the order the shuffle drew them
	 * @param string|null $after the artist of whatever plays immediately before this run,
	 *                           so a pinned current track is not followed by itself
	 * @return list<Track>
	 */
	public static function spreadArtists(array $tracks, ?string $after = null): array {
		$count = count($tracks);
		if ($count < 2) {
			return $tracks;
		}

		$keys = array_map(static fn (Track $t): ?string => self::normalise($t->getArtist()), $tracks);
		$previous = self::normalise($after);

		for ($i = 0; $i < $count; $i++) {
			// Only a clash with what comes *before* is worth repairing here: a clash with
			// what comes after is the same clash, and the pass reaches it at the next index.
			if (!self::clash($keys[$i], $i === 0 ? $previous : $keys[$i - 1])) {
				continue;
			}

			for ($j = $i + 1; $j < $count; $j++) {
				if (self::swapIsClean($keys, $i, $j, $previous)) {
					[$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
					[$tracks[$i], $tracks[$j]] = [$tracks[$j], $tracks[$i]];
					break;
				}
			}
		}

		return $tracks;
	}

	/**
	 * Would exchanging positions $i and $j leave both of them free of neighbours by the
	 * same artist?
	 *
	 * Four adjacencies can be disturbed by one swap, and when $i and $j are neighbours
	 * some of those are the same pair seen from both ends — which is why this asks the
	 * hypothetical key at an index rather than mutating a copy of the array per candidate.
	 *
	 * @param list<?string> $keys
	 */
	private static function swapIsClean(array $keys, int $i, int $j, ?string $previous): bool {
		$at = static function (int $index) use ($keys, $i, $j, $previous): ?string {
			if ($index < 0) {
				return $previous;
			}
			if ($index >= count($keys)) {
				// Past the end. The channel may loop round to the top, but the top is a
				// fresh draw by then — see PlaybackService::reshuffleIfCycleComplete.
				return null;
			}

			return match ($index) {
				$i => $keys[$j],
				$j => $keys[$i],
				default => $keys[$index],
			};
		};

		foreach ([$i, $j] as $index) {
			if (self::clash($at($index), $at($index - 1)) || self::clash($at($index), $at($index + 1))) {
				return false;
			}
		}

		return true;
	}

	private static function clash(?string $a, ?string $b): bool {
		return $a !== null && $a === $b;
	}

	/**
	 * Move the tracks people have voted for to the front of what is coming.
	 *
	 * "The front of what is coming" is not the front of the array. A channel is a cycle,
	 * so next means immediately after whatever is playing, wherever that sits — and after
	 * the track behind it too when that one is pinned, because by then every client has
	 * loaded it into its idle audio element, and moving it makes all of them play the
	 * wrong thing for a moment and then hard-seek.
	 *
	 * Order among the voted: most votes first, and on a tie whoever was voted for first.
	 * That tie-break is the point — without it a two-all tie is settled by playlist
	 * position, which is the very thing a vote exists to override. `id` settles the rest,
	 * so the result is a total order and two calls on the same input agree.
	 *
	 * With no votes this hands back its input untouched. That is what lets the caller read
	 * "same order out as in" as "nothing to write", and it is why a channel nobody votes
	 * on never re-anchors its timeline.
	 *
	 * @param list<Track> $order the base order
	 * @param int|null $currentId the track being heard, if any
	 * @param int|null $pinnedNextId the track after it, when clients have already loaded it
	 * @param array<int, array{votes: int, firstAt: int}> $tally by track id
	 * @return list<Track>
	 */
	public static function promoteVoted(
		array $order,
		?int $currentId,
		?int $pinnedNextId,
		array $tally,
	): array {
		$voted = [];
		foreach ($order as $track) {
			$id = (int)$track->getId();
			// What is playing is already at the front of what is coming, and what is pinned
			// behind it must not move at all. Their votes are spent when they play.
			if ($id === $currentId || $id === $pinnedNextId) {
				continue;
			}
			if (($tally[$id]['votes'] ?? 0) > 0) {
				$voted[] = $track;
			}
		}

		if ($voted === []) {
			return $order;
		}

		usort($voted, static function (Track $a, Track $b) use ($tally): int {
			$left = (int)$a->getId();
			$right = (int)$b->getId();

			return (($tally[$right]['votes'] ?? 0) <=> ($tally[$left]['votes'] ?? 0))
				?: (($tally[$left]['firstAt'] ?? 0) <=> ($tally[$right]['firstAt'] ?? 0))
				?: ($left <=> $right);
		});

		$promoted = [];
		foreach ($voted as $track) {
			$promoted[(int)$track->getId()] = true;
		}

		$rest = array_values(array_filter(
			$order,
			static fn (Track $t): bool => !isset($promoted[(int)$t->getId()]),
		));

		$at = self::insertionPoint($rest, $currentId, $pinnedNextId);

		return [...array_slice($rest, 0, $at), ...$voted, ...array_slice($rest, $at)];
	}

	/**
	 * Where in $rest the voted block goes: after the playing track, and after the one
	 * behind it when that is pinned too.
	 *
	 * The awkward case is a pinned pair straddling the end of the array — the playing
	 * track last and the one after it first, because the channel loops round. Inserting
	 * "after the playing track" would then mean at the very end, which is between the two
	 * tracks that were pinned together precisely so that nothing could come between them.
	 * So the block goes after the wrapped one instead.
	 *
	 * @param list<Track> $rest
	 */
	private static function insertionPoint(array $rest, ?int $currentId, ?int $pinnedNextId): int {
		if ($currentId === null) {
			return 0;
		}

		$at = null;
		$nextAt = null;
		foreach ($rest as $index => $track) {
			if ((int)$track->getId() === $currentId) {
				$at = $index;
			} elseif ($pinnedNextId !== null && (int)$track->getId() === $pinnedNextId) {
				$nextAt = $index;
			}
		}

		if ($at === null) {
			// Nothing is playing that is still in the list. Everything is "coming".
			return 0;
		}

		if ($nextAt === 0 && $at === count($rest) - 1) {
			return 1;
		}

		return $nextAt === $at + 1 ? $at + 2 : $at + 1;
	}

	private static function normalise(?string $artist): ?string {
		if ($artist === null) {
			return null;
		}

		$key = mb_strtolower(trim($artist));

		return $key === '' ? null : $key;
	}
}
