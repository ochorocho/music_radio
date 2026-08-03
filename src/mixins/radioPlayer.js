/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Keeps this browser playing the same thing, at the same moment, as everyone else tuned
 * in to the channel.
 *
 * The shape of it:
 *
 *  - The server says where in the programme the channel is, on its clock.
 *  - This client measures how far its own clock is from the server's.
 *  - A local tick then derives the position continuously, without asking again.
 *
 * Polling therefore only has to notice *changes* — someone skipping, pausing, editing
 * the playlist. Track progression never waits on the network, which is what makes a ten
 * second poll interval perfectly adequate for a radio station.
 *
 * What the element is given is a *segment of the programme*, not a track: half an hour of
 * audio that runs across every track boundary inside it. That is the whole reason this
 * file looks the way it does. The previous design loaded one track and swapped to the next
 * when the first ended, which is fine until an iPhone locks its screen — iOS suspends page
 * timers, so at the moment the swap is due there is no JavaScript running to do it, and the
 * music simply stops. Audio the element is *already holding* keeps playing regardless, so
 * handing it the programme rather than a track is what makes a pocketed phone play on.
 *
 * The cost is stated rather than hidden: playback lasts as long as the buffer. When the
 * segment runs out on a locked phone there is still nothing awake to fetch another, so it
 * stops there — about half an hour in. Fixing *that* needs a stream paced at playback
 * speed, which holds a PHP worker per listener against a pool of eight, and is a worse
 * trade than the one taken here.
 */
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'

import { ServerClock } from '../utils/serverClock.js'
import { clientId } from '../utils/clientId.js'
import { playerStore } from '../utils/playerStore.js'
import { controlUrl, playbackSettingsUrl, programmeUrl, stateUrl } from '../utils/api.js'
import {
	CLOCK_BURST,
	CLOCK_BURST_SPACING_MS,
	CLOCK_REFRESH_MS,
	RECONNECT_MS,
	RESEEK_MS,
	SEGMENT_RELOAD_MS,
	STALL_RECOVERY_MS,
	TICK_MS,
	WATCH_CLOCK_BURST,
} from '../utils/syncConstants.js'

/**
 * Feature-detection for iOS is gone along with the second element.
 *
 * It existed because Safari ignores `preload` on `<audio>` and holds an exclusive audio
 * session, so priming a second element did no buffering and could interrupt the one making
 * sound. With one element playing a continuous programme there is nothing to prime, and the
 * platform no longer has to be asked about.
 */

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
			/**
			 * True while the element making sound has run out of data.
			 *
			 * Reactive, unlike the `stalledSince` timestamp that drives the logic, purely so
			 * that a stall is visible from the outside — it goes into the sync-debug payload,
			 * which is the only way to tell "the network stalled" from "the player is broken"
			 * when the complaint arrives second-hand from a phone.
			 */
			stalled: false,
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
			/**
			 * Counters for the diagnostic readout.
			 *
			 * iOS cannot be driven from the test suite — Nextcloud's unsupported-browser
			 * gate rejects a spoofed user agent both server-side and in the page — so the
			 * only way to tell a stuttering phone from a healthy one is to have the phone
			 * say. These are what it says. Cheap enough to keep always rather than behind
			 * a flag: three integers and an arithmetic mean.
			 */
			rateChanges: 0,
			stallCount: 0,
			hardSeeks: 0,
			/**
			 * Track boundaries crossed, and what happened when the next track was started.
			 *
			 * "It never plays the next song" could be several different faults — the
			 * boundary never firing, the browser refusing the play(), or the play losing a
			 * race with the load it was issued against. These three tell them apart from
			 * the phone, which is the only place the fault has ever been seen.
			 */
			boundaries: 0,
			playRefusals: 0,
			/**
			 * Programme segments fetched.
			 *
			 * The number that says whether the buffered-programme design is doing its job. A
			 * healthy listener collects roughly two an hour; one climbing steadily means
			 * something keeps knocking the element out of the segment it holds, which is the
			 * expensive failure and the one worth seeing from a phone.
			 */
			segmentLoads: 0,
			playRetries: 0,
			/**
			 * True between losing contact with the server and getting it back.
			 *
			 * Drives the retry cadence and the status line. Reactive because both of those
			 * are rendered — a listener who can see "reconnecting…" knows the silence is
			 * the network rather than the channel having stopped.
			 */
			connectionLost: false,
			reconnects: 0,
			/**
			 * Seconds of audio downloaded beyond the play position.
			 *
			 * The number that predicts a dropout *before* it is audible: a healthy element
			 * sits comfortably ahead, and one about to stutter hovers near zero.
			 */
			bufferedAheadMs: 0,
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
		this.activeAudio = null
		this.pendingSeek = false
		// Programme position the loaded segment begins at, so the element's own
		// currentTime can be read back as a position in the programme. Null when nothing
		// is loaded.
		this.segmentStartMs = null
		// performance.now() at which the active element last ran out of data, or 0 when it
		// has not. Everything about stall handling hangs off this one value.
		this.stalledSince = 0
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

			this.ensureAudioElement()

			// One element, unlocked inside the gesture. There used to be a second one primed
			// here for the next track; a segment carries its own track changes, so there is
			// no second element and nothing to prime.
			await this.unlock(this.activeAudio)

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

		applyMute() {
			if (this.activeAudio) {
				this.activeAudio.muted = this.muted
			}
		},

		tuneOut() {
			this.tunedIn = false
			this.stopTimers()
			if (this.activeAudio) {
				this.activeAudio.pause()
				this.activeAudio.removeAttribute('src')
				this.activeAudio.load()
			}
			this.localTrack = null
			this.segmentStartMs = null
			this.advancedAtServerMs = 0
			this.stalledSince = 0
			this.stalled = false
			this.driftMs = 0
			this.muted = false
		},

		teardown() {
			this.stopWatching()
			this.stopTimers()
			this.tunedIn = false
			this.activeAudio?.pause()
			this.removeAudioElement()
		},

		/**
		 * One element, for the whole session.
		 *
		 * There were two, ping-ponged, so that a track boundary did not cost the few hundred
		 * milliseconds it takes to load. A segment contains its boundaries, so the swap has
		 * nothing left to do — and the pair brought real problems with it: on iOS, priming
		 * the idle one could interrupt the one making sound, and the play() issued against a
		 * freshly-assigned src raced its own load.
		 */
		ensureAudioElement() {
			if (this.activeAudio) {
				return
			}

			const audio = new Audio()
			this.watchForStalls(audio)
			// The element must keep reading ahead of the play position without being asked:
			// everything downloaded before a phone locks is everything it gets to play.
			audio.preload = 'auto'
			audio.hidden = true
			audio.dataset.musicRadioPlayer = 'true'
			// Attached rather than left detached: a detached element still plays, but
			// nothing can see it — not the browser's own media controls, not an
			// accessibility tool, and not a test asserting that only one thing is
			// making sound.
			document.body.appendChild(audio)

			// The end of a segment is the one moment this design still needs JavaScript.
			// Awake, it fetches the next half hour and carries on; asleep, this is where a
			// locked phone falls silent, which is the limit the whole approach accepts —
			// unless the body turned out to be a whole lap, in which case it never ends.
			audio.addEventListener('ended', () => this.loadNextSegment())
			// Re-asked as the browser learns more, not decided once.
			//
			// The body carries no duration header — a Xing frame would have to be written
			// before the length is known, and this length depends on where the listener
			// joined — so the element estimates from the first frames it sees and refines
			// the answer as it downloads. On `loadedmetadata` that estimate can be out by a
			// quarter, which is precisely the moment the old code committed to it.
			for (const event of ['loadedmetadata', 'durationchange', 'canplaythrough']) {
				audio.addEventListener(event, () => this.applyLoopMode())
			}

			this.activeAudio = audio
		},

		/**
		 * Notice when an element runs out of data, and when it gets going again.
		 *
		 * Nothing watched for this before, and the omission is what made playback on a
		 * phone unusable. The only acknowledgement a stall got was `readyState < 2` in
		 * correctDrift, which returns without doing anything — so the broadcast walked away
		 * from a stalled element, and by the time it recovered the gap was past
		 * RESEEK_MS and the correction was a seek. A seek re-requests the audio, which
		 * on a weak connection stalls again, which widens the gap again. That loop is
		 * exactly what "the song gets stuck" describes.
		 *
		 * Knowing an element is stalled lets the correction wait for it instead.
		 *
		 * @param {HTMLAudioElement} audio
		 */
		watchForStalls(audio) {
			const stalled = () => {
				// Only the element making sound matters; the idle one is expected to be
				// short of data and is nobody's problem until it is swapped in.
				if (audio === this.activeAudio && this.stalledSince === 0) {
					this.stalledSince = performance.now()
					this.stalled = true
					this.stallCount++
				}
			}
			const recovered = () => {
				if (audio === this.activeAudio) {
					this.stalledSince = 0
					this.stalled = false
				}
			}

			audio.addEventListener('waiting', stalled)
			audio.addEventListener('stalled', stalled)
			audio.addEventListener('playing', recovered)
			audio.addEventListener('canplay', recovered)

			// Counted from the browser's own event rather than from wherever a rate might be
			// assigned, so it stays true no matter who does the assigning.
			//
			// Nothing should: correcting drift by nudging the rate is what broke playback on
			// Firefox and WebKit, and it was removed — see RESEEK_MS. This is the tripwire.
			// If it ever reads anything but zero, something has started nudging again.
			audio.addEventListener('ratechange', () => {
				if (audio === this.activeAudio) {
					this.rateChanges++
				}
			})
		},

		/**
		 * Whether the given position is already downloaded.
		 *
		 * @param {HTMLAudioElement} audio
		 * @param {number} targetMs
		 * @return {boolean}
		 */
		isBuffered(audio, targetMs) {
			const seconds = targetMs / 1000
			for (let i = 0; i < audio.buffered.length; i++) {
				if (seconds >= audio.buffered.start(i) && seconds <= audio.buffered.end(i)) {
					return true
				}
			}

			return false
		},

		removeAudioElement() {
			this.activeAudio?.remove()
			this.activeAudio = null
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
					// Still swallowed — a refused unlock must not reject the race or hold up
					// tuning in — but counted. This is the call that decides whether the rest
					// of the session can start audio on its own, so when it is refused the
					// diagnostics panel is the only place that can say so; reporting 0
					// refusals while the listener is looking at a "Tap to play" button sends
					// whoever reads it looking in the wrong place.
					audio.play().catch((error) => {
						if (error?.name === 'NotAllowedError') {
							this.playRefusals++
						}
					}),
					new Promise((resolve) => setTimeout(resolve, 1000)),
				])
				audio.pause()
			} catch (error) {
				// Best effort — an element that refuses to unlock will simply stay silent
				// until the listener interacts with it again.
			} finally {
				audio.muted = false
				audio.removeAttribute('src')
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

			// While contact is lost, try again quickly and keep trying. The ordinary
			// interval backs off to ten seconds on a quiet channel, which is the wrong
			// answer for somebody whose connection dropped for two of them: the timeline
			// keeps running locally, so every second spent not noticing the server is back
			// is a second of being further out of step with everyone else.
			//
			// There is no attempt limit. A radio left playing in another tab should pick
			// itself up whenever the network returns, not give up after a while and sit
			// silent — and the cost of asking is one small request every two seconds.
			const delay = this.connectionLost
				? RECONNECT_MS
				// Jitter so a room full of listeners does not poll in lockstep.
				: (this.syncState?.pollAfterMs ?? 10_000) * (0.85 + Math.random() * 0.3)

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
				const { data } = await axios.get(stateUrl(this.channel.id, this.shareToken), {
					params: {
						clientId: clientId(),
						// Read from the shared store, not from this instance's `tunedIn`.
						// Two instances of this mixin poll the same channel from one tab —
						// OnAir, which never plays audio, and GlobalPlayer, which does — so
						// asking either one about itself would have them contradict each
						// other on every poll, one adding this browser to the count and the
						// other removing it.
						listening: playerStore.isListeningTo(this.channel.id),
					},
				})
				this.applyState(data, options)
				this.syncError = null

				if (this.connectionLost) {
					// Back. The position has moved on without this listener, so put them
					// where everyone else is rather than leaving them behind by however
					// long the outage lasted — and start the audio again if it gave up
					// while there was nothing to play.
					// Nothing is done about the position here any more, and that is the
					// point: the element is holding half an hour of programme, so an outage
					// of a few seconds never interrupted it and it is already where it
					// should be. Whatever the outage did cost is measured on the next tick
					// and corrected there. Only the element being *stopped* — because it
					// reached the end of what it had — needs anything from this branch.
					this.connectionLost = false
					this.reconnects++
					this.resume()
				}
			} catch (error) {
				this.connectionLost = true
				this.syncError = t('music_radio', 'Lost contact with the channel — reconnecting…')
			}
		},

		applyState(state, options = {}) {
			const previous = this.syncState
			this.syncState = state

			if (previous && state.playlistVersion !== previous.playlistVersion) {
				this.$emit('playlist-changed')

				// The one thing distance cannot see.
				//
				// Everywhere else the decision to fetch a fresh segment is how far the
				// element is from the broadcast, which covers skips, seeks and a page that
				// was asleep. It cannot cover this: a segment's positions are positions in
				// the programme *as it was when the segment was fetched*, and an edit
				// rewrites that map. Disable a track and everything after it slides back by
				// its length — the element is not out of position, its idea of what
				// position means is stale, and the audio it holds from that point on is
				// simply the wrong music. Measuring agrees with itself all the way down.
				//
				// So the segment is discarded rather than corrected. This bumps on adding,
				// removing, disabling and reordering, and on the vote that reorders, which
				// is every way the running order can change.
				this.segmentStartMs = null
			}

			// Somebody voted, or a track's votes were spent because it played. The counts
			// live on the playlist rows, so the list is refetched — but nothing about the
			// broadcast has moved, which is why this is a counter of its own rather than
			// part of playlistVersion.
			if (previous && state.voteVersion !== previous.voteVersion) {
				this.$emit('votes-changed')
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

			if (!state.current || state.status === 'paused' || state.status === 'ended' || state.status === 'empty') {
				this.localTrack = state.current
					? { ...state.current }
					: null
				if (state.status !== 'playing') {
					this.activeAudio?.pause()
				}

				// A paused channel is still put in the right place, so that pressing play
				// starts sound — at the right moment of the programme — rather than starting
				// a download. This is also where a seek made while paused is applied: the
				// tick does not run on a stopped channel, so nothing else would apply it
				// until after playback had resumed at the old position.
				if (state.status === 'paused') {
					this.alignSegment()
				}
				return
			}

			this.localTrack = { ...state.current }

			// What decides whether a fresh segment is needed is *how far out* this element
			// is, never which event brought the state in.
			//
			// The old code reloaded on a track change or a forced apply, which is both too
			// often and not often enough: a track change needs nothing now — the segment
			// contains it — while a skip, a seek, or a playlist edit that shifts every
			// position afterwards can arrive with no track change at all. Measuring the gap
			// covers all of them without enumerating any of them.
			this.alignSegment()
			this.resume()
		},

		/**
		 * Put the element where the broadcast is, as cheaply as that can be done.
		 *
		 * Three rungs, and everything that corrects a position climbs the same ladder:
		 * leave it alone, seek inside the segment already downloaded, or fetch a segment
		 * from where the listener should be. Says nothing about whether to *play* — that is
		 * `status`, and getting the two mixed up is what once left a resumed channel silent.
		 */
		alignSegment() {
			const target = this.programmeTargetMs()
			if (target === null || !this.activeAudio) {
				return
			}

			if (this.segmentStartMs === null) {
				this.loadSegment(target)
				return
			}

			const elementMs = this.elementProgrammeMs()
			if (elementMs === null) {
				return
			}

			const diff = this.programmeDriftMs(elementMs, target)
			this.driftMs = Math.round(diff)

			// Anything short of RESEEK_MS is left alone. Nothing at all happens to the
			// element — see the note on RESEEK_MS for why "do nothing" is the correction.
			if (Math.abs(diff) < RESEEK_MS) {
				return
			}

			if (Math.abs(diff) > SEGMENT_RELOAD_MS) {
				// Too far out to be anywhere in what was downloaded — somebody skipped, or
				// this page was asleep long enough for the programme to leave it behind.
				this.loadSegment(target)
				return
			}

			this.hardSeek(target)
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
					this.playRefusals++
					return
				}

				// AbortError is routine — a load() or a new src while play() was pending
				// aborts it. It is *not* routine at a track boundary, which is exactly when
				// it happens: advanceLocally assigns a new src, seeks, and calls play() in
				// the same breath, so the play is racing the load it was issued against.
				//
				// One retry when the element is actually ready. Without it the only thing
				// that could start the track was the forced state refresh straight
				// afterwards, which fires just as early and loses the same race — and if
				// both lose, nothing else ever tries and the channel simply stops between
				// songs. Cheap, idempotent, and harmless where the first attempt worked.
				if (error?.name === 'AbortError') {
					this.playWhenReady(audio)
					return
				}

				this.syncError = t('music_radio', 'This browser would not start playback')
			})
		},

		/**
		 * Try again once the element has enough data to start.
		 *
		 * `once` on both, and guarded on the element still being the active one: a boundary
		 * that arrives while this is pending must not have a stale listener start the track
		 * that has since been swapped away.
		 *
		 * @param {HTMLAudioElement} audio the element the failed play() belonged to
		 */
		playWhenReady(audio) {
			const attempt = () => {
				audio.removeEventListener('canplay', attempt)
				audio.removeEventListener('loadeddata', attempt)

				if (audio !== this.activeAudio || !audio.paused || this.syncState?.status !== 'playing') {
					return
				}

				this.playRetries++
				audio.play().then(() => {
					this.needsGesture = false
				}).catch((error) => {
					if (error?.name === 'NotAllowedError') {
						this.needsGesture = true
						this.playRefusals++
					}
				})
			}

			audio.addEventListener('canplay', attempt, { once: true })
			audio.addEventListener('loadeddata', attempt, { once: true })
		},

		/**
		 * Hand the element half an hour of programme starting at `fromMs`.
		 *
		 * Every load is a gap of a few hundred milliseconds, so this is the expensive
		 * operation in the file and everything else exists to avoid needing it. In steady
		 * state it happens about twice an hour.
		 *
		 * @param {number} fromMs programme position the segment should begin at
		 */
		loadSegment(fromMs) {
			const audio = this.activeAudio
			if (!this.channel || !audio) {
				return
			}

			this.segmentStartMs = Math.max(0, Math.round(fromMs))
			this.segmentLoads++
			// A new source, so any outstanding stall belonged to the old one.
			this.stalledSince = 0
			this.stalled = false

			// Off until the new body's length has been seen; applyLoopMode decides on
			// `loadedmetadata`. Carrying the last one's answer over would loop a half-hour
			// segment as though it were the whole programme.
			audio.loop = false
			audio.src = programmeUrl(this.channel.id, this.segmentStartMs, this.shareToken)
			audio.muted = this.muted
			audio.load()

			// Assigning a source stops the element, so every load has to start it again.
			// Doing that here rather than at each call site is what stops one of them
			// forgetting — which is a silent failure, and was the shape of a bug that once
			// left a resumed channel playing nothing.
			if (this.tunedIn && this.syncState?.status === 'playing') {
				this.resume()
			}
		},

		/**
		 * Let the element repeat the body for ever, when the body is the whole programme.
		 *
		 * For a channel short enough to be sent whole this is the best outcome available:
		 * no request is ever needed again, so the buffer ceiling that otherwise stops a
		 * locked phone after half an hour does not exist. `loop` is the browser's own, and
		 * costs no JavaScript at the seam — which is the entire test any of this has to
		 * pass.
		 *
		 * The server's `programmeLoops` is an offer, not an instruction, and the length
		 * check is what makes it safe to take. A lap the server could not finish — a file
		 * missing, a copy not prepared yet — comes back short, and looping *that* would
		 * repeat a fragment of the programme while claiming to be the programme, silently
		 * and for as long as the tab stayed open. A partial lap is missing at least one
		 * whole track, so it cannot pass a check this tight.
		 *
		 * The tolerance is per-track because the two lengths are measured differently: the
		 * body is the prepared copies' actual bytes, `totalDurationMs` is what was read from
		 * the originals, and re-encoding shifts each one by a frame or so.
		 */
		applyLoopMode() {
			const audio = this.activeAudio
			if (!audio) {
				return
			}

			const total = this.syncState?.totalDurationMs ?? 0
			const bodyMs = (audio.duration || 0) * 1000
			const slackMs = 1000 + 100 * (this.syncState?.playableCount ?? 0)

			audio.loop = this.syncState?.programmeLoops === true
				&& total > 0
				&& Math.abs(bodyMs - total) < slackMs
		},

		/**
		 * Carry on where the last segment ran out.
		 *
		 * Fired from the element's own `ended`, which is the only place this design still
		 * depends on JavaScript running at a particular moment. On a phone with the screen
		 * locked it does not run, and that is where the music stops — half an hour in,
		 * rather than at the end of the first song, which is the trade the whole approach
		 * was for.
		 *
		 * The clock decides where to resume rather than "the end of the last segment": if
		 * this page was throttled while the segment played out, those are not the same
		 * position, and only one of them is where everybody else is.
		 */
		loadNextSegment() {
			if (!this.tunedIn || this.syncState?.status !== 'playing') {
				return
			}

			const target = this.programmeTargetMs()
			if (target === null) {
				return
			}

			this.loadSegment(target)
		},

		// ------------------------------------------------------------- the clock

		/**
		 * Where in the *programme* this client should be, right now.
		 *
		 * The server reports a programme position and the instant it was true; this adds
		 * however long ago that was, measured on the shared clock. It is the one number the
		 * audio side works in — which track that lands in is the UI's business, and derived
		 * separately.
		 *
		 * Null when there is nothing to be positioned in: an empty playlist, or a state
		 * that has not arrived yet.
		 *
		 * @return {number|null}
		 */
		programmeTargetMs() {
			const state = this.syncState
			if (!state || typeof state.programmePositionMs !== 'number') {
				return null
			}

			if (state.status === 'paused') {
				return state.programmePositionMs
			}

			return state.programmePositionMs + (this.clock.now() - state.serverTimeMs)
		},

		/**
		 * Where in the programme the element has actually got to.
		 *
		 * `currentTime` is an offset into the segment, and the segment began at a known
		 * programme position — so the two together are a position in the programme, which
		 * is what makes a comparison against the clock meaningful.
		 *
		 * @return {number|null}
		 */
		elementProgrammeMs() {
			if (this.segmentStartMs === null || !this.activeAudio) {
				return null
			}

			return this.segmentStartMs + this.activeAudio.currentTime * 1000
		},

		/**
		 * How far behind the broadcast the element is, signed, taking the loop into account.
		 *
		 * Both positions run forward for ever, but the server's is wrapped into the length
		 * of the programme, so a listener who crosses the wrap would otherwise appear to be
		 * a whole programme ahead. Taking the shorter way round the loop is what makes the
		 * comparison survive that — the same reasoning as `TimelineService::wrap`, one step
		 * further because this answer is signed.
		 *
		 * On a channel that does not loop the shorter way round is still taken; a genuine
		 * error of more than half a programme would be misread, but nothing that far out is
		 * recoverable by seeking anyway, and it reloads either way.
		 *
		 * @param {number} elementMs
		 * @param {number} targetMs
		 * @return {number} positive when the element is behind
		 */
		programmeDriftMs(elementMs, targetMs) {
			const total = this.syncState?.totalDurationMs ?? 0
			const diff = targetMs - elementMs
			if (total <= 0) {
				return diff
			}

			const wrapped = ((diff % total) + total) % total

			return wrapped > total / 2 ? wrapped - total : wrapped
		},

		/** How far into the current track the broadcast is, right now. */
		/**
		 * Where in the current track this client should be, in milliseconds.
		 *
		 * No playback delay is applied here, and the attempt to add one is worth recording
		 * so it is not tried again the same way.
		 *
		 * Subtracting a fixed delay — `max(0, live - 3000)` — looks right and is not. A
		 * programme has nothing before its own start, so for the first three seconds after
		 * play, a jump or a seek the expression is pinned at zero while real time keeps
		 * moving. The position readout freezes, and drift correction spends those seconds
		 * pulling the element back towards a target that is not advancing. Doing it
		 * properly needs a pre-roll: hold the element silent until the delayed programme
		 * has actually begun, which is real machinery and buys little.
		 *
		 * Little, because the reason it was wanted does not hold up. A delay does not
		 * increase how far ahead the browser has buffered — that is set by download speed
		 * against playback speed, not by which absolute position is being played. What
		 * actually stopped iOS from stalling repeatedly is refusing to seek at a stalled
		 * element (see correctDrift and hardSeek), which costs nothing and needs no delay.
		 *
		 * @return {number}
		 */
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

			// Past the end of this track. Nothing happens to the audio — the segment
			// already contains the next track and crossed into it on its own — but the
			// UI's idea of what is on air has to move.
			if (this.targetOffsetMs() >= this.localTrack.durationMs) {
				this.advanceLocally()
				return
			}

			this.sampleBuffer()
			this.correctDrift()
		},

		/**
		 * How much audio is downloaded beyond where the element is playing.
		 *
		 * Sampled on the tick rather than computed on demand, because the interesting
		 * reading is the one taken *while* it is playing badly — by the time somebody looks
		 * at a readout, a phone that stuttered a moment ago has usually recovered.
		 *
		 * Only the range containing the play position counts. A media element can hold
		 * several disjoint ranges after seeking, and the ones behind or ahead of a gap say
		 * nothing about whether the next second of audio is there.
		 */
		sampleBuffer() {
			const audio = this.activeAudio
			if (!audio) {
				return
			}

			const at = audio.currentTime
			for (let i = 0; i < audio.buffered.length; i++) {
				if (at >= audio.buffered.start(i) && at <= audio.buffered.end(i)) {
					this.bufferedAheadMs = Math.round((audio.buffered.end(i) - at) * 1000)
					return
				}
			}

			// Playing from a position nothing covers: about to stall, if it has not already.
			this.bufferedAheadMs = 0
		},

		/**
		 * Cross a track boundary in the UI.
		 *
		 * Audio no longer has any part in this. The element is playing a segment that
		 * already contains the next track, so it crosses the boundary by itself, with no
		 * load, no seek and no play() — which is precisely why a locked phone keeps
		 * playing, and why the swap, the preload and the retry that used to live here are
		 * all gone.
		 *
		 * What is left is bookkeeping: the server is authority for what is on air, but a
		 * poll may be seconds away and a title that lags the audio by seconds looks broken.
		 * The next track and its length are already known, so the display moves now and the
		 * state is refreshed straight after to pick up the track after that.
		 */
		advanceLocally() {
			const next = this.syncState?.next
			if (!next) {
				this.refreshState({ force: true })
				return
			}

			const startedAtMs = this.localTrack.startedAtMs + this.localTrack.durationMs

			this.localTrack = {
				trackId: next.trackId,
				durationMs: next.durationMs,
				title: next.title,
				artist: next.artist,
				startedAtMs,
				offsetMs: 0,
			}
			this.advancedAtServerMs = startedAtMs
			this.boundaries++

			// Re-derive from the server rather than trusting the local step, which advances
			// exactly one track: a page whose timers were throttled can overrun a boundary
			// by seconds and be several tracks out. Cheap, and it keeps the display honest
			// even though the sound no longer depends on it.
			this.refreshState({ force: true })
		},

		/**
		 * Pull this element back towards the broadcast.
		 *
		 * Three outcomes, cheapest first: leave it alone, seek inside the segment it is
		 * already holding, or give up on that segment and fetch one from where the listener
		 * should be. Almost everything lands in the first — the element plays a linear
		 * stream from a known position and has nothing to drift against except its own
		 * clock.
		 */
		correctDrift() {
			const audio = this.activeAudio
			// A paused element is deliberately not corrected. While one is stopped the
			// broadcast keeps moving, so the drift grows without limit and every correction
			// past RESEEK_MS hard-seeks — and each hard seek mutes across the seek. A player
			// that had stopped was therefore also being re-muted roughly once a second,
			// which would have kept it silent even once it was started again.
			if (!audio || audio.paused || audio.readyState < 2 || this.pendingSeek) {
				return
			}

			// Waiting for data. Correcting now would measure a gap the element could not
			// have closed — it is not drifting, it is stopped — and the correction for a
			// gap that size is a seek, which throws away the buffering that was about to
			// end the stall. Doing nothing is what lets it recover.
			//
			// Past STALL_RECOVERY_MS it is not coming back on its own, so fall through and
			// let the ordinary correction re-seek it.
			if (this.stalledSince !== 0 && performance.now() - this.stalledSince < STALL_RECOVERY_MS) {
				return
			}

			// The correction itself is the same one a state response applies, so it lives in
			// one place. All this method adds is *when* it is safe to make.
			this.alignSegment()
		},

		/**
		 * Move the element to a programme position inside the segment it holds.
		 *
		 * @param {number} programmeMs
		 */
		hardSeek(programmeMs) {
			const audio = this.activeAudio
			if (!audio || this.segmentStartMs === null) {
				return
			}

			let withinMs = programmeMs - this.segmentStartMs

			// A looping element has been round more times than anyone counted, so the
			// distance from where the body started is only meaningful modulo the body.
			if (audio.loop && audio.duration > 0) {
				const bodyMs = audio.duration * 1000
				withinMs = ((withinMs % bodyMs) + bodyMs) % bodyMs
			}

			if (withinMs < 0 || (audio.duration > 0 && withinMs > audio.duration * 1000)) {
				// Outside the segment, so no seek can reach it.
				this.loadSegment(programmeMs)
				return
			}

			// Only ever into audio that has already arrived.
			//
			// The segment is served with `Accept-Ranges: none` — a programme position is not
			// a byte offset into a file, and a stream computed from a moving position cannot
			// honestly answer a range request. So there is nothing for the browser to fetch
			// a seek with: it either lands in the buffer or it does not land at all.
			//
			// Waiting is also the right answer regardless of ranges. Seeking away cancels
			// whatever was downloading, which is the worst possible response to a slow
			// connection and turns one stall into a series of them. The correction is not
			// lost — the position is measured again on the next tick, by which time the data
			// is usually there.
			if (!this.isBuffered(audio, withinMs)) {
				return
			}

			audio.muted = true
			this.pendingSeek = true
			this.hardSeeks++

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
				audio.currentTime = Math.max(0, withinMs) / 1000
			} catch (error) {
				this.pendingSeek = false
				audio.muted = this.muted
			}
		},

		// --------------------------------------------------------------- control

		/**
		 * Drive the broadcast, signed in or through a link.
		 *
		 * Both forms go through the same helper for the same reason the state and track
		 * endpoints do: the player is one component, and a link that was granted control
		 * has to behave exactly like the signed-in one — including the 409, which is how
		 * two people sharing a link find out they pressed at the same moment. Whether this
		 * link may control anything is the server's decision, not this method's; the UI
		 * hides the buttons, and the endpoint refuses regardless.
		 *
		 * @param {string} action play|pause|next|previous|seek|jumpTo
		 * @param {object} [payload]
		 */
		async sendControl(action, payload = {}) {
			try {
				const { data } = await axios.post(
					controlUrl(this.channel.id, this.shareToken),
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
			try {
				const { data } = await axios.put(
					playbackSettingsUrl(this.channel.id, this.shareToken),
					payload,
				)
				this.applyState(data, { force: true })
			} catch (error) {
				showError(error?.response?.data?.error ?? t('music_radio', 'That did not work'))
			}
		},
	},
}
