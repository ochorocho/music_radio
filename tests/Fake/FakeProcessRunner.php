<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Tests\Fake;

use OCA\MusicRadio\Process\IProcessRunner;
use OCA\MusicRadio\Process\ProcessResult;

/**
 * A runner that does not run anything.
 *
 * This is the whole reason IProcessRunner exists. With it, every decision the import
 * service makes — what to do with each kind of failure, when to give up, what to write on
 * the row — is testable without a network, a binary, or a fork. A canned result is queued
 * per call, and the argv it was asked to run is recorded so a test can assert on it.
 */
class FakeProcessRunner implements IProcessRunner {

	/** @var list<ProcessResult> */
	private array $queued = [];

	/** @var list<list<string>> */
	public array $calls = [];

	/** @var list<string> lines to feed to the progress callback on the next run */
	private array $progressLines = [];

	public bool $available = true;

	/** Whether the caller's abort check ever came back true. */
	public bool $sawAbort = false;

	/** A file to create in the working directory, standing in for a download. */
	public ?string $producesFile = null;
	public string $producesContent = 'fake mp3 bytes';

	public function queue(ProcessResult $result): self {
		$this->queued[] = $result;

		return $this;
	}

	public function queueSuccess(string $stdout = ''): self {
		return $this->queue(new ProcessResult(0, $stdout, '', false, false));
	}

	public function queueFailure(string $stderr, int $exitCode = 1): self {
		return $this->queue(new ProcessResult($exitCode, '', $stderr, false, false));
	}

	/**
	 * @param list<string> $lines
	 */
	public function withProgress(array $lines): self {
		$this->progressLines = $lines;

		return $this;
	}

	public function isAvailable(): bool {
		return $this->available;
	}

	public function run(
		array $argv,
		string $cwd,
		array $env,
		int $timeoutSeconds,
		?callable $onStdoutLine = null,
		?callable $shouldAbort = null,
	): ProcessResult {
		$this->calls[] = $argv;

		if ($onStdoutLine !== null) {
			foreach ($this->progressLines as $line) {
				$onStdoutLine($line);
			}
		}

		// Asked at least once per run, the way a real download polls it between reads.
		if ($shouldAbort !== null && $shouldAbort()) {
			$this->sawAbort = true;

			return new ProcessResult(143, '', '', false, true);
		}

		if ($this->producesFile !== null) {
			file_put_contents($cwd . '/' . $this->producesFile, $this->producesContent);
		}

		return array_shift($this->queued)
			?? new ProcessResult(0, '', '', false, false);
	}

	/** The most recent command, for asserting on what was actually asked for. */
	public function lastCall(): array {
		return $this->calls === [] ? [] : $this->calls[array_key_last($this->calls)];
	}
}
