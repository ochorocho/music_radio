<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * The worker script this app ships, so a worker can keep itself current.
 *
 * A remote worker is a copy of `worker/music-radio-worker` sitting on somebody else's
 * machine, and the two ends have to agree about the shape of a job. Left alone, that copy
 * is frozen at whenever it was installed while the server it talks to is upgraded — which
 * is a version skew nobody would notice until a job failed strangely. So the server offers
 * its own copy, and `music-radio-worker update` takes it.
 *
 * **What is served is what the instance vouches for.** This file is part of the app's
 * signed set: `occ integrity:sign-app` hashes every shipped file, so a modified worker
 * script makes the instance's own integrity check fail. Nothing here re-implements that —
 * it just means the bytes handed out are the bytes that were released.
 *
 * The checksum is what the worker actually compares. It is computed here rather than
 * stored, because the file is small and any stored value would be one more thing that could
 * disagree with the file beside it.
 *
 * Everything answers null when the file is absent. A worker that gets nothing simply does
 * not update itself, which is the right outcome for an install that was stripped down, or
 * for anybody who has replaced the app directory with something of their own.
 */
class WorkerScript {

	/** Where the script lives inside the app. */
	public const RELATIVE_PATH = 'worker/music-radio-worker';

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What the worker compares against, without sending the script itself.
	 *
	 * Carried on the greeting, which the worker asks for anyway — so the check that runs
	 * every quarter of an hour costs no extra request, and the ~40 KB body is fetched only
	 * when the two sides actually differ.
	 *
	 * @return array{version: string, sha256: string, bytes: int}|null
	 */
	public function describe(): ?array {
		$contents = $this->contents();
		if ($contents === null) {
			return null;
		}

		return [
			// The app's version rather than one of the script's own. It is cosmetic — the
			// checksum is the identity — but it is what makes a log line readable.
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
			'sha256' => hash('sha256', $contents),
			'bytes' => strlen($contents),
		];
	}

	public function contents(): ?string {
		$path = $this->path();
		if ($path === null || !is_file($path) || !is_readable($path)) {
			return null;
		}

		$contents = @file_get_contents($path);

		return is_string($contents) && $contents !== '' ? $contents : null;
	}

	private function path(): ?string {
		try {
			return rtrim($this->appManager->getAppPath(Application::APP_ID), '/') . '/' . self::RELATIVE_PATH;
		} catch (\Throwable $e) {
			$this->logger->debug('Could not locate the worker script', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return null;
		}
	}
}
