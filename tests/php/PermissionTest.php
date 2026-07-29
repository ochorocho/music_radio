<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Permission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PermissionTest extends TestCase {

	public function testHasRequiresEveryRequestedBit(): void {
		$mask = Permission::LISTEN | Permission::ADD_TRACKS;

		self::assertTrue(Permission::has($mask, Permission::LISTEN));
		self::assertTrue(Permission::has($mask, Permission::LISTEN | Permission::ADD_TRACKS));
		self::assertFalse(Permission::has($mask, Permission::CONTROL));
		self::assertFalse(Permission::has($mask, Permission::ADD_TRACKS | Permission::CONTROL));
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function normalizeProvider(): array {
		return [
			'nothing stays nothing' => [
				Permission::NONE,
				Permission::NONE,
			],
			'anything implies being able to listen' => [
				Permission::ADD_TRACKS,
				Permission::ADD_TRACKS | Permission::LISTEN,
			],
			'managing implies curating and DJing' => [
				Permission::MANAGE,
				Permission::MANAGE | Permission::EDIT_PLAYLIST | Permission::CONTROL
					| Permission::ADD_TRACKS | Permission::LISTEN,
			],
			'editing the playlist implies adding to it' => [
				Permission::EDIT_PLAYLIST,
				Permission::EDIT_PLAYLIST | Permission::ADD_TRACKS | Permission::LISTEN,
			],
			'sharing alone does not imply curating' => [
				Permission::SHARE,
				Permission::SHARE | Permission::LISTEN,
			],
			'unknown bits are discarded' => [
				Permission::LISTEN | 1024,
				Permission::LISTEN,
			],
			'a full mask is unchanged' => [
				Permission::ALL,
				Permission::ALL,
			],
		];
	}

	#[DataProvider('normalizeProvider')]
	public function testNormalize(int $input, int $expected): void {
		self::assertSame($expected, Permission::normalize($input));
	}

	public function testNormalizeIsIdempotent(): void {
		foreach ([Permission::NONE, Permission::ADD_TRACKS, Permission::MANAGE, Permission::ALL] as $mask) {
			$once = Permission::normalize($mask);
			self::assertSame($once, Permission::normalize($once));
		}
	}

	/**
	 * The whole point of the app: a contributor can add music but cannot decide what is
	 * playing. If normalisation ever leaked CONTROL into this preset, sharees would
	 * silently become co-DJs.
	 */
	public function testContributorPresetCannotControlPlayback(): void {
		$mask = Permission::normalize(Permission::PRESET_CONTRIBUTOR);

		self::assertTrue(Permission::has($mask, Permission::LISTEN));
		self::assertTrue(Permission::has($mask, Permission::ADD_TRACKS));
		self::assertFalse(Permission::has($mask, Permission::CONTROL));
		self::assertFalse(Permission::has($mask, Permission::EDIT_PLAYLIST));
		self::assertFalse(Permission::has($mask, Permission::MANAGE));
		self::assertFalse(Permission::has($mask, Permission::SHARE));
	}

	public function testListenerPresetCanOnlyListen(): void {
		self::assertSame(Permission::LISTEN, Permission::normalize(Permission::PRESET_LISTENER));
	}

	public function testOwnerMaskCoversEveryCapability(): void {
		$described = Permission::describe(Permission::ALL);

		self::assertSame([
			'listen' => true,
			'addTracks' => true,
			'control' => true,
			'editPlaylist' => true,
			'share' => true,
			'manage' => true,
		], $described);
	}

	public function testDescribeReportsNothingForAnEmptyMask(): void {
		self::assertSame([], array_filter(Permission::describe(Permission::NONE)));
	}
}
