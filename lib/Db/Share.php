<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A share of one channel. Deliberately the app's own ACL row rather than a core
 * `OCP\Share\IShare`: `IShare::setNode()` is typed to `OCP\Files\Node` and core's
 * `Share20\Manager::generalChecks()` rejects anything that is not a File or Folder,
 * so a non-file entity like a channel cannot be shared through core at all. Every
 * app that shares custom entities (deck, tables, forms — and core's own calendars,
 * via `dav_shares`) does the same thing.
 *
 * @method int getChannelId()
 * @method void setChannelId(int $channelId)
 * @method int getShareType()
 * @method void setShareType(int $shareType)
 * @method string|null getReceiver()
 * @method void setReceiver(?string $receiver)
 * @method string|null getToken()
 * @method void setToken(?string $token)
 * @method string|null getPassword()
 * @method void setPassword(?string $password)
 * @method bool getRequireApproval()
 * @method void setRequireApproval(bool $requireApproval)
 * @method bool getAllowVoting()
 * @method void setAllowVoting(bool $allowVoting)
 * @method bool getShowListenerCount()
 * @method void setShowListenerCount(bool $showListenerCount)
 * @method bool getAllowImport()
 * @method void setAllowImport(bool $allowImport)
 * @method int getPermissions()
 * @method void setPermissions(int $permissions)
 * @method int|null getExpiration()
 * @method void setExpiration(?int $expiration)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Share extends Entity implements \JsonSerializable {

	/** Values mirror OCP\Share\IShare::TYPE_* so the sharee picker's `source` maps directly. */
	public const TYPE_USER = 0;
	public const TYPE_GROUP = 1;
	public const TYPE_LINK = 3;
	public const TYPE_TEAM = 7;

	protected $channelId;
	protected $shareType;
	protected $receiver;
	protected $token;
	protected $password;
	protected $permissions;
	protected $requireApproval;
	protected $allowVoting;
	protected $showListenerCount;
	protected $allowImport;
	protected $expiration;
	protected $label;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('channelId', Types::BIGINT);
		$this->addType('shareType', Types::INTEGER);
		$this->addType('receiver', Types::STRING);
		$this->addType('token', Types::STRING);
		$this->addType('password', Types::STRING);
		$this->addType('permissions', Types::INTEGER);
		$this->addType('requireApproval', Types::BOOLEAN);
		$this->addType('allowVoting', Types::BOOLEAN);
		$this->addType('showListenerCount', Types::BOOLEAN);
		$this->addType('allowImport', Types::BOOLEAN);
		$this->addType('expiration', Types::BIGINT);
		$this->addType('label', Types::STRING);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
	}

	public function isExpired(int $nowSeconds): bool {
		$expiration = $this->getExpiration();

		return $expiration !== null && $expiration <= $nowSeconds;
	}

	/**
	 * The password hash is never serialised — only whether one is set.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'channelId' => $this->getChannelId(),
			'shareType' => $this->getShareType(),
			'receiver' => $this->getReceiver(),
			'token' => $this->getToken(),
			'hasPassword' => ($this->getPassword() ?? '') !== '',
			'permissions' => $this->getPermissions(),
			// Decided per share: an owner may trust the people they named while holding
			// whatever arrives through a link, and may want accounts voting but not
			// anonymous visitors.
			'requireApproval' => $this->getRequireApproval() !== false,
			'allowVoting' => $this->getAllowVoting() === true,
			'showListenerCount' => $this->getShowListenerCount() !== false,
			// Off unless the owner said otherwise — on a link this is what lets a stranger
			// spend the owner's quota, so it is never inherited by accident.
			'allowImport' => $this->getAllowImport() === true,
			'expiration' => $this->getExpiration(),
			'label' => $this->getLabel(),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
