<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Service\Ordering;
use PHPUnit\Framework\TestCase;

/**
 * The two pure ordering rules.
 *
 * Both are stated as properties rather than as fixed expected arrays wherever that is
 * possible: "no two neighbours share an artist" is the thing that matters, and pinning a
 * particular permutation would only record which swap the repair happened to choose.
 */
class OrderingTest extends TestCase {

	private static function track(int $id, ?string $artist = null): Track {
		$track = new Track();
		$track->setId($id);
		$track->setArtist($artist);

		return $track;
	}

	/**
	 * @param list<array{int, string|null}> $rows
	 * @return list<Track>
	 */
	private static function tracks(array $rows): array {
		return array_map(static fn (array $row): Track => self::track($row[0], $row[1]), $rows);
	}

	/** @param list<Track> $tracks */
	private static function artists(array $tracks): array {
		return array_map(static fn (Track $t): ?string => $t->getArtist(), $tracks);
	}

	/** @param list<Track> $tracks */
	private static function ids(array $tracks): array {
		return array_map(static fn (Track $t): int => (int)$t->getId(), $tracks);
	}

	/**
	 * @param list<Track> $tracks
	 * @return int how many neighbouring pairs share a (known) artist
	 */
	private static function adjacentRepeats(array $tracks): int {
		$repeats = 0;
		$artists = self::artists($tracks);
		for ($i = 1; $i < count($artists); $i++) {
			if ($artists[$i] !== null && $artists[$i] === $artists[$i - 1]) {
				$repeats++;
			}
		}

		return $repeats;
	}

	// ------------------------------------------------------------ artist spread

	public function testItSeparatesARunByTheSameBand(): void {
		$spread = Ordering::spreadArtists(self::tracks([
			[1, 'The National'], [2, 'The National'], [3, 'The National'],
			[4, 'Interpol'], [5, 'Slipknot'], [6, 'TOOL'],
		]));

		self::assertSame(0, self::adjacentRepeats($spread));
	}

	public function testItKeepsEveryTrack(): void {
		$spread = Ordering::spreadArtists(self::tracks([
			[1, 'a'], [2, 'a'], [3, 'b'], [4, 'b'], [5, 'c'], [6, null], [7, 'a'],
		]));

		$ids = self::ids($spread);
		sort($ids);
		self::assertSame([1, 2, 3, 4, 5, 6, 7], $ids);
	}

	public function testItLeavesADrawThatAlreadySpreadsAlone(): void {
		$drawn = self::tracks([[1, 'a'], [2, 'b'], [3, 'a'], [4, 'c'], [5, 'b']]);

		// The randomness is the product; a repair that reshuffles a clean draw has thrown
		// it away for nothing.
		self::assertSame([1, 2, 3, 4, 5], self::ids(Ordering::spreadArtists($drawn)));
	}

	public function testAnUnreadArtistTagIsNotABand(): void {
		$drawn = self::tracks([[1, null], [2, null], [3, null], [4, ''], [5, '   ']]);

		// Five files whose tags failed to read are five unknowns, not a five-piece. Herding
		// them apart would be acting on information nobody has.
		self::assertSame([1, 2, 3, 4, 5], self::ids(Ordering::spreadArtists($drawn)));
	}

	public function testItMatchesArtistsRegardlessOfCaseAndPadding(): void {
		$spread = Ordering::spreadArtists(self::tracks([
			[1, 'KoRn'], [2, ' korn '], [3, 'Interpol'], [4, 'TOOL'],
		]));

		self::assertSame(0, self::adjacentRepeats($spread));
	}

	public function testItDoesNotFollowThePinnedTrackWithItsOwnArtist(): void {
		$spread = Ordering::spreadArtists(
			self::tracks([[1, 'The National'], [2, 'Interpol'], [3, 'TOOL']]),
			after: 'the national',
		);

		self::assertNotSame('The National', self::artists($spread)[0]);
	}

	public function testItPlacesWhatItCanWhenNoArrangementWorks(): void {
		// Four of six by one band. Two of them must end up adjacent whatever anyone does —
		// there are only two other tracks to separate four with — so one repeat is the
		// floor, and the draw as it stands has three. Degrading to the floor is the whole
		// claim of "best effort".
		$spread = Ordering::spreadArtists(self::tracks([
			[1, 'a'], [2, 'a'], [3, 'a'], [4, 'a'], [5, 'b'], [6, 'c'],
		]));

		self::assertCount(6, $spread);
		self::assertSame(1, self::adjacentRepeats($spread));
	}

	public function testShortListsAreLeftAlone(): void {
		self::assertSame([1], self::ids(Ordering::spreadArtists(self::tracks([[1, 'a']]))));
	}

	// --------------------------------------------------------- vote promotion

	/** @return array<int, array{votes: int, firstAt: int}> */
	private static function tally(array $rows): array {
		$tally = [];
		foreach ($rows as $id => [$votes, $firstAt]) {
			$tally[$id] = ['votes' => $votes, 'firstAt' => $firstAt];
		}

		return $tally;
	}

	public function testNoVotesChangesNothingAtAll(): void {
		$order = self::tracks([[1, null], [2, null], [3, null]]);

		// Identity, not just equality: the caller reads "same order back" as "nothing to
		// write", which is what stops an unvoted channel re-anchoring its timeline.
		self::assertSame($order, Ordering::promoteVoted($order, 1, null, []));
	}

	public function testAVotedTrackBecomesTheNextOne(): void {
		$order = self::tracks([[1, null], [2, null], [3, null], [4, null]]);

		self::assertSame(
			[1, 4, 2, 3],
			self::ids(Ordering::promoteVoted($order, 1, null, self::tally([4 => [2, 100]]))),
		);
	}

	public function testMostVotedFirstThenDown(): void {
		$order = self::tracks([[1, null], [2, null], [3, null], [4, null], [5, null]]);

		$promoted = Ordering::promoteVoted($order, 1, null, self::tally([
			3 => [1, 100],
			4 => [5, 100],
			5 => [3, 100],
		]));

		self::assertSame([1, 4, 5, 3, 2], self::ids($promoted));
	}

	public function testATieGoesToWhoeverWasVotedForFirst(): void {
		$order = self::tracks([[1, null], [2, null], [3, null], [4, null]]);

		// 4 sits behind 3 in the playlist and was voted for earlier. Playlist position is
		// exactly what a vote is meant to override, so the earlier vote wins.
		$promoted = Ordering::promoteVoted($order, 1, null, self::tally([
			3 => [2, 900],
			4 => [2, 100],
		]));

		self::assertSame([1, 4, 3, 2], self::ids($promoted));
	}

	public function testATrackFromBehindThePlayheadIsPulledForward(): void {
		$order = self::tracks([[1, null], [2, null], [3, null], [4, null]]);

		// 1 has already been round this cycle. On a looping channel "next" still means
		// straight after 3, not "wait for the whole rest of the programme".
		self::assertSame(
			[2, 3, 1, 4],
			self::ids(Ordering::promoteVoted($order, 3, null, self::tally([1 => [1, 100]]))),
		);
	}

	public function testThePreloadedNextTrackIsNotJumped(): void {
		$order = self::tracks([[1, null], [2, null], [3, null], [4, null]]);

		// Every client has already loaded 2 into its idle element; a vote gets the slot
		// after it, not before it.
		self::assertSame(
			[1, 2, 4, 3],
			self::ids(Ordering::promoteVoted($order, 1, 2, self::tally([4 => [1, 100]]))),
		);
	}

	public function testAPinnedPairStraddlingTheEndIsNotSplit(): void {
		$order = self::tracks([[1, null], [2, null], [3, null], [4, null]]);

		// Playing the last track, wrapping to the first: 4 → 1 is the pinned pair, so the
		// voted track goes after 1 rather than at the end of the array, which is between
		// the two things that were pinned together.
		self::assertSame(
			[1, 3, 2, 4],
			self::ids(Ordering::promoteVoted($order, 4, 1, self::tally([3 => [1, 100]]))),
		);
	}

	public function testVotesForWhatIsAlreadyPlayingDoNotReorder(): void {
		$order = self::tracks([[1, null], [2, null], [3, null]]);

		self::assertSame($order, Ordering::promoteVoted($order, 1, null, self::tally([1 => [4, 100]])));
	}

	public function testWithNothingPlayingTheVotedTrackGoesToTheTop(): void {
		$order = self::tracks([[1, null], [2, null], [3, null]]);

		self::assertSame(
			[3, 1, 2],
			self::ids(Ordering::promoteVoted($order, null, null, self::tally([3 => [1, 100]]))),
		);
	}
}
