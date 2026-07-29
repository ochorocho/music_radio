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
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Permission;
use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Creating and constraining shares.
 *
 * The admin-level switches are the interesting part: they live in server configuration
 * that is cached, so exercising them through a browser is unreliable. Here they are just
 * a mock returning true or false.
 */
class ShareServiceTest extends TestCase {

	private const NOW = 1_700_000_000;

	private ShareMapper&MockObject $shareMapper;
	private IShareManager&MockObject $shareManager;
	private IUserManager&MockObject $userManager;
	private IHasher&MockObject $hasher;
	private ShareService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->shareMapper = $this->createMock(ShareMapper::class);
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->hasher = $this->createMock(IHasher::class);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('tokentokentoken1');

		// No token collisions by default.
		$this->shareMapper->method('findByToken')->willThrowException(new DoesNotExistException('none'));
		$this->shareMapper->method('insert')->willReturnArgument(0);
		$this->shareMapper->method('update')->willReturnArgument(0);

		$this->hasher->method('hash')->willReturnCallback(static fn (string $p): string => 'hashed:' . $p);

		// Permissive by default; individual tests tighten what they care about.
		$this->shareManager->method('sharingDisabledForUser')->willReturn(false);
		$this->shareManager->method('allowGroupSharing')->willReturn(true);
		$this->shareManager->method('shareApiAllowLinks')->willReturn(true);
		$this->shareManager->method('shareApiLinkEnforcePassword')->willReturn(false);

		$this->userManager->method('userExists')->willReturn(true);

		$this->service = new ShareService(
			$this->shareMapper,
			$this->shareManager,
			$this->userManager,
			$this->createMock(IGroupManager::class),
			$this->createMock(IEventDispatcher::class),
			$this->hasher,
			$secureRandom,
			$clock,
			new NullLogger(),
		);
	}

	/**
	 * Rebuild the service with specific admin switches.
	 *
	 * @param array<string, bool> $switches
	 */
	private function withSwitches(array $switches): ShareService {
		$shareManager = $this->createMock(IShareManager::class);
		$shareManager->method('sharingDisabledForUser')->willReturn($switches['sharingDisabled'] ?? false);
		$shareManager->method('allowGroupSharing')->willReturn($switches['groupSharing'] ?? true);
		$shareManager->method('shareApiAllowLinks')->willReturn($switches['links'] ?? true);
		$shareManager->method('shareApiLinkEnforcePassword')->willReturn($switches['enforcePassword'] ?? false);

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturn('tokentokentoken1');

		return new ShareService(
			$this->shareMapper,
			$shareManager,
			$this->userManager,
			$this->createMock(IGroupManager::class),
			$this->createMock(IEventDispatcher::class),
			$this->hasher,
			$secureRandom,
			$clock,
			new NullLogger(),
		);
	}

	private static function channel(): Channel {
		$channel = new Channel();
		$channel->setId(1);
		$channel->setUserId('alice');

		return $channel;
	}

	// ------------------------------------------------------------- link passwords

	public function testALinkCanBeCreatedWithAPasswordInOneStep(): void {
		$share = $this->service->create(
			self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN,
			password: 'Listen-To-This-2026!',
		);

		self::assertSame('hashed:Listen-To-This-2026!', $share->getPassword());
	}

	/**
	 * The link must never exist unprotected, not even for the moment between being
	 * created and being secured — by then the URL has already been generated.
	 */
	public function testThePasswordIsHashedNotStoredInTheClear(): void {
		$share = $this->service->create(
			self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN,
			password: 'Listen-To-This-2026!',
		);

		self::assertStringNotContainsString('Listen-To-This-2026!', (string)json_encode($share->jsonSerialize()));
		self::assertTrue($share->jsonSerialize()['hasPassword']);
	}

	public function testALinkWithoutAPasswordIsAllowedWhenTheServerPermitsIt(): void {
		$share = $this->service->create(self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN);

		self::assertNull($share->getPassword());
		self::assertFalse($share->jsonSerialize()['hasPassword']);
	}

	public function testABareLinkIsRefusedWhenTheServerEnforcesPasswords(): void {
		$service = $this->withSwitches(['enforcePassword' => true]);

		$this->expectException(MusicRadioException::class);
		$service->create(self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN);
	}

	public function testALinkWithAPasswordIsAcceptedWhenTheServerEnforcesThem(): void {
		$service = $this->withSwitches(['enforcePassword' => true]);

		$share = $service->create(
			self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN,
			password: 'Listen-To-This-2026!',
		);

		self::assertNotNull($share->getPassword());
	}

	/**
	 * Otherwise the enforcement could be undone one request after satisfying it.
	 */
	public function testAPasswordCannotBeClearedWhenTheServerEnforcesThem(): void {
		$service = $this->withSwitches(['enforcePassword' => true]);

		$share = new Share();
		$share->setShareType(Share::TYPE_LINK);
		$share->setPassword('hashed:something');

		$this->expectException(MusicRadioException::class);
		$service->setPassword($share, null);
	}

	public function testAPasswordCanBeClearedWhenTheServerDoesNot(): void {
		$share = new Share();
		$share->setShareType(Share::TYPE_LINK);
		$share->setPassword('hashed:something');

		self::assertNull($this->service->setPassword($share, null)->getPassword());
	}

	public function testOnlyLinksCanCarryAPassword(): void {
		$share = new Share();
		$share->setShareType(Share::TYPE_USER);

		$this->expectException(MusicRadioException::class);
		$this->service->setPassword($share, 'Listen-To-This-2026!');
	}

	/**
	 * An empty string is "no password", not a password of zero length.
	 */
	public function testAnEmptyPasswordCountsAsNone(): void {
		$share = $this->service->create(
			self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN,
			password: '',
		);

		self::assertNull($share->getPassword());
	}

	// ------------------------------------------------------------ other switches

	public function testSharingIsRefusedWhenDisabledForTheUser(): void {
		$service = $this->withSwitches(['sharingDisabled' => true]);

		$this->expectException(ForbiddenException::class);
		$service->create(self::channel(), 'alice', Share::TYPE_USER, 'bob', Permission::LISTEN);
	}

	public function testGroupSharingIsRefusedWhenTheServerDisallowsIt(): void {
		$service = $this->withSwitches(['groupSharing' => false]);

		$this->expectException(MusicRadioException::class);
		$service->create(self::channel(), 'alice', Share::TYPE_GROUP, 'staff', Permission::LISTEN);
	}

	public function testLinksAreRefusedWhenTheServerDisallowsThem(): void {
		$service = $this->withSwitches(['links' => false]);

		$this->expectException(MusicRadioException::class);
		$service->create(self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN);
	}

	public function testCapabilitiesReportTheServerSwitches(): void {
		$service = $this->withSwitches([
			'sharingDisabled' => false,
			'groupSharing' => false,
			'links' => true,
			'enforcePassword' => true,
		]);

		self::assertSame([
			'sharingEnabled' => true,
			'groupSharingAllowed' => false,
			'linksAllowed' => true,
			'linkPasswordEnforced' => true,
		], $service->capabilities('alice'));
	}

	// --------------------------------------------------------------- link limits

	public function testALinkIsCreatedListenOnlyByDefault(): void {
		$share = $this->service->create(
			self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN,
		);

		self::assertSame(Permission::LISTEN, $share->getPermissions());
		self::assertNull($share->getReceiver());
		self::assertNotNull($share->getToken());
	}

	/**
	 * The one thing beyond listening that makes sense for a link: someone with no account
	 * has no stored files to contribute, but they do have a file on their device.
	 */
	public function testALinkMayAlsoAllowUploading(): void {
		$share = $this->service->create(
			self::channel(), 'alice', Share::TYPE_LINK, null, Permission::LISTEN | Permission::ADD_TRACKS,
		);

		self::assertSame(Permission::LISTEN | Permission::ADD_TRACKS, $share->getPermissions());
	}

	/**
	 * Anyone at all can follow a link, so the bits that decide what the channel *is* are
	 * never on offer — and asking for them fails loudly rather than quietly granting less.
	 */
	public function testALinkCannotBeCreatedWithAnythingElse(): void {
		$this->expectException(MusicRadioException::class);
		$this->service->create(self::channel(), 'alice', Share::TYPE_LINK, null, Permission::ALL);
	}

	public function testALinkCannotBeUpgradedPastUploadingLater(): void {
		$share = new Share();
		$share->setShareType(Share::TYPE_LINK);
		$share->setPermissions(Permission::LISTEN);

		$this->expectException(MusicRadioException::class);
		$this->service->update($share, Permission::CONTROL, null, null);
	}

	public function testUploadingCanBeSwitchedOnAndOffAfterwards(): void {
		$share = new Share();
		$share->setShareType(Share::TYPE_LINK);
		$share->setPermissions(Permission::LISTEN);

		$this->service->update($share, Permission::LISTEN | Permission::ADD_TRACKS, null, null);
		self::assertSame(Permission::LISTEN | Permission::ADD_TRACKS, $share->getPermissions());

		$this->service->update($share, Permission::LISTEN, null, null);
		self::assertSame(Permission::LISTEN, $share->getPermissions());
	}
}
