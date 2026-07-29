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
 * One share per recipient per channel.
 *
 * Without this, sharing the same channel with the same person twice silently produced a
 * second row, and their effective permissions became the OR of both — so "downgrade them
 * to listener" would appear to work while an older, more generous row kept granting what
 * it always had.
 */
class Version000300Date20260729160000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Drop any duplicates before the unique index is added, or creating it fails.
	 *
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('music_radio_shares')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('channel_id', 'share_type', 'receiver')
			->addSelect($qb->func()->min('id'))
			->from('music_radio_shares')
			->where($qb->expr()->isNotNull('receiver'))
			->groupBy('channel_id', 'share_type', 'receiver')
			->having($qb->expr()->gt($qb->func()->count('id'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$groups = $result->fetchAll();
		$result->closeCursor();

		foreach ($groups as $group) {
			// Keep the oldest row — it is the one whose id anything else may refer to.
			$keep = (int)($group['MIN(id)'] ?? $group['min_id'] ?? 0);

			$delete = $this->db->getQueryBuilder();
			$delete->delete('music_radio_shares')
				->where($delete->expr()->eq('channel_id', $delete->createNamedParameter($group['channel_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($delete->expr()->eq('share_type', $delete->createNamedParameter($group['share_type'], IQueryBuilder::PARAM_INT)))
				->andWhere($delete->expr()->eq('receiver', $delete->createNamedParameter($group['receiver'], IQueryBuilder::PARAM_STR)))
				->andWhere($delete->expr()->neq('id', $delete->createNamedParameter($keep, IQueryBuilder::PARAM_INT)));
			$removed = $delete->executeStatement();

			if ($removed > 0) {
				$output->info(sprintf('Removed %d duplicate share(s) for channel %s', $removed, $group['channel_id']));
			}
		}
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('music_radio_shares')) {
			return null;
		}

		$table = $schema->getTable('music_radio_shares');
		if ($table->hasIndex('mr_shr_unique_idx')) {
			return null;
		}

		// Link shares carry a NULL receiver, and SQL treats NULLs as distinct, so this
		// constrains real recipients while still allowing several links per channel.
		$table->addUniqueIndex(['channel_id', 'share_type', 'receiver'], 'mr_shr_unique_idx');

		return $schema;
	}
}
