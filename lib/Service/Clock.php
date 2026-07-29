<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The one place the app reads the wall clock.
 *
 * The broadcast timeline is a pure function of (anchor, playlist, now), so `now` has to
 * be injectable for any of it to be testable. Everything that needs a timestamp takes
 * this rather than calling time() directly.
 */
class Clock {

	public function __construct(
		private ITimeFactory $timeFactory,
	) {
	}

	/** Unix seconds — for created/updated/expiry bookkeeping. */
	public function nowSeconds(): int {
		return $this->timeFactory->getTime();
	}

	/**
	 * Unix milliseconds — the resolution the timeline works in. Track offsets are
	 * compared against the deadband in the tens of milliseconds, so seconds is too
	 * coarse for anything on the playback path.
	 */
	public function nowMillis(): int {
		return (int)$this->timeFactory->now()->format('Uv');
	}
}
