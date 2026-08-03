<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\Clock;
use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\RemoteImportSettings;
use OCA\MusicRadio\Service\YtDlpLocator;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may collect imports, and whether anybody is.
 *
 * The allow-list is the whole security boundary of the worker API — an account named here
 * can be handed any queued job and can upload audio into any channel owner's storage — so
 * the cases that matter are the ones about *not* being on it.
 */
class RemoteImportSettingsTest extends TestCase {

	private const NOW = 1_700_000_000;

	/** @var array<string, mixed> */
	private array $values = [];
	private RemoteImportSettings $settings;

	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn (string $a, string $k, string $d = ''): string => (string)($this->values[$k] ?? $d));
		$appConfig->method('getValueInt')
			->willReturnCallback(fn (string $a, string $k, int $d = 0): int => (int)($this->values[$k] ?? $d));
		$appConfig->method('getValueBool')
			->willReturnCallback(fn (string $a, string $k, bool $d = false): bool => (bool)($this->values[$k] ?? $d));
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $a, string $k, string $v): bool {
				$this->values[$k] = $v;

				return true;
			});
		$appConfig->method('setValueInt')
			->willReturnCallback(function (string $a, string $k, int $v): bool {
				$this->values[$k] = $v;

				return true;
			});

		$clock = $this->createMock(Clock::class);
		$clock->method('nowSeconds')->willReturn(self::NOW);

		$this->settings = new RemoteImportSettings($appConfig, $clock);
	}

	// ------------------------------------------------------------------- mode

	public function testAServerFetchesItsOwnImportsUntilItIsToldOtherwise(): void {
		$this->assertFalse($this->settings->isRemote());
		$this->assertSame(RemoteImportSettings::MODE_LOCAL, $this->settings->mode());
	}

	public function testAnUnrecognisedModeIsTreatedAsLocal(): void {
		// The safe direction: a typo must not stop this server importing, it must stop it
		// waiting for a machine that does not exist.
		$this->values[RemoteImportSettings::CONFIG_MODE] = 'somewhere-else';

		$this->assertFalse($this->settings->isRemote());
	}

	// ---------------------------------------------------------------- workers

	#[DataProvider('workerLists')]
	public function testTheAllowListIsReadLeniently(string $stored, array $expected): void {
		$this->values[RemoteImportSettings::CONFIG_WORKERS] = $stored;

		$this->assertSame($expected, $this->settings->workerAccounts());
	}

	/**
	 * @return array<string, array{string, list<string>}>
	 */
	public static function workerLists(): array {
		return [
			'nothing' => ['', []],
			'one' => ['radio-worker', ['radio-worker']],
			'several' => ['nas,laptop', ['nas', 'laptop']],
			'spaced out' => [' nas , laptop ', ['nas', 'laptop']],
			'trailing comma' => ['nas,', ['nas']],
			'said twice' => ['nas,nas', ['nas']],
		];
	}

	public function testNobodyMayCollectByDefault(): void {
		$this->assertFalse($this->settings->isWorker('admin'));
	}

	public function testAnAccountNotOnTheListMayNotCollect(): void {
		$this->values[RemoteImportSettings::CONFIG_WORKERS] = 'radio-worker';

		$this->assertTrue($this->settings->isWorker('radio-worker'));
		$this->assertFalse($this->settings->isWorker('admin'));
		// Anonymous is not a worker either, which is the case that matters if a route is
		// ever made public by accident.
		$this->assertFalse($this->settings->isWorker(null));
	}

	// ------------------------------------------------------------ checking in

	public function testAWorkerIsOfflineUntilItHasEverSaidAnything(): void {
		$this->assertFalse($this->settings->isOnline());
	}

	public function testCheckingInIsRememberedWithTheRuntimeItFound(): void {
		$this->settings->markSeen('nas', 'node:/usr/bin/node');

		$this->assertTrue($this->settings->isOnline());
		$this->assertSame('nas', $this->settings->seenName());
		$this->assertSame('node:/usr/bin/node', $this->settings->seenJsRuntime());
	}

	public function testAWorkerThatStoppedTalkingIsReportedOffline(): void {
		$this->values[RemoteImportSettings::CONFIG_SEEN_AT]
			= self::NOW - RemoteImportSettings::OFFLINE_AFTER_SECONDS - 1;

		$this->assertFalse($this->settings->isOnline());
	}

	// ----------------------------------------------------------- availability

	public function testTheMasterSwitchStillApplies(): void {
		// Moving the work to another machine does not make "may this server fetch from
		// YouTube at all" somebody else's decision.
		$this->values[RemoteImportSettings::CONFIG_WORKERS] = 'radio-worker';
		$this->settings->markSeen('nas', null);

		$this->assertSame(ImportError::DISABLED, $this->settings->status()->reason);
	}

	public function testWithNoWorkerAccountTheFeatureSaysSoRatherThanWaiting(): void {
		$this->values[YtDlpLocator::CONFIG_ENABLED] = true;

		$status = $this->settings->status();

		$this->assertFalse($status->available);
		$this->assertSame(ImportError::REMOTE_NOT_CONFIGURED, $status->reason);
	}

	public function testAnAbsentWorkerIsRefusedUpFrontRatherThanQueuedForEver(): void {
		$this->values[YtDlpLocator::CONFIG_ENABLED] = true;
		$this->values[RemoteImportSettings::CONFIG_WORKERS] = 'radio-worker';

		$status = $this->settings->status();

		$this->assertFalse($status->available);
		$this->assertSame(ImportError::REMOTE_WORKER_OFFLINE, $status->reason);
	}

	public function testAnAbsentWorkerIsStillAServerThatDoesImports(): void {
		// The distinction the sharing panel reads. A worker that is switched off, rebooting
		// or between polls cannot take a job — but every decision an administrator had to
		// make has been made, and the per-share "Can add tracks from YouTube" permission is
		// still in force. Gating that switch on `available` hid a setting that was doing
		// something, and made it impossible to prepare shares before starting a worker for
		// the first time.
		$this->values[YtDlpLocator::CONFIG_ENABLED] = true;
		$this->values[RemoteImportSettings::CONFIG_WORKERS] = 'radio-worker';

		$status = $this->settings->status();

		$this->assertFalse($status->available);
		$this->assertTrue($status->configured);
	}

	public function testAServerWithNoWorkerAccountIsNotConfigured(): void {
		// The other side of it: nobody may collect, so there is nothing to offer an owner
		// and the switch should stay away.
		$this->values[YtDlpLocator::CONFIG_ENABLED] = true;

		$this->assertFalse($this->settings->status()->configured);
	}

	public function testImportingSwitchedOffIsNotConfigured(): void {
		$this->values[RemoteImportSettings::CONFIG_WORKERS] = 'radio-worker';
		$this->settings->markSeen('nas', null);

		$this->assertFalse($this->settings->status()->configured);
	}

	public function testAListeningWorkerMakesImportingAvailable(): void {
		$this->values[YtDlpLocator::CONFIG_ENABLED] = true;
		$this->values[RemoteImportSettings::CONFIG_WORKERS] = 'radio-worker';
		$this->settings->markSeen('nas', 'node:/usr/bin/node');

		$status = $this->settings->status();

		$this->assertTrue($status->available);
		$this->assertTrue($status->configured);
		$this->assertNull($status->reason);
		// The worker's runtime, carried through so that everything which asks "can cookies
		// be used" gets an answer about the machine that will actually run yt-dlp.
		$this->assertSame('node', $status->jsRuntime?->name);
		// Deliberately nothing about paths or versions: they describe a machine this
		// server cannot inspect.
		$this->assertNull($status->ytDlpPath);
		$this->assertFalse($status->outdated);
	}

	public function testARuntimeThatIsNotOneYtDlpKnowsIsIgnored(): void {
		$this->values[YtDlpLocator::CONFIG_ENABLED] = true;
		$this->values[RemoteImportSettings::CONFIG_WORKERS] = 'radio-worker';
		// A worker reports this, and it goes onto a command line. Anything unrecognised
		// costs the worker its cookies rather than being passed through.
		$this->settings->markSeen('nas', 'bash:/bin/bash');

		$this->assertNull($this->settings->status()->jsRuntime);
	}
}
