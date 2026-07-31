<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\MusicLibrary;
use OCA\MusicRadio\Service\SettingsStore;
use OCA\MusicRadio\Service\ToolStatus;
use OCA\MusicRadio\Service\YoutubeImportService;
use OCA\MusicRadio\Service\YtDlpLocator;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reading and writing the settings pages.
 *
 * Two things are worth pinning here, and both are places where a settings form can be
 * quietly wrong rather than visibly broken.
 *
 * **Units.** Durations are stored in seconds and shown in minutes; sizes are stored in
 * bytes and shown in megabytes. A form that converts one way and not the other looks
 * correct until somebody saves twice.
 *
 * **Partial saves.** A page carries several fields, and one bad value must not take the
 * good ones with it — nor may it be written anyway.
 */
class SettingsStoreTest extends TestCase {

	private const USER = 'alice';

	/** @var array<string, mixed> */
	private array $appValues = [];
	/** @var array<string, mixed> */
	private array $userValues = [];
	/** @var list<string> paths the user's files do not contain */
	private array $missingFolders = [];

	private IAppConfig&MockObject $appConfig;
	private SettingsStore $store;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueBool')
			->willReturnCallback(fn (string $a, string $k, bool $d = false): bool => (bool)($this->appValues[$k] ?? $d));
		$this->appConfig->method('getValueString')
			->willReturnCallback(fn (string $a, string $k, string $d = ''): string => (string)($this->appValues[$k] ?? $d));
		$this->appConfig->method('getValueInt')
			->willReturnCallback(fn (string $a, string $k, int $d = 0): int => (int)($this->appValues[$k] ?? $d));
		$this->appConfig->method('setValueBool')
			->willReturnCallback(function (string $a, string $k, bool $v): bool {
				$this->appValues[$k] = $v;

				return true;
			});
		$this->appConfig->method('setValueString')
			->willReturnCallback(function (string $a, string $k, string $v): bool {
				$this->appValues[$k] = $v;

				return true;
			});
		$this->appConfig->method('setValueInt')
			->willReturnCallback(function (string $a, string $k, int $v): bool {
				$this->appValues[$k] = $v;

				return true;
			});

		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')
			->willReturnCallback(fn (string $u, string $a, string $k, string $d = ''): string => (string)($this->userValues[$k] ?? $d));
		$userConfig->method('setValueString')
			->willReturnCallback(function (string $u, string $a, string $k, string $v): bool {
				$this->userValues[$k] = $v;

				return true;
			});

		$locator = $this->createMock(YtDlpLocator::class);
		$locator->method('status')->willReturn(new ToolStatus(available: true, reason: null));
		$locator->method('ytDlpPath')->willReturn(null);

		// Every folder the tests name is treated as existing unless a test says otherwise;
		// the existence rule has its own cases below.
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturnCallback(
			fn (string $path): bool => !in_array($path, $this->missingFolders, true),
		);
		$userFolder->method('get')->willReturnCallback(
			fn (string $path): Folder => $this->createMock(Folder::class),
		);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params),
		);

		$this->store = new SettingsStore(
			$this->appConfig,
			$userConfig,
			$locator,
			$rootFolder,
			$l10n,
			new NullLogger(),
		);
	}

	// ----------------------------------------------------------------- units

	public function testMinutesAndMegabytesSurviveARoundTrip(): void {
		$errors = $this->store->saveAdmin([
			YoutubeImportService::CONFIG_MAX_DURATION => 45,
			YoutubeImportService::CONFIG_MAX_SOURCE_BYTES => 250,
		]);

		self::assertSame([], $errors);
		// Stored in the units the rest of the code works in…
		self::assertSame(45 * 60, $this->appValues[YoutubeImportService::CONFIG_MAX_DURATION]);
		self::assertSame(250 * 1024 * 1024, $this->appValues[YoutubeImportService::CONFIG_MAX_SOURCE_BYTES]);

		// …and read back in the units people think in, unchanged.
		$values = $this->store->adminValues();
		self::assertSame(45, $values[YoutubeImportService::CONFIG_MAX_DURATION]);
		self::assertSame(250, $values[YoutubeImportService::CONFIG_MAX_SOURCE_BYTES]);
	}

	public function testSavingTwiceDoesNotCompoundTheConversion(): void {
		$this->store->saveAdmin([YoutubeImportService::CONFIG_MAX_DURATION => 30]);
		$roundTripped = $this->store->adminValues()[YoutubeImportService::CONFIG_MAX_DURATION];
		$this->store->saveAdmin([YoutubeImportService::CONFIG_MAX_DURATION => $roundTripped]);

		self::assertSame(30 * 60, $this->appValues[YoutubeImportService::CONFIG_MAX_DURATION]);
	}

	// ------------------------------------------------------------ validation

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function refusedNumberProvider(): array {
		return [
			'zero' => [0],
			'negative' => [-5],
			'not a number' => ['nonsense'],
		];
	}

	#[DataProvider('refusedNumberProvider')]
	public function testANumberBelowOneIsRefused(mixed $given): void {
		$errors = $this->store->saveAdmin([YoutubeImportService::CONFIG_MAX_DURATION => $given]);

		self::assertArrayHasKey(YoutubeImportService::CONFIG_MAX_DURATION, $errors);
		// And refused means not written, rather than written and complained about.
		self::assertArrayNotHasKey(YoutubeImportService::CONFIG_MAX_DURATION, $this->appValues);
	}

	public function testARelativeYtDlpPathIsRefused(): void {
		$errors = $this->store->saveAdmin([YtDlpLocator::CONFIG_YTDLP_PATH => 'yt-dlp']);

		self::assertArrayHasKey(YtDlpLocator::CONFIG_YTDLP_PATH, $errors);
		self::assertArrayNotHasKey(YtDlpLocator::CONFIG_YTDLP_PATH, $this->appValues);
	}

	public function testAPathToNothingIsRefused(): void {
		$errors = $this->store->saveAdmin([YtDlpLocator::CONFIG_YTDLP_PATH => '/definitely/not/here/yt-dlp']);

		self::assertArrayHasKey(YtDlpLocator::CONFIG_YTDLP_PATH, $errors);
	}

	/** Clearing the override is how detection is switched back on. */
	public function testAnEmptyPathIsAccepted(): void {
		self::assertSame([], $this->store->saveAdmin([YtDlpLocator::CONFIG_YTDLP_PATH => '']));
		self::assertSame('', $this->appValues[YtDlpLocator::CONFIG_YTDLP_PATH]);
	}

	/**
	 * The cached version describes the binary that was there before. Keeping it would have
	 * the page report the old one's version against the new one's path.
	 */
	public function testChangingThePathForgetsTheCachedVersion(): void {
		$this->appValues[YtDlpLocator::CONFIG_CHECKED_AT] = 12345;

		$this->store->saveAdmin([YtDlpLocator::CONFIG_YTDLP_PATH => '']);

		self::assertSame(0, $this->appValues[YtDlpLocator::CONFIG_CHECKED_AT]);
	}

	/**
	 * One bad field must not discard the others — a page is saved as a whole, and being
	 * told to retype three correct values because the fourth was wrong is its own bug.
	 */
	public function testGoodFieldsAreSavedAlongsideARefusedOne(): void {
		$errors = $this->store->saveAdmin([
			YtDlpLocator::CONFIG_ENABLED => true,
			YtDlpLocator::CONFIG_YTDLP_PATH => 'relative/path',
			YoutubeImportService::CONFIG_MAX_DURATION => 20,
		]);

		self::assertSame([YtDlpLocator::CONFIG_YTDLP_PATH], array_keys($errors));
		self::assertTrue($this->appValues[YtDlpLocator::CONFIG_ENABLED]);
		self::assertSame(20 * 60, $this->appValues[YoutubeImportService::CONFIG_MAX_DURATION]);
	}

	public function testFieldsThatWereNotSubmittedAreLeftAlone(): void {
		$this->appValues[YtDlpLocator::CONFIG_ENABLED] = true;

		$this->store->saveAdmin([YoutubeImportService::CONFIG_MAX_DURATION => 10]);

		self::assertTrue($this->appValues[YtDlpLocator::CONFIG_ENABLED]);
	}

	// -------------------------------------------------------------- personal

	public function testAFolderIsSaved(): void {
		self::assertSame([], $this->store->savePersonal(self::USER, [
			MusicLibrary::CONFIG_FOLDER => 'Media/Music',
		]));
		self::assertSame('Media/Music', $this->userValues[MusicLibrary::CONFIG_FOLDER]);
	}

	/**
	 * Refused rather than quietly corrected: saving "../music" and being shown "music"
	 * afterwards would be worse than being told why.
	 */
	public function testAFolderEscapingTheUsersFilesIsRefused(): void {
		$errors = $this->store->savePersonal(self::USER, [
			MusicLibrary::CONFIG_FOLDER => '../../etc',
		]);

		self::assertArrayHasKey(MusicLibrary::CONFIG_FOLDER, $errors);
		self::assertArrayNotHasKey(MusicLibrary::CONFIG_FOLDER, $this->userValues);
	}

	/**
	 * The page only offers a picker, so this cannot happen through it — but the endpoint is
	 * reachable directly, and the rule the page presents has to be the server's rule.
	 */
	public function testAFolderThatDoesNotExistIsRefused(): void {
		$this->missingFolders = ['Nowhere'];

		$errors = $this->store->savePersonal(self::USER, [MusicLibrary::CONFIG_FOLDER => 'Nowhere']);

		self::assertArrayHasKey(MusicLibrary::CONFIG_FOLDER, $errors);
		self::assertArrayNotHasKey(MusicLibrary::CONFIG_FOLDER, $this->userValues);
	}

	/** Clearing it means the default, which is created on first use as it always was. */
	public function testAnEmptyFolderIsAcceptedEvenIfTheDefaultIsNotThereYet(): void {
		$this->missingFolders = [MusicLibrary::DEFAULT_FOLDER];

		self::assertSame([], $this->store->savePersonal(self::USER, [MusicLibrary::CONFIG_FOLDER => '']));
	}

	public function testAnEmptyFolderMeansTheDefault(): void {
		self::assertSame([], $this->store->savePersonal(self::USER, [MusicLibrary::CONFIG_FOLDER => '']));
		self::assertSame(MusicLibrary::DEFAULT_FOLDER, $this->store->personalState(self::USER)['values'][MusicLibrary::CONFIG_FOLDER]);
	}

	public function testTheDefaultIsReportedWhenNothingIsSet(): void {
		$state = $this->store->personalState(self::USER);

		self::assertSame(MusicLibrary::DEFAULT_FOLDER, $state['values'][MusicLibrary::CONFIG_FOLDER]);
		self::assertSame(MusicLibrary::DEFAULT_FOLDER, $state['defaultFolder']);
	}

	// ------------------------------------------------------------- defaults

	public function testTheDownloaderIsOffUntilItIsSwitchedOn(): void {
		self::assertFalse($this->store->adminValues()[YtDlpLocator::CONFIG_ENABLED]);
	}

	public function testTheAdminStateCarriesTheLiveStatus(): void {
		$state = $this->store->adminState();

		self::assertArrayHasKey('summary', $state);
		self::assertArrayHasKey('ytDlp', $state);
		self::assertArrayHasKey('values', $state);
	}
}
