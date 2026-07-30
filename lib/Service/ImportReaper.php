<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\ImportMapper;

/**
 * Releasing imports that are never going to finish.
 *
 * Its own class because it is called from two places that must not depend on each other: a
 * timed background job does the whole instance every five minutes, and the endpoint that
 * lists a channel's imports does that one channel before answering.
 *
 * The second caller looks redundant and is not. If background jobs are not running on a
 * server — a very common misconfiguration — then the timed job is not running either, and
 * every import would sit at "queued" for ever with nothing able to say so. Doing the sweep
 * on read means the browser is told "that import never started, background jobs may not be
 * running on this server", which names the actual problem.
 */
class ImportReaper {

	public function __construct(
		private ImportMapper $importMapper,
		private Clock $clock,
	) {
	}

	/**
	 * @param int|null $channelId limit the sweep to one channel, for the on-read case
	 * @return array{stalled: int, neverStarted: int, pruned: int}
	 */
	public function sweep(?int $channelId = null): array {
		$now = $this->clock->nowSeconds();

		$stalled = $this->importMapper->failStalled(
			$now - YoutubeImportService::STALL_AFTER_SECONDS,
			$now,
			ImportError::STALLED,
			$channelId,
		);

		$neverStarted = $this->importMapper->failNeverStarted(
			$now - YoutubeImportService::NEVER_STARTED_AFTER_SECONDS,
			$now,
			ImportError::NEVER_STARTED,
			$channelId,
		);

		// Only the instance-wide sweep prunes. Deleting rows is not something a read
		// request should be doing, and the on-read case only needs to unstick what it is
		// about to display.
		$pruned = $channelId === null
			? $this->importMapper->pruneFinished($now - YoutubeImportService::KEEP_FINISHED_SECONDS)
			: 0;

		return ['stalled' => $stalled, 'neverStarted' => $neverStarted, 'pruned' => $pruned];
	}
}
