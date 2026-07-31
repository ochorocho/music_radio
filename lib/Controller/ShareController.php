<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Db\Share;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\ChannelService;
use OCA\MusicRadio\Service\PermissionService;
use OCA\MusicRadio\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ShareController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChannelService $channelService,
		private ShareService $shareService,
		private PermissionService $permissionService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(int $id): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::SHARE);
			$shares = $this->shareService->listForChannel($channel);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse([
			'shares' => array_map(fn (Share $share) => $this->shareService->present($share), $shares),
			// So the UI can reflect what this server permits rather than offering options
			// the server will refuse.
			'capabilities' => $this->shareService->capabilities((string)$this->userId),
		]);
	}

	#[NoAdminRequired]
	public function create(
		int $id,
		int $shareType,
		?string $receiver = null,
		int $permissions = Permission::PRESET_LISTENER,
		?int $expiration = null,
		?string $label = null,
		?string $password = null,
	): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::SHARE);

			$share = $this->shareService->create(
				$channel,
				$this->userId,
				$shareType,
				$receiver,
				$permissions,
				$expiration,
				$label,
				$password,
			);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		// A new share changes who can reach the channel, so any cached resolution for
		// this request is now wrong.
		$this->permissionService->clearCache();
		$this->channelService->syncVotingMode($channel);

		return new DataResponse($this->shareService->present($share), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function update(
		int $id,
		int $shareId,
		?int $permissions = null,
		?int $expiration = null,
		?string $label = null,
		?bool $requireApproval = null,
		?bool $allowVoting = null,
		?bool $showListenerCount = null,
		?bool $allowImport = null,
	): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::SHARE);

			$share = $this->shareService->find($channel, $shareId);
			$share = $this->shareService->update(
				$share,
				$permissions,
				$expiration,
				$label,
				$requireApproval,
				$allowVoting,
				$showListenerCount,
				$allowImport,
			);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		$this->permissionService->clearCache();
		// Voting is decided per share now, but the running order is a property of the
		// channel — turning the switch on for one audience is what puts the whole playlist
		// in vote order. See ChannelService::syncVotingMode.
		$this->channelService->syncVotingMode($channel);

		return new DataResponse($this->shareService->present($share));
	}

	/**
	 * Set or clear a public link's password.
	 *
	 * Deliberately not PasswordConfirmationRequired: the same session can already create
	 * a link carrying any password it likes, so demanding re-confirmation to *change* one
	 * guards nothing and only makes the flow awkward.
	 */
	#[NoAdminRequired]
	public function setPassword(int $id, int $shareId, ?string $password = null): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::SHARE);

			$share = $this->shareService->find($channel, $shareId);
			$share = $this->shareService->setPassword($share, $password);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse($this->shareService->present($share));
	}

	#[NoAdminRequired]
	public function destroy(int $id, int $shareId): DataResponse {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::SHARE);

			$share = $this->shareService->find($channel, $shareId);
			$this->shareService->delete($share);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		$this->permissionService->clearCache();
		// Removing the last share that could vote puts the author's order back.
		$this->channelService->syncVotingMode($channel);

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}
}
