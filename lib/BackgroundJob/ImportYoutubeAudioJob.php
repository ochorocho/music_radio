<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\BackgroundJob;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Service\YoutubeImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One queued import.
 *
 * Queued rather than timed: it is added by the request that asked for the import and runs
 * once. It is deliberately *not* listed in info.xml — that section is for jobs Nextcloud
 * should schedule itself, and listing a queued job there would run it with no argument.
 *
 * The job holds no state and makes no decisions. It exists to find the row and hand it to
 * the service, and to make sure that a crash on the way still leaves something readable
 * behind.
 */
class ImportYoutubeAudioJob extends QueuedJob {

	public function __construct(
		ITimeFactory $time,
		private ImportMapper $importMapper,
		private YoutubeImportService $importService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		// One import at a time across the whole instance. Downloading is cheap but
		// transcoding is not, and a channel with five queued links should not put five
		// ffmpeg processes on a server that is also serving pages. Nextcloud enforces this
		// when handing out jobs, so a queue simply drains one at a time.
		$this->setAllowParallelRuns(false);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$importId = is_array($argument) ? (int)($argument['importId'] ?? 0) : 0;
		if ($importId <= 0) {
			return;
		}

		try {
			$import = $this->importMapper->findById($importId);
		} catch (\Throwable) {
			// The channel was deleted, or the row was pruned, before this ran. There is
			// nothing to do and nobody left to tell.
			return;
		}

		try {
			$this->importService->perform($import);
		} catch (\Throwable $e) {
			// perform() is written not to throw, so reaching here means a bug rather than
			// a failed download. Logged loudly; the reaper will release the row, since
			// there is no longer a worker to update it.
			$this->logger->error('A YouTube import job threw, which it should not', [
				'app' => Application::APP_ID,
				'importId' => $importId,
				'exception' => $e,
			]);
		}
	}
}
