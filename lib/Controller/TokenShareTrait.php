<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\Db\Channel;
use OCA\MusicRadio\Db\Share;

/**
 * The token-to-share plumbing that core's PublicShareController expects, shared by the
 * page controller and the JSON API so a link means exactly the same thing to both.
 *
 * Implementing classes must provide `$shareService` and `$channelMapper`.
 */
trait TokenShareTrait {

	private ?Share $resolvedShare = null;
	private bool $shareResolved = false;
	private ?Channel $resolvedChannel = null;

	/**
	 * Look the token up once per request. Core calls isValidToken() and then
	 * getPasswordHash() and then the handler, so without memoising this the same query
	 * would run three times.
	 */
	protected function share(): ?Share {
		if (!$this->shareResolved) {
			$this->shareResolved = true;
			$this->resolvedShare = $this->shareService->findValidLink($this->getToken());
		}

		return $this->resolvedShare;
	}

	protected function channel(): ?Channel {
		if ($this->resolvedChannel !== null) {
			return $this->resolvedChannel;
		}

		$share = $this->share();
		if ($share === null) {
			return null;
		}

		try {
			return $this->resolvedChannel = $this->channelMapper->find($share->getChannelId());
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Whether this link is usable.
	 *
	 * Expiry is decided here, and a share that has run out is reported exactly like one
	 * that never existed. Distinguishing them would turn a 404 into an oracle for
	 * whether a given token was ever real.
	 */
	public function isValidToken(): bool {
		return $this->share() !== null && $this->channel() !== null;
	}

	/**
	 * ⚠ Must be a string, never null.
	 *
	 * The signature core declares is `?string`, but core then passes the result straight
	 * into `validateTokenSession(string, string)` and `storeTokenSession(string, string)`
	 * — and PublicShareController declares strict_types. Returning null there is a
	 * runtime TypeError that no static analysis will warn about.
	 */
	protected function getPasswordHash(): ?string {
		return $this->share()?->getPassword() ?? '';
	}

	protected function isPasswordProtected(): bool {
		return ($this->share()?->getPassword() ?? '') !== '';
	}
}
