<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Exception\NotFoundException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\ChannelService;
use OCA\MusicRadio\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ChannelController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChannelService $channelService,
		private PermissionService $permissionService,
		private ChannelMapper $channelMapper,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		return new DataResponse(['channels' => $this->channelService->listForUser($this->userId)]);
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse($this->channelService->present($channel, $this->userId));
	}

	#[NoAdminRequired]
	public function create(string $title, ?string $description = null): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$channel = $this->channelService->create($this->userId, $title, $description);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse(
			$this->channelService->present($channel, $this->userId),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	public function update(int $id, ?string $title = null, ?string $description = null, ?int $coverFileId = null): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::MANAGE);
			$channel = $this->channelService->update($channel, $title, $description, $coverFileId);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse($this->channelService->present($channel, $this->userId));
	}

	/**
	 * Deleting is the owner's alone — not merely MANAGE. Someone granted management of a
	 * channel should be able to curate it, not destroy it out from under its owner.
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$channel = $this->findOwned($id, $this->userId);
			$this->channelService->delete($channel);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * @throws NotFoundException
	 */
	private function findOwned(int $id, string $userId): Channel {
		try {
			return $this->channelMapper->findOwnedBy($id, $userId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException('Channel not found');
		}
	}
}
