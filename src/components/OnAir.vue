<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - The broadcast: what is on air right now, and (for whoever may drive it) the controls.
-->
<template>
	<section class="music-radio-onair" data-testid="on-air">
		<!-- Everything the sync tests need, in one place and never shown to a user. -->
		<span
			class="music-radio-onair__debug"
			data-testid="sync-debug"
			aria-hidden="true">{{ debugJson }}</span>

		<div v-if="!isListening" class="music-radio-onair__tunein">
			<!-- What the channel is doing, whether or not this person is listening to it.
			     Someone running a channel wants to see it is on air without having to
			     hear it. -->
			<div v-if="localTrack" class="music-radio-onair__now" data-testid="off-air-status">
				<span class="music-radio-onair__badge" :class="{ 'music-radio-onair__badge--live': isBroadcasting }">
					{{ statusLabel }}
				</span>

				<div class="music-radio-onair__text">
					<span class="music-radio-onair__title" data-testid="now-playing-title">
						{{ localTrack.title }}
					</span>
					<span v-if="localTrack.artist" class="music-radio-onair__artist">
						{{ localTrack.artist }}
					</span>
				</div>

				<span class="music-radio-onair__time" data-testid="now-playing-time">
					{{ formatDuration(displayOffsetMs) }} / {{ formatDuration(localTrack.durationMs) }}
				</span>
			</div>

			<NcProgressBar
				v-if="localTrack"
				class="music-radio-onair__progress"
				:value="progressPercent"
				size="medium" />

			<p v-if="localTrack" class="music-radio-onair__hint" data-testid="off-air-note">
				{{ t('music_radio', 'You are not listening. The channel is playing without you.') }}
			</p>

			<NcButton
				variant="primary"
				size="large"
				data-testid="tune-in"
				:disabled="!canTuneIn"
				@click="startListening">
				<template #icon>
					<RadioTowerIcon :size="20" />
				</template>
				{{ t('music_radio', 'Tune in') }}
			</NcButton>
			<p class="music-radio-onair__hint">
				{{ tuneInHint }}
			</p>
		</div>

		<template v-else>
			<div class="music-radio-onair__now">
				<span class="music-radio-onair__badge" :class="{ 'music-radio-onair__badge--live': isBroadcasting }">
					{{ statusLabel }}
				</span>

				<div class="music-radio-onair__text">
					<span class="music-radio-onair__title" data-testid="now-playing-title">
						{{ localTrack ? localTrack.title : t('music_radio', 'Nothing on air') }}
					</span>
					<span v-if="localTrack && localTrack.artist" class="music-radio-onair__artist">
						{{ localTrack.artist }}
					</span>
				</div>

				<span class="music-radio-onair__time" data-testid="now-playing-time">
					{{ formatDuration(displayOffsetMs) }} / {{ formatDuration(localTrack ? localTrack.durationMs : null) }}
				</span>

				<span data-testid="mute-toggle">
					<NcButton
						variant="tertiary"
						:aria-label="isMuted
							? t('music_radio', 'Unmute')
							: t('music_radio', 'Mute')"
						:pressed="isMuted"
						@click="toggleMuted">
						<template #icon>
							<VolumeOffIcon v-if="isMuted" :size="20" />
							<VolumeHighIcon v-else :size="20" />
						</template>
					</NcButton>
				</span>

				<span data-testid="tune-out">
					<NcButton
						variant="tertiary"
						:aria-label="t('music_radio', 'Stop listening')"
						@click="confirmTuneOut = true">
						<template #icon>
							<PowerIcon :size="20" />
						</template>
					</NcButton>
				</span>
			</div>

			<!--
				Browsers refuse to start audio that no one asked for, and a resume can land
				long after the gesture that tuned in. Asking is the only way out of that, and
				it beats silence with nothing to click.
			-->
			<NcButton
				v-if="needsGesture"
				class="music-radio-onair__gesture"
				variant="primary"
				data-testid="tap-to-play"
				@click="resumePlayback">
				<template #icon>
					<PlayIcon :size="20" />
				</template>
				{{ t('music_radio', 'Tap to play') }}
			</NcButton>

			<NcProgressBar
				class="music-radio-onair__progress"
				:value="progressPercent"
				size="medium" />

			<p class="music-radio-onair__sync" data-testid="sync-status">
				{{ isMuted ? t('music_radio', 'Muted — the channel is still playing') : syncLabel }}
			</p>
		</template>

		<NcDialog
			v-if="confirmTuneOut"
			:name="t('music_radio', 'Stop listening?')"
			:message="t('music_radio', 'The channel keeps broadcasting without you. Tuning back in will drop you wherever it has got to, not where you left off.')"
			:buttons="tuneOutButtons"
			size="small"
			data-testid="tune-out-confirm"
			@closing="confirmTuneOut = false" />

		<div v-if="canControl" class="music-radio-onair__controls" data-testid="player-controls">
			<NcButton
				:aria-label="t('music_radio', 'Previous track')"
				data-testid="control-previous"
				@click="sendControl('previous')">
				<template #icon>
					<SkipPreviousIcon :size="20" />
				</template>
			</NcButton>

			<NcButton
				variant="primary"
				:aria-label="isBroadcasting ? t('music_radio', 'Pause the broadcast') : t('music_radio', 'Start the broadcast')"
				data-testid="control-playpause"
				@click="sendControl(isBroadcasting ? 'pause' : 'play')">
				<template #icon>
					<PauseIcon v-if="isBroadcasting" :size="20" />
					<PlayIcon v-else :size="20" />
				</template>
			</NcButton>

			<NcButton
				:aria-label="t('music_radio', 'Next track')"
				data-testid="control-next"
				@click="sendControl('next')">
				<template #icon>
					<SkipNextIcon :size="20" />
				</template>
			</NcButton>

			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="syncState ? syncState.loop : true"
				data-testid="control-loop"
				@update:model-value="sendSettings({ loop: $event })">
				{{ t('music_radio', 'Loop') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="syncState ? syncState.shuffle : false"
				data-testid="control-shuffle"
				@update:model-value="sendSettings({ shuffle: $event })">
				{{ t('music_radio', 'Shuffle') }}
			</NcCheckboxRadioSwitch>
		</div>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import PauseIcon from 'vue-material-design-icons/Pause.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import PowerIcon from 'vue-material-design-icons/Power.vue'
import RadioTowerIcon from 'vue-material-design-icons/RadioTower.vue'
import SkipNextIcon from 'vue-material-design-icons/SkipNext.vue'
import SkipPreviousIcon from 'vue-material-design-icons/SkipPrevious.vue'
import VolumeHighIcon from 'vue-material-design-icons/VolumeHigh.vue'
import VolumeOffIcon from 'vue-material-design-icons/VolumeOff.vue'

import radioPlayer from '../mixins/radioPlayer.js'
import { playerStore } from '../utils/playerStore.js'
import { CONTROL, can } from '../utils/permissions.js'
import { formatDuration } from '../utils/format.js'

export default {
	name: 'OnAir',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcProgressBar,
		PauseIcon,
		PlayIcon,
		PowerIcon,
		RadioTowerIcon,
		SkipNextIcon,
		SkipPreviousIcon,
		VolumeHighIcon,
		VolumeOffIcon,
	},

	mixins: [radioPlayer],

	props: {
		channel: {
			type: Object,
			required: true,
		},
		/** Used only to decide whether tuning in is worthwhile. */
		playableCount: {
			type: Number,
			default: 0,
		},
		/** Set on the public page; makes the player address the token endpoints. */
		publicToken: {
			type: String,
			default: null,
		},
	},

	emits: ['playlist-changed', 'on-air-changed', 'tuned-in-changed'],

	data() {
		return {
			// Mirrors the tick so the readout moves without re-rendering on every frame.
			displayOffsetMs: 0,
			displayTimer: null,
			confirmTuneOut: false,
		}
	},

	computed: {
		canControl() {
			return can(this.channel.permissions, CONTROL)
		},

		/**
		 * Whether the app is listening to *this* channel.
		 *
		 * Listening belongs to the app, not to this view — the player keeps running while
		 * you browse other channels — so the answer comes from the store rather than from
		 * this component's own copy of the mixin, which only ever watches.
		 */
		isListening() {
			return playerStore.isListeningTo(this.channel.id)
		},

		isMuted() {
			return playerStore.muted
		},

		tuneOutButtons() {
			return [
				{
					label: t('music_radio', 'Keep listening'),
					callback: () => {
						this.confirmTuneOut = false
					},
				},
				{
					label: t('music_radio', 'Stop listening'),
					variant: 'error',
					callback: () => {
						this.confirmTuneOut = false
						this.stopListening()
					},
				},
			]
		},

		canTuneIn() {
			return this.playableCount > 0
		},

		tuneInHint() {
			if (!this.canTuneIn) {
				return t('music_radio', 'Add some music before going on air.')
			}
			return t('music_radio', 'You will hear whatever is playing right now, in step with everyone else listening.')
		},

		statusLabel() {
			switch (this.status) {
			case 'playing': return t('music_radio', 'On air')
			case 'paused': return t('music_radio', 'Paused')
			case 'ended': return t('music_radio', 'Finished')
			default: return t('music_radio', 'Silent')
			}
		},

		progressPercent() {
			if (!this.localTrack || !this.localTrack.durationMs) {
				return 0
			}
			return Math.min(100, (this.displayOffsetMs / this.localTrack.durationMs) * 100)
		},

		/**
		 * The sync figures worth showing.
		 *
		 * While listening these come from the store, because the player making the sound
		 * is a different component: this one is only watching, and its own clock and
		 * drift describe nothing anybody can hear.
		 */
		liveSync() {
			if (this.isListening) {
				return playerStore.sync
			}
			return {
				clockReady: this.clockReady,
				clockRttMs: this.clockRttMs,
				driftMs: this.driftMs,
				error: this.syncError,
			}
		},

		/**
		 * Whether the player is waiting to be asked. Read off the store for the same reason
		 * as liveSync: this component's own mixin owns no audio, so its answer would always
		 * be no.
		 */
		needsGesture() {
			return this.isListening && this.liveSync.needsGesture === true
		},

		syncLabel() {
			const sync = this.liveSync
			if (sync.error) {
				return sync.error
			}
			if (!sync.clockReady) {
				return t('music_radio', 'Syncing…')
			}
			return t('music_radio', 'In sync (±{drift} ms, {rtt} ms away)', {
				drift: Math.abs(sync.driftMs),
				rtt: sync.clockRttMs,
			})
		},

		/**
		 * A compact snapshot of this listener's state. Two browsers can be compared
		 * against each other from this alone, which is how the sync test works.
		 *
		 * @return {string}
		 */
		debugJson() {
			return JSON.stringify({
				trackId: this.localTrack?.trackId ?? null,
				offsetMs: Math.round(this.displayOffsetMs),
				status: this.status,
				stateVersion: this.syncState?.stateVersion ?? null,
				clockOffsetMs: Math.round(this.clock?.offset ?? 0),
				driftMs: this.liveSync.driftMs,
				tunedIn: this.isListening,
				muted: this.isMuted,
			})
		},
	},

	watch: {
		/**
		 * Tell the playlist which row to mark. Watched rather than emitted from every
		 * code path that can change it, of which there are several.
		 */
		localTrack: {
			immediate: true,
			handler(track) {
				this.$emit('on-air-changed', track?.trackId ?? null)
			},
		},

		/** Private previewing is only offered while not listening to the channel. */
		isListening: {
			immediate: true,
			handler(value) {
				this.$emit('tuned-in-changed', value)
			},
		},
	},

	mounted() {
		// Follow the channel from the start: someone who has not tuned in still sees what
		// is on air, they just do not hear it.
		this.startWatching()
		this.displayTimer = setInterval(() => {
			// Clamped to the track: between the true boundary and the tick that crosses
			// it the raw figure runs past the end, which is meaningless to read and
			// misleading to compare.
			this.displayOffsetMs = this.localTrack
				? Math.max(0, Math.min(this.targetOffsetMs(), this.localTrack.durationMs))
				: 0
		}, 250)
	},

	beforeUnmount() {
		clearInterval(this.displayTimer)
	},

	methods: {
		formatDuration,

		startListening() {
			// The token goes into the store because the player is mounted elsewhere and
			// has no other way to learn it exists.
			playerStore.tuneIn(this.channel, this.publicToken)
		},

		stopListening() {
			playerStore.tuneOut()
		},

		/**
		 * Hand the browser the gesture it is holding out for. The player picks this up
		 * synchronously, so play() still runs inside this click.
		 */
		resumePlayback() {
			playerStore.requestResume()
		},

		toggleMuted() {
			playerStore.toggleMute()
		},
	},
}
</script>

<style scoped>
.music-radio-onair {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 0.5rem);
	padding: 1rem;
	margin-block-end: 1.5rem;
	background-color: var(--color-background-hover);
}

.music-radio-onair__debug {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.music-radio-onair__tunein {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	padding-block: 0.5rem;
	text-align: center;
}

.music-radio-onair__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	max-inline-size: 32rem;
}

.music-radio-onair__now {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.music-radio-onair__badge {
	flex: none;
	font-size: 0.75em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	padding: 0.15rem 0.5rem;
	border-radius: var(--border-radius-pill, 1rem);
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.music-radio-onair__badge--live {
	/* --color-error is a light background tint (#FFE7E7), not a saturated red, so white
	   text on it fails contrast badly. --color-error-text is the partner Nextcloud
	   defines for exactly this pairing. */
	background-color: var(--color-error);
	color: var(--color-error-text);
	font-weight: bold;
}

.music-radio-onair__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1 1 auto;
}

.music-radio-onair__title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: 600;
}

.music-radio-onair__artist {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-onair__time {
	flex: none;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
}

.music-radio-onair__progress {
	margin-block-start: 0.75rem;
}

.music-radio-onair__sync {
	margin: 0.5rem 0 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.music-radio-onair__controls {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
	margin-block-start: 1rem;
	padding-block-start: 1rem;
	border-top: 1px solid var(--color-border);
}
</style>
