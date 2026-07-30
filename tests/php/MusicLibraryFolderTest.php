<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\MusicLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The download folder is a user-writable string on its way into a filesystem call, so it
 * gets its own test class. Every case below is a value someone could actually put in the
 * setting — by typing it, by pasting a path, or by writing straight to the config table.
 */
class MusicLibraryFolderTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function folderProvider(): array {
		return [
			// Accepted, unchanged.
			'a plain name' => ['Music', 'Music'],
			'a name with a space' => ['My Music', 'My Music'],
			'a nested path' => ['Media/Music', 'Media/Music'],
			'four segments is still fine' => ['a/b/c/d', 'a/b/c/d'],
			'a name with dots in it' => ['Music v2.0', 'Music v2.0'],
			'a leading dot is a hidden folder, not a traversal' => ['.music', '.music'],
			'non-latin names are names' => ['Musik/Hörspiele', 'Musik/Hörspiele'],

			// Normalised, then accepted.
			'surrounding slashes are trimmed' => ['/Music/', 'Music'],
			'backslashes are separators too' => ['Media\\Music', 'Media/Music'],

			// Rejected — traversal.
			'a parent reference' => ['..', 'Music'],
			'a parent reference in a path' => ['Media/../../etc', 'Music'],
			'a trailing parent reference' => ['Music/..', 'Music'],
			'a current-directory reference' => ['.', 'Music'],
			'three dots is still only dots' => ['...', 'Music'],
			'an absolute path reduces to its segments and must not climb' => ['/etc/../../root', 'Music'],

			// Rejected — malformed.
			'empty' => ['', 'Music'],
			'only whitespace' => ['   ', 'Music'],
			'a whitespace-only segment' => ['Media/ /Music', 'Music'],
			'surrounding whitespace is trimmed' => ['  Music  ', 'Music'],
			'only slashes' => ['///', 'Music'],
			'a doubled slash' => ['Media//Music', 'Music'],
			'too deep' => ['a/b/c/d/e', 'Music'],

			// Rejected — characters a filesystem or Nextcloud refuses.
			'a null byte' => ["Mu\0sic", 'Music'],
			'a newline' => ["Music\nRadio", 'Music'],
			'a colon' => ['C:Music', 'Music'],
			'a wildcard' => ['Mus*ic', 'Music'],
			'a pipe' => ['Music|Radio', 'Music'],

			// Rejected — unreasonable.
			'a segment longer than any filesystem allows' => [str_repeat('a', 300), 'Music'],
		];
	}

	#[DataProvider('folderProvider')]
	public function testSanitiseFolderPath(string $configured, string $expected): void {
		self::assertSame($expected, MusicLibrary::sanitiseFolderPath($configured));
	}

	public function testTheFallbackIsTheDefaultFolder(): void {
		// The cases above spell 'Music' out so they read as the path a user would see;
		// this is what ties them to the constant.
		self::assertSame(MusicLibrary::DEFAULT_FOLDER, MusicLibrary::sanitiseFolderPath('..'));
	}

	/**
	 * Whatever comes out must be safe to hand to Folder::get(), which means it can never
	 * be absolute and can never contain a segment that climbs.
	 */
	#[DataProvider('folderProvider')]
	public function testResultIsAlwaysARelativeNonClimbingPath(string $configured): void {
		$result = MusicLibrary::sanitiseFolderPath($configured);

		self::assertNotSame('', $result);
		self::assertStringStartsNotWith('/', $result);
		self::assertNotContains('..', explode('/', $result));
		self::assertNotContains('.', explode('/', $result));
	}
}
