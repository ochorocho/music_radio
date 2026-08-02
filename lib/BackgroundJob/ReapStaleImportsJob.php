<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\BackgroundJob;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Service\ImportReaper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Tidy up imports that will never finish.
 *
 * A background job worker can be killed by an out-of-memory reaper, a container restart or
 * a PHP fatal, and when it is, the row it was working on keeps saying "running" with
 * nobody running it. Somebody has to notice, and the worker cannot do it itself.
 *
 * This is only half of the answer. It cannot report the case where background jobs are not
 * running at all, because then it is not running either — so the same sweep is also done,
 * scoped to one channel, when a browser asks for that channel's imports.
 *
 * @see ImportReaper for the sweep itself
 * @see \OCA\MusicRadio\Controller\ImportController::index for the other half
 */
class ReapStaleImportsJob extends TimedJob {

	private const INTERVAL_SECONDS = 300;

	public function __construct(
		ITimeFactory $time,
		private ImportReaper $reaper,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL_SECONDS);
		// Nothing here is urgent, and it should never compete with work somebody is
		// waiting on.
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$reaped = $this->reaper->sweep();
		} catch (\Throwable $e) {
			$this->logger->error('Could not tidy up stale imports', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return;
		}

		if ($reaped['stalled'] > 0 || $reaped['neverStarted'] > 0) {
			// Worth a log line: repeated never-started rows mean cron is not running — or,
			// in remote mode, that nothing is collecting — and that is the sort of thing an
			// administrator finds by grepping.
			$this->logger->warning('Released imports that will never finish', [
				'app' => Application::APP_ID,
				'stalled' => $reaped['stalled'],
				'neverStarted' => $reaped['neverStarted'],
			]);
		}

		if ($reaped['requeued'] > 0) {
			// A different event from the one above, and a much less alarming one: a remote
			// worker went away mid-job and somebody else will now do it. Logged at info
			// because a few of these is a normal week, and a lot of them is a machine that
			// keeps rebooting.
			$this->logger->info('Gave imports back to the queue after a worker went quiet', [
				'app' => Application::APP_ID,
				'requeued' => $reaped['requeued'],
			]);
		}
	}
}
