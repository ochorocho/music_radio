<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Share;
use OCA\MusicRadio\Permission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShareTest extends TestCase {

	private const NOW = 1_700_000_000;

	private static function share(?int $expiration, ?string $password = null): Share {
		$share = new Share();
		$share->setShareType(Share::TYPE_LINK);
		$share->setExpiration($expiration);
		$share->setPassword($password);
		$share->setPermissions(Permission::LISTEN);
		$share->setToken('abcdefghijklmnop');
		$share->setCreatedBy('alice');
		$share->setCreatedAt(0);

		return $share;
	}

	/**
	 * @return array<string, array{int|null, bool}>
	 */
	public static function expirationProvider(): array {
		return [
			'no expiry' => [null, false],
			'expires later' => [self::NOW + 3600, false],
			'expires in a second' => [self::NOW + 1, false],
			// Inclusive: the moment it is reached, it is over.
			'expires exactly now' => [self::NOW, true],
			'expired a second ago' => [self::NOW - 1, true],
			'long expired' => [self::NOW - 86_400, true],
		];
	}

	#[DataProvider('expirationProvider')]
	public function testIsExpired(?int $expiration, bool $expected): void {
		self::assertSame($expected, self::share($expiration)->isExpired(self::NOW));
	}

	/**
	 * The hash must never reach the client — only whether a password is set at all.
	 */
	public function testTheSerialisedShareNeverCarriesThePasswordHash(): void {
		$json = self::share(null, '$argon2id$v=19$m=65536,t=4,p=1$somethingsecret')->jsonSerialize();

		self::assertArrayNotHasKey('password', $json);
		self::assertTrue($json['hasPassword']);
		self::assertStringNotContainsString('argon2', json_encode($json));
	}

	public function testAShareWithoutAPasswordSaysSo(): void {
		self::assertFalse(self::share(null)->jsonSerialize()['hasPassword']);
		self::assertFalse(self::share(null, '')->jsonSerialize()['hasPassword']);
	}

	/**
	 * These values are the API contract with the sharee picker, which reports what it
	 * found using core's own numbering.
	 */
	public function testShareTypesMatchTheValuesCoreUses(): void {
		self::assertSame(0, Share::TYPE_USER);
		self::assertSame(1, Share::TYPE_GROUP);
		self::assertSame(3, Share::TYPE_LINK);
		self::assertSame(7, Share::TYPE_TEAM);
	}
}
