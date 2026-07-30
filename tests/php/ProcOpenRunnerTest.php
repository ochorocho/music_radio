<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests;

use OCA\MusicRadio\Process\ProcOpenRunner;
use PHPUnit\Framework\TestCase;

/**
 * The runner has no Nextcloud dependencies, so unlike most of this app's process-adjacent
 * code it can be tested for real — against actual programs, in an actual fork.
 *
 * The tests that matter here are the ones proving there is no shell. Everything else in the
 * import feature is built on that assumption.
 */
class ProcOpenRunnerTest extends TestCase {

	private ProcOpenRunner $runner;

	protected function setUp(): void {
		parent::setUp();
		$this->runner = new ProcOpenRunner();

		if (!$this->runner->isAvailable()) {
			self::markTestSkipped('proc_open is disabled on this PHP');
		}
	}

	public function testAvailableWhenProcOpenIsNotDisabled(): void {
		self::assertTrue($this->runner->isAvailable());
	}

	public function testCapturesStdoutAndExitCode(): void {
		$result = $this->runner->run(['/bin/echo', 'hello'], sys_get_temp_dir(), [], 5);

		self::assertSame(0, $result->exitCode);
		self::assertSame('hello', trim($result->stdout));
		self::assertTrue($result->succeeded());
	}

	// -------------------------------------------------------------- no shell

	/**
	 * The single most important test in this file. If a shell were involved, `$(id)` would
	 * be replaced by the output of `id`; because the command is an array handed to execvp,
	 * it stays eleven literal characters.
	 */
	public function testCommandSubstitutionIsNotEvaluated(): void {
		$result = $this->runner->run(['/bin/echo', '$(id)'], sys_get_temp_dir(), [], 5);

		self::assertSame('$(id)', trim($result->stdout));
		self::assertStringNotContainsString('uid=', $result->stdout);
	}

	public function testBacktickSubstitutionIsNotEvaluated(): void {
		$result = $this->runner->run(['/bin/echo', '`id`'], sys_get_temp_dir(), [], 5);

		self::assertSame('`id`', trim($result->stdout));
		self::assertStringNotContainsString('uid=', $result->stdout);
	}

	public function testCommandSeparatorsAreJustCharacters(): void {
		$result = $this->runner->run(['/bin/echo', 'one; id && whoami | cat'], sys_get_temp_dir(), [], 5);

		self::assertSame('one; id && whoami | cat', trim($result->stdout));
		self::assertStringNotContainsString('uid=', $result->stdout);
	}

	public function testGlobsAreNotExpanded(): void {
		$result = $this->runner->run(['/bin/echo', '/etc/*'], sys_get_temp_dir(), [], 5);

		self::assertSame('/etc/*', trim($result->stdout));
	}

	public function testAnArgumentWithSpacesStaysOneArgument(): void {
		// `printf '%s\n'` prints one line per argument, so this counts them.
		$result = $this->runner->run(
			['/usr/bin/printf', '%s\n', 'two words'],
			sys_get_temp_dir(),
			[],
			5,
		);

		self::assertSame(['two words'], array_values(array_filter(explode("\n", trim($result->stdout)))));
	}

	// ------------------------------------------------------------ environment

	public function testTheEnvironmentIsReplacedNotInherited(): void {
		putenv('MUSIC_RADIO_LEAK_CHECK=leaked');

		try {
			$result = $this->runner->run(['/usr/bin/env'], sys_get_temp_dir(), ['ONLY' => 'this'], 5);

			self::assertStringNotContainsString('MUSIC_RADIO_LEAK_CHECK', $result->stdout);
			self::assertStringContainsString('ONLY=this', $result->stdout);
		} finally {
			putenv('MUSIC_RADIO_LEAK_CHECK');
		}
	}

	// ----------------------------------------------------------------- limits

	public function testStderrAndFailingExitCodeAreReported(): void {
		$result = $this->runner->run(
			['/bin/sh', '-c', 'echo trouble >&2; exit 3'],
			sys_get_temp_dir(),
			[],
			5,
		);

		self::assertSame(3, $result->exitCode);
		self::assertSame('trouble', $result->stderr);
		self::assertFalse($result->succeeded());
	}

	public function testATimeoutStopsTheProcess(): void {
		$started = microtime(true);
		$result = $this->runner->run(['/bin/sleep', '30'], sys_get_temp_dir(), [], 1);
		$elapsed = microtime(true) - $started;

		self::assertTrue($result->timedOut);
		self::assertFalse($result->succeeded());
		// Well under the 30 s the process asked for, with room for the grace period.
		self::assertLessThan(10, $elapsed);
	}

	public function testAbortingStopsTheProcess(): void {
		$result = $this->runner->run(
			['/bin/sleep', '30'],
			sys_get_temp_dir(),
			[],
			30,
			null,
			static fn (): bool => true,
		);

		self::assertTrue($result->aborted);
		self::assertFalse($result->timedOut);
		self::assertFalse($result->succeeded());
	}

	// ------------------------------------------------------------- line reads

	public function testEachLineOfStdoutIsHandedOverOnce(): void {
		$lines = [];

		$this->runner->run(
			['/usr/bin/printf', 'a\nb\nc\n'],
			sys_get_temp_dir(),
			[],
			5,
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			},
		);

		self::assertSame(['a', 'b', 'c'], $lines);
	}

	public function testAFinalLineWithoutANewlineIsStillReported(): void {
		$lines = [];

		$this->runner->run(
			['/usr/bin/printf', 'no trailing newline'],
			sys_get_temp_dir(),
			[],
			5,
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			},
		);

		self::assertSame(['no trailing newline'], $lines);
	}

	// ------------------------------------------------------------------ input

	public function testAnEmptyCommandIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->runner->run([], sys_get_temp_dir(), [], 5);
	}

	public function testAMissingBinaryFailsLoudly(): void {
		$this->expectException(\RuntimeException::class);

		$this->runner->run(['/nonexistent/music-radio-test'], sys_get_temp_dir(), [], 5);
	}

	public function testTheChildCannotBlockOnStdin(): void {
		// `cat` with no arguments reads stdin forever; with stdin on /dev/null it sees EOF
		// straight away and exits. Without that, this test would hit the timeout.
		$result = $this->runner->run(['/bin/cat'], sys_get_temp_dir(), [], 5);

		self::assertTrue($result->succeeded());
		self::assertFalse($result->timedOut);
	}
}
