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

	/** The ordering columns, and the only values any caller here may name. */
	public const ORDER_SORT = 'sort_order';
	public const ORDER_SHUFFLE = 'shuffle_order';
	public const ORDER_VOTE = 'vote_order';

	/**
	 * The channel's tracks in broadcast order.
	 *
	 * Three ordering columns, but not three alternatives. `sort_order` is the arrangement
	 * somebody dragged into place and is written by nothing else; `shuffle_order` holds a
	 * materialised random arrangement. Whichever of those two is in force is the channel's
	 * **base order** — see {@see findAllForChannelInBaseOrder}. `vote_order` is the
	 * **running order**: that base order with the tracks people have voted for pulled up
	 * behind whatever is playing.
	 *
	 * So voting is checked first, and it composes with shuffle rather than replacing it: a
	 * shuffled channel that takes votes plays its shuffle, with requests jumping the queue.
	 * These used to be alternatives, with shuffle winning, which meant every vote cast on a
	 * shuffled channel was silently discarded.
	 *
	 * `id` breaks ties so the order is total and stable — two rows can share a sort_order
	 * after a concurrent append.
	 *
	 * Taking the Channel rather than its flags on purpose: this choice is made in exactly
	 * one place, so no caller can pick a different ordering from the one the timeline is
	 * anchored against.
	 *
	 * @return Track[]
	 * @throws Exception
	 */
	public function findAllForChannelInPlayOrder(Channel $channel): array {
		return $this->findAllOrderedBy(
			$channel->getId(),
			$channel->getAllowVoting() ? self::ORDER_VOTE : self::baseOrderColumn($channel),
		);
	}

	/**
	 * The channel's tracks in its base order — what it would play if nobody had voted.
	 *
	 * The running order is recomputed from this rather than from itself, which is what
	 * makes "no votes, so the ordinary order" true by construction instead of by
	 * maintenance. Reading the previous running order back and adjusting it would leave
	 * the author's arrangement irrecoverable after a few votes, and left `vote_order`
	 * needing to be kept in step by every path that adds, removes or reorders a track.
	 *
	 * @return Track[]
	 * @throws Exception
	 */
	public function findAllForChannelInBaseOrder(Channel $channel): array {
		return $this->findAllOrderedBy($channel->getId(), self::baseOrderColumn($channel));
	}

	private static function baseOrderColumn(Channel $channel): string {
		return $channel->getShuffle() ? self::ORDER_SHUFFLE : self::ORDER_SORT;
	}

	/**
	 * @param self::ORDER_* $column
	 * @return Track[]
	 * @throws Exception
	 */
	private function findAllOrderedBy(int $channelId, string $column): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->orderBy($column, 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Highest value of one ordering column in the channel, or null when it has no tracks.
	 * Used to append without renumbering.
	 *
	 * Each column has to be asked separately, and that is the point of this taking one.
	 * Appending used to read the highest `sort_order` and write it into `shuffle_order`
	 * as well, on the assumption that the two stay in step — they do not, because a
	 * shuffle renumbers one of them and a drag renumbers the other. Once they had drifted,
	 * a newly added track could be handed a `shuffle_order` already in use, and it landed
	 * beside an unrelated track in the middle of the running order instead of at the end.
	 *
	 * @param self::ORDER_* $column
	 * @throws Exception
	 */
	public function maxOrder(int $channelId, string $column): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max($column))
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
	 * Assign a new position in one ordering column without loading the row. Used by the
	 * transactional renumbers in TrackService::reorder() and VoteService.
	 *
	 * @param self::ORDER_* $column
	 * @throws Exception
	 */
	public function updateOrder(int $trackId, int $channelId, string $column, int $position): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set($column, $qb->createNamedParameter($position, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
