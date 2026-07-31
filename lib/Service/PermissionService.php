<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ShareMapper;
use OCA\MusicRadio\Exception\ForbiddenException;
use OCA\MusicRadio\Permission;
use OCP\Cache\CappedMemoryCache;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Teams\ITeamManager;
use Psr\Log\LoggerInterface;

/**
 * Works out what a given user may do with a given channel.
 *
 * A user can reach a channel through several routes at once — directly, via a group,
 * via a team — so the effective mask is the bitwise OR of every matching share. Being
 * in two groups with different grants gives you the union, which is what users expect
 * and what core's own sharing does.
 */
class PermissionService {

	/** @var CappedMemoryCache<int> */
	private CappedMemoryCache $resolved;
	/** @var array<string, string[]> */
	private array $groupIdCache = [];
	/** @var array<string, string[]> */
	private array $teamIdCache = [];

	public function __construct(
		private ShareMapper $shareMapper,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ITeamManager $teamManager,
		private VisitorIdentity $visitorIdentity,
		private Clock $clock,
		private LoggerInterface $logger,
	) {
		$this->resolved = new CappedMemoryCache();
	}

	/**
	 * The caller's effective permissions on this channel. `null` means an anonymous
	 * request — those reach a channel only through a link token, which
	 * ShareService handles separately, so they get nothing here.
	 */
	/**
	 * What the shares granting this person access say about approval and voting.
	 *
	 * Both are decided per share now, so somebody reached by two of them — named directly
	 * and through a group, say — has to be given one answer. The generous one wins in both
	 * cases, matching how the permission mask above is combined: access granted twice is
	 * not access halved.
	 *
	 * The owner is not a share and is governed by the channel: their own additions are
	 * never held, and they may vote whenever the channel is voting at all.
	 *
	 * @return array{requireApproval: bool, allowVoting: bool, showListenerCount: bool, allowImport: bool}
	 */
	public function shareRulesFor(Channel $channel, ?string $userId): array {
		if ($userId !== null && $userId !== '' && $channel->getUserId() === $userId) {
			// The owner sees their own audience figure whatever any share says, and imports
			// on their own channel subject only to the administrator's switch. Importing
			// spends their storage and their server's time, so there is nobody left to ask.
			return [
				'requireApproval' => false,
				'allowVoting' => $channel->getAllowVoting() === true,
				'showListenerCount' => true,
				'allowImport' => true,
			];
		}

		if ($userId === null || $userId === '') {
			return [
				'requireApproval' => true,
				'allowVoting' => false,
				'showListenerCount' => false,
				'allowImport' => false,
			];
		}

		$shares = $this->shareMapper->findForRecipient(
			$channel->getId(),
			$userId,
			$this->groupIdsOf($userId),
			$this->teamIdsOf($userId),
		);

		$now = $this->clock->nowSeconds();
		$requireApproval = true;
		$allowVoting = false;
		$showListenerCount = false;
		$allowImport = false;
		$found = false;

		foreach ($shares as $share) {
			if ($share->isExpired($now)) {
				continue;
			}
			$found = true;
			$requireApproval = $requireApproval && $share->getRequireApproval() !== false;
			$allowVoting = $allowVoting || $share->getAllowVoting() === true;
			$showListenerCount = $showListenerCount || $share->getShowListenerCount() !== false;
			$allowImport = $allowImport || $share->getAllowImport() === true;
		}

		return [
			// Somebody with no share at all cannot add anything anyway; answering "hold it"
			// is the safe reading of a question that should not arise.
			'requireApproval' => $found ? $requireApproval : true,
			// The channel term is not a second switch any more — it is the derived flag
			// ChannelService::syncVotingMode maintains, and it is true exactly when some
			// share says so. Kept in the expression anyway, because it is also what decides
			// whether the playlist is in vote order at all: letting somebody vote on a
			// channel that is not counting votes would be a promise nothing keeps.
			'allowVoting' => $found && $allowVoting && $channel->getAllowVoting(),
			'showListenerCount' => $found && $showListenerCount,
			// The share alone, plus the administrator's switch above it. The channel used to
			// have a say too, which meant the same question was answered in two places.
			'allowImport' => $found && $allowImport,
		];
	}

	public function resolve(Channel $channel, ?string $userId): int {
		if ($userId === null || $userId === '') {
			return Permission::NONE;
		}

		// The owner is never a share row.
		if ($channel->getUserId() === $userId) {
			return Permission::ALL;
		}

		$key = $channel->getId() . '#' . $userId;
		$cached = $this->resolved->get($key);
		if ($cached !== null) {
			return $cached;
		}

		$shares = $this->shareMapper->findForRecipient(
			$channel->getId(),
			$userId,
			$this->groupIdsOf($userId),
			$this->teamIdsOf($userId),
		);

		$now = $this->clock->nowSeconds();
		$permissions = Permission::NONE;
		foreach ($shares as $share) {
			if ($share->isExpired($now)) {
				continue;
			}
			$permissions |= $share->getPermissions();
		}

		$permissions = Permission::normalize($permissions);
		$this->resolved->set($key, $permissions);

		return $permissions;
	}

	/**
	 * @throws ForbiddenException
	 */
	public function requirePermission(Channel $channel, ?string $userId, int $required): int {
		$permissions = $this->resolve($channel, $userId);
		if (!Permission::has($permissions, $required)) {
			throw new ForbiddenException('You do not have permission to do that on this channel');
		}

		return $permissions;
	}

	/**
	 * @return string[]
	 */
	public function groupIdsOf(string $userId): array {
		if (isset($this->groupIdCache[$userId])) {
			return $this->groupIdCache[$userId];
		}

		$user = $this->userManager->get($userId);
		$groupIds = $user === null ? [] : $this->groupManager->getUserGroupIds($user);

		return $this->groupIdCache[$userId] = $groupIds;
	}

	/**
	 * @return string[]
	 */
	public function teamIdsOf(string $userId): array {
		if (isset($this->teamIdCache[$userId])) {
			return $this->teamIdCache[$userId];
		}

		$teamIds = [];
		try {
			foreach ($this->teamManager->getTeamsForUser($userId) as $team) {
				$teamIds[] = $team->getId();
			}
		} catch (\Throwable $e) {
			// getTeamsForUser() delegates to the Teams (circles) app, which is optional
			// and may be disabled. Team shares simply do not apply then.
			$this->logger->debug('Could not resolve teams for user; ignoring team shares', [
				'app' => 'music_radio',
				'exception' => $e,
			]);
		}

		return $this->teamIdCache[$userId] = $teamIds;
	}

	/**
	 * Whether this user may remove this specific track. Someone with only ADD_TRACKS is
	 * allowed to take back what they themselves added — anything more would let a
	 * contributor edit the owner's playlist, anything less would leave them unable to
	 * undo their own mistake.
	 */
	public function canRemoveTrack(int $permissions, string $trackAddedBy, ?string $userId): bool {
		if (Permission::has($permissions, Permission::EDIT_PLAYLIST)) {
			return true;
		}

		return Permission::has($permissions, Permission::ADD_TRACKS)
			&& $userId !== null
			&& $trackAddedBy === $userId;
	}

	/**
	 * The same rule for somebody with no account, whose browser key stands in for a user
	 * id.
	 *
	 * Kept apart from the method above rather than folded into it: that one compares
	 * against a Nextcloud user id, this one against a value the browser supplied, and the
	 * two must not be able to be passed to each other by accident. A visitor key can never
	 * equal a user id anyway — it is prefixed with a character user ids cannot contain —
	 * but the signatures say so too.
	 */
	public function canVisitorRemoveTrack(int $linkPermissions, string $trackAddedBy, ?string $visitorKey): bool {
		// The curator branch mirrors canRemoveTrack above. A link can now be given
		// EDIT_PLAYLIST, and a curator who may reorder anyone's track but not remove one
		// would be a strange half-measure — the two go together on the signed-in side and
		// are described to the owner as one switch.
		if (Permission::has($linkPermissions, Permission::EDIT_PLAYLIST)) {
			return true;
		}

		return Permission::has($linkPermissions, Permission::ADD_TRACKS)
			&& $this->visitorIdentity->owns($trackAddedBy, $visitorKey);
	}

	/**
	 * Only used by tests and long-lived processes; a request normally resolves each
	 * (channel, user) pair once.
	 */
	public function clearCache(): void {
		$this->resolved->clear();
		$this->groupIdCache = [];
		$this->teamIdCache = [];
	}
}
