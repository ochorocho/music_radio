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
	 * Only used by tests and long-lived processes; a request normally resolves each
	 * (channel, user) pair once.
	 */
	public function clearCache(): void {
		$this->resolved->clear();
		$this->groupIdCache = [];
		$this->teamIdCache = [];
	}
}
