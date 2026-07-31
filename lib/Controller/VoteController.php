<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\ChannelService;
use OCA\MusicRadio\Service\PermissionService;
use OCA\MusicRadio\Service\VoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Voting, for people with an account.
 *
 * Their user id is the voter key, which is what makes one-vote-per-track mean anything
 * here — unlike the anonymous path, where it is a cookie that proves nothing.
 */
class VoteController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChannelService $channelService,
		private PermissionService $permissionService,
		private VoteService $voteService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Vote for a track, or take the vote back.
	 *
	 * Rate-limited well above what a person can do by hand: this writes a single row and
	 * the unique index makes repeats harmless, so the limit is here to stop a script
	 * hammering the recompute check rather than to ration voting.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function toggle(int $id, int $trackId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$channel = $this->channelService->findReadable($id, $this->userId);

			// The share that let this person in decides, rather than a bit on its permission
			// mask — two ways of saying the same thing only invites them to disagree.
			if (!$this->permissionService->shareRulesFor($channel, $this->userId)['allowVoting']) {
				return new DataResponse(
					['error' => 'Voting is not enabled for you on this channel'],
					Http::STATUS_FORBIDDEN,
				);
			}

			return new DataResponse($this->voteService->toggle($channel, $trackId, $this->userId));
		} catch (MusicRadioException $e) {
			return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
		}
	}
}
