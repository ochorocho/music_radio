<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Process;

/**
 * The one place in this app that forks a process.
 *
 * Three rules, each of which is load-bearing:
 *
 * 1. **The command is an array, never a string.** PHP's array form of proc_open goes
 *    straight to execvp with no shell in between, so quoting, word splitting, globbing,
 *    `$(…)`, `;` and `|` simply do not happen. Passing a string here — even a carefully
 *    escaped one — would put a shell back in the path and undo the URL canonicaliser's
 *    work in one line.
 * 2. **The environment is replaced, not inherited.** A web server's environment can carry
 *    `LD_PRELOAD`, `PYTHONPATH`, proxy variables and whatever else a hosting setup felt
 *    like exporting. The child gets exactly what the caller names and nothing else.
 * 3. **The process is always bounded.** A download that hangs must not hold a background
 *    job forever, so there is a deadline, and the deadline is enforced with a signal
 *    rather than by hoping.
 *
 * @see IProcessRunner for why this is behind an interface
 */
class ProcOpenRunner implements IProcessRunner {

	/**
	 * yt-dlp's `--dump-single-json` for a video with many formats runs to a few hundred
	 * kilobytes. This is well clear of that while still bounding a program that decides to
	 * print forever.
	 */
	private const MAX_STDOUT_BYTES = 8 * 1024 * 1024;

	/**
	 * Only the tail of stderr is kept. Diagnosis lives in the last few lines — the error,
	 * not the hundred warnings before it.
	 */
	private const MAX_STDERR_BYTES = 256 * 1024;

	/** How long a terminated process is given to exit before it is killed outright. */
	private const GRACE_SECONDS = 5;

	/** Length of one wait, and therefore how often the deadline and abort check run. */
	private const TICK_MICROSECONDS = 250_000;

	public function isAvailable(): bool {
		if (!function_exists('proc_open')) {
			return false;
		}

		// function_exists() returns true for a function listed in disable_functions on
		// some PHP builds, so the list is checked directly as well.
		$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

		return !in_array('proc_open', $disabled, true);
	}

	public function run(
		array $argv,
		string $cwd,
		array $env,
		int $timeoutSeconds,
		?callable $onStdoutLine = null,
		?callable $shouldAbort = null,
	): ProcessResult {
		if ($argv === []) {
			throw new \InvalidArgumentException('No command given');
		}
		if (!$this->isAvailable()) {
			throw new \RuntimeException('proc_open is not available on this server');
		}

		$descriptors = [
			// The child must never be able to wait on input that will not come.
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$pipes = [];
		$process = @proc_open($argv, $descriptors, $pipes, $cwd, $env);

		if (!is_resource($process)) {
			throw new \RuntimeException('Could not start ' . basename($argv[0]));
		}

		foreach ($pipes as $pipe) {
			stream_set_blocking($pipe, false);
		}

		$stdout = '';
		$stderr = '';
		$pending = '';
		$timedOut = false;
		$aborted = false;
		$deadline = microtime(true) + $timeoutSeconds;
		$killAfter = null;

		while (true) {
			$open = array_filter($pipes, static fn ($pipe): bool => is_resource($pipe) && !feof($pipe));

			if ($open !== []) {
				$read = array_values($open);
				$write = null;
				$except = null;

				// The return value is deliberately ignored: false means interrupted by a
				// signal, which is not an error, and 0 means the tick elapsed with nothing
				// to read — which is exactly when the checks below need to run.
				@stream_select($read, $write, $except, 0, self::TICK_MICROSECONDS);

				foreach ($read as $pipe) {
					$chunk = fread($pipe, 65536);
					if ($chunk === false || $chunk === '') {
						continue;
					}

					if ($pipe === $pipes[1]) {
						$stdout = $this->appendCapped($stdout, $chunk, self::MAX_STDOUT_BYTES);
						$pending = $this->emitLines($pending . $chunk, $onStdoutLine);
					} else {
						$stderr = $this->appendTail($stderr . $chunk, self::MAX_STDERR_BYTES);
					}
				}
			} else {
				// Nothing left to read, but the process may still be finishing.
				usleep(self::TICK_MICROSECONDS);
			}

			$status = proc_get_status($process);
			if ($status['running'] !== true) {
				break;
			}

			if ($killAfter !== null) {
				if (microtime(true) >= $killAfter) {
					// SIGTERM was ignored or the process is wedged. SIGKILL cannot be.
					proc_terminate($process, 9);
					$killAfter = null;
				}
				continue;
			}

			if (microtime(true) >= $deadline) {
				$timedOut = true;
				proc_terminate($process, 15);
				$killAfter = microtime(true) + self::GRACE_SECONDS;
				continue;
			}

			if ($shouldAbort !== null && $shouldAbort()) {
				$aborted = true;
				proc_terminate($process, 15);
				$killAfter = microtime(true) + self::GRACE_SECONDS;
			}
		}

		// Anything buffered between the last read and the process exiting.
		foreach ([1, 2] as $fd) {
			if (!is_resource($pipes[$fd])) {
				continue;
			}
			$rest = stream_get_contents($pipes[$fd]);
			if (is_string($rest) && $rest !== '') {
				if ($fd === 1) {
					$stdout = $this->appendCapped($stdout, $rest, self::MAX_STDOUT_BYTES);
					$pending = $this->emitLines($pending . $rest, $onStdoutLine);
				} else {
					$stderr = $this->appendTail($stderr . $rest, self::MAX_STDERR_BYTES);
				}
			}
			fclose($pipes[$fd]);
		}

		// A final line with no trailing newline still counts.
		if ($pending !== '' && $onStdoutLine !== null) {
			$onStdoutLine($pending);
		}

		$exitCode = proc_close($process);

		return new ProcessResult(
			// proc_close returns -1 when the status was already reaped by proc_get_status;
			// a signalled process is reported as such rather than as a mystery -1.
			exitCode: $timedOut || $aborted ? ($exitCode === -1 ? 143 : $exitCode) : $exitCode,
			stdout: $stdout,
			stderr: trim($stderr),
			timedOut: $timedOut,
			aborted: $aborted,
		);
	}

	/**
	 * @param null|callable(string): void $onStdoutLine
	 * @return string whatever is left after the last newline, to be prepended to the next
	 *                chunk — a read can land in the middle of a line
	 */
	private function emitLines(string $buffer, ?callable $onStdoutLine): string {
		if ($onStdoutLine === null) {
			// Still split, so the buffer cannot grow without bound when nobody is looking.
			$lastBreak = strrpos($buffer, "\n");

			return $lastBreak === false ? $this->appendTail($buffer, 65536) : '';
		}

		$lines = explode("\n", $buffer);
		// explode always yields at least one element, and the last is either an incomplete
		// line or '' when the chunk ended on a newline.
		$remainder = array_pop($lines);

		foreach ($lines as $line) {
			$line = rtrim($line, "\r");
			if ($line !== '') {
				$onStdoutLine($line);
			}
		}

		return $remainder;
	}

	/** Keep the beginning: stdout is parsed from the start, so the head is what matters. */
	private function appendCapped(string $current, string $chunk, int $limit): string {
		if (strlen($current) >= $limit) {
			return $current;
		}

		return substr($current . $chunk, 0, $limit);
	}

	/** Keep the end: the reason a program failed is the last thing it said. */
	private function appendTail(string $combined, int $limit): string {
		return strlen($combined) <= $limit ? $combined : substr($combined, -$limit);
	}
}
