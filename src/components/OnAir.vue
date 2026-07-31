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
			<div class="music-radio-onair__status">
				<div v-if="localTrack" class="music-radio-onair__now" data-testid="off-air-status">
					<span class="music-radio-onair__badge" :class="{ 'music-radio-onair__badge--live': isBroadcasting }">
						{{ statusLabel }}
					</span>

					<!-- Zero is a real answer and is shown; "we cannot know" is null and shows
					     nothing at all. See the listenerCount computed. -->
					<span
						v-if="listenerCount !== null"
						class="music-radio-onair__listeners"
						data-testid="listener-count"
						:title="listenersLabel">
						<HeadphonesIcon :size="16" />
						<span aria-hidden="true">{{ listenerCount }}</span>
						<!-- Named with real text rather than an aria-label: a bare <span> has no
						     role, and ARIA forbids naming an element that cannot have a name. -->
						<span class="music-radio-onair__sr-only">{{ listenersLabel }}</span>
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

				<ProgressScrubber
					v-if="localTrack"
					class="music-radio-onair__progress"
					:offset-ms="displayOffsetMs"
					:duration-ms="localTrack.durationMs || 0"
					:seekable="canSeek"
					@seek="seekTo" />

				<p v-if="localTrack" class="music-radio-onair__hint" data-testid="off-air-note">
					{{ t('music_radio', 'You are not listening. The channel is playing without you.') }}<br>
					{{ tuneInHint }}
				</p>
			</div>

			<!-- Beside the readout rather than under it: the status, the progress bar and
			     the hint are all one thing to read, and the button is the one thing to do.
			     Stacking them made the card tall enough to matter, which is awkward now
			     that it is sticky. -->
			<NcButton
				class="music-radio-onair__tunein-button"
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
		</div>

		<template v-else>
			<div class="music-radio-onair__now">
				<span class="music-radio-onair__badge" :class="{ 'music-radio-onair__badge--live': isBroadcasting }">
					{{ statusLabel }}
				</span>

				<!-- Zero is a real answer and is shown; "we cannot know" is null and shows
				     nothing at all. See the listenerCount computed. -->
				<span
					v-if="listenerCount !== null"
					class="music-radio-onair__listeners"
					data-testid="listener-count"
					:title="listenersLabel">
					<HeadphonesIcon :size="16" />
					<span aria-hidden="true">{{ listenerCount }}</span>
					<!-- Named with real text rather than an aria-label: a bare <span> has no
					     role, and ARIA forbids naming an element that cannot have a name. -->
					<span class="music-radio-onair__sr-only">{{ listenersLabel }}</span>
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

			<ProgressScrubber
				class="music-radio-onair__progress"
				:offset-ms="displayOffsetMs"
				:duration-ms="localTrack ? (localTrack.durationMs || 0) : 0"
				:seekable="canSeek"
				@seek="seekTo" />

			<p class="music-radio-onair__sync" data-testid="sync-status">
				{{ isMuted ? t('music_radio', 'Muted — the channel is still playing') : syncLabel }}
				<!--
					Playback problems on a phone can only be diagnosed from the phone: iOS
					cannot be driven from the test suite, because Nextcloud's
					unsupported-browser gate rejects a spoofed user agent both server-side
					and in the page. So the device is given a way to say what it is seeing.

					Behind a toggle and closed by default — this is for the one conversation
					in fifty that starts "it keeps breaking up", not for everyone else.
				-->
				<button
					class="music-radio-onair__diagnostics-toggle"
					type="button"
					:aria-expanded="showDiagnostics"
					data-testid="diagnostics-toggle"
					@click="showDiagnostics = !showDiagnostics">
					{{ showDiagnostics
						? t('music_radio', 'Hide playback details')
						: t('music_radio', 'Playback details') }}
				</button>
			</p>

			<div v-if="showDiagnostics" class="music-radio-onair__diagnostics" data-testid="diagnostics">
				<dl class="music-radio-onair__diagnostics-list">
					<div v-for="row in diagnosticRows" :key="row.label">
						<dt>{{ row.label }}</dt>
						<dd>{{ row.value }}</dd>
					</div>
				</dl>
				<!-- Copyable, because the useful thing to do with these is paste them into
				     a message to whoever is looking at the bug. -->
				<NcButton data-testid="diagnostics-copy" @click="copyDiagnostics">
					{{ t('music_radio', 'Copy') }}
				</NcButton>
			</div>
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
import HeadphonesIcon from 'vue-material-design-icons/Headphones.vue'
import PauseIcon from 'vue-material-design-icons/Pause.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import PowerIcon from 'vue-material-design-icons/Power.vue'
import RadioTowerIcon from 'vue-material-design-icons/RadioTower.vue'
import SkipNextIcon from 'vue-material-design-icons/SkipNext.vue'
import SkipPreviousIcon from 'vue-material-design-icons/SkipPrevious.vue'
import VolumeHighIcon from 'vue-material-design-icons/VolumeHigh.vue'
import VolumeOffIcon from 'vue-material-design-icons/VolumeOff.vue'

import ProgressScrubber from './ProgressScrubber.vue'
import radioPlayer from '../mixins/radioPlayer.js'
import { playerStore } from '../utils/playerStore.js'
import { CONTROL, can } from '../utils/permissions.js'
import { formatDuration } from '../utils/format.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'OnAir',

	components: {
		HeadphonesIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		PauseIcon,
		PlayIcon,
		PowerIcon,
		ProgressScrubber,
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

	emits: ['playlist-changed', 'votes-changed', 'on-air-changed', 'tuned-in-changed'],

	data() {
		return {
			// Mirrors the tick so the readout moves without re-rendering on every frame.
			displayOffsetMs: 0,
			displayTimer: null,
			confirmTuneOut: false,
			showDiagnostics: false,
		}
	},

	computed: {
		canControl() {
			return can(this.channel.permissions, CONTROL)
		},

		/**
		 * Seeking is controlling: it moves the broadcast for everybody, exactly as the skip
		 * buttons do. `canControl` therefore answers it for a link visitor too — a link can
		 * be granted CONTROL now, and a scrubber that reads but will not move would be an
		 * odd thing to hand somebody who has the skip buttons beside it.
		 *
		 * There is still nothing to move to on a channel with no measured track, so that
		 * stays excluded before the control is offered rather than after it is pressed.
		 */
		canSeek() {
			return this.canControl
				&& !!this.localTrack
				&& !!this.localTrack.durationMs
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
			return ''
		},

		statusLabel() {
			switch (this.status) {
			case 'playing': return t('music_radio', 'On air')
			case 'paused': return t('music_radio', 'Paused')
			case 'ended': return t('music_radio', 'Finished')
			default: return t('music_radio', 'Silent')
			}
		},

		/**
		 * How many people are tuned in, or null when there is no number to show.
		 *
		 * Null covers two different situations that both mean "say nothing": the server
		 * has no distributed cache and genuinely cannot count, and the channel is not
		 * publishing the figure to this viewer. Neither is zero, which is a real answer
		 * and is shown as one.
		 *
		 * @return {number|null}
		 */
		listenerCount() {
			const count = this.syncState?.listenerCount

			return typeof count === 'number' ? count : null
		},

		/**
		 * The pill shows a bare number beside a headphones icon, which says nothing on its
		 * own to somebody using a screen reader — so the whole sentence goes in the label,
		 * and the digit is hidden from the accessibility tree rather than read twice.
		 *
		 * @return {string}
		 */
		listenersLabel() {
			return n(
				'music_radio',
				'%n person listening',
				'%n people listening',
				this.listenerCount ?? 0,
			)
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
		/**
		 * The readout, as label/value pairs.
		 *
		 * Ordered by what to look at first when a phone stutters: the buffer margin says
		 * whether audio is arriving fast enough, the stall count says whether it ran out,
		 * and the rate changes say whether the correction is fighting the browser. Drift
		 * and the round trip are the context for all three.
		 *
		 * @return {Array<{label: string, value: string}>}
		 */
		diagnosticRows() {
			const sync = this.liveSync

			return [
				{
					label: t('music_radio', 'Buffered ahead'),
					value: `${((sync.bufferedAheadMs ?? 0) / 1000).toFixed(1)} s`,
				},
				{ label: t('music_radio', 'Drift'), value: `${Math.round(sync.driftMs ?? 0)} ms` },
				{ label: t('music_radio', 'Ran out of audio'), value: `${sync.stallCount ?? 0}×` },
				{ label: t('music_radio', 'Rate changes'), value: `${sync.rateChanges ?? 0}×` },
				{ label: t('music_radio', 'Speed'), value: (sync.playbackRate ?? 1).toFixed(3) },
				{ label: t('music_radio', 'Re-seeks'), value: `${sync.hardSeeks ?? 0}×` },
				{ label: t('music_radio', 'Track changes'), value: `${sync.boundaries ?? 0}×` },
				{
					label: t('music_radio', 'Playback refused'),
					value: `${sync.playRefusals ?? 0}× (${sync.playRetries ?? 0} retried)`,
				},
				{ label: t('music_radio', 'Clock round trip'), value: `${Math.round(sync.clockRttMs ?? 0)} ms` },
			]
		},

		debugJson() {
			return JSON.stringify({
				trackId: this.localTrack?.trackId ?? null,
				offsetMs: Math.round(this.displayOffsetMs),
				status: this.status,
				stateVersion: this.syncState?.stateVersion ?? null,
				clockOffsetMs: Math.round(this.clock?.offset ?? 0),
				driftMs: this.liveSync.driftMs,
				stalled: this.liveSync.stalled === true,
				tunedIn: this.isListening,
				muted: this.isMuted,
				listenerCount: this.listenerCount,
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

		/**
		 * Put the readout on the clipboard.
		 *
		 * The point of the panel is that these numbers reach whoever is looking at the
		 * bug, and retyping seven figures off a phone screen is how that stops happening.
		 * The user agent goes along with them: which iOS, and which Safari, is the first
		 * thing anybody would ask.
		 */
		async copyDiagnostics() {
			const lines = this.diagnosticRows.map((row) => `${row.label}: ${row.value}`)
			lines.push(`User agent: ${navigator.userAgent}`)

			try {
				await navigator.clipboard.writeText(lines.join('\n'))
				showSuccess(t('music_radio', 'Playback details copied'))
			} catch (error) {
				// Clipboard access can be refused; the panel is selectable as a fallback.
				showError(t('music_radio', 'Could not copy — select the text and copy it manually'))
			}
		},

		/**
		 * There is no playhead per track to move — the channel holds one position over the
		 * whole programme, and which track that lands in is worked out by walking the
		 * durations. So "go to 1:05 of this track" is the existing seek action, which does
		 * that sum on the server where the playlist is authoritative.
		 *
		 * @param {number} offsetMs where in the current track to go
		 */
		seekTo(offsetMs) {
			this.sendControl('seek', { offsetMs: Math.round(offsetMs) })
		},

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
	/*
	 * Kept in view while the playlist scrolls under it: what is on air, and the controls
	 * for it, are the things you want to reach without scrolling back up.
	 *
	 * This works in both places the card is mounted, and for different reasons. Signed in,
	 * the scroller is `.app-content` (@nextcloud/vue gives it `overflow: auto` when there
	 * is no list slot, which this app does not use); on a public page core's `#content` is
	 * fixed and clipped, so `.music-radio-public` scrolls itself instead. Neither has an
	 * `overflow: clip` between here and the scroller, which is what sticky would not
	 * survive.
	 *
	 * `top` is not 0: both containers have top padding that scrolls away, so a card stuck
	 * flush against the scrollport reads as clipped. Sitting it just below leaves the gap
	 * the layout already has.
	 */
	position: sticky;
  top: 0;
	/* Above the playlist rows, and well below the 2000 @nextcloud/vue uses for its own
	   sticky affordances. */
	z-index: 10;

	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 0.5rem);
	padding: 1rem;
	margin-block-end: 1.5rem;
	/*
	 * Opaque, or rows would show through as they pass underneath. The shadow is what
	 * separates it from them once it is overlapping rather than sitting in the flow — the
	 * same pairing PreviewPlayer uses for the card stuck to the bottom.
	 */
	background-color: var(--color-background-hover);
	box-shadow: 0 0 10px var(--color-box-shadow);
}

.music-radio-onair__debug {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

/*
 * A row: everything there is to read on the left, the one thing to do on the right.
 *
 * This used to be a centred column with the button underneath, which made the not-listening
 * state markedly taller than the listening one — noticeable now that the card is sticky and
 * sits over the playlist. Wraps on a narrow viewport, where a row would squeeze both.
 */
.music-radio-onair__tunein {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.75rem 1rem;
	padding-block: 0.5rem;
}

.music-radio-onair__status {
	display: flex;
	flex-direction: column;
	/* Takes the leftover width; min-width lets the text inside ellipsise rather than
	   pushing the button out of the row. */
	flex: 1 1 20rem;
	min-inline-size: 0;
	gap: 0.35rem;
}

/* Never the flexible item — that is what truncates a label to fit. */
.music-radio-onair__tunein-button {
	flex: none;
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

/*
 * Deliberately quieter than the status badge beside it: which channel is on air is the
 * thing to read at a glance, and how many people are listening is a detail next to it.
 */
.music-radio-onair__listeners {
	flex: none;
	display: flex;
	align-items: center;
	gap: 0.2rem;
	font-size: 0.8em;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
	/* The figure changes every few seconds as people come and go; without a floor the
	   row would shuffle sideways each time it crosses a digit. */
	min-inline-size: 2.5rem;
}

/*
 * Read aloud, never drawn. Defined here rather than borrowing core's `.hidden-visually`,
 * which is not part of any interface this app is promised.
 */
.music-radio-onair__sr-only {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
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
	inline-size: 100%;
}

.music-radio-onair__sync {
	margin: 0.5rem 0 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

/* A link in prose rather than a button that looks like one: it sits at the end of the
   status line, and anything button-shaped there would read as an action on the broadcast. */
.music-radio-onair__diagnostics-toggle {
	background: none;
	border: 0;
	padding: 0;
	margin-inline-start: 0.5rem;
	font: inherit;
	color: var(--color-text-maxcontrast);
	text-decoration: underline;
	cursor: pointer;
}

.music-radio-onair__diagnostics {
	margin-block-start: 0.5rem;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 0.5rem);
	background-color: var(--color-background-hover);
	font-size: 0.8em;
	/* Selectable, so the numbers can still be got at if the clipboard is refused. */
	user-select: text;
}

.music-radio-onair__diagnostics-list {
	display: grid;
	grid-template-columns: auto auto;
	gap: 0.15rem 0.75rem;
	margin: 0 0 0.5rem;
}

.music-radio-onair__diagnostics-list > div {
	display: contents;
}

.music-radio-onair__diagnostics-list dt {
	color: var(--color-text-maxcontrast);
}

.music-radio-onair__diagnostics-list dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
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

/*
 * On a narrow screen the title gets its own line, above everything else.
 *
 * `__now` is a single row of badge, listener count, title and elapsed time. The first two
 * and the last are all `flex: none`, so the title is the only thing that can give — and on
 * a phone it gives everything, ellipsising to a word or two while a LIVE badge and a
 * timestamp sit beside it at full width. Which track is playing is the one thing this row
 * exists to say, so it goes first and takes the whole line; the rest wraps underneath and
 * stays perfectly readable.
 */
@media (max-width: 600px) {
	.music-radio-onair__now {
		flex-wrap: wrap;
		/* Tighter than the row gap: these are now two lines, not two columns. */
		row-gap: 0.35rem;
	}

	.music-radio-onair__text {
		order: -1;
		flex: 1 0 100%;
	}
}
</style>
