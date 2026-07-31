<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\BackgroundJob;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Service\VoteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Clear out votes that have stopped meaning anything.
 *
 * Two kinds. Nothing in this schema cascades, so removing a track leaves its votes behind
 * pointing at nothing — harmless but unbounded. And a channel nobody has voted on for a
 * month should not have month-old votes spring back into effect the next time somebody
 * presses play; the point of a vote is "I want this next", which expires.
 *
 * Deliberately not on the request path. Votes are normally spent when the track they name
 * reaches the front, which is the common case and needs no job — this only handles what
 * that never gets to.
 */
class SweepVotesJob extends TimedJob {

	private const INTERVAL_SECONDS = 3600;

	/**
	 * How long a vote stands.
	 *
	 * A week: long enough that a channel played once a weekend still honours what people
	 * asked for last time, short enough that it is not answering a request nobody
	 * remembers making.
	 */
	private const MAX_AGE_SECONDS = 7 * 86400;

	public function __construct(
		ITimeFactory $time,
		private VoteService $voteService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$swept = $this->voteService->sweep(self::MAX_AGE_SECONDS);
		} catch (\Throwable $e) {
			$this->logger->error('Could not sweep votes', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);

			return;
		}

		if ($swept['orphaned'] > 0 || $swept['stale'] > 0) {
			$this->logger->debug('Swept votes', [
				'app' => Application::APP_ID,
				'orphaned' => $swept['orphaned'],
				'stale' => $swept['stale'],
			]);
		}
	}
}
