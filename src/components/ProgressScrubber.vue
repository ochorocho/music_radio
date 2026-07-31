<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - How far through the current track the channel is — and, for whoever is allowed to
  - decide that, a control for moving it.
  -
  - Two renderings of the same fact rather than two components: a readout for everybody, and
  - a slider for whoever holds CONTROL. The slider is a native `<input type="range">` on
  - purpose. Dragging is not the only way people operate a control, and a scrubber reachable
  - only by pointer is not reachable at all for some — the native element brings the
  - keyboard, the touch handling and the announcements with it, and all any hand-rolled
  - version would do is lose them one at a time.
-->
<template>
	<div class="music-radio-scrub">
		<NcProgressBar
			v-if="!seekable"
			class="music-radio-scrub__bar"
			:value="percent"
			size="medium" />

		<input
			v-else
			class="music-radio-scrub__input"
			type="range"
			min="0"
			:max="durationMs"
			:step="stepMs"
			:value="steppedMs"
			:style="{ '--music-radio-scrub-fill': percent + '%' }"
			:aria-label="t('music_radio', 'Position in the track')"
			:aria-valuetext="positionLabel"
			data-testid="seek-bar"
			@input="onScrub"
			@change="onCommit">
	</div>
</template>

<script>
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'

import { formatDuration } from '../utils/format.js'

/**
 * How close the incoming position has to get to what was asked for before the control
 * stops holding its own value. Anything nearer than this is the seek having landed and
 * the clock ticking on from there.
 */
const SETTLED_WITHIN_MS = 2000

/** Long enough that a seek which never arrives does not leave the bar lying forever. */
const SETTLE_GIVE_UP_MS = 5000

/**
 * How long a settled value waits before it is sent.
 *
 * A held-down arrow key produces a `change` per repeat, and firing a request at each of
 * them is wrong twice over: every seek re-anchors the timeline and makes every listener
 * refetch, and the second request carries the state version the first has not finished
 * replacing — so it comes back 409 and the page announces a conflict with itself. Waiting
 * for the presses to stop turns a burst into the one seek the person meant. A pointer drag
 * only ever ends in a single `change`, so this is imperceptible there.
 */
const COMMIT_QUIET_MS = 300

/**
 * How many steps make up the whole track.
 *
 * A range input snaps its value to the step, so the step is not only how far a key press
 * moves — it is the only positions the control can express at all. A fixed step therefore
 * cannot be right for both a three-second jingle and a three-hour set: it is either too
 * coarse to point at anything in the first or too slow to cross the second. Sizing it
 * against the track keeps a press worth about one percent either way, and keeps the
 * rounding under the width of the handle.
 */
const STEPS_PER_TRACK = 100

/** Below this the snapping is finer than the quarter-second the readout itself ticks at. */
const MIN_STEP_MS = 250

export default {
	name: 'ProgressScrubber',

	components: {
		NcProgressBar,
	},

	props: {
		/** Where the channel is in the current track, as the ticking clock sees it. */
		offsetMs: {
			type: Number,
			default: 0,
		},

		/** Length of the current track; zero means there is nothing to be part-way through. */
		durationMs: {
			type: Number,
			default: 0,
		},

		/** Whether this viewer may move the broadcast, not merely watch it. */
		seekable: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['seek'],

	data() {
		return {
			// While a drag is in progress the control shows the pointer, not the programme
			// — otherwise the clock ticking underneath drags the handle out from under it.
			scrubMs: null,
			// And after the release it keeps showing what was asked for until the answer
			// comes back, rather than snapping to the old position for a moment first.
			committedMs: null,
			settleTimer: null,
			commitTimer: null,
		}
	},

	computed: {
		shownMs() {
			if (this.scrubMs !== null) {
				return this.scrubMs
			}
			if (this.committedMs !== null) {
				return this.committedMs
			}

			return this.offsetMs
		},

		stepMs() {
			return Math.max(MIN_STEP_MS, Math.round(this.durationMs / STEPS_PER_TRACK))
		},

		/**
		 * What the input actually holds, rather than what the clock says.
		 *
		 * The browser rounds any value it is given to the nearest step and draws the handle
		 * there, so anything drawn from the unrounded figure — the fill behind the handle,
		 * most obviously — ends up somewhere the handle is not.
		 */
		steppedMs() {
			if (!this.durationMs) {
				return 0
			}

			const stepped = Math.round(this.shownMs / this.stepMs) * this.stepMs

			return Math.max(0, Math.min(this.durationMs, stepped))
		},

		percent() {
			if (!this.durationMs) {
				return 0
			}

			return Math.min(100, Math.max(0, (this.steppedMs / this.durationMs) * 100))
		},

		/**
		 * What a screen reader says instead of "45" — a raw millisecond count is true and
		 * useless. Announced against the length, since a position means nothing alone.
		 */
		positionLabel() {
			return t('music_radio', '{position} of {duration}', {
				position: formatDuration(this.steppedMs),
				duration: formatDuration(this.durationMs),
			})
		},
	},

	beforeUnmount() {
		clearTimeout(this.settleTimer)
		clearTimeout(this.commitTimer)
	},

	methods: {
		/**
		 * @param {Event} event the range input being moved, by pointer or by key
		 */
		onScrub(event) {
			this.scrubMs = Number(event.target.value)
		},

		/**
		 * Released, or moved with a key. One request per gesture rather than one per pixel:
		 * every seek re-anchors the timeline and makes every listener refetch, so streaming
		 * them during the drag would put the whole audience through the drag as well.
		 *
		 * @param {Event} event the range input that has settled
		 */
		onCommit(event) {
			const target = Number(event.target.value)
			this.scrubMs = null
			this.committedMs = target

			clearTimeout(this.settleTimer)
			this.settleTimer = setTimeout(() => {
				this.committedMs = null
			}, SETTLE_GIVE_UP_MS + COMMIT_QUIET_MS)

			clearTimeout(this.commitTimer)
			this.commitTimer = setTimeout(() => {
				this.commitTimer = null
				this.$emit('seek', this.committedMs ?? target)
			}, COMMIT_QUIET_MS)
		},
	},

	watch: {
		offsetMs(value) {
			if (this.commitTimer !== null && this.committedMs !== null) {
				// Still waiting to be sent; the position coming in is the old one by
				// definition and says nothing about whether the seek landed.
				return
			}
			if (this.committedMs !== null && Math.abs(value - this.committedMs) < SETTLED_WITHIN_MS) {
				clearTimeout(this.settleTimer)
				this.committedMs = null
			}
		},
	},
}
</script>

<style scoped>
.music-radio-scrub {
	inline-size: 100%;
}

/*
 * Shaped like the progress bar it replaces, so the interface does not change proportions
 * depending on who is looking at it. The fill is a gradient rather than a second element:
 * a range input has no part that can be styled as "the bit behind the handle".
 */
.music-radio-scrub__input {
	inline-size: 100%;
	margin: 0;
	padding: 0;
	block-size: 20px;
	background: transparent;
	cursor: pointer;
	appearance: none;
	-webkit-appearance: none;
}

.music-radio-scrub__input::-webkit-slider-runnable-track {
	block-size: 4px;
	border-radius: 2px;
	background:
		linear-gradient(var(--color-primary-element), var(--color-primary-element))
			0 / var(--music-radio-scrub-fill, 0%) 100% no-repeat,
		var(--color-background-darker);
}

.music-radio-scrub__input::-moz-range-track {
	block-size: 4px;
	border-radius: 2px;
	background:
		linear-gradient(var(--color-primary-element), var(--color-primary-element))
			0 / var(--music-radio-scrub-fill, 0%) 100% no-repeat,
		var(--color-background-darker);
}

.music-radio-scrub__input::-webkit-slider-thumb {
	appearance: none;
	-webkit-appearance: none;
	inline-size: 14px;
	block-size: 14px;
	margin-block-start: -5px;
	border: 0;
	border-radius: 50%;
	background-color: var(--color-primary-element);
}

.music-radio-scrub__input::-moz-range-thumb {
	inline-size: 14px;
	block-size: 14px;
	border: 0;
	border-radius: 50%;
	background-color: var(--color-primary-element);
}

/* Keyboard focus has to be visible on the handle, which is the only part that moves. */
.music-radio-scrub__input:focus-visible::-webkit-slider-thumb {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.music-radio-scrub__input:focus-visible::-moz-range-thumb {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}
</style>
