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
use OCA\MusicRadio\Service\VisitorIdentity;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
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
	private VisitorIdentity $visitorIdentity;
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

		// Real rather than mocked: it is a handful of string rules with no collaborators
		// worth faking, and the visitor cases below are about those rules being right.
		$this->visitorIdentity = new VisitorIdentity(
			$this->createMock(IRequest::class),
			$this->createMock(ISecureRandom::class),
		);

		$this->service = new PermissionService(
			$this->shareMapper,
			$this->groupManager,
			$this->userManager,
			$this->teamManager,
			$this->visitorIdentity,
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

		$service = new PermissionService($shareMapper, $groupManager, $userManager, $teamManager, $this->visitorIdentity, $clock, new NullLogger());
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

		$service = new PermissionService($shareMapper, $groupManager, $userManager, $teamManager, $this->visitorIdentity, $clock, new NullLogger());

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

	// ------------------------------------------------ the same rule, through a link

	public function testAVisitorTakesBackOnlyWhatTheyAdded(): void {
		$mine = '?link:visitor-key';

		self::assertTrue($this->service->canVisitorRemoveTrack(
			Permission::PRESET_CONTRIBUTOR, $mine, 'visitor-key',
		));
		self::assertFalse($this->service->canVisitorRemoveTrack(
			Permission::PRESET_CONTRIBUTOR, '?link:somebody-else', 'visitor-key',
		));
	}

	/**
	 * A link can be given EDIT_PLAYLIST now, and it means the same thing there as anywhere
	 * else. Reordering anybody's track while being unable to remove one would be a strange
	 * half-permission, and the owner is offered the two as a single switch.
	 */
	public function testALinkThatCuratesCanRemoveAnyTrack(): void {
		$curator = Permission::PRESET_CONTRIBUTOR | Permission::EDIT_PLAYLIST;

		self::assertTrue($this->service->canVisitorRemoveTrack($curator, '?link:somebody-else', 'visitor-key'));
		self::assertTrue($this->service->canVisitorRemoveTrack($curator, 'alice', 'visitor-key'));
	}

	/**
	 * With no cookie there is no identity to compare against, so an uploader's own row is
	 * not theirs to take back either — but curating still is, because that answer does not
	 * depend on who is asking.
	 */
	public function testAVisitorWithNoKeyRemovesNothingUnlessTheyCurate(): void {
		self::assertFalse($this->service->canVisitorRemoveTrack(
			Permission::PRESET_CONTRIBUTOR, '?link:visitor-key', null,
		));
		self::assertTrue($this->service->canVisitorRemoveTrack(
			Permission::PRESET_CONTRIBUTOR | Permission::EDIT_PLAYLIST, '?link:visitor-key', null,
		));
	}

	public function testALinkThatOnlyListensRemovesNothing(): void {
		self::assertFalse($this->service->canVisitorRemoveTrack(
			Permission::LISTEN, '?link:visitor-key', 'visitor-key',
		));
	}

	/**
	 * The rule ImportController reads. It used to check the channel instead, which meant a
	 * sharee who was told `canImport: false` by the tracks endpoint could nevertheless post
	 * an import and have it accepted.
	 */
	public function testAShareThatMayNotImportIsToldSo(): void {
		$this->withShares([self::shareWithRules(['allowImport' => false])]);

		self::assertFalse($this->service->shareRulesFor(self::openChannel(), 'bob')['allowImport']);
	}

	// --------------------------------------------------- the rules a share carries

	/**
	 * @param array{requireApproval?: bool, allowVoting?: bool, showListenerCount?: bool, allowImport?: bool} $rules
	 */
	private static function shareWithRules(array $rules, ?int $expiration = null): Share {
		$share = self::share(Share::TYPE_USER, 'bob', Permission::PRESET_CONTRIBUTOR, $expiration);
		$share->setRequireApproval($rules['requireApproval'] ?? true);
		$share->setAllowVoting($rules['allowVoting'] ?? false);
		$share->setShowListenerCount($rules['showListenerCount'] ?? true);
		$share->setAllowImport($rules['allowImport'] ?? false);

		return $share;
	}

	private static function openChannel(string $owner = 'alice'): Channel {
		$channel = self::channel($owner);
		$channel->setAllowVoting(true);
		$channel->setAllowImport(true);

		return $channel;
	}

	public function testAShareDecidesTheRulesForThePersonItLetIn(): void {
		$this->withShares([
			self::shareWithRules([
				'requireApproval' => false,
				'allowVoting' => true,
				'showListenerCount' => true,
				'allowImport' => true,
			]),
		]);

		self::assertSame([
			'requireApproval' => false,
			'allowVoting' => true,
			'showListenerCount' => true,
			'allowImport' => true,
		], $this->service->shareRulesFor(self::openChannel(), 'bob'));
	}

	/**
	 * The generous answer wins, the same way the permissions themselves combine — being
	 * named twice must not take anything away.
	 */
	public function testTwoSharesThatDisagreeGiveTheGenerousAnswer(): void {
		$this->withShares([
			self::shareWithRules([
				'requireApproval' => true,
				'allowVoting' => false,
				'showListenerCount' => false,
				'allowImport' => false,
			]),
			self::shareWithRules([
				'requireApproval' => false,
				'allowVoting' => true,
				'showListenerCount' => true,
				'allowImport' => true,
			]),
		]);

		self::assertSame([
			'requireApproval' => false,
			'allowVoting' => true,
			'showListenerCount' => true,
			'allowImport' => true,
		], $this->service->shareRulesFor(self::openChannel(), 'bob'));
	}

	public function testAnExpiredShareCarriesNoRulesEither(): void {
		$this->withShares([
			self::shareWithRules([
				'requireApproval' => false,
				'allowVoting' => true,
				'showListenerCount' => true,
				'allowImport' => true,
			], self::NOW - 1),
		]);

		self::assertSame([
			'requireApproval' => true,
			'allowVoting' => false,
			'showListenerCount' => false,
			'allowImport' => false,
		], $this->service->shareRulesFor(self::openChannel(), 'bob'));
	}

	/**
	 * Voting is the one rule the channel still has a say in, and not as a preference: its
	 * `allow_voting` is derived from the shares themselves and is what decides whether the
	 * playlist is in vote order at all. A channel not counting votes cannot let anybody
	 * cast one, however its share rows read — which is the state a half-applied
	 * syncVotingMode would leave, so it is asserted rather than assumed.
	 *
	 * Importing is no longer gated that way. It used to be, which meant an owner had to say
	 * yes twice and a share could be silently inert; the share and the administrator decide
	 * it now.
	 */
	public function testOnlyVotingStillDependsOnTheChannel(): void {
		$this->withShares([
			self::shareWithRules([
				'allowVoting' => true,
				'showListenerCount' => true,
				'allowImport' => true,
			]),
		]);

		$rules = $this->service->shareRulesFor(self::channel('alice'), 'bob');

		self::assertFalse($rules['allowVoting']);
		self::assertTrue($rules['allowImport']);
		// Not gated on the channel — there is no channel-wide switch for it any more.
		self::assertTrue($rules['showListenerCount']);
	}

	public function testSomeoneWithNoShareIsToldNothingAndHeldOnEverything(): void {
		$this->withShares([]);

		self::assertSame([
			'requireApproval' => true,
			'allowVoting' => false,
			'showListenerCount' => false,
			'allowImport' => false,
		], $this->service->shareRulesFor(self::openChannel(), 'bob'));
	}

	/**
	 * The owner has no share row of their own, so nothing per-share can answer for them:
	 * never held, always counted, and free to import on their own channel — that spends
	 * their storage and their server's time, so there is nobody left for them to be asking.
	 *
	 * Voting is the exception, and for the same reason as the test above: they can only
	 * vote on a channel that is counting votes, which is true exactly when they have
	 * granted voting to somebody.
	 */
	public function testTheOwnerIsAnsweredByTheChannelItself(): void {
		$this->withShares([]);

		self::assertSame([
			'requireApproval' => false,
			'allowVoting' => true,
			'showListenerCount' => true,
			'allowImport' => true,
		], $this->service->shareRulesFor(self::openChannel(), 'alice'));

		$closed = $this->service->shareRulesFor(self::channel('alice'), 'alice');
		self::assertFalse($closed['allowVoting']);
		self::assertTrue($closed['allowImport']);
		self::assertTrue($closed['showListenerCount']);
	}

	public function testAnAnonymousRequestCarriesNoRules(): void {
		$this->withShares([]);

		self::assertSame([
			'requireApproval' => true,
			'allowVoting' => false,
			'showListenerCount' => false,
			'allowImport' => false,
		], $this->service->shareRulesFor(self::openChannel(), null));
	}
}
