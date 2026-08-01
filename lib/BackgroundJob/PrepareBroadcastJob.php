<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\BackgroundJob;

use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Service\BroadcastLibrary;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Prepare one track's broadcast copy, away from anyone who is waiting.
 *
 * Transcoding used to happen on the streaming request itself, the first time a track was
 * asked for. That put a job measured in seconds on the path of a listener measured in
 * milliseconds, and the shape of the failure was worse than the delay: a request may only
 * prepare a couple of tracks before it has to answer (see
 * {@see \OCA\MusicRadio\Service\ProgrammeStreamService}'s build budget), so the first
 * person to play a new channel got a short span, a gap, another short span, and so on until
 * the library caught up. On a channel small enough to be sent whole it also meant no lap
 * existed yet, so the element could not loop and the whole benefit of that was lost for the
 * first few minutes.
 *
 * Doing it when the track is *added* removes the race entirely: by the time anyone presses
 * play the copy is usually already there, and the build budget goes back to being what it
 * was meant to be — a backstop, not the normal path.
 *
 * Queued rather than timed, and deliberately not in info.xml, for the same reason as
 * {@see ImportYoutubeAudioJob}: it is added by the request that created the track and runs
 * once with an argument.
 */
class PrepareBroadcastJob extends QueuedJob {

	public function __construct(
		ITimeFactory $time,
		private TrackMapper $trackMapper,
		private BroadcastLibrary $library,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		// One transcode at a time across the instance, matching the import job. Adding
		// twenty tracks at once is an ordinary thing to do, and twenty ffmpeg processes on
		// a server that is also serving pages is not.
		$this->setAllowParallelRuns(false);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$trackId = is_array($argument) ? (int)($argument['trackId'] ?? 0) : 0;
		$channelId = is_array($argument) ? (int)($argument['channelId'] ?? 0) : 0;
		if ($trackId <= 0 || $channelId <= 0) {
			return;
		}

		try {
			// Scoped to its channel, which is how the mapper reads a track anywhere else.
			$track = $this->trackMapper->find($trackId, $channelId);
		} catch (\Throwable) {
			// Removed again before this ran. Nothing to prepare and nobody to tell.
			return;
		}

		$source = $this->sourceOf($track->getAddedBy(), $track->getFileId());
		if ($source === null) {
			// Said out loud, quietly.
			//
			// This returned in silence at first, on the grounds that the streaming path
			// resolves the same file and reports it properly. That reasoning holds for the
			// listener and fails completely for anyone trying to work out why nothing is
			// being prepared: a worker that runs, finds nothing and says nothing is
			// indistinguishable from one that is not running at all.
			$this->logger->info('Nothing to prepare: the file behind this track could not be resolved', [
				'app' => 'music_radio',
				'trackId' => $trackId,
				'fileId' => $track->getFileId(),
				'addedBy' => $track->getAddedBy(),
			]);

			return;
		}

		try {
			$this->library->ensure($track, $source);
		} catch (\Throwable $e) {
			// Logged and dropped. A track that will not transcode is a real problem, but it
			// is one the listener's request will report properly when it happens; failing
			// the job would only retry the same broken file.
			$this->logger->warning('Could not prepare a track for broadcast', [
				'app' => 'music_radio',
				'trackId' => $trackId,
				'exception' => $e,
			]);
		}
	}

	private function sourceOf(?string $userId, ?int $fileId): ?File {
		if ($userId === null || $fileId === null) {
			return null;
		}

		try {
			$folder = $this->rootFolder->getUserFolder($userId);
			foreach ($folder->getById($fileId) as $node) {
				if ($node instanceof File && $node->isReadable()) {
					return $node;
				}
			}
		} catch (\Throwable) {
			// Storage unreachable, or the account is gone.
		}

		return null;
	}
}
