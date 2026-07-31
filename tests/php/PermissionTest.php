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
			// Voting used to be a bit here. It is a switch on the share now, so the value
			// it occupied is reserved and must be discarded like any other unknown bit —
			// otherwise a mask stored while it was in use would grant something it no
			// longer means.
			'the retired voting bit is discarded' => [
				Permission::LISTEN | Permission::RETIRED_VOTE,
				Permission::LISTEN,
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

	/**
	 * A public link can be trusted with the broadcast — listening, adding, being the DJ,
	 * curating the running order — because those are decisions about the music and the
	 * owner makes them per link.
	 *
	 * SHARE and MANAGE are the two that never appear, and for a different reason than the
	 * rest: they decide who else reaches the channel and what the channel is, not what it
	 * is playing. Handing either to whoever holds a URL is not something an owner could
	 * mean to do, so it is not on offer to be got wrong.
	 */
	public function testALinkMayCarryTheBroadcastButNotTheChannel(): void {
		self::assertTrue(Permission::has(Permission::LINK_ALLOWED, Permission::LISTEN));
		self::assertTrue(Permission::has(Permission::LINK_ALLOWED, Permission::ADD_TRACKS));
		self::assertTrue(Permission::has(Permission::LINK_ALLOWED, Permission::CONTROL));
		self::assertTrue(Permission::has(Permission::LINK_ALLOWED, Permission::EDIT_PLAYLIST));

		self::assertFalse(Permission::has(Permission::LINK_ALLOWED, Permission::SHARE));
		self::assertFalse(Permission::has(Permission::LINK_ALLOWED, Permission::MANAGE));
	}

	/** Whatever it grows to include, it can never exceed what any share may carry. */
	public function testTheLinkMaskIsASubsetOfEverything(): void {
		self::assertSame(Permission::LINK_ALLOWED, Permission::LINK_ALLOWED & Permission::ALL);
		self::assertSame(Permission::LINK_ALLOWED, Permission::normalize(Permission::LINK_ALLOWED));
	}
}
