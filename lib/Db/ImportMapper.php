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
 * @extends QBMapper<Import>
 *
 * Several methods here write with a bare UPDATE rather than by loading an entity and
 * saving it. That is deliberate and the reason is the same each time: the row is being
 * changed by a worker in another process while a browser polls it, so the write has to be
 * a single statement whose effect does not depend on what this process last read.
 * `claim()` in particular is a lock, not a convenience.
 */
class ImportMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'music_radio_imports', Import::class);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function find(int $id, int $channelId): Import {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Used by the job, which knows an id and nothing else.
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	public function findById(int $id): Import {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * A channel's imports, newest first.
	 *
	 * @return Import[]
	 * @throws Exception
	 */
	public function findAllForChannel(int $channelId, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * @throws Exception
	 */
	public function countActiveForUser(string $userId): int {
		return $this->countActive(
			static fn (IQueryBuilder $qb) => $qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
		);
	}

	/**
	 * @throws Exception
	 */
	public function countActiveForChannel(int $channelId): int {
		return $this->countActive(
			static fn (IQueryBuilder $qb) => $qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)),
		);
	}

	/**
	 * Whether this channel is already fetching this video.
	 *
	 * Two people pasting the same link within a minute of each other is an ordinary
	 * accident, and without this it would cost two downloads and produce two identical
	 * tracks. (Once the first one lands, TrackService's own duplicate check takes over.)
	 *
	 * @throws Exception
	 */
	public function hasActiveForVideo(int $channelId, string $videoId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'matches'))
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('video_id', $qb->createNamedParameter($videoId)))
			->andWhere($this->activeStatuses($qb));

		return $this->scalar($qb) > 0;
	}

	/**
	 * Take ownership of a queued import.
	 *
	 * The whole point is the `status = QUEUED` in the WHERE clause. Cron can hand the same
	 * queued job to two workers, and a job that was retried after a crash can arrive
	 * alongside one that is already running; this makes the second one a no-op instead of
	 * a second download. A caller that gets 0 back must do nothing at all.
	 *
	 * @return int rows affected — 1 means this caller owns the import, 0 means somebody
	 *             else does, or it was cancelled before it started
	 * @throws Exception
	 */
	public function claim(int $id, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Import::STATUS_RUNNING, IQueryBuilder::PARAM_INT))
			->set('phase', $qb->createNamedParameter(Import::PHASE_RESOLVING, IQueryBuilder::PARAM_INT))
			->set('started_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('heartbeat_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('attempts', $qb->func()->add('attempts', $qb->expr()->literal(1)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Import::STATUS_QUEUED, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Say that the work is still going, and how far along it is.
	 *
	 * Called often — roughly once a second while a download runs — so it deliberately does
	 * not load or save an entity. The heartbeat is what distinguishes a slow import from
	 * one whose worker was killed.
	 *
	 * @throws Exception
	 */
	public function touch(int $id, int $now, int $phase, int $progress): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('heartbeat_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('phase', $qb->createNamedParameter($phase, IQueryBuilder::PARAM_INT))
			->set('progress', $qb->createNamedParameter($progress, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Import::STATUS_RUNNING, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Just the status, for the cancel check a running worker makes between progress
	 * updates. Loading the whole row once a second to read one number would be wasteful.
	 *
	 * @throws Exception
	 */
	public function statusOf(int $id): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('status')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value === false ? null : (int)$value;
	}

	/**
	 * Stop an import that has not finished.
	 *
	 * A queued row simply never runs. A running one keeps going until its worker notices
	 * at the next heartbeat, which is why the worker polls the status rather than being
	 * signalled.
	 *
	 * @return int rows affected; 0 means it had already finished
	 * @throws Exception
	 */
	public function cancel(int $id, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Import::STATUS_CANCELLED, IQueryBuilder::PARAM_INT))
			->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($this->activeStatuses($qb));

		return $qb->executeStatement();
	}

	// ------------------------------------------------------------------ reaping

	/**
	 * Fail imports whose worker stopped talking.
	 *
	 * A job killed by an OOM, a restarted container or a fatal error leaves a row saying
	 * "running" with nobody running it. Without this the person who asked would watch a
	 * spinner for ever.
	 *
	 * @param int $silentSince heartbeats older than this are considered dead
	 * @return int rows reaped
	 * @throws Exception
	 */
	public function failStalled(int $silentSince, int $now, string $errorCode, ?int $channelId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Import::STATUS_FAILED, IQueryBuilder::PARAM_INT))
			->set('error_code', $qb->createNamedParameter($errorCode))
			->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Import::STATUS_RUNNING, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('heartbeat_at', $qb->createNamedParameter($silentSince, IQueryBuilder::PARAM_INT)));

		if ($channelId !== null) {
			$qb->andWhere($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));
		}

		return $qb->executeStatement();
	}

	/**
	 * Fail imports that were never picked up.
	 *
	 * This one carries information: a queued row that has sat untouched for an hour almost
	 * always means background jobs are not running on this server at all, and the message
	 * for that code says so. It is the only way the app can report a broken cron, since
	 * anything it could schedule to notice would be broken too.
	 *
	 * @return int rows reaped
	 * @throws Exception
	 */
	public function failNeverStarted(int $queuedBefore, int $now, string $errorCode, ?int $channelId = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Import::STATUS_FAILED, IQueryBuilder::PARAM_INT))
			->set('error_code', $qb->createNamedParameter($errorCode))
			->set('finished_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Import::STATUS_QUEUED, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($queuedBefore, IQueryBuilder::PARAM_INT)));

		if ($channelId !== null) {
			$qb->andWhere($qb->expr()->eq('channel_id', $qb->createNamedParameter($channelId, IQueryBuilder::PARAM_INT)));
		}

		return $qb->executeStatement();
	}

	/**
	 * Finished rows are history, and history that nobody will read is just growth.
	 *
	 * @return int rows removed
	 * @throws Exception
	 */
	public function pruneFinished(int $before): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->notIn(
				'status',
				$qb->createNamedParameter(
					[Import::STATUS_QUEUED, Import::STATUS_RUNNING],
					IQueryBuilder::PARAM_INT_ARRAY,
				),
			))
			->andWhere($qb->expr()->lt('finished_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('finished_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
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

	// ------------------------------------------------------------------ helpers

	/**
	 * @param callable(IQueryBuilder): string $scope
	 * @throws Exception
	 */
	private function countActive(callable $scope): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'matches'))
			->from($this->getTableName())
			->where($scope($qb))
			->andWhere($this->activeStatuses($qb));

		return $this->scalar($qb);
	}

	private function activeStatuses(IQueryBuilder $qb): string {
		return $qb->expr()->in(
			'status',
			$qb->createNamedParameter(
				[Import::STATUS_QUEUED, Import::STATUS_RUNNING],
				IQueryBuilder::PARAM_INT_ARRAY,
			),
		);
	}

	/**
	 * @throws Exception
	 */
	private function scalar(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value === false ? 0 : (int)$value;
	}
}
