<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * A channel owner's YouTube cookies: kept encrypted, lent to yt-dlp, never shown again.
 *
 * **What this is for.** YouTube answers some servers with "Sign in to confirm you're not a
 * bot" — a judgement about the address the request came from, not about the video. Nothing
 * on the server can argue with it; the only thing that changes the answer is asking as
 * somebody signed in. yt-dlp does that with a Netscape cookie file, and this is where that
 * file lives between imports.
 *
 * **Whose cookies.** The channel owner's, not the person who pasted the link. That matches
 * what the rest of an import already does — the audio lands in the owner's storage and
 * against their quota whoever asked for it — and it means a public link cannot make a
 * stranger's session fetch anything. It also puts the account at risk on the person getting
 * the benefit, which is the right way round: YouTube may lock an account it decides is
 * being automated, so what belongs in here is a throwaway, and {@see docs/youtube-cookies.md}
 * says so in the first paragraph.
 *
 * **How it is protected.**
 *
 * - Stored through {@see IUserConfig} with `FLAG_SENSITIVE`, which is not merely a label:
 *   core encrypts flagged values at rest with the instance secret (`ICrypto`, AES-CBC plus
 *   an HMAC) and masks them in `occ config:list` and support dumps. Encrypting here as well
 *   would add a second layer over the same secret and buy nothing — while losing the
 *   masking, because the flag is what drives it.
 * - Stored **lazy**, so a multi-kilobyte blob is not loaded on every request for every user
 *   when only the import job ever reads it.
 * - Written with `deleteKey()` first. The flag on an existing key cannot be changed in
 *   place, so a key that ever existed unflagged would keep storing plaintext — deleting is
 *   the only way to be sure of what a write produces.
 * - Never returned to a browser. {@see describe()} answers with counts and dates; there is
 *   deliberately no read path to the value except the one that writes the file yt-dlp reads.
 * - The file itself is written 0600 inside the per-import temporary directory, which the
 *   service removes in a `finally` whatever happens.
 */
class CookieJar {

	public const CONFIG_COOKIES = 'youtube_cookies';
	public const CONFIG_STORED_AT = 'youtube_cookies_stored_at';

	/** The name yt-dlp is pointed at, inside the import's own directory. */
	public const FILE_NAME = 'cookies.txt';

	/**
	 * A real jar is a couple of kilobytes. This is loose enough not to reject anyone's and
	 * tight enough that the config table cannot be used as storage.
	 */
	public const MAX_BYTES = 128 * 1024;

	/**
	 * A cookie line, as every exporter writes it: domain, whether subdomains are included,
	 * path, secure, expiry, name, value — tab separated.
	 *
	 * The domain may carry a `#HttpOnly_` prefix, which looks like a comment and is not;
	 * dropping those lines would throw away exactly the session cookies that matter.
	 */
	private const COOKIE_LINE = '/^(#HttpOnly_)?([^\s#][^\t]*)\t([^\t]*)\t([^\t]*)\t([^\t]*)\t(-?\d+)\t([^\t]*)\t(.*)$/';

	/**
	 * The jar has to be for YouTube.
	 *
	 * Checked because the likeliest mistake is not a malformed file but the right file for
	 * the wrong site — an export taken while the wrong tab was focused. That produces a jar
	 * yt-dlp accepts and YouTube ignores, so without this the symptom would be "I added
	 * cookies and nothing changed".
	 */
	private const EXPECTED_DOMAINS = ['youtube.com', 'google.com'];

	/**
	 * The cookies that actually carry the sign-in.
	 *
	 * Everything else in a YouTube jar is preferences and telemetry, and — this is the part
	 * that matters — YouTube *adds* short-lived ones of its own during a run. After a single
	 * import the soonest expiry in the jar is some half-hour visitor cookie, so "when does
	 * this stop working" answered over the whole jar reports a healthy set of credentials as
	 * expired within the hour. Answered over these, it is the date the session dies.
	 *
	 * Names as Google writes them, matched exactly. A miss here costs a description, never
	 * an import: an unrecognised jar is reported as having no known expiry, not refused.
	 */
	private const LOGIN_COOKIES = [
		'SID', 'HSID', 'SSID', 'APISID', 'SAPISID', 'LOGIN_INFO',
		'__Secure-1PSID', '__Secure-3PSID',
		'__Secure-1PAPISID', '__Secure-3PAPISID',
	];

	public function __construct(
		private IUserConfig $userConfig,
		private Clock $clock,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	// ------------------------------------------------------------------ storing

	/**
	 * @return string|null why it was refused, or null when it was stored
	 */
	public function store(string $userId, string $text): ?string {
		$text = trim($text);

		$problem = $this->check($text);
		if ($problem !== null) {
			return $problem;
		}

		$this->write($userId, $text, $this->clock->nowSeconds());

		return null;
	}

	public function clear(string $userId): void {
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_COOKIES);
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_STORED_AT);
	}

	public function has(string $userId): bool {
		return $this->read($userId) !== null;
	}

	/**
	 * The write, with the flags that make it a secret rather than a setting.
	 *
	 * @see self for why the key is deleted first
	 */
	private function write(string $userId, string $text, ?int $storedAt = null): void {
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_COOKIES);
		$this->userConfig->setValueString(
			$userId,
			Application::APP_ID,
			self::CONFIG_COOKIES,
			$text,
			lazy: true,
			flags: IUserConfig::FLAG_SENSITIVE,
		);

		if ($storedAt !== null) {
			$this->userConfig->setValueInt($userId, Application::APP_ID, self::CONFIG_STORED_AT, $storedAt);
		}
	}

	/**
	 * @return string|null the stored jar, or null when there is none
	 */
	private function read(string $userId): ?string {
		$stored = $this->userConfig->getValueString(
			$userId,
			Application::APP_ID,
			self::CONFIG_COOKIES,
			lazy: true,
		);

		return $stored === '' ? null : $stored;
	}

	// --------------------------------------------------------------- validating

	/**
	 * Everything that can be known about a jar without asking YouTube.
	 *
	 * Rejecting here rather than at import time is the whole point: the person pasting it is
	 * looking at the page and can fix it, whereas the same mistake found during an import
	 * surfaces hours later on a queue row, to somebody who may not be them.
	 *
	 * @return string|null why it was refused, or null when it looks usable
	 */
	public function check(string $text): ?string {
		if ($text === '') {
			return $this->l10n->t('Paste the contents of the cookies.txt file.');
		}
		if (strlen($text) > self::MAX_BYTES) {
			return $this->l10n->t('That file is too large to be a cookie export.');
		}
		// A cookie file is text. Anything with a NUL in it is a different kind of file, and
		// would be written to disk and handed to a subprocess.
		if (str_contains($text, "\0")) {
			return $this->l10n->t('That does not look like a cookies.txt file.');
		}

		$cookies = self::parse($text);
		if ($cookies === []) {
			return $this->l10n->t('No cookies could be read from that. Export in the Netscape format — see the instructions below.');
		}

		foreach ($cookies as $cookie) {
			foreach (self::EXPECTED_DOMAINS as $domain) {
				if (str_ends_with(ltrim($cookie['domain'], '.'), $domain)) {
					return null;
				}
			}
		}

		return $this->l10n->t('Those cookies are not for YouTube. Export them from a tab that is signed in to youtube.com.');
	}

	/**
	 * @return list<array{domain: string, name: string, expires: int}>
	 */
	public static function parse(string $text): array {
		$cookies = [];

		foreach (preg_split('/\R/', $text) ?: [] as $line) {
			if (preg_match(self::COOKIE_LINE, rtrim($line, "\r"), $matches) !== 1) {
				continue;
			}

			$cookies[] = [
				'domain' => $matches[2],
				'name' => $matches[7],
				// Zero means a session cookie: gone when the browser closes, and of no use
				// to a server. Kept in the list so the count is honest, and skipped when
				// working out when the jar dies.
				'expires' => (int)$matches[6],
			];
		}

		return $cookies;
	}

	// ------------------------------------------------------------- describing

	/**
	 * What the settings page may say about a stored jar.
	 *
	 * Counts, domains and dates — never a name and never a value. The page's job is to let
	 * somebody confirm that something is stored and decide whether it is still good, and
	 * none of that needs the secret back.
	 *
	 * `expiresAt` is when the sign-in dies — the soonest expiry among {@see LOGIN_COOKIES}
	 * and nothing else. Null when none of them are recognisable, which `signedIn` also
	 * reports: a jar exported from a window that was not actually logged in is the quietest
	 * way to get this wrong, because it stores cleanly, imports cleanly, and changes
	 * nothing.
	 *
	 * @return array{count: int, domains: list<string>, storedAt: int, expiresAt: int|null,
	 *               signedIn: bool}|null
	 */
	public function describe(string $userId): ?array {
		$stored = $this->read($userId);
		if ($stored === null) {
			return null;
		}

		$cookies = self::parse($stored);

		$domains = [];
		$expiries = [];
		foreach ($cookies as $cookie) {
			$domains[ltrim($cookie['domain'], '.')] = true;

			// A zero expiry is a session cookie: dead when the browser closed, so it says
			// nothing about how long this jar has left.
			if (in_array($cookie['name'], self::LOGIN_COOKIES, true) && $cookie['expires'] > 0) {
				$expiries[] = $cookie['expires'];
			}
		}

		return [
			'count' => count($cookies),
			'domains' => array_keys($domains),
			'storedAt' => $this->userConfig->getValueInt(
				$userId,
				Application::APP_ID,
				self::CONFIG_STORED_AT,
			),
			'expiresAt' => $expiries === [] ? null : min($expiries),
			'signedIn' => $expiries !== [],
		];
	}

	// ------------------------------------------------------------------ lending

	/**
	 * Put the jar on disk for one run, and say where.
	 *
	 * @param string $directory the import's own temporary directory, removed by the caller
	 * @return string|null the path to give `--cookies`, or null when there is nothing to lend
	 */
	public function writeTo(string $userId, string $directory): ?string {
		$stored = $this->read($userId);
		if ($stored === null) {
			return null;
		}

		$path = rtrim($directory, '/') . '/' . self::FILE_NAME;

		// Created empty and locked down *before* anything is written to it. Writing first
		// and chmod'ing after would leave the cookies world-readable for the width of that
		// gap, on a server where the temporary directory may be shared.
		$handle = @fopen($path, 'w');
		if ($handle === false) {
			$this->logger->warning('Could not write the YouTube cookie file for an import', [
				'app' => Application::APP_ID,
				// Deliberately the directory and not the user: which account holds cookies is
				// not something to write to a log that others read.
				'directory' => $directory,
			]);

			return null;
		}

		@chmod($path, 0600);
		fwrite($handle, $stored);
		fclose($handle);

		return $path;
	}

	/**
	 * Take back whatever yt-dlp left in the file.
	 *
	 * YouTube rotates its session cookies, and yt-dlp writes the rotated jar back out when
	 * it was given one. Without this the stored copy would be the one that was pasted, going
	 * staler every run, and the feature would quietly need redoing every few days.
	 *
	 * Silent about everything. This runs after an import has already succeeded or failed and
	 * has been reported; there is no outcome here worth changing that verdict, and a jar that
	 * came back unreadable is simply not written — the previous one stays, which is the safe
	 * of the two directions.
	 */
	public function refreshFrom(string $userId, string $path): void {
		if (!is_file($path)) {
			return;
		}

		$written = @file_get_contents($path);
		if (!is_string($written)) {
			return;
		}

		$written = trim($written);

		// Unchanged is the common case, and writing anyway would mean a config write on
		// every import for nothing.
		if ($written === '' || $written === $this->read($userId)) {
			return;
		}

		// Held to the same standard as a paste. A run that ended by emptying the jar, or by
		// leaving something that no longer parses, must not replace a jar that works.
		if ($this->check($written) !== null) {
			return;
		}

		// The stored-at date is left alone on purpose: it records when a person last put
		// cookies here, which is what they need to reason about. Moving it forward on every
		// refresh would make a jar from March look like it was pasted this morning.
		$this->write($userId, $written);
	}
}
