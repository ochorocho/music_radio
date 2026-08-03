<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tidy the three ordering columns, and give `vote_order` a starting value.
 *
 * Two faults left their marks in the data, and neither can be undone by the code that
 * stopped causing them:
 *
 * **`vote_order` was never written until somebody voted.** It was added with a default of
 * zero, but TrackMapper reads it as the broadcast order the moment a channel takes votes
 * at all. Every row holding the same zero meant the order fell through to the `id`
 * tiebreak, so switching voting on quietly rearranged a playlist into the order its rows
 * happened to have been inserted in. That is now recomputed from the base order on the
 * next poll, so it heals itself — but only once somebody opens the page, and until then a
 * channel left playing broadcasts in row-id order.
 *
 * **Appended tracks were given a `shuffle_order` taken from the highest `sort_order`.**
 * Those two columns drift apart, so the value could already be in use: the affected track
 * sits beside an unrelated one in the middle of the running order instead of at the end of
 * it. Appending now reads each column's own maximum, which stops it happening again and
 * does nothing about the rows where it already has.
 *
 * So rather than copy values across, this renumbers. Each column is rewritten into an
 * evenly spaced sequence in the order it already describes — which changes no ordering,
 * only the numbers expressing it — and any collision is resolved on the way. `vote_order`
 * then takes the channel's base order, which is what the running order is defined to be
 * when nobody has voted.
 *
 * **No schema change**, and no attempt to reconstruct anybody's votes.
 */
class Version001600Date20260803120000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	/** Matches TrackService::SORT_STEP, so appending after this still lands at the end. */
	private const STEP = 1000;

	/**
	 * A channel at a time, because which column `vote_order` should follow is a per-channel
	 * question and a correlated update that asks it row by row is the kind of SQL that
	 * works on one database and not the next.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		try {
			$channels = $this->db->getQueryBuilder();
			$channels->select('id', 'shuffle')->from('music_radio_channels');

			$result = $channels->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (\Throwable) {
			// A fresh install: nothing has been created yet, and the defaults are right.
			return;
		}

		foreach ($rows as $row) {
			try {
				$this->renumberChannel((int)$row['id'], (bool)$row['shuffle']);
			} catch (\Throwable) {
				// Not worth failing an upgrade over. The next poll of a channel that takes
				// votes recomputes its running order anyway, and the next time one that
				// shuffles comes round to the top it redraws and renumbers itself.
			}
		}
	}

	private function renumberChannel(int $channelId, bool $shuffle): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'sort_order', 'shuffle_order')
			->from('music_radio_tracks')
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId)));

		$result = $qb->executeQuery();
		$tracks = $result->fetchAll();
		$result->closeCursor();

		if ($tracks === []) {
			return;
		}

		$sort = self::positionsIn($tracks, 'sort_order');
		$shuffled = self::positionsIn($tracks, 'shuffle_order');

		foreach ($tracks as $track) {
			$id = (int)$track['id'];
			$this->write($id, $channelId, [
				'sort_order' => $sort[$id],
				'shuffle_order' => $shuffled[$id],
				'vote_order' => $shuffle ? $shuffled[$id] : $sort[$id],
			]);
		}
	}

	/**
	 * The evenly spaced position each track takes in one column's existing order.
	 *
	 * `id` breaks ties exactly as the ordering query does, so two rows sharing a value come
	 * out in the order they were already being played in.
	 *
	 * @param list<array<string, mixed>> $tracks
	 * @return array<int, int> track id => new position
	 */
	private static function positionsIn(array $tracks, string $column): array {
		usort($tracks, static fn (array $a, array $b): int
			=> ((int)$a[$column] <=> (int)$b[$column]) ?: ((int)$a['id'] <=> (int)$b['id']));

		$positions = [];
		$at = 0;
		foreach ($tracks as $track) {
			$at += self::STEP;
			$positions[(int)$track['id']] = $at;
		}

		return $positions;
	}

	/**
	 * @param array<string, int> $columns
	 */
	private function write(int $trackId, int $channelId, array $columns): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('music_radio_tracks')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($trackId)))
			->andWhere($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId)));

		foreach ($columns as $column => $position) {
			$qb->set($column, $qb->createNamedParameter($position));
		}

		$qb->executeStatement();
	}
}
