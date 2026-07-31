<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Db\VoteMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Playlist management.
 *
 * Every method that changes the track set, its order or its durations runs inside
 * TimelineService::withPreservedPosition(), so editing a playlist never disturbs what
 * listeners are currently hearing. That is the invariant this class exists to uphold —
 * see TimelineService for why a bare UPDATE would be a bug.
 */
class TrackService {

	/** Sort orders are allocated with gaps so an append never has to renumber. */
	private const SORT_STEP = 1000;

	private const MAX_TRACKS_PER_CHANNEL = 2000;
	private const MAX_ADD_PER_REQUEST = 200;

	private const AUDIO_MIME_PREFIX = 'audio/';

	public function __construct(
		private TrackMapper $trackMapper,
		private VoteMapper $voteMapper,
		private PermissionService $permissionService,
		private TimelineService $timelineService,
		private AudioProbe $audioProbe,
		private IRootFolder $rootFolder,
		private Clock $clock,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return Track[]
	 * @throws Exception
	 */
	public function listForChannel(Channel $channel): array {
		return $this->trackMapper->findAllForChannel($channel->getId());
	}

	/**
	 * @throws NotFoundException
	 * @throws Exception
	 */
	public function find(Channel $channel, int $trackId): Track {
		try {
			return $this->trackMapper->find($trackId, $channel->getId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException('Track not found');
		}
	}

	/**
	 * Append files from the adding user's own storage to the channel's playlist.
	 *
	 * The files stay in the adder's storage and are read back from it at stream time, so
	 * adding a track deliberately makes that one file audible to everyone who can listen
	 * to the channel. The UI says so at the point of adding.
	 *
	 * @param string $userId whose storage the file ids are resolved against
	 * @param int[] $fileIds
	 * @param array<int, int> $durationHints fileId => duration in ms, as measured by the
	 *                                       adding browser; used only if the probe fails
	 * @param string|null $addedBy who to credit, when that is not the same as whose
	 *                             storage the file lives in — a public-link upload lands
	 *                             in the owner's files but was not put there by them
	 * @return array{added: Track[], skipped: array<int, string>}
	 * @throws MusicRadioException on invalid input or a full channel
	 * @throws Exception
	 */
	public function add(
		Channel $channel,
		string $userId,
		array $fileIds,
		array $durationHints = [],
		?string $addedBy = null,
		?bool $approvedOverride = null,
	): array {
		$addedBy ??= $userId;

		$fileIds = array_values(array_unique(array_filter($fileIds, static fn (int $id): bool => $id > 0)));

		if ($fileIds === []) {
			throw new MusicRadioException('No files given');
		}
		if (count($fileIds) > self::MAX_ADD_PER_REQUEST) {
			throw new MusicRadioException('Too many files in one request');
		}

		$existingCount = $this->trackMapper->countForChannel($channel->getId());
		if ($existingCount + count($fileIds) > self::MAX_TRACKS_PER_CHANNEL) {
			throw new MusicRadioException('This channel has reached its track limit');
		}

		$alreadyPresent = array_flip($this->trackMapper->findExistingFileIds($channel->getId(), $fileIds));

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nextSort = ($this->trackMapper->maxSortOrder($channel->getId()) ?? 0) + self::SORT_STEP;

		$added = [];
		$skipped = [];

		// Probing reads the files, so it happens before the timeline is touched; the
		// mutation itself is then a short set of inserts.
		$prepared = [];
		foreach ($fileIds as $fileId) {
			if (isset($alreadyPresent[$fileId])) {
				$skipped[$fileId] = 'already in this channel';
				continue;
			}

			$file = $this->resolveAudioFile($userFolder, $fileId);
			if ($file === null) {
				$skipped[$fileId] = 'not an audio file you can read';
				continue;
			}

			$hint = isset($durationHints[$fileId]) ? (int)$durationHints[$fileId] : null;
			$probe = $this->audioProbe->probe($file, $hint);

			$prepared[] = ['file' => $file, 'probe' => $probe];
		}

		if ($prepared === []) {
			return ['added' => [], 'skipped' => $skipped];
		}

		$now = $this->clock->nowSeconds();

		// Whether what somebody adds plays straight away is decided by the share that let
		// them in, not by the channel — an owner may trust the people they named while
		// holding whatever arrives through a link handed round a room.
		//
		// A link upload passes its own answer in, because the share it came through is
		// known there and cannot be found from `$addedBy` (a visitor key is not an account).
		$approved = $approvedOverride ?? !$this->permissionService->shareRulesFor($channel, $addedBy)['requireApproval'];

		$this->timelineService->withPreservedPosition($channel, function () use ($prepared, $channel, $addedBy, $approved, $now, &$nextSort, &$added): void {
			foreach ($prepared as $item) {
				/** @var File $file */
				$file = $item['file'];
				$probe = $item['probe'];

				$track = new Track();
				$track->setChannelId($channel->getId());
				$track->setFileId($file->getId());
				$track->setAddedBy($addedBy);
				$track->setSortOrder($nextSort);
				$track->setShuffleOrder($nextSort);
				$track->setTitle($probe['title'] ?? $this->titleFromFilename($file->getName()));
				$track->setArtist($probe['artist']);
				$track->setAlbum($probe['album']);
				$track->setDurationMs($probe['durationMs']);
				$track->setDurationSource($probe['source']);
				$track->setMimetype($file->getMimeType());
				$track->setSize($file->getSize());
				$track->setUnavailable(false);
				$track->setApproved($approved);
				$track->setCreatedAt($now);

				$added[] = $this->trackMapper->insert($track);
				$nextSort += self::SORT_STEP;
			}
		});

		return ['added' => $added, 'skipped' => $skipped];
	}

	/**
	 * @throws Exception
	 */
	public function remove(Channel $channel, Track $track): void {
		$this->timelineService->withPreservedPosition($channel, function () use ($track): void {
			// Before the row itself: votes point at a track id, and leaving them behind
			// would let a future track inherit them.
			$this->voteMapper->clearForTrack($track->getId());
			$this->trackMapper->delete($track);
		});
	}

	/**
	 * Apply a new playlist order.
	 *
	 * The submitted list must be a permutation of the channel's current tracks. That is
	 * the concurrency guard: if a contributor appended a track while someone was
	 * dragging rows around, the submitted list no longer matches and the reorder is
	 * rejected rather than silently dropping the new track.
	 *
	 * @param int[] $trackIds
	 * @throws MusicRadioException when the submitted order is not a permutation
	 * @throws Exception
	 */
	public function reorder(Channel $channel, array $trackIds): void {
		$current = $this->trackMapper->findAllForChannel($channel->getId());
		$currentIds = array_map(static fn (Track $t): int => $t->getId(), $current);

		$submitted = array_values(array_map('intval', $trackIds));

		$expected = $currentIds;
		$got = $submitted;
		sort($expected);
		sort($got);

		if ($expected !== $got) {
			throw new MusicRadioException(
				'The playlist changed while you were reordering it. Reload and try again.',
			);
		}

		$this->timelineService->withPreservedPosition($channel, function () use ($submitted, $channel): void {
			$this->db->beginTransaction();
			try {
				$order = self::SORT_STEP;
				foreach ($submitted as $trackId) {
					$this->trackMapper->updateSortOrder($trackId, $channel->getId(), $order);
					$order += self::SORT_STEP;
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		});
	}

	/**
	 * Correct a track's duration by hand. Goes through the timeline guard because
	 * changing a duration moves everything after it on the programme.
	 *
	 * @throws MusicRadioException on an implausible duration
	 * @throws Exception
	 */
	public function setDuration(Channel $channel, Track $track, int $durationMs): Track {
		if ($durationMs < 1000) {
			throw new MusicRadioException('Duration must be at least one second');
		}

		$this->timelineService->withPreservedPosition($channel, function () use ($track, $durationMs): void {
			$track->setDurationMs($durationMs);
			$track->setDurationSource(Track::DURATION_SOURCE_MANUAL);
			$track->setUnavailable(false);
			$this->trackMapper->update($track);
		});

		return $track;
	}

	/**
	 * Skip a track, or stop skipping it.
	 *
	 * Goes through the timeline guard because a disabled track leaves the programme and
	 * an enabled one rejoins it — either way every prefix after it moves, which without
	 * this would jolt everyone listening.
	 *
	 * @throws Exception
	 */
	/**
	 * Let a held track play, or put it back on hold.
	 *
	 * Separate from setDisabled() on purpose — see Track::isPlayable(). Approving something
	 * the owner had also skipped leaves it skipped, because those are two different
	 * decisions and this one does not overrule the other.
	 *
	 * @throws Exception
	 */
	public function setApproved(Channel $channel, Track $track, bool $approved): Track {
		if ($track->getApproved() === $approved) {
			return $track;
		}

		// Changes what is on the timeline, so it goes through the same guard as every
		// other playlist edit.
		$this->timelineService->withPreservedPosition($channel, function () use ($track, $approved): void {
			$track->setApproved($approved);
			$this->trackMapper->update($track);
		});

		return $track;
	}

	public function setDisabled(Channel $channel, Track $track, bool $disabled): Track {
		if ($track->getDisabled() === $disabled) {
			return $track;
		}

		$this->timelineService->withPreservedPosition($channel, function () use ($track, $disabled): void {
			$track->setDisabled($disabled);
			$this->trackMapper->update($track);
		});

		return $track;
	}

	/**
	 * Flag a track whose file can no longer be read, so it drops out of the programme
	 * instead of broadcasting silence. Called from the streaming path.
	 *
	 * @throws Exception
	 */
	public function markUnavailable(Channel $channel, Track $track): void {
		if ($track->getUnavailable()) {
			return;
		}

		$this->logger->warning('Track file is no longer readable; removing it from the broadcast', [
			'app' => 'music_radio',
			'channelId' => $channel->getId(),
			'trackId' => $track->getId(),
			'fileId' => $track->getFileId(),
		]);

		$this->timelineService->withPreservedPosition($channel, function () use ($track): void {
			$track->setUnavailable(true);
			$this->trackMapper->update($track);
		});
	}

	/**
	 * Resolve a file id inside the given user's folder, rejecting anything that is not
	 * a readable audio file. The id is always checked against the user's own folder, so
	 * a caller cannot reach a file they have no access to by guessing ids.
	 */
	private function resolveAudioFile(\OCP\Files\Folder $userFolder, int $fileId): ?File {
		try {
			$nodes = $userFolder->getById($fileId);
		} catch (NotPermittedException) {
			return null;
		}

		foreach ($nodes as $node) {
			if (!$node instanceof File) {
				continue;
			}
			if (!str_starts_with($node->getMimeType(), self::AUDIO_MIME_PREFIX)) {
				continue;
			}
			if (!$node->isReadable()) {
				continue;
			}

			return $node;
		}

		return null;
	}

	private function titleFromFilename(string $name): string {
		$base = pathinfo($name, PATHINFO_FILENAME);

		return mb_substr($base === '' ? $name : $base, 0, 255);
	}
}
