<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\ChannelService;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\ListenerPresence;
use OCA\MusicRadio\Service\PermissionService;
use OCA\MusicRadio\Service\PlaybackService;
use OCA\MusicRadio\Service\VoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class PlaybackController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChannelService $channelService,
		private PlaybackService $playbackService,
		private PermissionService $permissionService,
		private ListenerPresence $listenerPresence,
		private VoteService $voteService,
		private Clock $clock,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Server time, for the client's clock-offset estimate.
	 *
	 * Kept separate from the state endpoint and free of any database work so the probe
	 * is a tiny round-trip: the client fires several in a burst and keeps the one with
	 * the lowest delay, which only works if the response cost is negligible and
	 * consistent. Public because anonymous listeners on a shared link need it too, and
	 * the current time is not a secret.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function time(): DataResponse {
		return new DataResponse(['serverTimeMs' => $this->clock->nowMillis()]);
	}

	/**
	 * @param string|null $clientId a per-tab id the browser made up, so one browser
	 *                              polling twice for the same channel — `OnAir` and
	 *                              `GlobalPlayer` both do — counts once
	 * @param bool $listening whether audio is actually playing here, which is not
	 *                        something the request itself can tell us
	 */
	#[NoAdminRequired]
	public function state(int $id, ?string $clientId = null, bool $listening = false): DataResponse {
		// Sampled before any work, so the client's round-trip estimate reflects the
		// whole request rather than just the tail of it.
		$receivedAt = $this->clock->nowMillis();

		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$permissions = $this->permissionService->resolve($channel, $this->userId);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		// Deliberately here and not in buildState: that method is documented as a pure
		// read for good reasons, and this is the only place that knows who is asking.
		$listeners = $this->listenerPresence->record($id, $clientId, $listening);

		// Spend the votes of whatever has since played, and honour any that arrived.
		//
		// This has to be driven by something, and there is no track-boundary event to hang
		// it on — a channel is one continuous programme. It used to run only when somebody
		// voted, which meant a track that played with nobody voting afterwards kept its
		// votes for ever, and the next recompute treated them as current. The poll is the
		// one thing that happens reliably while a channel is playing.
		//
		// Cheap: it is debounced to once every VoteService::RECOMPUTE_EVERY_SECONDS per
		// channel, and the check is a comparison against a column already loaded, so the
		// common case costs nothing regardless of how many people are listening.
		if ($channel->getAllowVoting()) {
			$this->voteService->recomputeIfDue($channel);
		}

		return new DataResponse($this->playbackService->buildState(
			$channel,
			$permissions,
			$receivedAt,
			$listeners,
			$this->permissionService->shareRulesFor($channel, $this->userId)['showListenerCount'],
		));
	}

	/**
	 * Drive the broadcast. Gated on CONTROL, which is deliberately separate from being
	 * able to add tracks — that split is the whole point of the app.
	 *
	 * @param string $action play|pause|next|previous|seek|jumpTo
	 */
	#[NoAdminRequired]
	public function control(
		int $id,
		string $action,
		?int $offsetMs = null,
		?int $trackId = null,
		?int $expectedStateVersion = null,
	): DataResponse {
		$receivedAt = $this->clock->nowMillis();

		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$permissions = $this->permissionService->requirePermission($channel, $this->userId, Permission::CONTROL);

			// Optimistic concurrency: two open tabs (or two co-DJs) must not silently
			// overwrite each other. The caller gets the current state back and can retry.
			if ($expectedStateVersion !== null && $expectedStateVersion !== $channel->getStateVersion()) {
				return new DataResponse([
					'error' => 'The channel changed since you last loaded it',
					'state' => $this->playbackService->buildState(
						$channel, $permissions, $receivedAt, $this->listenerPresence->count($id),
					),
				], Http::STATUS_CONFLICT);
			}

			$channel = match ($action) {
				'play' => $this->playbackService->play($channel),
				'pause' => $this->playbackService->pause($channel),
				'next' => $this->playbackService->next($channel),
				'previous' => $this->playbackService->previous($channel),
				'seek' => $this->playbackService->seek($channel, $offsetMs ?? 0),
				'jumpTo' => $this->playbackService->jumpTo($channel, $trackId ?? 0),
				default => throw new MusicRadioException('Unknown playback action'),
			};
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		// Hand the new state straight back so the controller's own UI updates without
		// waiting for its next poll. Counted read-only rather than skipped: every one of
		// these replaces the client's state wholesale, and a missing count would blink
		// the listener figure out of the page on every press of play.
		return new DataResponse($this->playbackService->buildState(
			$channel, $permissions, $receivedAt, $this->listenerPresence->count($id),
		));
	}

	#[NoAdminRequired]
	public function settings(int $id, ?bool $loop = null, ?bool $shuffle = null): DataResponse {
		$receivedAt = $this->clock->nowMillis();

		try {
			$channel = $this->channelService->findReadable($id, $this->userId);
			$permissions = $this->permissionService->requirePermission($channel, $this->userId, Permission::CONTROL);
			$channel = $this->playbackService->updateSettings($channel, $loop, $shuffle);
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}

		return new DataResponse($this->playbackService->buildState(
			$channel, $permissions, $receivedAt, $this->listenerPresence->count($id),
		));
	}
}
