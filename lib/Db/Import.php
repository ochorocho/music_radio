<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getChannelId()
 * @method void setChannelId(int $channelId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getVideoId()
 * @method void setVideoId(string $videoId)
 * @method int getStatus()
 * @method void setStatus(int $status)
 * @method int getPhase()
 * @method void setPhase(int $phase)
 * @method int getProgress()
 * @method void setProgress(int $progress)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method int|null getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method int|null getTrackId()
 * @method void setTrackId(?int $trackId)
 * @method bool getApproved()
 * @method void setApproved(bool $approved)
 * @method string|null getErrorCode()
 * @method void setErrorCode(?string $errorCode)
 * @method string|null getErrorDetail()
 * @method void setErrorDetail(?string $errorDetail)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int getHeartbeatAt()
 * @method void setHeartbeatAt(int $heartbeatAt)
 * @method int getFinishedAt()
 * @method void setFinishedAt(int $finishedAt)
 */
class Import extends Entity implements \JsonSerializable {

	/** Written, waiting for a background job to pick it up. */
	public const STATUS_QUEUED = 0;
	/** A worker has claimed it and is doing the work. */
	public const STATUS_RUNNING = 1;
	public const STATUS_DONE = 2;
	public const STATUS_FAILED = 3;
	/** Stopped on purpose, by the person who asked for it or someone curating the channel. */
	public const STATUS_CANCELLED = 4;

	public const PHASE_PENDING = 0;
	/** Asking YouTube what the video is, before downloading anything. */
	public const PHASE_RESOLVING = 1;
	/** The only phase where `progress` means anything. */
	public const PHASE_DOWNLOADING = 2;
	/** ffmpeg, which reports nothing at all — hence phases rather than one percentage. */
	public const PHASE_CONVERTING = 3;
	/** Writing into the owner's files and appending the track. */
	public const PHASE_SAVING = 4;

	public const SOURCE_YOUTUBE = 'youtube';

	protected $channelId;
	protected $userId;
	protected $source;
	protected $videoId;
	protected $status;
	protected $phase;
	protected $progress;
	protected $title;
	protected $durationMs;
	protected $trackId;
	protected $errorCode;
	protected $errorDetail;
	protected $approved;
	protected $attempts;
	protected $createdAt;
	protected $startedAt;
	protected $heartbeatAt;
	protected $finishedAt;

	public function __construct() {
		$this->addType('channelId', Types::BIGINT);
		$this->addType('userId', Types::STRING);
		$this->addType('source', Types::STRING);
		$this->addType('videoId', Types::STRING);
		$this->addType('status', Types::SMALLINT);
		$this->addType('phase', Types::SMALLINT);
		$this->addType('progress', Types::SMALLINT);
		$this->addType('title', Types::STRING);
		$this->addType('durationMs', Types::INTEGER);
		$this->addType('trackId', Types::BIGINT);
		$this->addType('errorCode', Types::STRING);
		$this->addType('errorDetail', Types::STRING);
		// Decided when the import is asked for, because the job that files it cannot work
		// it out: it holds only this row, and a link's requester is `?link:<key>` rather
		// than an account that could be resolved back to a share.
		$this->addType('approved', Types::BOOLEAN);
		$this->addType('attempts', Types::SMALLINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('startedAt', Types::BIGINT);
		$this->addType('heartbeatAt', Types::BIGINT);
		$this->addType('finishedAt', Types::BIGINT);
	}

	/**
	 * Whether this import is still going to change. Drives whether the browser keeps
	 * polling and whether the row counts against the caps.
	 */
	public function isActive(): bool {
		return $this->getStatus() === self::STATUS_QUEUED
			|| $this->getStatus() === self::STATUS_RUNNING;
	}

	/**
	 * A name for this row.
	 *
	 * The title only exists once the probe has run, so until then there is nothing to show
	 * but the id — which is at least stable, and recognisably part of the link the person
	 * pasted.
	 */
	public function displayTitle(): string {
		$title = $this->getTitle();

		return $title === null || $title === '' ? $this->getVideoId() : $title;
	}

	public function statusName(): string {
		return match ($this->getStatus()) {
			self::STATUS_QUEUED => 'queued',
			self::STATUS_RUNNING => 'running',
			self::STATUS_DONE => 'done',
			self::STATUS_FAILED => 'failed',
			self::STATUS_CANCELLED => 'cancelled',
			default => 'unknown',
		};
	}

	public function phaseName(): string {
		return match ($this->getPhase()) {
			self::PHASE_RESOLVING => 'resolving',
			self::PHASE_DOWNLOADING => 'downloading',
			self::PHASE_CONVERTING => 'converting',
			self::PHASE_SAVING => 'saving',
			default => 'pending',
		};
	}

	/**
	 * Whether a percentage is worth showing.
	 *
	 * Only the download reports progress. ffmpeg says nothing while it transcodes, and a
	 * bar sitting at 100% for the last third of the work reads as broken — so the UI is
	 * told to show an indeterminate one instead of a misleading number.
	 */
	public function hasMeaningfulProgress(): bool {
		return $this->getPhase() === self::PHASE_DOWNLOADING;
	}

	/**
	 * Note what is absent: no error *message*. The code is translated when someone asks
	 * for it, in a request that knows their language, which a background job does not.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'channelId' => $this->getChannelId(),
			'userId' => $this->getUserId(),
			'source' => $this->getSource(),
			'videoId' => $this->getVideoId(),
			'status' => $this->statusName(),
			'phase' => $this->phaseName(),
			'progress' => $this->getProgress(),
			'showProgress' => $this->hasMeaningfulProgress(),
			'title' => $this->displayTitle(),
			'durationMs' => $this->getDurationMs(),
			'trackId' => $this->getTrackId(),
			'errorCode' => $this->getErrorCode(),
			'active' => $this->isActive(),
			'createdAt' => $this->getCreatedAt(),
			'finishedAt' => $this->getFinishedAt(),
		];
	}
}
