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
	) {
	}

	public static function unavailable(string $reason): self {
		return new self(false, $reason);
	}

	/**
	 * @return array{available: bool, reason: string|null, outdated: bool}
	 */
	public function jsonSerialize(): array {
		return [
			'available' => $this->available,
			'reason' => $this->reason,
			// Worth telling everyone: an import that fails because the downloader is stale
			// looks like a broken feature, and this lets the UI say otherwise before
			// somebody tries.
			'outdated' => $this->outdated,
		];
	}
}
