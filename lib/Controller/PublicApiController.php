<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\AudioStreamService;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\PlaybackService;
use OCA\MusicRadio\Service\ShareService;
use OCA\MusicRadio\Service\TrackService;
use OCA\MusicRadio\Service\UploadService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\PublicShareController;
use OCP\IRequest;
use OCP\ISession;

/**
 * What an anonymous listener's player talks to.
 *
 * Extends the plain PublicShareController rather than the Auth one on purpose: an API
 * call should answer 404 for a bad token, not redirect to a password form. Being a
 * PublicShareController still means core's middleware validates the token and honours a
 * password already entered in this session, since both controllers read the same session
 * key.
 *
 * ⚠ When the middleware rejects a token it renders core's HTML "share not found" page,
 * even for these JSON routes. The client therefore treats any non-200 as "this link is
 * gone" rather than trying to parse the body.
 */
class PublicApiController extends PublicShareController {
	use TokenShareTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		ISession $session,
		private ShareService $shareService,
		private ChannelMapper $channelMapper,
		private TrackService $trackService,
		private PlaybackService $playbackService,
		private AudioStreamService $audioStreamService,
		private UploadService $uploadService,
		private Clock $clock,
	) {
		parent::__construct($appName, $request, $session);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function state(): DataResponse {
		$receivedAt = $this->clock->nowMillis();

		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(
			$this->playbackService->buildState($channel, $this->linkPermissions(), $receivedAt),
		);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function tracks(): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([
			'tracks' => $this->trackService->listForChannel($channel),
			'playlistVersion' => $channel->getPlaylistVersion(),
		]);
	}

	/**
	 * Audio for an anonymous listener.
	 *
	 * Deliberately not rate-limited. A media element's request pattern is unpredictable
	 * — Safari alone opens with a probe, then ranges, then re-requests on seek, and the
	 * next track is preloaded alongside — so a limit here would show up as playback
	 * randomly stopping. Guessing the token is already covered by the middleware's
	 * brute-force throttling.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function stream(int $trackId): Response {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$track = $this->trackService->find($channel, $trackId);

			return $this->audioStreamService->stream($channel, $track, $this->request);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}
	}

	/**
	 * Put a track on the channel, from someone with no account.
	 *
	 * Off unless the owner has turned uploading on for this particular link. The file is
	 * charged to the owner's storage, so the rate limit here is deliberately tight — a
	 * link that leaks should cost its owner a nuisance, not their quota.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 3600)]
	public function upload(): DataResponse {
		$channel = $this->channel();
		if ($channel === null) {
			return new DataResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if (!Permission::has($this->linkPermissions(), Permission::ADD_TRACKS)) {
			return new DataResponse(
				['error' => 'This link does not allow uploading'],
				Http::STATUS_FORBIDDEN,
			);
		}

		$upload = $this->request->getUploadedFile('file');
		if (!is_array($upload)) {
			return new DataResponse(['error' => 'No file was uploaded'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$track = $this->uploadService->storeForChannel($channel, $upload);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse(['track' => $track], Http::STATUS_CREATED);
	}

	/**
	 * What this particular link grants. Never more than LINK_ALLOWED, whatever is in the
	 * row — the mask is re-clamped here so a permission that stopped being appropriate
	 * for links cannot keep working on rows created while it was.
	 */
	private function linkPermissions(): int {
		return ($this->share()?->getPermissions() ?? Permission::NONE) & Permission::LINK_ALLOWED;
	}
}
