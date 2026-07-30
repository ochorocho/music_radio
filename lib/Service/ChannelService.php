<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Db\ShareMapper;
use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Exception\NotFoundException;
use OCA\MusicRadio\Permission;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\IDBConnection;

class ChannelService {

	private const MAX_TITLE_LENGTH = 255;
	private const MAX_DESCRIPTION_LENGTH = 4000;

	public function __construct(
		private ChannelMapper $channelMapper,
		private TrackMapper $trackMapper,
		private ShareMapper $shareMapper,
		private ImportMapper $importMapper,
		private PermissionService $permissionService,
		private Clock $clock,
		private IDBConnection $db,
	) {
	}

	/**
	 * Channels this user owns, plus the ones shared with them, each annotated with the
	 * caller's effective permissions and its track count.
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws Exception
	 */
	public function listForUser(string $userId): array {
		$owned = $this->channelMapper->findAllOwnedBy($userId);
		$shared = $this->channelMapper->findAllSharedWith(
			$userId,
			$this->permissionService->groupIdsOf($userId),
			$this->permissionService->teamIdsOf($userId),
			$this->clock->nowSeconds(),
		);

		$out = [];
		foreach ([...$owned, ...$shared] as $channel) {
			$out[] = $this->present($channel, $userId);
		}

		return $out;
	}

	/**
	 * @throws NotFoundException when the channel is missing, or the caller may not hear it
	 * @throws Exception
	 */
	public function findReadable(int $id, ?string $userId): Channel {
		try {
			$channel = $this->channelMapper->find($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException('Channel not found');
		}

		if (!Permission::has($this->permissionService->resolve($channel, $userId), Permission::LISTEN)) {
			// Same response as "does not exist": a stranger must not be able to probe
			// which channel ids are real.
			throw new NotFoundException('Channel not found');
		}

		return $channel;
	}

	/**
	 * @throws MusicRadioException on invalid input
	 * @throws Exception
	 */
	public function create(string $userId, string $title, ?string $description = null): Channel {
		$now = $this->clock->nowSeconds();

		$channel = new Channel();
		$channel->setUserId($userId);
		$channel->setTitle($this->validateTitle($title));
		$channel->setDescription($this->validateDescription($description));
		$channel->setCoverFileId(null);
		// A new channel starts silent and at the top of the programme; nothing plays
		// until its owner presses play.
		$channel->setStartedAtMs($this->clock->nowMillis());
		$channel->setEpochOffsetMs(0);
		$channel->setPaused(true);
		$channel->setLoopEnabled(true);
		$channel->setShuffle(false);
		$channel->setShuffleSeed(0);
		$channel->setStateVersion(1);
		$channel->setPlaylistVersion(1);
		$channel->setCreatedAt($now);
		$channel->setUpdatedAt($now);

		return $this->channelMapper->insert($channel);
	}

	/**
	 * Update the channel's descriptive fields. Playback settings are deliberately not
	 * editable here — they move the timeline and belong to the playback endpoints.
	 *
	 * @throws MusicRadioException on invalid input
	 * @throws Exception
	 */
	public function update(Channel $channel, ?string $title, ?string $description, ?int $coverFileId): Channel {
		if ($title !== null) {
			$channel->setTitle($this->validateTitle($title));
		}
		if ($description !== null) {
			$channel->setDescription($this->validateDescription($description));
		}
		if ($coverFileId !== null) {
			$channel->setCoverFileId($coverFileId > 0 ? $coverFileId : null);
		}

		$channel->setStateVersion($channel->getStateVersion() + 1);
		$channel->setUpdatedAt($this->clock->nowSeconds());

		return $this->channelMapper->update($channel);
	}

	/**
	 * Delete a channel and everything hanging off it. There are no database foreign
	 * keys (Nextcloud convention), so the cascade is explicit — and transactional, so a
	 * failure part-way cannot leave orphaned tracks or shares pointing at a dead channel.
	 *
	 * @throws Exception
	 */
	public function delete(Channel $channel): void {
		$this->db->beginTransaction();
		try {
			$this->trackMapper->deleteAllForChannel($channel->getId());
			$this->shareMapper->deleteAllForChannel($channel->getId());
			// An import still running will find its channel gone and give up; removing the
			// rows here keeps the queue from carrying work for a channel nobody can see.
			$this->importMapper->deleteAllForChannel($channel->getId());
			$this->channelMapper->delete($channel);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * The channel as the API returns it: the entity plus what this caller may do with it.
	 *
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	public function present(Channel $channel, ?string $userId): array {
		$permissions = $this->permissionService->resolve($channel, $userId);

		return array_merge($channel->jsonSerialize(), [
			'isOwner' => $userId !== null && $channel->getUserId() === $userId,
			'permissions' => $permissions,
			'can' => Permission::describe($permissions),
			'trackCount' => $this->trackMapper->countForChannel($channel->getId()),
		]);
	}

	/**
	 * @throws MusicRadioException
	 */
	private function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '') {
			throw new MusicRadioException('Title must not be empty');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new MusicRadioException('Title is too long');
		}

		return $title;
	}

	/**
	 * @throws MusicRadioException
	 */
	private function validateDescription(?string $description): ?string {
		if ($description === null) {
			return null;
		}
		$description = trim($description);
		if ($description === '') {
			return null;
		}
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new MusicRadioException('Description is too long');
		}

		return $description;
	}
}
