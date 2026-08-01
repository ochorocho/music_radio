<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - The thing that actually makes sound.
  -
  - Mounted once by the app and never unmounted, so navigating between channels does not
  - interrupt what is playing. It renders nothing visible: the channel view draws the
  - controls and reads state from `playerStore`, while this component owns the audio
  - elements, the clock and the drift correction.
-->
<template>
	<span
		class="music-radio-global-player"
		data-testid="global-player"
		:data-channel-id="channel ? channel.id : ''"
		aria-hidden="true" />
</template>

<script>
import radioPlayer from '../mixins/radioPlayer.js'
import { playerStore } from '../utils/playerStore.js'

export default {
	name: 'GlobalPlayer',

	mixins: [radioPlayer],

	computed: {
		/** The mixin drives everything off this. */
		channel() {
			return playerStore.channel
		},

		/**
		 * Read off the store rather than taken as a prop, because on a public page the
		 * page and the player are mounted separately and only the store connects them.
		 * The mixin uses it to choose between the token endpoints and the private ones.
		 */
		publicToken() {
			return playerStore.token
		},

		storeChannelId() {
			return playerStore.channel?.id ?? null
		},

		storeMuted() {
			return playerStore.muted
		},

		syncReadout() {
			return {
				clockReady: this.clockReady,
				clockRttMs: this.clockRttMs,
				driftMs: this.driftMs,
				stalled: this.stalled,
				error: this.syncError,
				needsGesture: this.needsGesture,
				// For the diagnostic panel. Published from here for the same reason
				// everything else is: this component owns the audio, and the component
				// drawing the readout does not.
				bufferedAheadMs: this.bufferedAheadMs,
				rateChanges: this.rateChanges,
				stallCount: this.stallCount,
				hardSeeks: this.hardSeeks,
				boundaries: this.boundaries,
				segmentLoads: this.segmentLoads,
				playRefusals: this.playRefusals,
				playRetries: this.playRetries,
				connectionLost: this.connectionLost,
				reconnects: this.reconnects,
				playbackRate: this.activeAudio?.playbackRate ?? 1,
			}
		},

		resumeRequest() {
			return playerStore.resumeRequest
		},

		/** What the lock screen should say. Watched as one object so it updates together. */
		mediaSessionState() {
			return {
				title: this.localTrack?.title ?? '',
				artist: this.localTrack?.artist ?? '',
				channel: this.channel?.title ?? '',
				playing: this.tunedIn && this.isBroadcasting,
			}
		},
	},

	watch: {
		/**
		 * Tell the operating system what is playing.
		 *
		 * Without this a locked iPhone shows a blank card with a generic speaker icon, and
		 * the AirPods pinch does nothing — the audio is an anonymous noise as far as iOS is
		 * concerned. With it the lock screen names the track and the hardware controls work.
		 *
		 * Guarded rather than assumed: `mediaSession` is absent on older Safari and on
		 * Firefox until recently, and a missing API here must not take the player down with
		 * it. Everything below is decoration — nothing about playback depends on it.
		 */
		mediaSessionState: {
			immediate: true,
			deep: true,
			handler(state) {
				if (!('mediaSession' in navigator)) {
					return
				}

				navigator.mediaSession.playbackState = state.playing ? 'playing' : 'paused'

				if (!state.title) {
					navigator.mediaSession.metadata = null
					return
				}

				try {
					navigator.mediaSession.metadata = new window.MediaMetadata({
						title: state.title,
						artist: state.artist || '',
						album: state.channel || '',
					})
				} catch (error) {
					// Some engines expose mediaSession without MediaMetadata. The playback
					// state above still lands, which is the half that matters.
				}
			},
		},


		storeChannelId: {
			immediate: true,
			async handler(next, previous) {
				// Leaving one channel for another has to stop the first, or both would be
				// audible at once.
				if (previous !== undefined && previous !== null) {
					this.tuneOut()
				}
				if (next !== null) {
					await this.tuneIn()
				}
			},
		},

		storeMuted(value) {
			if (this.muted !== value) {
				this.muted = value
				this.applyMute()
			}
		},

		/**
		 * Mirror the live sync figures into the store, so whichever component is drawing
		 * the status line reports this player's state rather than its own.
		 */
		syncReadout: {
			immediate: true,
			deep: true,
			handler(value) {
				playerStore.sync = value
			},
		},

		/**
		 * The listener pressed "Tap to play" somewhere else in the tree.
		 *
		 * `flush: 'sync'` is the whole point: the handler has to run in the same task as
		 * the click that bumped the counter, because the gesture the browser is waiting
		 * for does not survive being deferred to the next tick.
		 */
		resumeRequest: {
			flush: 'sync',
			handler() {
				if (this.tunedIn) {
					this.resume()
				}
			},
		},
	},

	mounted() {
		this.wireMediaSessionControls()
	},

	beforeUnmount() {
		if (!('mediaSession' in navigator)) {
			return
		}
		for (const action of ['play', 'pause', 'stop']) {
			try {
				navigator.mediaSession.setActionHandler(action, null)
			} catch (error) {
				// An engine that does not know the action refuses to clear it either.
			}
		}
	},

	methods: {
		/**
		 * Make the lock screen and the headphone buttons work.
		 *
		 * Only the actions that mean something for a radio. There is deliberately no
		 * `nexttrack` or `seekto`: this is a broadcast, and skipping would move it for
		 * everybody listening rather than just for whoever pressed. That is a real
		 * capability the app has — it is CONTROL, granted per share — but a lock-screen
		 * button gives no way to know whether the person holding the phone has it, and a
		 * control that silently does nothing is worse than one that is not offered.
		 *
		 * Pausing is different: it stops *this* listener's audio and leaves the broadcast
		 * running, which is exactly what the mute and tune-out controls already do.
		 */
		wireMediaSessionControls() {
			if (!('mediaSession' in navigator)) {
				return
			}

			const set = (action, handler) => {
				try {
					navigator.mediaSession.setActionHandler(action, handler)
				} catch (error) {
					// Unsupported actions throw rather than being ignored.
				}
			}

			set('play', () => {
				if (this.tunedIn) {
					// Straight through, not via the store: this handler *is* the user
					// gesture, and deferring it to a watcher would spend it.
					this.resume()
				}
			})
			set('pause', () => this.activeAudio?.pause())
			set('stop', () => this.activeAudio?.pause())
		},
	},
}
</script>

<style scoped>
.music-radio-global-player {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
}
</style>
