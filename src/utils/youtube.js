/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * A client-side echo of the server's link parsing.
 *
 * Deliberately not a security boundary — the server re-derives the video id from scratch
 * and only ever builds its command line from that, so nothing here is trusted. This exists
 * for one reason: to answer an obvious typo in the field the person is still looking at,
 * instead of sending a request and replying with a toast.
 *
 * Kept loose on purpose. Anything this accepts and the server rejects produces a clear
 * message from the server, which is a fine outcome; anything this rejects wrongly would
 * block a link that would actually have worked, which is not. When in doubt it says yes.
 *
 * @see lib/Service/YoutubeUrl.php for the version that decides
 */

const HOSTS = [
	'youtube.com',
	'www.youtube.com',
	'm.youtube.com',
	'music.youtube.com',
	'youtube-nocookie.com',
	'www.youtube-nocookie.com',
	'youtu.be',
	'www.youtu.be',
]

const ID = /^[A-Za-z0-9_-]{11}$/

/** Paths that carry the id in the next segment. */
const ID_PATHS = ['shorts', 'embed', 'live', 'v']

/**
 * @param {string} input whatever is in the field
 * @return {boolean} whether this is worth sending to the server
 */
export function isYoutubeLink(input) {
	return videoId(input) !== null
}

/**
 * @param {string} input
 * @return {string|null} the video id, or null when this is clearly not a video link
 */
export function videoId(input) {
	const trimmed = (input ?? '').trim()
	if (trimmed === '') {
		return null
	}
	if (ID.test(trimmed)) {
		return trimmed
	}

	let parsed
	try {
		parsed = new URL(trimmed)
	} catch {
		return null
	}

	if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
		return null
	}
	// `https://youtube.com@evil.example/` parses with host evil.example; a link carrying
	// credentials is never a video link.
	if (parsed.username !== '' || parsed.password !== '') {
		return null
	}
	if (!HOSTS.includes(parsed.hostname.toLowerCase())) {
		return null
	}

	const segments = parsed.pathname.split('/').filter((segment) => segment !== '')

	if (segments[0] === 'watch') {
		const candidate = parsed.searchParams.get('v')
		return candidate !== null && ID.test(candidate) ? candidate : null
	}
	if (segments.length >= 2 && ID_PATHS.includes(segments[0].toLowerCase())) {
		return ID.test(segments[1]) ? segments[1] : null
	}
	if (segments.length === 1) {
		return ID.test(segments[0]) ? segments[0] : null
	}

	return null
}
