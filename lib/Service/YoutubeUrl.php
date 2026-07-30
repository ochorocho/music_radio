<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

/**
 * Reducing whatever someone pasted to an eleven-character video id.
 *
 * This is the security boundary of the whole import feature, and it is worth being explicit
 * about why. Importing means handing arguments to an external program. yt-dlp has options
 * that read files (`--config-location`, `--load-info-json`), options that write them, and
 * options that run commands (`--exec`); it will also happily follow a URL to any host,
 * including one on the server's own network, and expand a playlist into hundreds of
 * downloads. Every one of those is reachable from a crafted string.
 *
 * So no part of the input ever reaches the command line. What survives this class is at
 * most eleven characters from `[A-Za-z0-9_-]`, and the URL handed to yt-dlp is *rebuilt*
 * from those characters by canonical(). A string cannot carry an option through a filter
 * that only lets an id out.
 *
 * That is also why this class is final, static and dependency-free: there is nothing to
 * configure, nothing to inject, and nothing to mock, so every rejection is a plain unit
 * test.
 */
final class YoutubeUrl {

	/**
	 * Hosts a video id may be taken from.
	 *
	 * A fixed list, compared after lowercasing, against the *whole* host. Matching a
	 * suffix instead would accept `youtube.com.evil.example`; matching a substring would
	 * accept `notyoutube.com`.
	 */
	private const HOSTS = [
		'youtube.com',
		'www.youtube.com',
		'm.youtube.com',
		'music.youtube.com',
		'youtube-nocookie.com',
		'www.youtube-nocookie.com',
		'youtu.be',
		'www.youtu.be',
	];

	/** Every id YouTube issues is eleven of these characters. */
	private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

	/**
	 * Path prefixes that carry the id as the next segment: youtu.be/<id> uses the bare
	 * path, the rest name the player they belong to.
	 */
	private const ID_PATHS = ['shorts', 'embed', 'live', 'v'];

	/**
	 * A defensive ceiling on how much text is worth parsing at all. Real links are well
	 * under a hundred characters; anything past this is either a mistake or an attempt to
	 * find a limit somewhere else.
	 */
	private const MAX_INPUT_LENGTH = 2048;

	/**
	 * @return string|null the video id, or null when this is not a YouTube video link.
	 *                     Deliberately null rather than an exception: "that is not a
	 *                     YouTube link" is ordinary user input, not an error condition, and
	 *                     the caller has a better message for it than this class does.
	 */
	public static function videoId(string $input): ?string {
		$input = trim($input);

		if ($input === '' || strlen($input) > self::MAX_INPUT_LENGTH) {
			return null;
		}

		// Someone pasting just the id is doing something unambiguous, and accepting it
		// costs nothing — it is already the only thing this method can return.
		if (preg_match(self::ID_PATTERN, $input) === 1) {
			return $input;
		}

		// A scheme-relative URL parses with no scheme and would otherwise slip past the
		// scheme check below with its host intact.
		if (str_starts_with($input, '//')) {
			return null;
		}

		$parts = parse_url($input);
		if ($parts === false || !isset($parts['host'])) {
			return null;
		}

		// Only the two schemes a link can plausibly be written with. Nothing else is
		// entertained — `file://`, `javascript:` and friends have no business here.
		$scheme = strtolower($parts['scheme'] ?? '');
		if ($scheme !== 'https' && $scheme !== 'http') {
			return null;
		}

		// `https://youtube.com@evil.example/` has host `evil.example` and userinfo
		// `youtube.com`. parse_url gets that right, but a URL carrying credentials is
		// never a video link, and refusing it outright removes the class of confusion.
		if (isset($parts['user']) || isset($parts['pass'])) {
			return null;
		}

		if (!in_array(strtolower($parts['host']), self::HOSTS, true)) {
			return null;
		}

		return self::fromPathAndQuery($parts['path'] ?? '', $parts['query'] ?? '');
	}

	/**
	 * The one URL form the downloader is ever given.
	 *
	 * Built from the id alone. Note what is *not* here: no `list=`, no `t=`, no `si=`, no
	 * tracking parameters, and nothing the caller passed in. That is what makes
	 * `--no-playlist` a second line of defence rather than the only one.
	 */
	public static function canonical(string $videoId): string {
		return 'https://www.youtube.com/watch?v=' . $videoId;
	}

	private static function fromPathAndQuery(string $path, string $query): ?string {
		$segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

		// /watch?v=<id> — the canonical form, and the only one that carries the id in the
		// query string.
		if (($segments[0] ?? '') === 'watch') {
			parse_str($query, $params);
			$candidate = $params['v'] ?? null;

			return is_string($candidate) ? self::validate($candidate) : null;
		}

		// /shorts/<id>, /embed/<id>, /live/<id>, /v/<id>
		if (count($segments) >= 2 && in_array(strtolower($segments[0]), self::ID_PATHS, true)) {
			return self::validate($segments[1]);
		}

		// youtu.be/<id>, where the id is the whole path. Accepted for any allowlisted host
		// rather than only youtu.be: youtube.com has no other single-segment path that
		// looks like an id, so there is nothing to confuse it with.
		if (count($segments) === 1) {
			return self::validate($segments[0]);
		}

		return null;
	}

	/**
	 * The single gate every candidate passes through, however it was found. Nothing returns
	 * an id without coming through here.
	 */
	private static function validate(string $candidate): ?string {
		return preg_match(self::ID_PATTERN, $candidate) === 1 ? $candidate : null;
	}
}
