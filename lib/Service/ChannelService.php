<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\ChannelMapper;
use OCA\MusicRadio\Db\ImportMapper;
use OCA\MusicRadio\Db\ShareMapper;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Db\TrackMapper;
use OCA\MusicRadio\Db\VoteMapper;
use OCA\MusicRadio\Exception\MusicRadioException;
use OCA\MusicRadio\Exception\NotFoundException;
use OCA\MusicRadio\Permission;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception;
use OCP\IDBConnection;

class ChannelService {

	private const MAX_TITLE_LENGTH = 255;
	private const MAX_DESCRIPTION_LENGTH = 4000;

	public function __construct(
		private ChannelMapper $channelMapper,
		private TrackMapper $trackMapper,
		private ShareMapper $shareMapper,
		private VoteMapper $voteMapper,
		private ImportMapper $importMapper,
		private PermissionService $permissionService,
		private BroadcastLibrary $library,
		private Clock $clock,
		private IDBConnection $db,
	) {
	}

	/**
	 * Channels this user owns, plus the ones shared with them, each annotated with the
	 * caller's effective permissions and its track count.
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws Exception
	 */
	public function listForUser(string $userId): array {
		$owned = $this->channelMapper->findAllOwnedBy($userId);
		$shared = $this->channelMapper->findAllSharedWith(
			$userId,
			$this->permissionService->groupIdsOf($userId),
			$this->permissionService->teamIdsOf($userId),
			$this->clock->nowSeconds(),
		);

		$out = [];
		foreach ([...$owned, ...$shared] as $channel) {
			$out[] = $this->present($channel, $userId);
		}

		return $out;
	}

	/**
	 * @throws NotFoundException when the channel is missing, or the caller may not hear it
	 * @throws Exception
	 */
	public function findReadable(int $id, ?string $userId): Channel {
		try {
			$channel = $this->channelMapper->find($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException('Channel not found');
		}

		if (!Permission::has($this->permissionService->resolve($channel, $userId), Permission::LISTEN)) {
			// Same response as "does not exist": a stranger must not be able to probe
			// which channel ids are real.
			throw new NotFoundException('Channel not found');
		}

		return $channel;
	}

	/**
	 * @throws MusicRadioException on invalid input
	 * @throws Exception
	 */
	public function create(string $userId, string $title, ?string $description = null): Channel {
		$now = $this->clock->nowSeconds();

		$channel = new Channel();
		$channel->setUserId($userId);
		$channel->setTitle($this->validateTitle($title));
		$channel->setDescription($this->validateDescription($description));
		$channel->setCoverFileId(null);
		// A new channel starts silent and at the top of the programme; nothing plays
		// until its owner presses play.
		$channel->setStartedAtMs($this->clock->nowMillis());
		$channel->setEpochOffsetMs(0);
		$channel->setPaused(true);
		$channel->setLoopEnabled(true);
		$channel->setShuffle(false);
		$channel->setShuffleSeed(0);
		$channel->setStateVersion(1);
		$channel->setPlaylistVersion(1);
		// Set explicitly rather than left to the column defaults, so the object returned
		// from here describes the row that was written rather than a half-populated one.
		$channel->setRequireApproval(false);
		$channel->setShowListenerCount(true);
		// Derived from the shares from here on — see syncVotingMode. A new channel has none,
		// so this is the right answer as well as the right starting point.
		$channel->setAllowVoting(false);
		$channel->setCreatedAt($now);
		$channel->setUpdatedAt($now);

		return $this->channelMapper->insert($channel);
	}

	/**
	 * Update the channel's descriptive fields.
	 *
	 * Playback settings are deliberately not editable here — they move the timeline and
	 * belong to the playback endpoints.
	 *
	 * Neither is voting or YouTube importing, any more: both used to be channel-wide
	 * switches that AND-gated a per-share one, which meant the same question was asked in
	 * two places and could be answered twice. They are now decided per share, in the share
	 * dialog, beside everything else that describes what one audience may do. What is left
	 * of the channel's own copy is `allow_voting`, which is no longer a preference but a
	 * derived fact — see syncVotingMode.
	 *
	 * @throws MusicRadioException on invalid input
	 * @throws Exception
	 */
	public function update(
		Channel $channel,
		?string $title,
		?string $description,
		?int $coverFileId,
		?bool $requireApproval = null,
		?bool $showListenerCount = null,
	): Channel {
		if ($title !== null) {
			$channel->setTitle($this->validateTitle($title));
		}
		if ($description !== null) {
			$channel->setDescription($this->validateDescription($description));
		}
		if ($coverFileId !== null) {
			$channel->setCoverFileId($coverFileId > 0 ? $coverFileId : null);
		}

		// The same null-means-leave-alone convention as the fields above, so a caller can
		// change one switch without restating the rest of the channel.
		//
		// Approval changes what a playlist *row* looks like to everybody the channel is
		// shared with: it decides whether a held track is marked as waiting. That is
		// answered by the tracks endpoint, which clients only re-fetch when
		// `playlistVersion` moves, so without the flag below a sharee kept the old rows
		// until something unrelated happened to reload them.
		//
		// The listener count is not tracked here on purpose: it is read from the broadcast
		// state, which already refreshes on the ordinary poll.
		$rowsChanged = false;

		if ($requireApproval !== null) {
			if ($requireApproval !== $channel->getRequireApproval()) {
				$rowsChanged = true;
			}
			$channel->setRequireApproval($requireApproval);
		}
		if ($showListenerCount !== null) {
			$channel->setShowListenerCount($showListenerCount);
		}

		if ($rowsChanged) {
			// Deliberately only the playlist counter, not the timeline. Nothing about what
			// is playing has moved, so the anchor is untouched and no listener is disturbed
			// — they simply fetch the rows again.
			$channel->setPlaylistVersion($channel->getPlaylistVersion() + 1);
		}

		$channel->setStateVersion($channel->getStateVersion() + 1);
		$channel->setUpdatedAt($this->clock->nowSeconds());

		return $this->channelMapper->update($channel);
	}

	/**
	 * Bring `allow_voting` back in line with the shares, after any of them changed.
	 *
	 * The flag is not a preference any more — nobody sets it, and the share dialog no
	 * longer offers it. It survives because it is not only a permission gate: it is what
	 * TrackMapper::findAllForChannelInPlayOrder reads to choose between `vote_order` and
	 * the author's `sort_order`, and what VoteService reads to decide whether recomputing
	 * that order is meaningful at all. Somewhere has to answer "is this channel a channel
	 * where votes move things", and a column on the channel is where every one of those
	 * readers already looks.
	 *
	 * "At least one share allows voting" is that answer. Turning the last one off restores
	 * the author's order exactly, which is the behaviour the old channel-wide switch had.
	 *
	 * Writes only on a change, and bumps `playlistVersion` when it does: the running order
	 * everybody sees has just been rewritten, and clients re-fetch the rows only when that
	 * counter moves. The same reasoning as `$rowsChanged` above.
	 *
	 * @throws Exception
	 */
	public function syncVotingMode(Channel $channel): void {
		$voting = $this->shareMapper->anyAllowsVoting($channel->getId());
		if ($voting === ($channel->getAllowVoting() === true)) {
			return;
		}

		$channel->setAllowVoting($voting);
		$channel->setPlaylistVersion($channel->getPlaylistVersion() + 1);
		$channel->setStateVersion($channel->getStateVersion() + 1);
		$channel->setUpdatedAt($this->clock->nowSeconds());

		$this->channelMapper->update($channel);
	}

	/**
	 * Delete a channel and everything hanging off it. There are no database foreign
	 * keys (Nextcloud convention), so the cascade is explicit — and transactional, so a
	 * failure part-way cannot leave orphaned tracks or shares pointing at a dead channel.
	 *
	 * @throws Exception
	 */
	public function delete(Channel $channel): void {
		// Read before the rows go, used after they have.
		//
		// Prepared copies are keyed by track id, so once the rows are deleted there is
		// nothing left to say which files belonged to this channel and they become
		// unreclaimable — about a megabyte per minute of audio, in a directory nobody
		// looks at. Collecting the ids first is the only chance to know.
		$trackIds = array_map(
			static fn (Track $track): int => (int)$track->getId(),
			$this->trackMapper->findAllForChannel($channel->getId()),
		);

		$this->db->beginTransaction();
		try {
			$this->trackMapper->deleteAllForChannel($channel->getId());
			// Nothing in this schema cascades, and the hourly sweep is too late to be the
			// only answer — a deleted channel must not leave rows behind that a new channel
			// reusing an id could inherit.
			$this->voteMapper->clearForChannel($channel->getId());
			$this->shareMapper->deleteAllForChannel($channel->getId());
			// An import still running will find its channel gone and give up; removing the
			// rows here keeps the queue from carrying work for a channel nobody can see.
			$this->importMapper->deleteAllForChannel($channel->getId());
			$this->channelMapper->delete($channel);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Only once the transaction has committed. A rollback would put every track back,
		// and files deleted on the way would not come with them — the channel would return
		// with its audio silently unprepared.
		foreach ($trackIds as $trackId) {
			$this->library->forget($trackId);
		}
	}

	/**
	 * The channel as the API returns it: the entity plus what this caller may do with it.
	 *
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	public function present(Channel $channel, ?string $userId): array {
		$permissions = $this->permissionService->resolve($channel, $userId);

		return array_merge($channel->jsonSerialize(), [
			'isOwner' => $userId !== null && $channel->getUserId() === $userId,
			'permissions' => $permissions,
			'can' => Permission::describe($permissions),
			'trackCount' => $this->trackMapper->countForChannel($channel->getId()),
		]);
	}

	/**
	 * @throws MusicRadioException
	 */
	private function validateTitle(string $title): string {
		$title = trim($title);
		if ($title === '') {
			throw new MusicRadioException('Title must not be empty');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new MusicRadioException('Title is too long');
		}

		return $title;
	}

	/**
	 * @throws MusicRadioException
	 */
	private function validateDescription(?string $description): ?string {
		if ($description === null) {
			return null;
		}
		$description = trim($description);
		if ($description === '') {
			return null;
		}
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new MusicRadioException('Description is too long');
		}

		return $description;
	}
}
