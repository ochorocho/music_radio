<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Process;

/**
 * Running an external program.
 *
 * An interface for one implementation, which is usually a smell — here it is the point.
 * `proc_open` cannot be exercised under the app's unit-test bootstrap in any meaningful
 * way, so everything that *decides* what to run and what to make of the result is tested
 * against a fake runner, and the small piece that actually forks is left to the end-to-end
 * suite. Without this seam, the import service would be untestable in its entirety.
 */
interface IProcessRunner {

	/**
	 * Whether this server permits running external programs at all.
	 *
	 * Shared hosting routinely puts `proc_open` in `disable_functions`, so this is a real
	 * deployment state to report, not a defensive nicety.
	 */
	public function isAvailable(): bool;

	/**
	 * @param list<string> $argv the program and its arguments, already safe. The first
	 *                           element is the binary. Passed to proc_open as an array, so
	 *                           there is no shell and no word splitting: an argument
	 *                           containing spaces, quotes or semicolons is one argument.
	 * @param array<string, string> $env replaces the environment entirely — nothing is
	 *                                   inherited
	 * @param null|callable(string): void $onStdoutLine called once per complete line of
	 *                                                  stdout, for progress reporting
	 * @param null|callable(): bool $shouldAbort polled about once a second; returning true
	 *                                           terminates the process
	 */
	public function run(
		array $argv,
		string $cwd,
		array $env,
		int $timeoutSeconds,
		?callable $onStdoutLine = null,
		?callable $shouldAbort = null,
	): ProcessResult;
}
