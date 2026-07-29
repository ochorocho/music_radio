<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\AppInfo\Application;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {

	public function testAppIdMatchesInfoXml(): void {
		$info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		self::assertNotFalse($info, 'appinfo/info.xml must be parseable');
		self::assertSame(Application::APP_ID, (string)$info->id);
	}

	/**
	 * The harness pins NEXTCLOUD_VERSION; if the declared range stops covering it,
	 * `occ app:enable` fails with an unhelpful message. Fail loudly here instead.
	 */
	public function testDeclaredNextcloudRangeCoversTheHarnessVersion(): void {
		$info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		self::assertNotFalse($info);

		$min = (int)$info->dependencies->nextcloud['min-version'];
		$max = (int)$info->dependencies->nextcloud['max-version'];

		self::assertLessThanOrEqual($min, 33, 'min-version must not exclude Nextcloud 33');
		self::assertGreaterThanOrEqual(33, $max, 'max-version must not exclude Nextcloud 33');
	}

	public function testNamespaceMatchesComposerAutoload(): void {
		$composer = json_decode((string)file_get_contents(__DIR__ . '/../../composer.json'), true);
		self::assertArrayHasKey('OCA\\MusicRadio\\', $composer['autoload']['psr-4']);
		self::assertSame('lib/', $composer['autoload']['psr-4']['OCA\\MusicRadio\\']);
	}
}
