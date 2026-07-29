/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Which channel this browser is listening to.
 *
 * Kept outside the component tree on purpose. Listening used to live inside the channel
 * view, so navigating to a different channel unmounted the player and the music simply
 * stopped — and coming back left you looking at a "Tune in" button with no explanation.
 * Holding it here means playback survives moving around the app, and ends only when the
 * page is closed or the listener tunes in somewhere else.
 */
import { reactive } from 'vue'

export const playerStore = reactive({
	/** The channel being listened to, or null. */
	channel: null,
	/**
	 * Share token, when listening from a public link. The player needs it to address the
	 * token endpoints — an anonymous listener has no session to authorise the others.
	 */
	token: null,
	/** Silenced locally, without touching the broadcast. */
	muted: false,

	/**
	 * How the player that is actually making sound is getting on. Published here because
	 * the component that draws the readout is not the one that owns the audio, and a
	 * status line reporting its own idle clock rather than the live one would claim
	 * "Syncing…" forever.
	 */
	sync: {
		clockReady: false,
		clockRttMs: 0,
		driftMs: 0,
		error: null,
	},

	/**
	 * @param {object} channel
	 * @param {string|null} token share token, when listening through a public link
	 */
	tuneIn(channel, token = null) {
		this.muted = false
		this.token = token
		this.channel = channel
	},

	tuneOut() {
		this.channel = null
		this.token = null
		this.muted = false
		this.sync = { clockReady: false, clockRttMs: 0, driftMs: 0, error: null }
	},

	toggleMute() {
		this.muted = !this.muted
	},

	/**
	 * @param {number} channelId
	 * @return {boolean}
	 */
	isListeningTo(channelId) {
		return this.channel?.id === channelId
	},
})
