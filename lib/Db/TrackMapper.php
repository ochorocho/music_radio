<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Track>
 *
 * ⚠ Mutating a track's channel membership, order, duration or availability changes the
 * broadcast timeline. Callers must go through TrackService, which wraps every such write
 * in TimelineService::withPreservedPosition() so listeners do not jump. Do not call the
 * mutating methods here from a controller.
 */
class TrackMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'music_radio_tracks', Track::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id, int $channelId): Track {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * The channel's tracks in author order.
	 *
	 * @return Track[]
	 * @throws Exception
	 */
	public function findAllForChannel(int $channelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The channel's tracks in broadcast order — shuffled order when the channel is
	 * shuffling, author order otherwise. `id` breaks ties so the order is total and
	 * stable (two rows can share a sort_order after a concurrent append).
	 *
	 * @return Track[]
	 * @throws Exception
	 */
	public function findAllForChannelInPlayOrder(int $channelId, bool $shuffle): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->orderBy($shuffle ? 'shuffle_order' : 'sort_order', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Highest sort_order in the channel, or null when it has no tracks. Used to append
	 * without renumbering.
	 *
	 * @throws Exception
	 */
	public function maxSortOrder(int $channelId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sort_order'))
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value === null || $value === false ? null : (int)$value;
	}

	/**
	 * Which of the given file ids are already in the channel — so adding the same file
	 * twice by accident can be reported rather than silently duplicated.
	 *
	 * @param int[] $fileIds
	 * @return int[]
	 * @throws Exception
	 */
	public function findExistingFileIds(int $channelId, array $fileIds): array {
		if ($fileIds === []) {
			return [];
		}

		$found = [];
		// Chunked well below the 1000-parameter limit some drivers impose on IN().
		foreach (array_chunk($fileIds, 900) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id')
				->from($this->getTableName())
				->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

			$result = $qb->executeQuery();
			foreach ($result->fetchAll() as $row) {
				$found[] = (int)$row['file_id'];
			}
			$result->closeCursor();
		}

		return $found;
	}

	/**
	 * @throws Exception
	 */
	public function countForChannel(int $channelId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * @throws Exception
	 */
	public function deleteAllForChannel(int $channelId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Assign a new sort_order to one row without loading it. Used by the transactional
	 * renumber in TrackService::reorder().
	 *
	 * @throws Exception
	 */
	public function updateSortOrder(int $trackId, int $channelId, int $sortOrder): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('sort_order', $qb->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
