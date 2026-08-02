<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Service\WorkerScript;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The copy of the worker script this app hands out.
 *
 * Small, and every case is about *not* handing something out. A worker takes what this
 * returns and runs it, so "there is nothing here" has to be answered as nothing rather than
 * as an empty file or a guess.
 */
class WorkerScriptTest extends TestCase {

	private string $appDirectory;

	protected function setUp(): void {
		parent::setUp();

		$this->appDirectory = sys_get_temp_dir() . '/mr-worker-script-' . bin2hex(random_bytes(6));
		mkdir($this->appDirectory . '/worker', 0700, true);
	}

	protected function tearDown(): void {
		@unlink($this->appDirectory . '/worker/music-radio-worker');
		@rmdir($this->appDirectory . '/worker');
		@rmdir($this->appDirectory);

		parent::tearDown();
	}

	private function script(string $appDirectory): WorkerScript {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appDirectory);
		$appManager->method('getAppVersion')->willReturn('0.13.0');

		return new WorkerScript($appManager, new NullLogger());
	}

	private function shipScript(string $body): void {
		file_put_contents($this->appDirectory . '/worker/music-radio-worker', $body);
	}

	public function testTheScriptIsDescribedByItsChecksum(): void {
		$this->shipScript("#!/usr/bin/env python3\nprint('hello')\n");

		$described = $this->script($this->appDirectory)->describe();

		$this->assertNotNull($described);
		// Computed rather than stored, so it cannot disagree with the file beside it.
		$this->assertSame(hash('sha256', "#!/usr/bin/env python3\nprint('hello')\n"), $described['sha256']);
		$this->assertSame(38, $described['bytes']);
		$this->assertSame('0.13.0', $described['version']);
	}

	public function testAnInstallWithoutTheWorkerDirectoryOffersNothing(): void {
		// A stripped deployment, or an app directory somebody manages themselves. Answered
		// as "nothing to update to" rather than as a fault: a worker that hears nothing
		// keeps the copy it has, which is the safe of the two directions.
		$missing = $this->script($this->appDirectory . '/nowhere');

		$this->assertNull($missing->describe());
		$this->assertNull($missing->contents());
	}

	public function testAnEmptyFileIsNotAScript(): void {
		// A truncated deployment must not be served as though it were the program. The
		// worker would refuse it at the compile check anyway; refusing to offer it at all
		// is one fewer confusing step.
		$this->shipScript('');

		$this->assertNull($this->script($this->appDirectory)->describe());
	}

	public function testAnUnreadableAppPathIsNotAnError(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new \RuntimeException('no such app'));

		$this->assertNull((new WorkerScript($appManager, new NullLogger()))->describe());
	}
}
