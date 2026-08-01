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
use OCA\MusicRadio\Service\ProgrammeStreamService;
use OCA\MusicRadio\Service\TrackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
		private ProgrammeStreamService $programmeStreamService,
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

	/**
	 * Serve a stretch of the channel's programme, starting at a position in it.
	 *
	 * This is what a listener actually plays. Handing over one track at a time means
	 * something has to load the next one when it ends, and on an iPhone with the screen
	 * locked nothing can: the page's timers are suspended, so the music simply stops. Half
	 * an hour of programme in a single body crosses every boundary inside it without the
	 * browser asking anyone for anything.
	 *
	 * NoCSRFRequired for the same reason as the per-track stream: an <audio> element cannot
	 * send a request token. Access is checked exactly as it is there.
	 *
	 * @param int $from position in the programme, in milliseconds
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function programme(int $id, int $from = 0): Response {
		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$this->permissionService->requirePermission($channel, $this->userId, Permission::LISTEN);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		$stream = $this->programmeStreamService->stream($channel, $from);
		if ($stream === null) {
			// Nothing to play: an empty playlist, or a channel that has run to its end
			// without looping. Answered as "not found" rather than an empty body so a
			// client can tell it apart from silence.
			return new DataResponse(['error' => 'Nothing to broadcast'], Http::STATUS_NOT_FOUND);
		}

		return $stream;
	}
}
