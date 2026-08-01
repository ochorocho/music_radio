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
		/** The element making sound has run out of data and is waiting for more. */
		stalled: false,
		error: null,
		/** The browser refused to start playback until something is pressed. */
		needsGesture: false,
		/**
		 * What the diagnostic panel reads.
		 *
		 * iOS cannot be exercised from the test suite, so when somebody reports that a
		 * phone stutters there is otherwise nothing to go on but the description. These
		 * are the four numbers that distinguish the possible causes: a starved buffer, an
		 * element repeatedly running dry, a seek storm, or a rate being changed so often
		 * that the browser never settles.
		 */
		bufferedAheadMs: 0,
		rateChanges: 0,
		stallCount: 0,
		hardSeeks: 0,
		boundaries: 0,
		playRefusals: 0,
		playRetries: 0,
		connectionLost: false,
		reconnects: 0,
		playbackRate: 1,
	},

	/**
	 * Bumped when the listener presses "Tap to play".
	 *
	 * A counter rather than a flag because the same request can be made twice, and because
	 * the player watches it synchronously: play() has to run in the same task as the click
	 * or the gesture it needs is already gone.
	 */
	resumeRequest: 0,

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
		this.sync = { clockReady: false, clockRttMs: 0, driftMs: 0, stalled: false, error: null, needsGesture: false }
	},

	/** Ask the player — which lives in a different component — to start again. */
	requestResume() {
		this.resumeRequest++
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
