<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Share;
use OCA\MusicRadio\Db\ShareMapper;
use OCA\MusicRadio\Exception\ForbiddenException;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Exception\NotFoundException;
use OCA\MusicRadio\Permission;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\DB\Exception;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\Events\ValidatePasswordPolicyEvent;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use OCP\Security\PasswordContext;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

/**
 * Sharing a channel with people.
 *
 * These are the app's own share rows, not core's — a channel is not a file, and core's
 * sharing is typed to files from top to bottom. The admin-level switches that govern
 * sharing on the instance are still honoured, because a user who has had sharing turned
 * off should not be able to share a channel either.
 */
class ShareService {

	private const TOKEN_LENGTH = 16;

	public function __construct(
		private ShareMapper $shareMapper,
		private IShareManager $shareManager,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IEventDispatcher $eventDispatcher,
		private IHasher $hasher,
		private ISecureRandom $secureRandom,
		private Clock $clock,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Share[]
	 * @throws Exception
	 */
	public function listForChannel(Channel $channel): array {
		return $this->shareMapper->findAllForChannel($channel->getId());
	}

	/**
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function find(Channel $channel, int $shareId): Share {
		try {
			return $this->shareMapper->find($shareId, $channel->getId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException('Share not found');
		}
	}

	/**
	 * @param int $shareType one of Share::TYPE_*
	 * @param string|null $receiver uid / gid / team id; ignored for link shares
	 * @throws MusicRadioException
	 * @throws Exception
	 */
	public function create(
		Channel $channel,
		string $createdBy,
		int $shareType,
		?string $receiver,
		int $permissions,
		?int $expiration = null,
		?string $label = null,
		?string $password = null,
	): Share {
		$this->assertSharingAllowed($createdBy, $shareType);

		$share = new Share();
		$share->setChannelId($channel->getId());
		$share->setShareType($shareType);
		$share->setCreatedBy($createdBy);
		$share->setCreatedAt($this->clock->nowSeconds());
		$share->setExpiration($this->validateExpiration($expiration));
		$share->setLabel($label === null || trim($label) === '' ? null : mb_substr(trim($label), 0, 255));

		if ($shareType === Share::TYPE_LINK) {
			$share->setPermissions($this->validateLinkPermissions($permissions));
			$share->setReceiver(null);
			$share->setToken($this->generateToken());

			$password = $password === null || $password === '' ? null : $password;

			// Some servers require every public link to carry a password. Creating one
			// without would quietly produce a link the admin has forbidden.
			if ($password === null && $this->shareManager->shareApiLinkEnforcePassword()) {
				throw new MusicRadioException('This server requires a password on public links');
			}

			if ($password !== null) {
				$this->assertPasswordMeetsPolicy($password);
				$share->setPassword($this->hasher->hash($password));
			}
		} else {
			$share->setPermissions($this->validatePermissions($permissions));
			$share->setReceiver($this->validateReceiver($shareType, $receiver, $channel));
			$share->setToken(null);
		}

		try {
			return $this->shareMapper->insert($share);
		} catch (\OCP\DB\Exception $e) {
			if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new MusicRadioException(
					'This channel is already shared with them',
					Http::STATUS_CONFLICT,
				);
			}
			throw $e;
		}
	}

	/**
	 * @throws MusicRadioException
	 * @throws Exception
	 */
	/**
	 * @param bool|null $requireApproval null leaves it alone, as with every field here
	 * @param bool|null $allowVoting likewise
	 */
	public function update(
		Share $share,
		?int $permissions,
		?int $expiration,
		?string $label,
		?bool $requireApproval = null,
		?bool $allowVoting = null,
		?bool $showListenerCount = null,
		?bool $allowImport = null,
	): Share {
		if ($requireApproval !== null) {
			$share->setRequireApproval($requireApproval);
		}
		if ($allowVoting !== null) {
			$share->setAllowVoting($allowVoting);
		}
		if ($showListenerCount !== null) {
			$share->setShowListenerCount($showListenerCount);
		}
		if ($allowImport !== null) {
			$share->setAllowImport($allowImport);
		}

		if ($permissions !== null) {
			$share->setPermissions($share->getShareType() === Share::TYPE_LINK
				? $this->validateLinkPermissions($permissions)
				: $this->validatePermissions($permissions));
		}

		if ($expiration !== null) {
			// 0 clears the expiry rather than meaning "the epoch".
			$share->setExpiration($expiration === 0 ? null : $this->validateExpiration($expiration));
		}

		if ($label !== null) {
			$share->setLabel(trim($label) === '' ? null : mb_substr(trim($label), 0, 255));
		}

		return $this->shareMapper->update($share);
	}

	/**
	 * @throws Exception
	 */
	public function delete(Share $share): void {
		$this->shareMapper->delete($share);
	}

	/**
	 * Set, change or clear a link share's password.
	 *
	 * @param string|null $password null clears it
	 * @throws MusicRadioException
	 * @throws Exception
	 */
	public function setPassword(Share $share, ?string $password): Share {
		if ($share->getShareType() !== Share::TYPE_LINK) {
			throw new MusicRadioException('Only public links can have a password');
		}

		if ($password === null || $password === '') {
			if ($this->shareManager->shareApiLinkEnforcePassword()) {
				throw new MusicRadioException('This server requires a password on public links');
			}
			$share->setPassword(null);

			return $this->shareMapper->update($share);
		}

		// Run the instance's password policy over it, so a channel link cannot be a way
		// around rules the admin set for every other share.
		$this->assertPasswordMeetsPolicy($password);

		$share->setPassword($this->hasher->hash($password));

		return $this->shareMapper->update($share);
	}

	public function verifyPassword(Share $share, string $password): bool {
		$hash = $share->getPassword();
		if ($hash === null || $hash === '') {
			return false;
		}

		return $this->hasher->verify($password, $hash);
	}

	/**
	 * Resolve a link token to its share, or null when it is unknown, not a link, or has
	 * expired. Callers must treat all three identically so the API cannot be used to
	 * discover which tokens exist.
	 *
	 * @throws Exception
	 */
	public function findValidLink(string $token): ?Share {
		if ($token === '') {
			return null;
		}

		try {
			$share = $this->shareMapper->findByToken($token);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}

		if ($share->isExpired($this->clock->nowSeconds())) {
			return null;
		}

		return $share;
	}

	/**
	 * Present a share for the API, resolving the receiver to something displayable.
	 *
	 * @return array<string, mixed>
	 */
	public function present(Share $share): array {
		$data = $share->jsonSerialize();
		$data['displayName'] = $this->displayNameOf($share);

		return $data;
	}

	/**
	 * What this server permits, for the sharing UI to reflect.
	 *
	 * @return array<string, bool>
	 */
	public function capabilities(string $userId): array {
		return [
			'sharingEnabled' => !$this->shareManager->sharingDisabledForUser($userId),
			'groupSharingAllowed' => $this->shareManager->allowGroupSharing(),
			'linksAllowed' => $this->shareManager->shareApiAllowLinks(),
			'linkPasswordEnforced' => $this->shareManager->shareApiLinkEnforcePassword(),
		];
	}

	// ------------------------------------------------------------------ guards

	/**
	 * The instance's own sharing switches still apply. A user whose sharing has been
	 * disabled, or an instance with group sharing or links turned off, must not be able
	 * to route around that through this app.
	 *
	 * @throws MusicRadioException
	 */
	private function assertSharingAllowed(string $userId, int $shareType): void {
		if ($this->shareManager->sharingDisabledForUser($userId)) {
			throw new ForbiddenException('Sharing is disabled for your account');
		}

		if ($shareType === Share::TYPE_GROUP && !$this->shareManager->allowGroupSharing()) {
			throw new MusicRadioException('Group sharing is disabled on this server');
		}

		if ($shareType === Share::TYPE_LINK && !$this->shareManager->shareApiAllowLinks()) {
			throw new MusicRadioException('Public links are disabled on this server');
		}
	}

	/**
	 * @throws MusicRadioException
	 */
	private function validatePermissions(int $permissions): int {
		$normalized = Permission::normalize($permissions);
		if ($normalized === Permission::NONE) {
			throw new MusicRadioException('A share has to grant at least listening');
		}

		return $normalized;
	}

	/**
	 * A public link reaches people with no account, but it can be given the same say over
	 * the broadcast as a named person — see Permission::LINK_ALLOWED. What it can never
	 * carry is SHARE or MANAGE: those decide who else reaches the channel and what the
	 * channel is, and neither is something an owner could mean to hand to whoever holds a
	 * URL.
	 *
	 * @throws MusicRadioException
	 */
	private function validateLinkPermissions(int $permissions): int {
		$normalized = Permission::normalize($permissions);
		if ($normalized === Permission::NONE) {
			return Permission::LISTEN;
		}

		// Refused rather than quietly clamped: silently handing back less than was asked
		// for is how a caller ends up believing a link grants something it does not.
		if (($normalized & ~Permission::LINK_ALLOWED) !== 0) {
			throw new MusicRadioException('A public link cannot be given sharing or management of the channel');
		}

		return $normalized;
	}

	/**
	 * @throws MusicRadioException
	 */
	private function validateReceiver(int $shareType, ?string $receiver, Channel $channel): string {
		$receiver = trim((string)$receiver);
		if ($receiver === '') {
			throw new MusicRadioException('Choose who to share with');
		}

		switch ($shareType) {
			case Share::TYPE_USER:
				if ($receiver === $channel->getUserId()) {
					throw new MusicRadioException('That is already your own channel');
				}
				if (!$this->userManager->userExists($receiver)) {
					throw new MusicRadioException('That account does not exist');
				}
				break;

			case Share::TYPE_GROUP:
				if (!$this->groupManager->groupExists($receiver)) {
					throw new MusicRadioException('That group does not exist');
				}
				break;

			case Share::TYPE_TEAM:
				// Teams live in the optional Circles app; if it is unavailable the share
				// simply will not resolve to anyone, so there is nothing useful to check.
				break;

			default:
				throw new MusicRadioException('Unsupported share type');
		}

		return $receiver;
	}

	/**
	 * @throws MusicRadioException
	 */
	private function validateExpiration(?int $expiration): ?int {
		if ($expiration === null) {
			return null;
		}
		if ($expiration <= $this->clock->nowSeconds()) {
			throw new MusicRadioException('The expiry date has to be in the future');
		}

		return $expiration;
	}

	/**
	 * @throws Exception
	 */
	private function generateToken(): string {
		// The unique index on `token` is the real guarantee; this loop just avoids
		// surfacing an astronomically unlikely collision to the user.
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$token = $this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_HUMAN_READABLE);
			try {
				$this->shareMapper->findByToken($token);
			} catch (DoesNotExistException) {
				return $token;
			} catch (MultipleObjectsReturnedException) {
				continue;
			}
		}

		throw new MusicRadioException('Could not create a link, please try again', Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	private function displayNameOf(Share $share): ?string {
		$receiver = $share->getReceiver();
		if ($receiver === null) {
			return null;
		}

		switch ($share->getShareType()) {
			case Share::TYPE_USER:
				return $this->userManager->get($receiver)?->getDisplayName() ?? $receiver;
			case Share::TYPE_GROUP:
				return $this->groupManager->get($receiver)?->getDisplayName() ?? $receiver;
			default:
				return $receiver;
		}
	}

	/**
	 * Hand the password to the instance's password policy, if one is configured.
	 *
	 * The policy signals rejection by throwing a HintException carrying a message meant
	 * for the user. Anything else that goes wrong means the policy app is absent or
	 * broken, which must not block sharing.
	 *
	 * @throws MusicRadioException when the password is rejected
	 */
	private function assertPasswordMeetsPolicy(string $password): void {
		try {
			$this->eventDispatcher->dispatchTyped(
				new ValidatePasswordPolicyEvent($password, PasswordContext::SHARING),
			);
		} catch (HintException $e) {
			throw new MusicRadioException($e->getHint() ?: $e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->debug('Password policy could not be evaluated; allowing the password', [
				'app' => 'music_radio',
				'exception' => $e,
			]);
		}
	}
}
