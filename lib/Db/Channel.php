<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A channel.
 *
 * Four of these columns describe what an audience may do — `require_approval`,
 * `show_listener_count`, `allow_voting`, `allow_import` — and none of them is a setting
 * an owner reaches any more. Every one of those questions is asked per share instead, in
 * the share dialog, because an owner may trust the people they named quite differently
 * from whoever ends up with a link. What survives here:
 *
 *  - `allow_voting` is **derived**, maintained by ChannelService::syncVotingMode as "at
 *    least one share allows voting". It stays because it is not only a permission gate:
 *    TrackMapper reads it to choose between `vote_order` and the author's `sort_order`.
 *  - the other three are **vestigial**. Nothing reads them at runtime; they are still
 *    written by the migrations that seeded the per-share columns from them, and still
 *    serialised, and are kept rather than dropped so an instance can be rolled back.
 *
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
 * @method bool getRequireApproval()
 * @method void setRequireApproval(bool $requireApproval)
 * @method bool getShowListenerCount()
 * @method void setShowListenerCount(bool $showListenerCount)
 * @method bool getAllowVoting()
 * @method void setAllowVoting(bool $allowVoting)
 * @method bool getAllowImport()
 * @method void setAllowImport(bool $allowImport)
 * @method int getVoteVersion()
 * @method void setVoteVersion(int $voteVersion)
 * @method int getVoteOrderedAt()
 * @method void setVoteOrderedAt(int $voteOrderedAt)
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
	protected $requireApproval;
	protected $showListenerCount;
	protected $allowVoting;
	protected $allowImport;
	protected $voteVersion;
	protected $voteOrderedAt;
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
		$this->addType('requireApproval', Types::BOOLEAN);
		$this->addType('showListenerCount', Types::BOOLEAN);
		$this->addType('allowVoting', Types::BOOLEAN);
		$this->addType('allowImport', Types::BOOLEAN);
		$this->addType('voteVersion', Types::BIGINT);
		$this->addType('voteOrderedAt', Types::BIGINT);
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
			'requireApproval' => $this->getRequireApproval(),
			'showListenerCount' => $this->getShowListenerCount(),
			'allowVoting' => $this->getAllowVoting(),
			'allowImport' => $this->getAllowImport(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
