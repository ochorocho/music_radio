<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Exception\ForbiddenException;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\ChannelService;
use OCA\MusicRadio\Service\PermissionService;
use OCA\MusicRadio\Service\TrackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class TrackController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChannelService $channelService,
		private TrackService $trackService,
		private PermissionService $permissionService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse([
			'tracks' => $this->trackService->listForChannel($channel),
			'playlistVersion' => $channel->getPlaylistVersion(),
		]);
	}

	/**
	 * @param int[] $fileIds
	 * @param array<int|string, int> $durationHints fileId => ms, measured by the browser
	 */
	#[NoAdminRequired]
	public function create(int $id, array $fileIds = [], array $durationHints = []): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::ADD_TRACKS);

			$normalisedHints = [];
			foreach ($durationHints as $fileId => $ms) {
				$normalisedHints[(int)$fileId] = (int)$ms;
			}

			$result = $this->trackService->add(
				$channel,
				$this->userId,
				array_map('intval', $fileIds),
				$normalisedHints,
			);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse([
			'tracks' => $result['added'],
			'skipped' => $result['skipped'],
		], Http::STATUS_CREATED);
	}

	/**
	 * @param int[] $trackIds the full playlist in its new order
	 */
	#[NoAdminRequired]
	public function reorder(int $id, array $trackIds = []): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::EDIT_PLAYLIST);
			$this->trackService->reorder($channel, $trackIds);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse(['tracks' => $this->trackService->listForChannel($channel)]);
	}

	#[NoAdminRequired]
	public function destroy(int $id, int $trackId): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			// Not a straight permission bit: someone with only ADD_TRACKS may take back
			// what they themselves added, but nothing else.
			$permissions = $this->permissionService->requirePermission($channel, $this->userId, Permission::ADD_TRACKS);
			$track = $this->trackService->find($channel, $trackId);

			if (!$this->permissionService->canRemoveTrack($permissions, $track->getAddedBy(), $this->userId)) {
				throw new ForbiddenException('You can only remove tracks you added yourself');
			}

			$this->trackService->remove($channel, $track);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	#[NoAdminRequired]
	public function update(int $id, int $trackId, ?int $durationMs = null, ?bool $disabled = null): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::EDIT_PLAYLIST);
			$track = $this->trackService->find($channel, $trackId);

			if ($durationMs !== null) {
				$track = $this->trackService->setDuration($channel, $track, $durationMs);
			}

			if ($disabled !== null) {
				$track = $this->trackService->setDisabled($channel, $track, $disabled);
			}
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse($track);
	}
}
