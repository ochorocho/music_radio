<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Service\TrackFiles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Finding the file behind a track.
 *
 * These exist because the app used to look in one place and one place only — the folder of
 * whoever `added_by` names — and two of the three ways a track comes into being do not put
 * the file there. A link upload and an import both land in the channel owner's music folder,
 * so both resolved to nothing, and nothing means "deleted" to the streaming path: the track
 * was flagged unavailable and reported as missing while its file sat there readable.
 */
class TrackFilesTest extends TestCase {

	private const OWNER = 'alice';
	private const FILE_ID = 4711;

	/**
	 * Which user folders were asked, in order, so a test can assert what was *not* opened.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * @param array<string, bool> $holds user id => whether that user's storage can reach the
	 *                                   file. A user missing from the list has no storage at
	 *                                   all and throws, as getUserFolder() does for an
	 *                                   account that is gone.
	 */
	private function files(array $holds): TrackFiles {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(
			function (string $userId) use ($holds): Folder {
				$this->asked[] = $userId;

				if (!array_key_exists($userId, $holds)) {
					throw new \RuntimeException('no such account: ' . $userId);
				}

				$folder = $this->createMock(Folder::class);
				$folder->method('getById')->willReturnCallback(
					function (int $fileId) use ($holds, $userId): array {
						if (!$holds[$userId] || $fileId !== self::FILE_ID) {
							return [];
						}

						$file = $this->createMock(File::class);
						$file->method('isReadable')->willReturn(true);

						return [$file];
					}
				);

				return $folder;
			}
		);

		return new TrackFiles($rootFolder, new NullLogger());
	}

	private static function channel(): Channel {
		$channel = new Channel();
		$channel->setId(7);
		$channel->setUserId(self::OWNER);

		return $channel;
	}

	private static function track(string $addedBy): Track {
		$track = new Track();
		$track->setId(1);
		$track->setChannelId(7);
		$track->setFileId(self::FILE_ID);
		$track->setAddedBy($addedBy);

		return $track;
	}

	// ------------------------------------------------------------------ the picker path

	public function testAFileTheAdderPickedIsFoundInTheirOwnStorage(): void {
		$file = $this->files(['bob' => true, self::OWNER => false])
			->resolve(self::channel(), self::track('bob'));

		self::assertNotNull($file);
		// Their storage answered, so the owner's was never opened.
		self::assertSame(['bob'], $this->asked);
	}

	// ------------------------------------------------------------------ the ingest paths

	public function testAnImportCreditedToAContributorIsFoundInTheOwnersStorage(): void {
		// What the server downloads is written into the owner's music folder against the
		// owner's quota, whoever pasted the link — so `added_by` names a storage it is not in.
		$file = $this->files(['bob' => false, self::OWNER => true])
			->resolve(self::channel(), self::track('bob'));

		self::assertNotNull($file);
		self::assertSame(['bob', self::OWNER], $this->asked);
	}

	public function testALinkUploadIsLookedForInTheOwnersStorageOnly(): void {
		$file = $this->files([self::OWNER => true])
			->resolve(self::channel(), self::track('?link:' . str_repeat('a', 32)));

		self::assertNotNull($file);
		// A visitor key is not an account, and must never be handed to getUserFolder().
		self::assertSame([self::OWNER], $this->asked);
	}

	public function testAnUploadFromABrowserWithNoCookieIsFoundTheSameWay(): void {
		$file = $this->files([self::OWNER => true])
			->resolve(self::channel(), self::track(Track::ADDED_BY_PUBLIC_LINK));

		self::assertNotNull($file);
		self::assertSame([self::OWNER], $this->asked);
	}

	// ------------------------------------------------------------------ genuinely gone

	public function testATrackWhoseFileIsInNeitherStorageResolvesToNothing(): void {
		$file = $this->files(['bob' => false, self::OWNER => false])
			->resolve(self::channel(), self::track('bob'));

		self::assertNull($file);
		self::assertSame(['bob', self::OWNER], $this->asked);
	}

	public function testAnAdderWhoseAccountHasGoneDoesNotStopTheOwnerBeingTried(): void {
		// getUserFolder() throws for an account that no longer exists; that says nothing
		// about the file, which for an import or an upload was never in their storage anyway.
		$file = $this->files([self::OWNER => true])->resolve(self::channel(), self::track('bob'));

		self::assertNotNull($file);
		self::assertSame(['bob', self::OWNER], $this->asked);
	}

	public function testTheOwnersOwnTrackAsksOneStorageOnce(): void {
		$file = $this->files([self::OWNER => true])->resolve(self::channel(), self::track(self::OWNER));

		self::assertNotNull($file);
		self::assertSame([self::OWNER], $this->asked);
	}
}
