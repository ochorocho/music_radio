<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Un-flag tracks that were never actually missing.
 *
 * Every path that reads a track's file used to look for it in `getUserFolder(added_by)`.
 * That column is a credit, not an address: an upload through a link is credited to a visitor
 * key which is not an account at all, and an import is credited to whoever pasted the URL
 * while the file itself is written into the *channel owner's* music folder. Both looked up
 * to nothing, and nothing is how the streaming path is told a file has been deleted — so the
 * track was flagged `unavailable`, dropped out of the programme and was reported as missing,
 * with the file sitting in the owner's Files perfectly readable.
 *
 * {@see \OCA\MusicRadio\Service\TrackFiles} now looks in the storages a track could actually
 * be in, which stops it happening — and does nothing for the rows already carrying the flag,
 * because `unavailable` is checked before any storage is opened. Hence this.
 *
 * Only rows credited to somebody other than the channel owner are touched, which is exactly
 * the set the fault could have produced; a track the owner added themselves always resolved
 * correctly, so a flag on one of those is genuine and is left alone. Clearing is safe even
 * where the file really has gone since: the first request that reaches such a track resolves
 * it, finds nothing, and flags it again — this time for the right reason.
 *
 * **No schema change.**
 */
class Version001700Date20260803160000 extends SimpleMigrationStep {

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

	/**
	 * A channel at a time. Which credit counts as "the owner" is a per-channel question, and
	 * an UPDATE joined against another table to ask it is the kind of SQL that works on one
	 * database and not the next.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		try {
			$channels = $this->db->getQueryBuilder();
			$channels->select('id', 'user_id')->from('music_radio_channels');

			$result = $channels->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (\Throwable) {
			// A fresh install: there is nothing to have been flagged.
			return;
		}

		$cleared = 0;

		foreach ($rows as $row) {
			try {
				$cleared += $this->clearChannel((int)$row['id'], (string)$row['user_id']);
			} catch (\Throwable) {
				// Not worth failing an upgrade over — the track is one somebody has to press
				// play on to notice, and it can be brought back by hand from the playlist.
			}
		}

		if ($cleared > 0) {
			$output->info($cleared . ' track(s) wrongly marked as missing are back on the air');
		}
	}

	/** @return int how many rows were cleared */
	private function clearChannel(int $channelId, string $ownerId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('music_radio_tracks')
			->set('unavailable', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('unavailable', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->neq('added_by', $qb->createNamedParameter($ownerId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
