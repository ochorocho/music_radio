<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Process;

/**
 * What came back from running an external program.
 *
 * `$timedOut` is kept separate from the exit code on purpose: a process the runner killed
 * reports whatever exit code the signal produced, which says nothing useful. Only the
 * runner knows it ran out of time, so it says so here rather than leaving the caller to
 * infer it from `exitCode === 143`.
 */
final class ProcessResult {

	public function __construct(
		public readonly int $exitCode,
		public readonly string $stdout,
		public readonly string $stderr,
		public readonly bool $timedOut,
		public readonly bool $aborted,
	) {
	}

	public function succeeded(): bool {
		return $this->exitCode === 0 && !$this->timedOut && !$this->aborted;
	}
}
