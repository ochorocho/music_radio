<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Http;

/**
 * One track's contribution to a programme stream: which bytes, from which prepared copy.
 *
 * A plain value rather than a Track, because by the time these are streamed the request
 * has finished deciding anything — the response only opens paths and copies bytes, and
 * giving it entities would invite it to make decisions it has no business making.
 */
final class ProgrammeSpan {

	public function __construct(
		public readonly int $trackId,
		/** Absolute path to the prepared copy under the broadcast directory. */
		public readonly string $path,
		public readonly int $start,
		public readonly int $length,
		/** How much programme time these bytes represent, for budgeting. */
		public readonly int $durationMs,
	) {
	}
}
