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
 * @extends QBMapper<Share>
 */
class ShareMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'music_radio_shares', Share::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id, int $channelId): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * @return Share[]
	 * @throws Exception
	 */
	public function findAllForChannel(int $channelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->orderBy('share_type', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Every share row that could grant this user access to the channel — direct, by
	 * group, or by team. PermissionService ORs their permission bitmasks together.
	 *
	 * @param string[] $groupIds
	 * @param string[] $teamIds
	 * @return Share[]
	 * @throws Exception
	 */
	public function findForRecipient(int $channelId, string $userId, array $groupIds, array $teamIds): array {
		$qb = $this->db->getQueryBuilder();

		$matches = [
			$qb->expr()->andX(
				$qb->expr()->eq('share_type', $qb->createNamedParameter(Share::TYPE_USER, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('receiver', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
			),
		];
		if ($groupIds !== []) {
			$matches[] = $qb->expr()->andX(
				$qb->expr()->eq('share_type', $qb->createNamedParameter(Share::TYPE_GROUP, IQueryBuilder::PARAM_INT)),
				$qb->expr()->in('receiver', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}
		if ($teamIds !== []) {
			$matches[] = $qb->expr()->andX(
				$qb->expr()->eq('share_type', $qb->createNamedParameter(Share::TYPE_TEAM, IQueryBuilder::PARAM_INT)),
				$qb->expr()->in('receiver', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_STR_ARRAY)),
			);
		}

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(...$matches));

		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findByToken(string $token): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(Share::TYPE_LINK, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Whether anybody this channel is shared with may vote.
	 *
	 * Drives `music_radio_channels.allow_voting`, which is no longer a switch an owner
	 * sets but a fact about the shares — see ChannelService::syncVotingMode for why the
	 * channel still needs to hold the answer. Expiry is deliberately not considered: a
	 * share that lapses does not reorder the playlist behind anyone's back, and the value
	 * is recomputed the next time any share is touched.
	 *
	 * @throws Exception
	 */
	public function anyAllowsVoting(int $channelId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('allow_voting', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return $row !== false;
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
}
