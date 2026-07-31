<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Migration\ClearThemingIconCache;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which cached icons the upgrade throws away.
 *
 * The risk here is not failing to delete — a stale favicon is a cosmetic problem — but
 * deleting somebody else's. This runs against a shared folder holding every app's
 * generated icons, so the matching has to be exact.
 */
class ClearThemingIconCacheTest extends TestCase {

	/** @var list<string> names passed to delete() */
	private array $deleted = [];

	/**
	 * @param array<string, list<string>> $tree folder name => file names; '' is the root
	 */
	private function step(array $tree, string $cachebuster = '2'): ClearThemingIconCache {
		$makeFolder = function (array $names) use (&$makeFolder): ISimpleFolder {
			$folder = $this->createMock(ISimpleFolder::class);
			$folder->method('getDirectoryListing')->willReturn(array_map(
				function (string $name): ISimpleFile {
					$file = $this->createMock(ISimpleFile::class);
					$file->method('getName')->willReturn($name);
					$file->method('delete')->willReturnCallback(function () use ($name): void {
						$this->deleted[] = $name;
					});

					return $file;
				},
				$names,
			));

			return $folder;
		};

		$root = $makeFolder($tree[''] ?? []);
		$root->method('getFolder')->willReturnCallback(function (string $name) use ($tree, $makeFolder): ISimpleFolder {
			if (!array_key_exists($name, $tree)) {
				throw new NotFoundException($name);
			}

			return $makeFolder($tree[$name]);
		});

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($root);

		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn($cachebuster);

		return new ClearThemingIconCache($factory, $config, new NullLogger());
	}

	private function sweep(ClearThemingIconCache $step): void {
		$step->run($this->createMock(IOutput::class));
	}

	public function testThisAppsCachedIconsAreRemoved(): void {
		$this->sweep($this->step(['2' => [
			'favIcon-music_radio#00679e',
			'touchIcon-music_radio#00679e',
		]]));

		self::assertSame(
			['favIcon-music_radio#00679e', 'touchIcon-music_radio#00679e'],
			$this->deleted,
		);
	}

	/**
	 * The folder is shared with every other app on the instance.
	 */
	public function testNothingBelongingToAnotherAppIsTouched(): void {
		$this->sweep($this->step(['2' => [
			'favIcon-files#00679e',
			'touchIcon-photos#00679e',
			'icon-core-#00679efiletypes_text.svg',
			'favIcon-music_radio#00679e',
		]]));

		self::assertSame(['favIcon-music_radio#00679e'], $this->deleted);
	}

	/**
	 * An app whose id merely starts with this one's must survive — which is why the match
	 * includes the separator rather than just the id.
	 */
	public function testAnAppWithALongerIdIsNotCaughtByTheMatch(): void {
		$this->sweep($this->step(['2' => [
			'favIcon-music_radio_extras#00679e',
			'favIcon-music_radio#00679e',
		]]));

		self::assertSame(['favIcon-music_radio#00679e'], $this->deleted);
	}

	/**
	 * Icons live one level down, in a folder named after the cachebuster — and older
	 * generations are not always tidied up, so every one of them is swept.
	 */
	public function testOlderCachebusterGenerationsAreSweptToo(): void {
		$this->sweep($this->step([
			'2' => ['favIcon-music_radio#00679e'],
			'1' => ['favIcon-music_radio#112233'],
			'0' => ['favIcon-music_radio#445566'],
		]));

		self::assertCount(3, $this->deleted);
	}

	public function testAnInstanceThemingHasNeverTouchedIsFine(): void {
		$this->sweep($this->step([]));

		self::assertSame([], $this->deleted);
	}

	/**
	 * A different colour is a different cache entry, and all of them describe the old icon.
	 */
	public function testEveryColourVariantGoes(): void {
		$this->sweep($this->step(['2' => [
			'favIcon-music_radio#00679e',
			'favIcon-music_radio#ff0000',
			'touchIcon-music_radio#ff0000',
		]]));

		self::assertCount(3, $this->deleted);
	}
}
