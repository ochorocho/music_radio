<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\AudioStreamService;
use OCA\MusicRadio\Service\ChannelService;
use OCA\MusicRadio\Service\PermissionService;
use OCA\MusicRadio\Service\TrackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class StreamController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChannelService $channelService,
		private TrackService $trackService,
		private PermissionService $permissionService,
		private AudioStreamService $audioStreamService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Serve one track's audio.
	 *
	 * NoCSRFRequired because the URL is consumed by an <audio> element, which cannot
	 * send a request token. Access is still fully checked: the channel must be readable
	 * by this user and the track must belong to that channel.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function track(int $id, int $trackId): Response {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::LISTEN);
			$track = $this->trackService->find($channel, $trackId);

			return $this->audioStreamService->stream($channel, $track, $this->request);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}
	}
}
