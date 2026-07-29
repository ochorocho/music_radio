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
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getAddedBy()
 * @method void setAddedBy(string $addedBy)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method int getShuffleOrder()
 * @method void setShuffleOrder(int $shuffleOrder)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getArtist()
 * @method void setArtist(?string $artist)
 * @method string|null getAlbum()
 * @method void setAlbum(?string $album)
 * @method int|null getDurationMs()
 * @method void setDurationMs(?int $durationMs)
 * @method int getDurationSource()
 * @method void setDurationSource(int $durationSource)
 * @method string|null getMimetype()
 * @method void setMimetype(?string $mimetype)
 * @method int|null getSize()
 * @method void setSize(?int $size)
 * @method bool getUnavailable()
 * @method void setUnavailable(bool $unavailable)
 * @method bool getDisabled()
 * @method void setDisabled(bool $disabled)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Track extends Entity implements \JsonSerializable {

	/** Duration has not been determined yet — the track is excluded from the timeline. */
	public const DURATION_SOURCE_UNKNOWN = 0;
	/** Read from the file's own headers by getID3. Authoritative. */
	public const DURATION_SOURCE_PROBE = 1;
	/** Measured by the adding browser and accepted because the probe failed. */
	public const DURATION_SOURCE_CLIENT = 2;
	/** Corrected by hand. */
	public const DURATION_SOURCE_MANUAL = 3;

	/**
	 * Stored in `added_by` for a track uploaded through a public link, where there is no
	 * account to attribute it to. `?` is not a legal character in a Nextcloud user id, so
	 * this can never be mistaken for a real one.
	 */
	public const ADDED_BY_PUBLIC_LINK = '?public-link';

	protected $channelId;
	protected $fileId;
	protected $addedBy;
	protected $sortOrder;
	protected $shuffleOrder;
	protected $title;
	protected $artist;
	protected $album;
	protected $durationMs;
	protected $durationSource;
	protected $mimetype;
	protected $size;
	protected $unavailable;
	protected $disabled;
	protected $createdAt;

	public function __construct() {
		$this->addType('channelId', Types::BIGINT);
		$this->addType('fileId', Types::BIGINT);
		$this->addType('addedBy', Types::STRING);
		$this->addType('sortOrder', Types::INTEGER);
		$this->addType('shuffleOrder', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('artist', Types::STRING);
		$this->addType('album', Types::STRING);
		$this->addType('durationMs', Types::INTEGER);
		$this->addType('durationSource', Types::SMALLINT);
		$this->addType('mimetype', Types::STRING);
		$this->addType('size', Types::BIGINT);
		$this->addType('unavailable', Types::BOOLEAN);
		$this->addType('disabled', Types::BOOLEAN);
		$this->addType('createdAt', Types::BIGINT);
	}

	/**
	 * Whether this track takes part in the broadcast timeline. A track without a known
	 * duration cannot be placed on the timeline at all, and an unreadable file would
	 * broadcast silence, so both are skipped.
	 */
	public function isPlayable(): bool {
		return !$this->getUnavailable()
			// Skipped on purpose by whoever runs the channel, as opposed to broken.
			&& !$this->getDisabled()
			&& $this->getDurationMs() !== null
			&& $this->getDurationMs() > 0;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'channelId' => $this->getChannelId(),
			'fileId' => $this->getFileId(),
			'addedBy' => $this->getAddedBy(),
			'uploadedViaLink' => $this->getAddedBy() === self::ADDED_BY_PUBLIC_LINK,
			'sortOrder' => $this->getSortOrder(),
			'title' => $this->getTitle(),
			'artist' => $this->getArtist(),
			'album' => $this->getAlbum(),
			'durationMs' => $this->getDurationMs(),
			'durationSource' => $this->getDurationSource(),
			'mimetype' => $this->getMimetype(),
			'size' => $this->getSize(),
			'unavailable' => $this->getUnavailable(),
			'disabled' => $this->getDisabled(),
			'playable' => $this->isPlayable(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
