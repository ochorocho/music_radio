/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * An id for this tab, so the server can count listeners.
 *
 * Per tab rather than per browser, and deliberately so: two tabs playing the same channel
 * are two people as far as anyone in the room is concerned, and `sessionStorage` is scoped
 * to exactly that. It is also not the visitor cookie — that one identifies a browser
 * across visits so it can take back its own uploads; this one just has to be stable for as
 * long as the tab is open, and is forgotten the moment it closes.
 *
 * Nothing is authenticated by it. Somebody who wants to inflate the number of listeners on
 * their own channel can, and the number is not worth defending against that.
 */

const KEY = 'music_radio_client'

let cached = null

/**
 * @return {string} lowercase alphanumerics, which is what the server will accept
 */
function generate() {
	// Enough that two open tabs will not collide; not a secret, so not from a CSPRNG.
	return Math.random().toString(36).slice(2).padEnd(12, '0')
		+ Math.random().toString(36).slice(2).padEnd(12, '0')
}

/**
 * This tab's id, made up on first use.
 *
 * @return {string}
 */
export function clientId() {
	if (cached !== null) {
		return cached
	}

	try {
		cached = window.sessionStorage.getItem(KEY)
		if (!cached) {
			cached = generate()
			window.sessionStorage.setItem(KEY, cached)
		}
	} catch (error) {
		// Storage can be unavailable outright — private mode in some browsers, or a
		// third-party-cookie policy applied to a page in an iframe. The id then lives for
		// the lifetime of the module instead, which is every bit as good for counting;
		// it only stops surviving a reload.
		cached = cached || generate()
	}

	return cached
}
