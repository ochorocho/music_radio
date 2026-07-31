<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One person wanting to hear one track sooner.
 *
 * @method int getChannelId()
 * @method void setChannelId(int $channelId)
 * @method int getTrackId()
 * @method void setTrackId(int $trackId)
 * @method string getVoterKey()
 * @method void setVoterKey(string $voterKey)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Vote extends Entity {

	protected $channelId;
	protected $trackId;
	protected $voterKey;
	protected $createdAt;

	public function __construct() {
		$this->addType('channelId', Types::BIGINT);
		$this->addType('trackId', Types::BIGINT);
		$this->addType('voterKey', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
	}
}
