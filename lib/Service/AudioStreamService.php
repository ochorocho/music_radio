<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Track;
use OCA\MusicRadio\Exception\NotFoundException;
use OCA\MusicRadio\Http\AudioStreamResponse;
use OCA\MusicRadio\Http\ByteRange;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\ISession;
use Psr\Log\LoggerInterface;

/**
 * Turns a playlist row into a playable HTTP response.
 *
 * Shared by the logged-in and (later) public-link streaming endpoints so both resolve
 * files and enforce containment through exactly the same code.
 */
class AudioStreamService {

	public function __construct(
		private TrackService $trackService,
		private IRootFolder $rootFolder,
		private ISession $session,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws NotFoundException when the track's file can no longer be served
	 */
	public function stream(Channel $channel, Track $track, IRequest $request): AudioStreamResponse {
		$file = $this->resolve($channel, $track);

		$range = ByteRange::parse($request->getHeader('Range') ?: null, $file->getSize());

		return new AudioStreamResponse($file, $range, $this->session, $this->logger);
	}

	/**
	 * Resolve the file behind a track.
	 *
	 * The file id comes from our own row, never from the request, and is looked up
	 * inside the folder of the user who added the track — so this cannot be steered at
	 * arbitrary files by tampering with a URL. The track id in the URL is validated
	 * against the channel before we ever get here.
	 *
	 * @throws NotFoundException
	 */
	private function resolve(Channel $channel, Track $track): File {
		if ($track->getUnavailable()) {
			throw new NotFoundException('This track is no longer available');
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($track->getAddedBy());
			$nodes = $userFolder->getById($track->getFileId());
		} catch (\Throwable $e) {
			// Anything at all here — the storage is unreachable, the owner's account is
			// gone, the id no longer resolves — means the track cannot be played.
			$this->logger->warning('Could not open the storage holding a track', [
				'app' => 'music_radio',
				'trackId' => $track->getId(),
				'exception' => $e,
			]);
			$nodes = [];
		}

		foreach ($nodes as $node) {
			if ($node instanceof File && $node->isReadable()) {
				return $node;
			}
		}

		// The file was deleted, moved out of reach, or the owner's account is gone.
		// Take it out of the broadcast rather than letting the channel play silence —
		// this goes through the timeline guard so listeners do not jump.
		$this->trackService->markUnavailable($channel, $track);

		throw new NotFoundException('This track is no longer available');
	}
}
