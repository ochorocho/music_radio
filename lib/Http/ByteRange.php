<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Http;

/**
 * A parsed HTTP `Range` header (RFC 9110 §14).
 *
 * Nextcloud's own `OCP\AppFramework\Http\StreamResponse` has no range support at all —
 * it just readfile()s the whole thing — so the app has to do this itself. It is not
 * optional for audio: Safari opens every media element with a `Range: bytes=0-1` probe
 * and refuses to play a response that does not answer it correctly, and no browser can
 * seek without it.
 *
 * Deliberately a plain value object with no Nextcloud dependencies, so the parsing rules
 * can be tested exhaustively without mocking a request.
 */
final class ByteRange {

	/** No (usable) range was requested — send the whole file with 200. */
	public const FULL = 'full';
	/** Send `start`..`end` inclusive with 206. */
	public const PARTIAL = 'partial';
	/** The client asked for something outside the file — 416. */
	public const UNSATISFIABLE = 'unsatisfiable';

	private function __construct(
		public readonly string $kind,
		public readonly int $start,
		public readonly int $end,
	) {
	}

	/**
	 * @param string|null $header the raw `Range` header value, if any
	 * @param int $size size of the file in bytes
	 */
	public static function parse(?string $header, int $size): self {
		$full = new self(self::FULL, 0, max(0, $size - 1));

		if ($header === null || trim($header) === '') {
			return $full;
		}

		// RFC 9110: a range unit we do not understand must be ignored, not rejected.
		if (!preg_match('/^\s*bytes\s*=\s*(.+)$/i', $header, $matches)) {
			return $full;
		}

		// Multiple ranges would require a multipart/byteranges body. No audio client
		// needs it, so the first range is served and the rest ignored — which is a legal
		// response, since a server may always send less than was asked for.
		$spec = trim(explode(',', $matches[1])[0]);

		if (!preg_match('/^(\d*)-(\d*)$/', $spec, $parts)) {
			return new self(self::UNSATISFIABLE, 0, 0);
		}

		[, $rawStart, $rawEnd] = $parts;

		// "-500" means the *last* 500 bytes, not "up to byte 500".
		if ($rawStart === '') {
			if ($rawEnd === '') {
				return new self(self::UNSATISFIABLE, 0, 0);
			}
			$suffixLength = (int)$rawEnd;
			if ($suffixLength <= 0 || $size === 0) {
				return new self(self::UNSATISFIABLE, 0, 0);
			}

			// Asking for more trailing bytes than exist yields the whole file.
			$start = max(0, $size - $suffixLength);

			return new self(self::PARTIAL, $start, $size - 1);
		}

		$start = (int)$rawStart;
		if ($size === 0 || $start >= $size) {
			return new self(self::UNSATISFIABLE, 0, 0);
		}

		// An absent end means "to the end of the file"; a too-large one is clamped
		// rather than rejected, which is what every real server does.
		$end = $rawEnd === '' ? $size - 1 : min((int)$rawEnd, $size - 1);

		if ($end < $start) {
			return new self(self::UNSATISFIABLE, 0, 0);
		}

		return new self(self::PARTIAL, $start, $end);
	}

	public function isPartial(): bool {
		return $this->kind === self::PARTIAL;
	}

	public function isUnsatisfiable(): bool {
		return $this->kind === self::UNSATISFIABLE;
	}

	/** Number of bytes to send. */
	public function length(): int {
		if ($this->isUnsatisfiable()) {
			return 0;
		}

		return $this->end - $this->start + 1;
	}

	/** The `Content-Range` header value for a file of the given size. */
	public function contentRange(int $size): string {
		if ($this->isUnsatisfiable()) {
			return 'bytes */' . $size;
		}

		return 'bytes ' . $this->start . '-' . $this->end . '/' . $size;
	}
}
