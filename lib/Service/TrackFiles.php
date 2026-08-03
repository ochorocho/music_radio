<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Finding the file a track is made of.
 *
 * There are two ways audio gets onto a channel and they file it in different places. A file
 * picked out of somebody's own Files stays where it is and is read back from their storage.
 * Anything the server had to *create* — an anonymous upload through a link, a download from
 * a paste of a YouTube URL — is written into the **channel owner's** music folder against
 * the owner's quota, whoever asked for it, so that the track survives that person's share
 * being revoked or their account being deleted.
 *
 * `added_by` records only the second half of that: who to credit. It is not an address, and
 * reading it as one is what this class was written to stop. Every resolution site used to do
 * `getUserFolder($track->getAddedBy())`, which is right for the picker and wrong for
 * everything else — a link upload is credited to a visitor key that is not an account at
 * all, and a contributor's import is credited to the contributor while the bytes sit in the
 * owner's folder. Both resolved to nothing, and "nothing" is how the streaming path is told
 * a file has been deleted: the track was flagged `unavailable` and vanished from the
 * programme, minutes after being added, with the file sitting there perfectly readable.
 *
 * So a track is looked for in every storage it could legitimately be in, most likely first.
 * There is no guessing involved in the *result* — a file id is unique across the instance,
 * so whichever storage it turns up in it is the same file the row names; the candidates only
 * decide through whose mounts we go looking for it.
 */
class TrackFiles {

	public function __construct(
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The readable file behind a track, or null when it is in none of the places it could be.
	 *
	 * Callers treat null as "this track can no longer be broadcast" — see
	 * {@see TrackService::markUnavailable()} — so everything that merely means "not here" is
	 * swallowed below rather than allowed to travel as an error.
	 */
	public function resolve(Channel $channel, Track $track): ?File {
		$fileId = $track->getFileId();
		if ($fileId <= 0) {
			return null;
		}

		foreach ($this->storagesFor($channel, $track) as $userId) {
			try {
				$nodes = $this->rootFolder->getUserFolder($userId)->getById($fileId);
			} catch (\Throwable $e) {
				// Storage unreachable, or an account that has gone. Either way this only says
				// the file is not to be found *here*; whether the track is playable at all is
				// decided once every candidate has been tried.
				$this->logger->debug('Could not look for a track\'s file in a storage', [
					'app' => Application::APP_ID,
					'trackId' => $track->getId(),
					'fileId' => $fileId,
					'storageOwner' => $userId,
					'exception' => $e,
				]);

				continue;
			}

			foreach ($nodes as $node) {
				if ($node instanceof File && $node->isReadable()) {
					return $node;
				}
			}
		}

		return null;
	}

	/**
	 * The accounts whose storage may hold this track's file, in the order to try them.
	 *
	 * Whoever added it comes first, because that is where a picked file is and looking there
	 * costs one lookup in the common case. The channel owner is always tried, because that is
	 * where everything the server wrote itself went — and it is the only candidate when the
	 * credit is a link visitor's key, which is not an account and must never be handed to
	 * getUserFolder().
	 *
	 * @return list<string>
	 */
	private function storagesFor(Channel $channel, Track $track): array {
		$owner = (string)$channel->getUserId();
		$addedBy = (string)$track->getAddedBy();

		if ($addedBy === '' || $addedBy === $owner || VisitorIdentity::isLinkUpload($addedBy)) {
			return [$owner];
		}

		return [$addedBy, $owner];
	}
}
