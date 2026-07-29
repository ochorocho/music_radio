<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int|null getCoverFileId()
 * @method void setCoverFileId(?int $coverFileId)
 * @method int getStartedAtMs()
 * @method void setStartedAtMs(int $startedAtMs)
 * @method int getEpochOffsetMs()
 * @method void setEpochOffsetMs(int $epochOffsetMs)
 * @method bool getPaused()
 * @method void setPaused(bool $paused)
 * @method bool getLoopEnabled()
 * @method void setLoopEnabled(bool $loopEnabled)
 * @method bool getShuffle()
 * @method void setShuffle(bool $shuffle)
 * @method int getShuffleSeed()
 * @method void setShuffleSeed(int $shuffleSeed)
 * @method int getStateVersion()
 * @method void setStateVersion(int $stateVersion)
 * @method int getPlaylistVersion()
 * @method void setPlaylistVersion(int $playlistVersion)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Channel extends Entity implements \JsonSerializable {

	protected $userId;
	protected $title;
	protected $description;
	protected $coverFileId;
	protected $startedAtMs;
	protected $epochOffsetMs;
	protected $paused;
	protected $loopEnabled;
	protected $shuffle;
	protected $shuffleSeed;
	protected $stateVersion;
	protected $playlistVersion;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::STRING);
		$this->addType('coverFileId', Types::BIGINT);
		$this->addType('startedAtMs', Types::BIGINT);
		$this->addType('epochOffsetMs', Types::BIGINT);
		$this->addType('paused', Types::BOOLEAN);
		$this->addType('loopEnabled', Types::BOOLEAN);
		$this->addType('shuffle', Types::BOOLEAN);
		$this->addType('shuffleSeed', Types::INTEGER);
		$this->addType('stateVersion', Types::BIGINT);
		$this->addType('playlistVersion', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'coverFileId' => $this->getCoverFileId(),
			'loop' => $this->getLoopEnabled(),
			'shuffle' => $this->getShuffle(),
			'paused' => $this->getPaused(),
			'stateVersion' => $this->getStateVersion(),
			'playlistVersion' => $this->getPlaylistVersion(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
