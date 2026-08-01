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
 * @extends QBMapper<Channel>
 */
class ChannelMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'music_radio_channels', Channel::class);
	}

	/**
	 * Unscoped lookup. Callers MUST apply an access check (PermissionService) —
	 * prefer findOwnedBy() when only the owner may act.
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id): Channel {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Every channel on the server, for maintenance that is not done on anyone's behalf.
	 *
	 * Deliberately not reachable from a controller — there is no request in which "all
	 * channels regardless of who may see them" is a correct answer. It exists for
	 * `occ music_radio:broadcast:build`, which runs as the administrator and has to be
	 * able to prepare a server's whole library.
	 *
	 * @return Channel[]
	 * @throws Exception
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findOwnedBy(int $id, string $userId): Channel {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * @return Channel[]
	 * @throws Exception
	 */
	public function findAllOwnedBy(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('title', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Channels reachable through a share row, i.e. shared *with* this user directly,
	 * with one of their groups, or with one of their teams. Expired shares are
	 * filtered out here so they are invisible rather than merely unusable.
	 *
	 * @param string[] $groupIds
	 * @param string[] $teamIds
	 * @return Channel[]
	 * @throws Exception
	 */
	public function findAllSharedWith(string $userId, array $groupIds, array $teamIds, int $nowSeconds): array {
		$qb = $this->db->getQueryBuilder();

		$receiverMatches = [
			$qb->expr()->andX(
				$qb->expr()->eq('s.share_type', $qb->createNamedParameter(Share::TYPE_USER, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('s.receiver', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
			),
		];
		if ($groupIds !== []) {
			$receiverMatches[] = $qb->expr()->andX(
				$qb->expr()->eq('s.share_type', $qb->createNamedParameter(Share::TYPE_GROUP, IQueryBuilder::PARAM_INT)),
				$qb->expr()->in('s.receiver', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}
		if ($teamIds !== []) {
			$receiverMatches[] = $qb->expr()->andX(
				$qb->expr()->eq('s.share_type', $qb->createNamedParameter(Share::TYPE_TEAM, IQueryBuilder::PARAM_INT)),
				$qb->expr()->in('s.receiver', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}

		$qb->selectDistinct('c.*')
			->from($this->getTableName(), 'c')
			->innerJoin('c', 'music_radio_shares', 's', $qb->expr()->eq('s.channel_id', 'c.id'))
			->where($qb->expr()->orX(...$receiverMatches))
			// Do not list the owner's own channels twice.
			->andWhere($qb->expr()->neq('c.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('s.expiration'),
				$qb->expr()->gt('s.expiration', $qb->createNamedParameter($nowSeconds, IQueryBuilder::PARAM_INT)),
			))
			->orderBy('c.title', 'ASC')
			->addOrderBy('c.id', 'ASC');

		return $this->findEntities($qb);
	}
}
