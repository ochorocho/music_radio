<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Vote>
 *
 * Votes are counted, not read one at a time — nothing in the app ever wants a list of who
 * voted, only how many did and whether you are one of them. The methods here are shaped
 * around that, so a channel's whole voting state is two queries rather than one per track.
 */
class VoteMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'music_radio_votes', Vote::class);
	}

	/**
	 * How many votes each track on this channel has.
	 *
	 * @return array<int, int> track id => count, omitting tracks with none
	 * @throws Exception
	 */
	public function countsForChannel(int $channelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('track_id')
			->selectAlias($qb->func()->count('*'), 'votes')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->groupBy('track_id');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['track_id']] = (int)$row['votes'];
		}
		$result->closeCursor();

		return $counts;
	}

	/**
	 * How many votes each track has, and when the first of them was cast.
	 *
	 * The timestamp is the tie-break for the running order: two tracks on three votes each
	 * are separated by which of them was asked for first, so that a tie falls back to the
	 * queue rather than to playlist position — which is the one thing a vote is there to
	 * override. See Ordering::promoteVoted.
	 *
	 * `MIN` rather than the row's own `created_at` because a track has one position in the
	 * queue however many people have voted for it, and it joined that queue when the first
	 * of them pressed the button. Taking the latest instead would send a track backwards
	 * every time it gained support.
	 *
	 * @return array<int, array{votes: int, firstAt: int}> by track id, omitting tracks with none
	 * @throws Exception
	 */
	public function tallyForChannel(int $channelId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('track_id')
			->selectAlias($qb->func()->count('*'), 'votes')
			->selectAlias($qb->func()->min('created_at'), 'first_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->groupBy('track_id');

		$result = $qb->executeQuery();
		$tally = [];
		while ($row = $result->fetch()) {
			$tally[(int)$row['track_id']] = [
				'votes' => (int)$row['votes'],
				'firstAt' => (int)$row['first_at'],
			];
		}
		$result->closeCursor();

		return $tally;
	}

	/**
	 * Which of this channel's tracks this particular person has voted for.
	 *
	 * @return list<int> track ids
	 * @throws Exception
	 */
	public function trackIdsVotedForBy(int $channelId, string $voterKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('track_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('voter_key', $qb->createNamedParameter($voterKey)));

		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['track_id'];
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Withdraw one person's vote.
	 *
	 * @return bool whether there was one to withdraw
	 * @throws Exception
	 */
	public function withdraw(int $trackId, string $voterKey): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('voter_key', $qb->createNamedParameter($voterKey)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Spend a track's votes.
	 *
	 * Called when the track reaches the front of the queue: the reward has been collected,
	 * so it starts again from nothing rather than staying permanently near the top.
	 *
	 * @throws Exception
	 */
	public function clearForTrack(int $trackId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('track_id', $qb->createNamedParameter($trackId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function clearForChannel(int $channelId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Votes for tracks that no longer exist.
	 *
	 * Removing a track does not cascade — nothing in this schema does — so without this a
	 * channel accumulates rows pointing at nothing. Cheap enough to run from a periodic
	 * job rather than making every deletion path remember.
	 *
	 * @throws Exception
	 */
	public function deleteOrphaned(): int {
		$qb = $this->db->getQueryBuilder();
		$sub = $this->db->getQueryBuilder();
		$sub->select('id')->from('music_radio_tracks');

		$qb->delete($this->getTableName())
			->where($qb->expr()->notIn(
				'track_id',
				$qb->createFunction($sub->getSQL()),
				IQueryBuilder::PARAM_INT,
			));

		return $qb->executeStatement();
	}

	/**
	 * Votes cast before a given moment.
	 *
	 * A channel nobody votes on any more should not keep its old votes for ever — they
	 * would spring back into effect the next time somebody pressed play.
	 *
	 * @throws Exception
	 */
	public function deleteOlderThan(int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
