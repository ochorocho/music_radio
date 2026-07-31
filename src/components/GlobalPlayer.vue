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
			}
		},

		resumeRequest() {
			return playerStore.resumeRequest
		},
	},

	watch: {
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
