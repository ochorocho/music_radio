<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Share;
use OCA\MusicRadio\Db\ShareMapper;
use OCA\MusicRadio\Exception\ForbiddenException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\PermissionService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Teams\ITeamManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Working out what someone may do with a channel.
 *
 * A person can reach a channel by several routes at once, so the interesting cases are
 * the ones where those routes disagree.
 */
class PermissionServiceTest extends TestCase {

	private const NOW = 1_700_000_000;

	private ShareMapper&MockObject $shareMapper;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private ITeamManager&MockObject $teamManager;
	private PermissionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->shareMapper = $this->createMock(ShareMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->teamManager = $this->createMock(ITeamManager::class);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->groupManager->method('getUserGroupIds')->willReturn([]);
		$this->teamManager->method('getTeamsForUser')->willReturn([]);

		$this->service = new PermissionService(
			$this->shareMapper,
			$this->groupManager,
			$this->userManager,
			$this->teamManager,
			$clock,
			new NullLogger(),
		);
	}

	private static function channel(string $owner = 'alice', int $id = 1): Channel {
		$channel = new Channel();
		$channel->setId($id);
		$channel->setUserId($owner);

		return $channel;
	}

	private static function share(int $type, ?string $receiver, int $permissions, ?int $expiration = null): Share {
		$share = new Share();
		$share->setShareType($type);
		$share->setReceiver($receiver);
		$share->setPermissions($permissions);
		$share->setExpiration($expiration);

		return $share;
	}

	/**
	 * @param Share[] $shares
	 */
	private function withShares(array $shares): void {
		$this->shareMapper->method('findForRecipient')->willReturn($shares);
	}

	public function testTheOwnerCanDoEverything(): void {
		$this->withShares([]);

		self::assertSame(Permission::ALL, $this->service->resolve(self::channel('alice'), 'alice'));
	}

	public function testSomeoneWithNoShareCanDoNothing(): void {
		$this->withShares([]);

		self::assertSame(Permission::NONE, $this->service->resolve(self::channel('alice'), 'bob'));
	}

	/**
	 * An anonymous request never resolves here — link access is handled through the
	 * token, not through a user id.
	 */
	public function testAnAnonymousRequestGetsNothing(): void {
		$this->withShares([]);

		self::assertSame(Permission::NONE, $this->service->resolve(self::channel('alice'), null));
		self::assertSame(Permission::NONE, $this->service->resolve(self::channel('alice'), ''));
	}

	public function testADirectShareGrantsWhatItSays(): void {
		$this->withShares([
			self::share(Share::TYPE_USER, 'bob', Permission::PRESET_CONTRIBUTOR),
		]);

		$resolved = $this->service->resolve(self::channel('alice'), 'bob');

		self::assertTrue(Permission::has($resolved, Permission::ADD_TRACKS));
		self::assertFalse(Permission::has($resolved, Permission::CONTROL));
	}

	/**
	 * Reaching a channel two ways gives the union of both, which is what people expect
	 * and what core's own sharing does.
	 */
	public function testSeveralRoutesCombineIntoTheUnionOfWhatTheyGrant(): void {
		$this->withShares([
			self::share(Share::TYPE_USER, 'bob', Permission::LISTEN),
			self::share(Share::TYPE_GROUP, 'staff', Permission::LISTEN | Permission::ADD_TRACKS),
			self::share(Share::TYPE_TEAM, 'djs', Permission::LISTEN | Permission::CONTROL),
		]);

		$resolved = $this->service->resolve(self::channel('alice'), 'bob');

		self::assertTrue(Permission::has($resolved, Permission::ADD_TRACKS));
		self::assertTrue(Permission::has($resolved, Permission::CONTROL));
		self::assertFalse(Permission::has($resolved, Permission::SHARE));
	}

	public function testAnExpiredShareGrantsNothing(): void {
		$this->withShares([
			self::share(Share::TYPE_USER, 'bob', Permission::ALL, self::NOW - 1),
		]);

		self::assertSame(Permission::NONE, $this->service->resolve(self::channel('alice'), 'bob'));
	}

	public function testAShareExpiringInTheFutureStillCounts(): void {
		$this->withShares([
			self::share(Share::TYPE_USER, 'bob', Permission::LISTEN, self::NOW + 3600),
		]);

		self::assertSame(Permission::LISTEN, $this->service->resolve(self::channel('alice'), 'bob'));
	}

	/**
	 * An expired route must not quietly keep contributing its permissions to the union.
	 */
	public function testAnExpiredShareIsIgnoredEvenAlongsideALiveOne(): void {
		$this->withShares([
			self::share(Share::TYPE_USER, 'bob', Permission::LISTEN),
			self::share(Share::TYPE_GROUP, 'staff', Permission::ALL, self::NOW - 60),
		]);

		$resolved = $this->service->resolve(self::channel('alice'), 'bob');

		self::assertSame(Permission::LISTEN, $resolved);
		self::assertFalse(Permission::has($resolved, Permission::CONTROL));
	}

	public function testRequirePermissionPassesWhenTheBitIsPresent(): void {
		$this->withShares([
			self::share(Share::TYPE_USER, 'bob', Permission::PRESET_CONTRIBUTOR),
		]);

		$resolved = $this->service->requirePermission(self::channel('alice'), 'bob', Permission::ADD_TRACKS);

		self::assertTrue(Permission::has($resolved, Permission::ADD_TRACKS));
	}

	public function testRequirePermissionRefusesWhenItIsNot(): void {
		$this->withShares([
			self::share(Share::TYPE_USER, 'bob', Permission::PRESET_CONTRIBUTOR),
		]);

		$this->expectException(ForbiddenException::class);
		$this->service->requirePermission(self::channel('alice'), 'bob', Permission::CONTROL);
	}

	public function testGroupMembershipIsPassedToTheLookup(): void {
		$group = $this->createMock(IGroup::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['staff', 'djs']);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$shareMapper = $this->createMock(ShareMapper::class);
		$shareMapper->expects(self::once())
			->method('findForRecipient')
			->with(1, 'bob', ['staff', 'djs'], [])
			->willReturn([]);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		$teamManager = $this->createMock(ITeamManager::class);
		$teamManager->method('getTeamsForUser')->willReturn([]);

		$service = new PermissionService($shareMapper, $groupManager, $userManager, $teamManager, $clock, new NullLogger());
		$service->resolve(self::channel('alice'), 'bob');

		unset($group);
	}

	/**
	 * Teams come from the optional Circles app. Its absence must leave the rest of
	 * sharing working rather than taking the whole resolution down with it.
	 */
	public function testTeamsBeingUnavailableIsNotFatal(): void {
		$teamManager = $this->createMock(ITeamManager::class);
		$teamManager->method('getTeamsForUser')->willThrowException(new \RuntimeException('circles is disabled'));

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$shareMapper = $this->createMock(ShareMapper::class);
		$shareMapper->method('findForRecipient')->willReturn([
			self::share(Share::TYPE_USER, 'bob', Permission::LISTEN),
		]);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		$service = new PermissionService($shareMapper, $groupManager, $userManager, $teamManager, $clock, new NullLogger());

		self::assertSame(Permission::LISTEN, $service->resolve(self::channel('alice'), 'bob'));
	}

	// --------------------------------------------------- removing another's track

	public function testCuratorsCanRemoveAnybodysTrack(): void {
		$permissions = Permission::normalize(Permission::EDIT_PLAYLIST);

		self::assertTrue($this->service->canRemoveTrack($permissions, 'alice', 'bob'));
	}

	public function testContributorsCanTakeBackOnlyTheirOwnTrack(): void {
		$permissions = Permission::PRESET_CONTRIBUTOR;

		self::assertTrue($this->service->canRemoveTrack($permissions, 'bob', 'bob'));
		self::assertFalse($this->service->canRemoveTrack($permissions, 'alice', 'bob'));
	}

	public function testPlainListenersCanRemoveNothing(): void {
		self::assertFalse($this->service->canRemoveTrack(Permission::LISTEN, 'bob', 'bob'));
	}

	public function testAnAnonymousVisitorCanRemoveNothing(): void {
		self::assertFalse($this->service->canRemoveTrack(Permission::PRESET_CONTRIBUTOR, 'bob', null));
	}
}
