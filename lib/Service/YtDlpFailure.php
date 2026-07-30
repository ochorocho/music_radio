<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Process\ProcessResult;

/**
 * Working out why a yt-dlp run did not produce a track.
 *
 * Reading English error strings is not elegant, but yt-dlp exits 1 for almost everything,
 * so it is the only signal that distinguishes "that video is private" from "the server has
 * no network". The alternative is telling everyone "the import failed", which is the least
 * useful sentence a piece of software can produce.
 *
 * Three things about a real yt-dlp run that this had to be built around, all confirmed by
 * running it rather than by reading the manual:
 *
 * 1. **Only `ERROR:` lines matter.** Every single run also emits a `WARNING:` about
 *    JavaScript runtimes being deprecated. That warning contains a URL and the word
 *    "deprecated", and matching against it would misclassify every failure.
 * 2. **A rejected `--match-filter` exits 0.** A video that is too long is not an error at
 *    all: yt-dlp prints "does not pass filter" on *stdout*, exits 0, and writes no file. So
 *    the exit code cannot be the first thing consulted.
 * 3. **Success is having a file.** Which is why the caller passes in whether one appeared,
 *    rather than this class trusting a zero exit.
 */
final class YtDlpFailure {

	/**
	 * @param bool $producedFile whether an audio file actually appeared. Exit code 0 with
	 *                           no file is a normal outcome, not a contradiction.
	 * @return string|null null when the run succeeded and there is nothing to explain
	 */
	public static function classify(ProcessResult $result, bool $producedFile): ?string {
		if ($producedFile && $result->succeeded()) {
			return null;
		}

		if ($result->aborted) {
			return ImportError::CANCELLED;
		}
		if ($result->timedOut) {
			return ImportError::TIMED_OUT;
		}

		// Checked before the exit code, because this case *is* a zero exit.
		if (str_contains($result->stdout, 'does not pass filter')) {
			return ImportError::TOO_LONG;
		}

		$errors = self::errorLines($result->stderr);

		// Order matters: the specific readings come before the general ones, because
		// several of these strings co-occur. "Private video" also says "Sign in", and an
		// outdated extractor also fails to "download" things.
		$code = self::fromErrorText($errors);
		if ($code !== null) {
			return $code;
		}

		// Exit 0, no error line, and still no file. yt-dlp thought it succeeded, so
		// whatever it produced was not audio we could use.
		if ($result->exitCode === 0) {
			return ImportError::NO_AUDIO;
		}

		return ImportError::UNKNOWN;
	}

	/**
	 * Also used on the probe pass, where there is no file to look for and the metadata
	 * checks happen separately.
	 */
	public static function classifyProbe(ProcessResult $result): ?string {
		if ($result->succeeded()) {
			return null;
		}

		return self::classify($result, false);
	}

	private static function fromErrorText(string $text): ?string {
		return match (true) {
			// Bot checks and age gates both talk about signing in, so they are separated
			// on the part that differs.
			self::matches($text, ['not a bot', 'confirm you.re not a bot']) => ImportError::BOT_CHECK,
			self::matches($text, ['confirm your age', 'age-restricted', 'inappropriate for some users']) => ImportError::AGE_RESTRICTED,
			self::matches($text, ['members-only', 'available to this channel.s members', 'join this channel']) => ImportError::MEMBERS_ONLY,
			self::matches($text, ['private video', 'sign in if you.ve been granted access']) => ImportError::VIDEO_PRIVATE,
			// Written loosely on purpose. YouTube phrases this as "The uploader has not
			// made this video available in your country", which does *not* contain the
			// phrase "not available in your country" — matching the obvious wording missed
			// the real one.
			self::matches($text, [
				'available in your country',
				'blocked it in your country',
				'available from your location',
				'geo.restricted',
			]) => ImportError::GEO_BLOCKED,
			self::matches($text, ['live event will begin', 'is live', 'premieres in']) => ImportError::LIVE_STREAM,
			self::matches($text, ['larger than max-filesize', 'file is larger than']) => ImportError::TOO_LARGE,

			// The most likely failure in ordinary operation: YouTube changes something and
			// the installed yt-dlp has not caught up. It is the only code whose message
			// names the actual fix.
			self::matches($text, [
				'nsig extraction failed',
				'signature extraction failed',
				'unable to extract',
				'please report this issue',
				'update to the latest version',
				'confirm the issue persists',
			]) => ImportError::DOWNLOADER_OUTDATED,

			self::matches($text, [
				'unable to download',
				'temporary failure in name resolution',
				'name or service not known',
				'connection refused',
				'connection reset',
				'connection timed out',
				'(could not|failed to|cannot) resolve',
				'urlopen error',
				'read timed out',
				'network is unreachable',
				'ssl.* verify failed',
			]) => ImportError::NETWORK,

			// Deliberately last of the video states: "Video unavailable" is what YouTube
			// says for a removed video, and also what it falls back to for several other
			// reasons. Anything more specific has already matched above.
			self::matches($text, ['video unavailable', 'has been removed', 'no longer available', 'does not exist']) => ImportError::VIDEO_UNAVAILABLE,

			default => null,
		};
	}

	/**
	 * Only the lines yt-dlp marked as errors, lowercased for matching.
	 *
	 * Dropping warnings is not tidiness. yt-dlp warns on every run about JavaScript
	 * runtimes, and that text would otherwise be scanned for every pattern above.
	 */
	private static function errorLines(string $stderr): string {
		$errors = [];

		foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
			$line = trim($line);
			if (stripos($line, 'ERROR:') === 0 || stripos($line, 'ERROR ') === 0) {
				$errors[] = $line;
			}
		}

		// If yt-dlp said nothing it labelled an error, fall back to the whole of stderr —
		// a crash or a message from something other than yt-dlp still needs reading.
		return strtolower($errors === [] ? $stderr : implode("\n", $errors));
	}

	/**
	 * @param list<string> $needles regular-expression fragments, matched case-insensitively.
	 *                              Apostrophes are written as `.` because yt-dlp is
	 *                              inconsistent about straight and typographic quotes.
	 */
	private static function matches(string $haystack, array $needles): bool {
		foreach ($needles as $needle) {
			if (preg_match('/' . $needle . '/i', $haystack) === 1) {
				return true;
			}
		}

		return false;
	}
}
