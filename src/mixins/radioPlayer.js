/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Keeps this browser playing the same thing, at the same moment, as everyone else tuned
 * in to the channel.
 *
 * The shape of it:
 *
 *  - The server says which track is playing and *when that track started*, on its clock.
 *  - This client measures how far its own clock is from the server's.
 *  - A local tick then derives the position continuously, without asking again.
 *
 * Polling therefore only has to notice *changes* — someone skipping, pausing, editing
 * the playlist. Track progression never waits on the network, which is what makes a ten
 * second poll interval perfectly adequate for a radio station.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

import { ServerClock } from '../utils/serverClock.js'
import { stateUrl, streamUrl } from '../utils/api.js'
import {
	CLOCK_BURST,
	CLOCK_BURST_SPACING_MS,
	CLOCK_REFRESH_MS,
	DEADBAND_MS,
	MAX_NUDGE_MS,
	PRELOAD_LEAD_MS,
	RATE_CHANGE_SETTLE_MS,
	RATE_CLAMP,
	SYNC_SPEED_TIME,
	TICK_MS,
	WATCH_CLOCK_BURST,
} from '../utils/syncConstants.js'

const clamp = (value, min, max) => Math.min(max, Math.max(min, value))

/**
 * A valid, empty WAV.
 *
 * Needed because calling play() on a media element with no source at all never settles
 * in Chromium — the promise simply hangs, taking the whole tune-in with it. Giving the
 * element something real to play makes the unlock resolve immediately.
 */
const SILENCE = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAgD4AAAB9AAACABAAZGF0YQAAAAA='

export default {
	data() {
		return {
			/** Last state received from the server. */
			syncState: null,
			/** True once the listener has pressed "Tune in". */
			tunedIn: false,
			/** Measured drift between this element and the broadcast, for the UI. */
			driftMs: 0,
			clockReady: false,
			clockRttMs: 0,
			syncError: null,
			/**
			 * Silenced locally. Deliberately not part of the channel state: it is this
			 * listener turning their own sound down, not something done to the broadcast.
			 * The timeline keeps running, so unmuting drops back in where everyone else is
			 * rather than replaying what was missed.
			 */
			muted: false,
			/**
			 * What this client believes is playing. Reactive because the UI and the sync
			 * readout are rendered from it; it advances locally between polls.
			 */
			localTrack: null,
			/**
			 * The browser refused to start playback without something being pressed.
			 * Surfaced so the listener is asked, rather than left with silence.
			 */
			needsGesture: false,
		}
	},

	computed: {
		current() {
			return this.syncState?.current ?? null
		},

		status() {
			return this.syncState?.status ?? null
		},

		isBroadcasting() {
			return this.status === 'playing'
		},

		/**
		 * Share token when this player is running on a public page, otherwise null.
		 * Everything else in here is identical either way.
		 *
		 * @return {string|null}
		 */
		shareToken() {
			return this.publicToken || null
		},
	},

	created() {
		this.clock = new ServerClock()
		this.watchTimer = null

		// Deliberately not reactive: these change on every 250 ms tick and nothing
		// renders from them directly, so keeping them off the reactivity graph avoids a
		// stream of pointless re-renders.
		this.pollTimer = null
		this.tickTimer = null
		this.clockTimer = null
		this.audioA = null
		this.audioB = null
		this.activeAudio = null
		this.idleAudio = null
		this.preloadedTrackId = null
		this.lastRateChangeAt = 0
		this.pendingSeek = false
		// Server time at which this client last crossed a track boundary on its own.
		// Guards against a poll that was computed before the crossing dragging it back.
		this.advancedAtServerMs = 0
	},

	beforeUnmount() {
		this.teardown()
	},

	methods: {
		// ------------------------------------------------------------- lifecycle

		/**
		 * Start following the broadcast. Must be called from a real user gesture: iOS
		 * (and Chrome) refuse to start audio otherwise, which is why there is a "Tune in"
		 * button rather than autoplay.
		 */
		async tuneIn() {
			if (this.tunedIn) {
				return
			}

			this.ensureAudioElements()

			// Unlock both elements inside the gesture. The second one will not be touched
			// until the first track ends, by which point the gesture is long gone and
			// Safari would refuse to play it — so it is primed now.
			await Promise.all([this.unlock(this.audioA), this.unlock(this.audioB)])

			this.tunedIn = true

			await this.clock.burst(CLOCK_BURST, CLOCK_BURST_SPACING_MS)
			this.clockReady = this.clock.ready
			this.clockRttMs = Math.round(this.clock.rtt)

			await this.refreshState()
			this.startTimers()
		},

		toggleMute() {
			this.muted = !this.muted
			this.applyMute()
		},

		/** Applied to both elements, so a track change cannot un-silence things. */
		applyMute() {
			for (const audio of [this.audioA, this.audioB]) {
				if (audio) {
					audio.muted = this.muted
				}
			}
		},

		tuneOut() {
			this.tunedIn = false
			this.stopTimers()
			for (const audio of [this.audioA, this.audioB]) {
				if (audio) {
					audio.pause()
					audio.removeAttribute('src')
					audio.load()
				}
			}
			this.localTrack = null
			this.preloadedTrackId = null
			this.advancedAtServerMs = 0
			this.driftMs = 0
			this.muted = false
		},

		teardown() {
			this.stopWatching()
			this.stopTimers()
			this.tunedIn = false
			for (const audio of [this.audioA, this.audioB]) {
				audio?.pause()
			}
			this.removeAudioElements()
		},

		ensureAudioElements() {
			if (this.audioA) {
				return
			}

			// Two elements, ping-ponged. Creating one at a track boundary costs a few
			// hundred milliseconds of silence while it loads — with a second element
			// already buffered, the switch is immediate.
			this.audioA = new Audio()
			this.audioB = new Audio()
			for (const audio of [this.audioA, this.audioB]) {
				audio.preload = 'auto'
				audio.hidden = true
				audio.dataset.musicRadioPlayer = 'true'
				// Attached rather than left detached: a detached element still plays, but
				// nothing can see it — not the browser's own media controls, not an
				// accessibility tool, and not a test asserting that only one thing is
				// making sound.
				document.body.appendChild(audio)
			}
			this.activeAudio = this.audioA
			this.idleAudio = this.audioB
		},

		removeAudioElements() {
			for (const audio of [this.audioA, this.audioB]) {
				audio?.remove()
			}
			this.audioA = this.audioB = this.activeAudio = this.idleAudio = null
		},

		/**
		 * Satisfy the autoplay policy for an element while a user gesture is in scope.
		 *
		 * @param {HTMLAudioElement} audio
		 */
		async unlock(audio) {
			try {
				audio.muted = true
				audio.src = SILENCE
				// Bounded: a media element that never settles must not be able to hang
				// tuning in. The unlock happens on the play() call itself, not on its
				// resolution, so giving up early costs nothing.
				await Promise.race([
					audio.play().catch(() => {}),
					new Promise((resolve) => setTimeout(resolve, 1000)),
				])
				audio.pause()
			} catch (error) {
				// Best effort — an element that refuses to unlock will simply stay silent
				// until the listener interacts with it again.
			} finally {
				audio.muted = false
				audio.removeAttribute('src')
				delete audio.dataset.trackId
				audio.load()
			}
		},

		/**
		 * Follow the channel without listening to it.
		 *
		 * Cheaper than tuning in — no audio, no drift correction — but the clock is still
		 * probed once, because the displayed position is derived from a server timestamp
		 * and would otherwise be off by however wrong this browser's clock is.
		 */
		async startWatching() {
			if (!this.channel) {
				return
			}
			// A short burst, not a single probe: the clock does not consider itself ready
			// until two samples have landed, so one would leave the readout stuck on
			// "Syncing…" for as long as the page stayed open.
			await this.clock.burst(WATCH_CLOCK_BURST, CLOCK_BURST_SPACING_MS)
			this.clockReady = this.clock.ready
			this.clockRttMs = Math.round(this.clock.rtt)

			await this.refreshState()
			this.scheduleNextPoll()

			clearInterval(this.watchTimer)
			this.watchTimer = setInterval(() => this.tick(), TICK_MS)
		},

		stopWatching() {
			clearInterval(this.watchTimer)
			clearTimeout(this.pollTimer)
			this.watchTimer = this.pollTimer = null
		},

		startTimers() {
			// Watching and listening both poll; only one of them may own the timer.
			clearInterval(this.watchTimer)
			this.watchTimer = null
			this.stopTimers()
			this.tickTimer = setInterval(() => this.tick(), TICK_MS)
			this.clockTimer = setInterval(async () => {
				await this.clock.probe()
				this.clockReady = this.clock.ready
				this.clockRttMs = Math.round(this.clock.rtt)
			}, CLOCK_REFRESH_MS)
			this.scheduleNextPoll()

			// A backgrounded tab has its timers throttled to roughly once a second, so
			// whatever it believes on return is stale. Re-sync from scratch instead.
			this.visibilityHandler = () => {
				if (document.visibilityState === 'visible' && this.tunedIn) {
					this.clock.burst(CLOCK_BURST, CLOCK_BURST_SPACING_MS)
					this.refreshState({ force: true })
				}
			}
			document.addEventListener('visibilitychange', this.visibilityHandler)
		},

		stopTimers() {
			clearInterval(this.tickTimer)
			clearInterval(this.clockTimer)
			clearTimeout(this.pollTimer)
			this.tickTimer = this.clockTimer = this.pollTimer = null
			if (this.visibilityHandler) {
				document.removeEventListener('visibilitychange', this.visibilityHandler)
				this.visibilityHandler = null
			}
		},

		scheduleNextPoll() {
			clearTimeout(this.pollTimer)
			const base = this.syncState?.pollAfterMs ?? 10_000
			// Jitter so a room full of listeners does not poll in lockstep.
			const delay = base * (0.85 + Math.random() * 0.3)
			this.pollTimer = setTimeout(async () => {
				await this.refreshState()
				this.scheduleNextPoll()
			}, delay)
		},

		// ----------------------------------------------------------------- state

		/**
		 * @param {object} [options]
		 * @param {boolean} [options.force] adopt the server position even if the track
		 *   has not changed — used after a tab has been backgrounded
		 */
		async refreshState(options = {}) {
			if (!this.channel) {
				return
			}
			try {
				const { data } = await axios.get(stateUrl(this.channel.id, this.shareToken))
				this.applyState(data, options)
				this.syncError = null
			} catch (error) {
				this.syncError = t('music_radio', 'Lost contact with the channel')
			}
		},

		applyState(state, options = {}) {
			const previous = this.syncState
			this.syncState = state

			if (previous && state.playlistVersion !== previous.playlistVersion) {
				this.$emit('playlist-changed')
			}

			if (!this.tunedIn) {
				// Not listening, but still watching: keep what is on air up to date so the
				// channel can show it. Nothing here touches audio.
				this.localTrack = state.current ? { ...state.current } : null
				return
			}

			// A response that was computed before this client crossed a track boundary
			// describes the *previous* track, and adopting it would throw playback
			// backwards by most of a track — briefly putting this listener badly out of
			// step with everyone else. The state is still recorded above (its version
			// counters and settings are current); only its playback position is ignored.
			//
			// `force` overrides this, because a tab returning from the background has to
			// take whatever the server says.
			if (!options.force && state.serverTimeMs < this.advancedAtServerMs) {
				return
			}

			const trackChanged = this.localTrack?.trackId !== state.current?.trackId

			if (!state.current || state.status === 'paused' || state.status === 'ended' || state.status === 'empty') {
				this.localTrack = state.current
					? { ...state.current }
					: null
				if (state.status !== 'playing') {
					this.activeAudio?.pause()
				}
				if (state.current) {
					this.loadTrack(this.activeAudio, state.current)
				}
				return
			}

			this.localTrack = { ...state.current }

			if (trackChanged || options.force) {
				this.loadTrack(this.activeAudio, state.current)
				this.hardSeek(this.targetOffsetMs())
				this.resume()
				return
			}

			// Whether this element should be making sound is a property of `status`, not a
			// consequence of the track changing — and getting that backwards is what left a
			// resumed channel silent.
			//
			// The branch above only fires on a new track or a forced apply. A resume is
			// neither: the track is the same, and the state arrives on an ordinary poll,
			// because the instance that has the audio is not the one that pressed the
			// button (see the note on this mixin). So nothing started the element again,
			// and it stayed on the pause that the paused branch above had given it —
			// indefinitely, while the rest of the UI reported a healthy broadcast.
			if (this.activeAudio?.paused) {
				// It froze where it was while the broadcast moved on, so put it back in
				// step before starting.
				this.hardSeek(this.targetOffsetMs())
				this.resume()
			}
		},

		/**
		 * Start the active element, and notice when the browser will not let us.
		 *
		 * play() rejects rather than throwing, and a rejection here is indistinguishable
		 * from silence unless somebody looks — which is why both call sites used to discard
		 * it. Autoplay rules mean a refusal is a real possibility on a resume, since by
		 * then the tune-in gesture is long gone.
		 */
		resume() {
			const audio = this.activeAudio
			if (!audio) {
				return
			}

			audio.play().then(() => {
				this.needsGesture = false
			}).catch((error) => {
				if (error?.name === 'NotAllowedError') {
					// Recoverable, but only by the listener: something has to be pressed.
					this.needsGesture = true
					return
				}

				// AbortError is routine — a load() or a new src while play() was pending
				// aborts it, and the next state application starts it again.
				if (error?.name !== 'AbortError') {
					this.syncError = t('music_radio', 'This browser would not start playback')
				}
			})
		},

		/**
		 * @param {HTMLAudioElement} audio
		 * @param {object} track
		 */
		loadTrack(audio, track) {
			if (!this.channel) {
				return
			}
			const url = streamUrl(this.channel.id, track.trackId, this.shareToken)
			if (audio.dataset.trackId === String(track.trackId)) {
				return
			}
			audio.dataset.trackId = String(track.trackId)
			audio.src = url
			audio.muted = this.muted
			audio.load()
		},

		// ------------------------------------------------------------- the clock

		/** How far into the current track the broadcast is, right now. */
		targetOffsetMs() {
			if (!this.localTrack) {
				return 0
			}
			if (this.syncState?.status === 'paused') {
				return this.localTrack.offsetMs
			}

			return this.clock.now() - this.localTrack.startedAtMs
		},

		// ----------------------------------------------------------------- tick

		tick() {
			if (!this.localTrack || this.syncState?.status !== 'playing') {
				return
			}

			// Someone watching without listening has no audio to keep in step; they only
			// need the position to keep moving, and a boundary to roll over.
			if (!this.tunedIn) {
				if (this.targetOffsetMs() >= this.localTrack.durationMs) {
					this.refreshState({ force: true })
				}
				return
			}

			if (!this.activeAudio) {
				return
			}

			const target = this.targetOffsetMs()

			// Past the end of this track: move on immediately rather than waiting for the
			// next poll, then confirm against the server.
			if (target >= this.localTrack.durationMs) {
				this.advanceLocally()
				return
			}

			this.preloadNextIfDue(target)
			this.correctDrift(target)
		},

		/**
		 * Cross a track boundary without waiting for the network.
		 *
		 * The server is the authority, but a poll may be seconds away and silence in the
		 * meantime would be obvious. The next track and its length are already known, so
		 * the switch happens now and the state is refreshed straight after to pick up the
		 * track after that.
		 */
		advanceLocally() {
			const next = this.syncState?.next
			if (!next) {
				this.refreshState({ force: true })
				return
			}

			const startedAtMs = this.localTrack.startedAtMs + this.localTrack.durationMs

			// Swap to the element that has been buffering this track.
			if (this.idleAudio?.dataset.trackId === String(next.trackId)) {
				const previous = this.activeAudio
				this.activeAudio = this.idleAudio
				this.idleAudio = previous
				this.idleAudio.pause()
			}

			this.localTrack = {
				trackId: next.trackId,
				durationMs: next.durationMs,
				title: next.title,
				artist: next.artist,
				startedAtMs,
				offsetMs: 0,
			}
			this.preloadedTrackId = null
			this.advancedAtServerMs = startedAtMs

			this.loadTrack(this.activeAudio, this.localTrack)
			this.hardSeek(this.targetOffsetMs())
			this.resume()

			// Re-derive from the server rather than trusting the local step.
			//
			// The optimistic advance above only covers the round trip; it steps exactly
			// one track, which is not enough if this page's timers were throttled — a
			// backgrounded tab gets its intervals cut to roughly one a second, so it can
			// overrun a boundary by seconds and end up several tracks behind. Forcing the
			// refresh makes the server the authority at every boundary, which is the one
			// place accumulated error would otherwise become audible.
			this.refreshState({ force: true })
		},

		/**
		 * @param {number} target current position within the track, in ms
		 */
		preloadNextIfDue(target) {
			const next = this.syncState?.next
			if (!next || !this.idleAudio) {
				return
			}
			if (this.preloadedTrackId === next.trackId) {
				return
			}
			if (this.localTrack.durationMs - target > PRELOAD_LEAD_MS) {
				return
			}

			this.loadTrack(this.idleAudio, next)
			this.preloadedTrackId = next.trackId
		},

		/**
		 * Pull this element back towards the broadcast.
		 *
		 * Small errors are absorbed by running fractionally fast or slow, which is
		 * inaudible. Large ones are seeked, which is not — so the element is muted across
		 * the seek to avoid the click.
		 *
		 * @param {number} target
		 */
		correctDrift(target) {
			const audio = this.activeAudio
			// A paused element is deliberately not corrected. While one is stopped the
			// broadcast keeps moving, so the drift grows without limit and every correction
			// past MAX_NUDGE_MS hard-seeks — and each hard seek mutes for up to a second.
			// A player that had stopped was therefore also being re-muted roughly once a
			// second, which would have kept it silent even once it was started again.
			if (!audio || audio.paused || audio.readyState < 2 || this.pendingSeek) {
				return
			}

			// Safari stalls `currentTime` briefly after a rate change, so anything
			// measured in that window is not a real drift reading.
			if (performance.now() - this.lastRateChangeAt < RATE_CHANGE_SETTLE_MS) {
				return
			}

			const actual = audio.currentTime * 1000
			const diff = target - actual
			this.driftMs = Math.round(diff)

			if (Math.abs(diff) <= DEADBAND_MS) {
				this.setRate(1)
				return
			}

			if (Math.abs(diff) < MAX_NUDGE_MS) {
				this.setRate(clamp(1 + diff / SYNC_SPEED_TIME, 1 - RATE_CLAMP, 1 + RATE_CLAMP))
				return
			}

			this.hardSeek(target)
		},

		setRate(rate) {
			const audio = this.activeAudio
			if (!audio || Math.abs(audio.playbackRate - rate) < 0.001) {
				return
			}
			audio.playbackRate = rate
			this.lastRateChangeAt = performance.now()
		},

		hardSeek(targetMs) {
			const audio = this.activeAudio
			if (!audio) {
				return
			}

			audio.muted = true
			this.pendingSeek = true

			const onSeeked = () => {
				// Back to whatever the listener chose, never unconditionally unmuted.
				audio.muted = this.muted
				this.pendingSeek = false
				audio.removeEventListener('seeked', onSeeked)
			}
			audio.addEventListener('seeked', onSeeked)
			// Not every element fires `seeked` reliably (notably when the range is not
			// buffered yet), so unmute regardless after a moment.
			setTimeout(onSeeked, 1000)

			try {
				audio.currentTime = Math.max(0, targetMs) / 1000
			} catch (error) {
				this.pendingSeek = false
				audio.muted = this.muted
			}
			this.setRate(1)
		},

		// --------------------------------------------------------------- control

		/**
		 * @param {string} action play|pause|next|previous|seek|jumpTo
		 * @param {object} [payload]
		 */
		async sendControl(action, payload = {}) {
			if (this.shareToken) {
				return
			}
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/music_radio/api/v1/channels/${this.channel.id}/control`),
					{ action, ...payload, expectedStateVersion: this.syncState?.stateVersion },
				)
				this.applyState(data, { force: true })
			} catch (error) {
				if (error?.response?.status === 409) {
					// Someone else changed the channel first; take their version.
					this.applyState(error.response.data.state, { force: true })
					showError(t('music_radio', 'Someone else changed the channel just now'))
					return
				}
				showError(error?.response?.data?.error ?? t('music_radio', 'That did not work'))
			}
		},

		async sendSettings(payload) {
			if (this.shareToken) {
				return
			}
			try {
				const { data } = await axios.put(
					generateUrl(`/apps/music_radio/api/v1/channels/${this.channel.id}/playback-settings`),
					payload,
				)
				this.applyState(data, { force: true })
			} catch (error) {
				showError(error?.response?.data?.error ?? t('music_radio', 'That did not work'))
			}
		},
	},
}
