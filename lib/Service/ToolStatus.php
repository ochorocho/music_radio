<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

/**
 * Whether this server can import from YouTube, and if not, what is missing.
 *
 * One object answers the question for everybody who needs it: the page that decides
 * whether to show the button, the service that refuses before enqueuing anything, the
 * admin setup check, and the occ command.
 *
 * The paths are deliberately kept out of jsonSerialize(). Whether ffmpeg lives in
 * /usr/bin or /opt/homebrew is of no use to someone adding music, and telling every logged
 * in user where a server keeps its binaries is free reconnaissance. Administrators get
 * them from the setup check and the occ command, which know who is asking.
 */
final class ToolStatus implements \JsonSerializable {

	public function __construct(
		public readonly bool $available,
		public readonly ?string $reason,
		public readonly ?string $ytDlpPath = null,
		public readonly ?string $ytDlpVersion = null,
		public readonly ?string $ffmpegDir = null,
		public readonly bool $outdated = false,
		public readonly ?JsRuntime $jsRuntime = null,
		/**
		 * Whether importing is *set up here*, whatever it can do this second.
		 *
		 * The distinction exists because two different questions were being answered by
		 * `available`, and one of them was getting the wrong answer. "Can an import be
		 * started right now" is the question for the button and for the refusal. "Does
		 * this server do YouTube imports at all" is the question for a channel owner
		 * deciding whether a share may use them — a stored permission that stays in force
		 * whether or not a machine happens to be answering.
		 *
		 * Gating that permission on `available` meant a remote worker being switched off
		 * hid a switch that was still doing something, so an owner could neither see nor
		 * change it, and could not set shares up before starting the worker for the first
		 * time. Defaults to false so that anything not saying otherwise is treated as not
		 * set up, which is the safe way round.
		 */
		public readonly bool $configured = false,
	) {
	}

	/** Not set up: there is nothing here to offer anybody. */
	public static function unavailable(string $reason): self {
		return new self(false, $reason);
	}

	/**
	 * Set up, but not working at this moment.
	 *
	 * Separate from {@see unavailable} so the difference survives into the UI: an import
	 * asked for now is still refused, and the permission is still worth showing.
	 */
	public static function offline(string $reason): self {
		return new self(false, $reason, configured: true);
	}

	/**
	 * @return array{available: bool, configured: bool, reason: string|null, outdated: bool}
	 */
	public function jsonSerialize(): array {
		return [
			'available' => $this->available,
			'configured' => $this->configured,
			'reason' => $this->reason,
			// Worth telling everyone: an import that fails because the downloader is stale
			// looks like a broken feature, and this lets the UI say otherwise before
			// somebody tries.
			'outdated' => $this->outdated,
		];
	}
}
