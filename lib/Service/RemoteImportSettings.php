<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Where the imports are done, and by whom.
 *
 * A Nextcloud host is often the worst machine available for fetching from YouTube. It has a
 * datacentre address, which is what a bot check is looking at; its distribution's yt-dlp is
 * frequently a year old, which for that program means broken; and it may have no ffmpeg and
 * no JavaScript runtime at all. None of that is fixable from inside the app.
 *
 * Remote mode leaves the queue, the rules and the storage exactly where they are and moves
 * only the *work*: a machine somewhere else — a NAS at home, a laptop, a small VM with a
 * residential route — collects jobs over the API, runs yt-dlp, and hands the finished MP3
 * back. Everything a user sees is unchanged, because everything a user sees is decided
 * here.
 *
 * Three settings, and the third is the interesting one:
 *
 * - **the mode**, which decides whether a new import is queued for a background job on this
 *   server or left waiting for a worker to collect it;
 * - **who may collect**, an explicit list of accounts and empty by default. This is not
 *   ceremony. A worker can be handed any queued job, is told the video to fetch, and can
 *   upload audio that lands in the channel owner's storage — so "any account with a
 *   password" would be a way for any user to put files in another user's files. The
 *   allow-list is what makes the API safe to expose at all;
 * - **whether the owner's cookies travel**, off by default, because they are the one thing
 *   here that is a secret rather than a job.
 *
 * The last-seen timestamp is written by the workers themselves as they poll. It is what
 * lets the app say "no worker has checked in" instead of accepting an import that nothing
 * will ever collect.
 */
class RemoteImportSettings {

	public const CONFIG_MODE = 'import_mode';
	public const CONFIG_WORKERS = 'remote_worker_users';
	public const CONFIG_FORWARD_COOKIES = 'remote_forward_cookies';

	/** Written by the workers, read by everything that reports on them. */
	public const CONFIG_SEEN_AT = 'remote_worker_seen_at';
	public const CONFIG_SEEN_NAME = 'remote_worker_seen_name';
	public const CONFIG_SEEN_JS = 'remote_worker_seen_js';

	public const MODE_LOCAL = 'local';
	public const MODE_REMOTE = 'remote';

	/**
	 * The bump this needs if the job descriptor ever changes shape. A worker checks it and
	 * says so rather than failing every job in a way that looks like a broken network.
	 */
	public const PROTOCOL = 1;

	/**
	 * How long a worker's silence is tolerated before the feature reports itself as
	 * unavailable.
	 *
	 * Generously more than any sensible poll interval, because the cost of being wrong is
	 * asymmetric: a worker on a slow network briefly marked offline refuses imports that
	 * would have worked, while a minute of staleness costs nothing.
	 */
	public const OFFLINE_AFTER_SECONDS = 300;

	/**
	 * How long a claimed job is a worker's before the queue takes it back.
	 *
	 * Much shorter than the local {@see YoutubeImportService::STALL_AFTER_SECONDS}, because
	 * the two failures are not the same failure. A local job that stops talking took the
	 * PHP process with it and there is nothing to hand the work back to; a remote worker
	 * that stops talking has usually rebooted or lost its network, and the job is fine. So
	 * it is taken back quickly and given to somebody else.
	 */
	public const LEASE_SECONDS = 120;

	/**
	 * How many workers may die on the same job before it is called a bad job.
	 *
	 * Three, which is enough to survive a reboot and a bad network without a poisonous
	 * video going round the fleet for ever.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * A job nobody has collected in this long means there is nobody to collect it.
	 *
	 * Fifteen minutes rather than the hour the local path allows: a worker polls every few
	 * seconds, so anything still waiting after this is not a slow queue, it is an absent
	 * one.
	 */
	public const UNCOLLECTED_AFTER_SECONDS = 900;

	/**
	 * How often a poll is allowed to write the timestamp.
	 *
	 * Every worker polls every few seconds; writing on each one would be an app config
	 * update several times a second for a value nobody reads that often.
	 */
	private const SEEN_WRITE_INTERVAL_SECONDS = 60;

	public function __construct(
		private IAppConfig $appConfig,
		private Clock $clock,
	) {
	}

	// -------------------------------------------------------------------- mode

	public function mode(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_MODE, self::MODE_LOCAL)
			=== self::MODE_REMOTE
				? self::MODE_REMOTE
				: self::MODE_LOCAL;
	}

	public function isRemote(): bool {
		return $this->mode() === self::MODE_REMOTE;
	}

	public function forwardsCookies(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::CONFIG_FORWARD_COOKIES, false);
	}

	// ----------------------------------------------------------------- workers

	/**
	 * The accounts allowed to collect jobs.
	 *
	 * @return list<string>
	 */
	public function workerAccounts(): array {
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_WORKERS);

		return array_values(array_unique(array_filter(
			array_map(static fn (string $id): string => trim($id), explode(',', $stored)),
			static fn (string $id): bool => $id !== '',
		)));
	}

	/**
	 * Whether this account may act as a worker.
	 *
	 * Deliberately not "is an administrator". A worker holds a credential on a machine that
	 * is by definition somewhere else, and that credential should be able to do this and as
	 * little else as possible — which means a dedicated account, not the admin's.
	 */
	public function isWorker(?string $userId): bool {
		return $userId !== null && in_array($userId, $this->workerAccounts(), true);
	}

	/**
	 * Note that a worker is alive.
	 *
	 * @param string $name what the worker calls itself; advisory, and only ever displayed
	 * @param string|null $jsRuntime the runtime it found for itself, which is what decides
	 *                               whether lending it cookies would help or ruin the run
	 *                               — see {@see YoutubeImportService::cookiesAreUsable()}
	 */
	public function markSeen(string $name, ?string $jsRuntime): void {
		$now = $this->clock->nowSeconds();

		if ($now - $this->seenAt() < self::SEEN_WRITE_INTERVAL_SECONDS
			&& $this->seenName() === $name) {
			return;
		}

		$this->appConfig->setValueInt(Application::APP_ID, self::CONFIG_SEEN_AT, $now);
		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_SEEN_NAME, mb_substr($name, 0, 64));
		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_SEEN_JS, mb_substr($jsRuntime ?? '', 0, 128));
	}

	public function seenAt(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_SEEN_AT);
	}

	public function seenName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_SEEN_NAME);
	}

	/** What the last worker to check in had to run YouTube's JavaScript with. */
	public function seenJsRuntime(): ?string {
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_SEEN_JS);

		return $stored === '' ? null : $stored;
	}

	public function isOnline(): bool {
		$seenAt = $this->seenAt();

		return $seenAt > 0 && $this->clock->nowSeconds() - $seenAt <= self::OFFLINE_AFTER_SECONDS;
	}

	// ------------------------------------------------------ what is true now

	/**
	 * Whether this server can import, answered for remote mode.
	 *
	 * The same object {@see YtDlpLocator::status()} produces, and it has to be: everything
	 * downstream — the button, the refusal, the setup check, the occ command — reads one
	 * shape and must not learn about modes. What differs is what is asked. None of the
	 * local binaries matter here; what matters is that somebody is out there listening.
	 *
	 * The master switch still applies. "Allow importing from YouTube" is the decision about
	 * whether this server does this at all, and moving the work elsewhere does not make it
	 * somebody else's decision.
	 */
	public function status(): ToolStatus {
		if (!$this->appConfig->getValueBool(
			Application::APP_ID,
			YtDlpLocator::CONFIG_ENABLED,
			YtDlpLocator::DEFAULT_ENABLED,
		)) {
			return ToolStatus::unavailable(ImportError::DISABLED);
		}

		if ($this->workerAccounts() === []) {
			return ToolStatus::unavailable(ImportError::REMOTE_NOT_CONFIGURED);
		}

		// Refusing here rather than accepting an import nothing will collect. The row would
		// sit at "queued" until the reaper gave up on it, which is a worse way to learn the
		// same thing an hour later.
		if (!$this->isOnline()) {
			return ToolStatus::unavailable(ImportError::REMOTE_WORKER_OFFLINE);
		}

		return new ToolStatus(
			available: true,
			reason: null,
			// Deliberately no paths and no version: they describe a machine this server
			// does not have and cannot inspect. The worker's own yt-dlp is its operator's
			// business, and `outdated` stays false rather than guessing.
			jsRuntime: JsRuntime::fromSpec($this->seenJsRuntime() ?? ''),
		);
	}
}
