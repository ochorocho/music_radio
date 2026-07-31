<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCA\MusicRadio\Db\Track;
use OCP\IRequest;
use OCP\Security\ISecureRandom;

/**
 * Telling one anonymous visitor from another.
 *
 * A share link is the same string for everyone who has it, so until now the app had no way
 * to distinguish the people using one: every anonymous upload was credited to the same
 * literal `?public-link`, which is why nobody could ever take their own upload back.
 *
 * This issues a per-browser key in a cookie and uses it in place of a user id. Be clear
 * about what that is worth: **it identifies a browser, not a person.** Clearing cookies,
 * opening a private window or using a second device produces a new one, and anybody who
 * wants to can forge one. It is enough to answer "is this the browser that uploaded this
 * track?" — a convenience — and deliberately not enough to protect anything that matters.
 * Nothing that costs the owner more than they already agreed to may rest on it.
 *
 * The key is stored in `added_by` alongside real user ids. That column is a user id
 * everywhere else, so the `?` prefix — illegal in a Nextcloud user id, the same trick
 * `Track::ADDED_BY_PUBLIC_LINK` already uses — is what keeps the two from ever being
 * confused.
 */
class VisitorIdentity {

	/**
	 * Scoped to the app's routes rather than the whole domain: it has no business being
	 * sent with requests for anything else.
	 */
	public const COOKIE = 'music_radio_visitor';

	private const PREFIX = '?link:';

	/**
	 * 32 characters from the alphabet below — about 165 bits, far more than enough to make
	 * guessing somebody else's key pointless.
	 */
	private const KEY_LENGTH = 32;

	/**
	 * The one place the alphabet is stated, so issuing and validating cannot drift apart.
	 * They did once: keys were generated from letters and digits but checked against hex,
	 * so every key this app issued was rejected as malformed and every upload fell back to
	 * being credited to nobody.
	 */
	private const KEY_ALPHABET = ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS;
	private const KEY_PATTERN = '/^[a-z0-9]{' . self::KEY_LENGTH . '}$/';

	/** Long enough that somebody can still remove yesterday's upload. */
	private const LIFETIME_DAYS = 365;

	public function __construct(
		private IRequest $request,
		private ISecureRandom $secureRandom,
	) {
	}

	/**
	 * The key this request carries, or null when it has none or the one it has is not a
	 * key this app issued.
	 *
	 * Validated rather than trusted: the value arrives from the browser, and it ends up in
	 * a database column and in comparisons. Anything that is not exactly what we issue is
	 * treated as absent.
	 */
	public function current(): ?string {
		$cookie = $this->request->getCookie(self::COOKIE);

		if (!is_string($cookie) || preg_match(self::KEY_PATTERN, $cookie) !== 1) {
			return null;
		}

		return $cookie;
	}

	public function issue(): string {
		return $this->secureRandom->generate(self::KEY_LENGTH, self::KEY_ALPHABET);
	}

	public function lifetime(): \DateTime {
		return new \DateTime('+' . self::LIFETIME_DAYS . ' days');
	}

	/**
	 * What to write in `added_by` for something this visitor contributed.
	 *
	 * Falls back to the old sentinel when there is no key — a visitor whose browser refuses
	 * cookies can still upload, they simply cannot take it back afterwards, which is
	 * exactly how it behaved before this existed.
	 */
	public function creditFor(?string $key): string {
		return $key === null ? Track::ADDED_BY_PUBLIC_LINK : self::PREFIX . $key;
	}

	/**
	 * Whether a track's `added_by` is this visitor.
	 *
	 * A null key never matches, so a visitor with no cookie is not handed everything that
	 * was uploaded before cookies existed.
	 */
	public function owns(string $addedBy, ?string $key): bool {
		return $key !== null && $addedBy === self::PREFIX . $key;
	}

	/** Whether this credit came through a link at all, however it was recorded. */
	public static function isLinkUpload(string $addedBy): bool {
		return $addedBy === Track::ADDED_BY_PUBLIC_LINK || str_starts_with($addedBy, self::PREFIX);
	}
}
