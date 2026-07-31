<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<li
		class="music-radio-track"
		:class="{
			'music-radio-track--muted': !track.playable,
			'music-radio-track--disabled': track.disabled,
			'music-radio-track--held': track.awaitingApproval,
			'music-radio-track--onair': isOnAir,
			'music-radio-track--dragging': isDragging,
			'music-radio-track--drop-before': dropEdge === 'before',
			'music-radio-track--drop-after': dropEdge === 'after',
		}"
		data-testid="track"
		:data-onair="isOnAir ? 'true' : 'false'"
		:draggable="canReorder"
		@dragstart="onDragStart"
		@dragend="$emit('drag-end')"
		@dragover.prevent="onDragOver"
		@dragleave="dropEdge = null"
		@drop.prevent="onDrop">
		<!-- The grip is decoration: the row itself is the drag source, and reordering is
		     also reachable from the actions menu for anyone not using a pointer. -->
		<span v-if="canReorder" class="music-radio-track__grip" aria-hidden="true">
			<DragIcon :size="20" />
		</span>
		<!-- Only someone who may drive the broadcast gets a button; for everyone else
		     this column just shows the position, or a marker for what is on air. There
		     is deliberately no private playback anywhere — one channel, one song.

		     A skipped track keeps its button, disabled. Dropping back to a bare number
		     would make the row a different shape from its neighbours and read as if the
		     track had been removed rather than paused. -->
		<span v-if="canControl && (track.playable || track.disabled)" class="music-radio-track__play" data-testid="play-track">
			<NcButton
				variant="tertiary"
				:disabled="track.disabled"
				:aria-label="playLabel"
				@click="$emit('play')">
				<template #icon>
					<VolumeHighIcon v-if="isOnAir" :size="20" />
					<PlayIcon v-else :size="20" />
				</template>
			</NcButton>
		</span>
		<!-- The words as well as the icon: the row is also tinted, and neither a tint nor a
		     glyph is anything a screen reader announces. -->
		<span v-else-if="isOnAir" class="music-radio-track__onair-icon" data-testid="track-onair">
			<VolumeHighIcon :size="20" />
			<span class="music-radio-track__sr-only">{{ t('music_radio', 'Playing now') }}</span>
		</span>
		<span v-else class="music-radio-track__index">{{ index + 1 }}</span>

		<div class="music-radio-track__text">
			<span class="music-radio-track__title" data-testid="track-title">{{ track.title }}</span>
			<span v-if="subtitle" class="music-radio-track__subtitle">{{ subtitle }}</span>
		</div>

		<span v-if="statusLabel" class="music-radio-track__status" data-testid="track-status">{{ statusLabel }}</span>

		<!--
			Shown to everyone who can see votes, pressable only by those who may cast one —
			a listener with no vote still wants to see what the room has asked for. The
			count is hidden from the accessibility tree and restated in the label, so it is
			not read out as a bare number next to an unexplained icon.
		-->
		<span v-if="showVotes" class="music-radio-track__votes" data-testid="track-votes">
			<NcButton
				variant="tertiary"
				:disabled="!canVote || !track.playable"
				:pressed="canVote ? Boolean(track.voted) : undefined"
				:aria-label="voteLabel"
				:title="voteLabel"
				data-testid="vote-track"
				@click="$emit('vote')">
				<template #icon>
					<HeartIcon v-if="track.voted" :size="20" />
					<HeartOutlineIcon v-else :size="20" />
				</template>
			</NcButton>
			<span class="music-radio-track__vote-count" aria-hidden="true">{{ track.votes || 0 }}</span>
		</span>

		<span class="music-radio-track__duration" data-testid="track-duration">
			{{ formatDuration(track.durationMs) }}
		</span>

		<!--
			The open state is held here rather than left to the component, and every entry
			goes through `choose`.

			"Move up" and "Move down" reorder the playlist, and Vue answers by moving this
			row's DOM node to its new position — taking the open menu with it. From there
			the menu never closes on its own: it sits over the list, anchored to a row that
			is no longer underneath it, until something else destroys it.
		-->
		<NcActions v-model:open="menuOpen" :inline="0">
			<!-- Offered only while not tuned in, so private playback can never be audible
			     alongside the channel. -->
			<NcActionButton v-if="canPreview" data-testid="preview-track" @click="choose('preview')">
				<template #icon>
					<HeadphonesIcon :size="20" />
				</template>
				{{ isPreviewing
					? t('music_radio', 'Stop playing just for me')
					: t('music_radio', 'Play just for me') }}
			</NcActionButton>
			<NcActionButton v-if="canReorder" :disabled="isFirst" @click="choose('move-up')">
				<template #icon>
					<ArrowUpIcon :size="20" />
				</template>
				{{ t('music_radio', 'Move up') }}
			</NcActionButton>
			<NcActionButton v-if="canReorder" :disabled="isLast" @click="choose('move-down')">
				<template #icon>
					<ArrowDownIcon :size="20" />
				</template>
				{{ t('music_radio', 'Move down') }}
			</NcActionButton>
			<!-- Curating rather than reordering: both of these change whether a track plays
			     at all, and both go through the track-update endpoint, which a link visitor
			     has no counterpart for. See `canCurate`. -->
			<NcActionButton v-if="canCurate" data-testid="toggle-disabled" @click="choose('toggle-disabled')">
				<template #icon>
					<PlayCircleOutlineIcon v-if="track.disabled" :size="20" />
					<CancelIcon v-else :size="20" />
				</template>
				{{ track.disabled
					? t('music_radio', 'Put back in the rotation')
					: t('music_radio', 'Skip for now') }}
			</NcActionButton>
			<NcActionButton
				v-if="canCurate && track.awaitingApproval"
				data-testid="approve-track"
				@click="choose('approve')">
				<template #icon>
					<CheckIcon :size="20" />
				</template>
				{{ t('music_radio', 'Let it play') }}
			</NcActionButton>

			<NcActionButton v-if="canRemove" data-testid="remove-track" @click="choose('remove')">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('music_radio', 'Remove from channel') }}
			</NcActionButton>
		</NcActions>
	</li>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DragIcon from 'vue-material-design-icons/Drag.vue'
import HeadphonesIcon from 'vue-material-design-icons/Headphones.vue'
import HeartIcon from 'vue-material-design-icons/Heart.vue'
import HeartOutlineIcon from 'vue-material-design-icons/HeartOutline.vue'
import PlayCircleOutlineIcon from 'vue-material-design-icons/PlayCircleOutline.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import VolumeHighIcon from 'vue-material-design-icons/VolumeHigh.vue'

import { formatDuration } from '../utils/format.js'

/** Mirrors Track::DURATION_SOURCE_* in PHP. */
const SOURCE_PROBE = 1

export default {
	name: 'TrackItem',

	components: {
		ArrowDownIcon,
		ArrowUpIcon,
		CancelIcon,
		CheckIcon,
		DeleteIcon,
		DragIcon,
		HeadphonesIcon,
		HeartIcon,
		HeartOutlineIcon,
		NcActionButton,
		NcActions,
		NcButton,
		PlayCircleOutlineIcon,
		PlayIcon,
		VolumeHighIcon,
	},

	props: {
		track: {
			type: Object,
			required: true,
		},
		index: {
			type: Number,
			required: true,
		},
		isFirst: {
			type: Boolean,
			default: false,
		},
		isLast: {
			type: Boolean,
			default: false,
		},
		/** Whether the running order can be changed from this row: drag, or move up/down. */
		canReorder: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether the two actions that decide if a track plays at all — skip it, let a held
		 * one play — are on offer. Normally the same answer as `canReorder`, and separate
		 * only because the public page cannot have them: approving is the owner's review of
		 * what strangers uploaded, and a link curator waving through another visitor's held
		 * track would quietly undo it.
		 */
		canCurate: {
			type: Boolean,
			default: false,
		},
		canRemove: {
			type: Boolean,
			default: false,
		},
		/** Whether this listener may decide what the channel plays. */
		canControl: {
			type: Boolean,
			default: false,
		},
		/** Whether this is the track the channel is broadcasting right now. */
		isOnAir: {
			type: Boolean,
			default: false,
		},
		/** True while this row is the one being dragged. */
		isDragging: {
			type: Boolean,
			default: false,
		},
		/** Whether private playback is available at all right now. */
		canPreview: {
			type: Boolean,
			default: false,
		},
		/** True while this row is the one being played privately. */
		isPreviewing: {
			type: Boolean,
			default: false,
		},
		/** Whether the channel has voting switched on at all. */
		showVotes: {
			type: Boolean,
			default: false,
		},
		/** Whether this particular person may cast one. */
		canVote: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['move-up', 'move-down', 'remove', 'play', 'preview', 'toggle-disabled', 'approve', 'vote', 'drag-start', 'drag-end', 'drop-on'],

	data() {
		return {
			menuOpen: false,
			/** Which side of this row the drop indicator sits on, if any. */
			dropEdge: null,
		}
	},

	computed: {
		subtitle() {
			return [this.track.artist, this.track.album].filter(Boolean).join(' · ')
		},

		/**
		 * Surface the two states that keep a track out of the broadcast, plus the case
		 * where the length had to be guessed — a wrong length shows up as everyone
		 * jumping at the track boundary, so it is worth flagging rather than hiding.
		 *
		 * @return {string}
		 */
		playLabel() {
			if (this.track.disabled) {
				return t('music_radio', '{title} is skipped — put it back in the rotation to play it', {
					title: this.track.title,
				})
			}
			return this.isOnAir
				? t('music_radio', 'Restart {title} for everyone', { title: this.track.title })
				: t('music_radio', 'Play {title} for everyone', { title: this.track.title })
		},

		/**
		 * Says what pressing it does *and* what the number means, because the number
		 * itself is hidden from screen readers — a bare digit beside a heart is not
		 * something that reads as anything.
		 *
		 * @return {string}
		 */
		voteLabel() {
			const votes = this.track.votes || 0

			if (!this.canVote) {
				return n('music_radio', '%n vote', '%n votes', votes)
			}

			return this.track.voted
				? t('music_radio', 'Remove your vote ({votes} so far)', { votes })
				: t('music_radio', 'Vote for this track ({votes} so far)', { votes })
		},

		statusLabel() {
			// Checked before the others: a deliberately skipped track is not broken, and
			// saying "length unknown" about one would be misleading.
			if (this.track.disabled) {
				return t('music_radio', 'Skipped')
			}
			// Someone is waiting on an answer, which is worth saying before anything about
			// the file itself.
			if (this.track.awaitingApproval) {
				return t('music_radio', 'Waiting for approval')
			}
			if (this.track.unavailable) {
				return t('music_radio', 'File missing')
			}
			if (this.track.durationMs === null) {
				return t('music_radio', 'Length unknown')
			}
			if (this.track.durationSource !== SOURCE_PROBE) {
				return t('music_radio', 'Estimated length')
			}
			return ''
		},
	},

	methods: {
		/**
		 * Act, and put the menu away.
		 *
		 * @param {string} event the action to emit
		 */
		choose(event) {
			this.menuOpen = false
			this.$emit(event)
		},

		formatDuration,

		onDragStart(event) {
			if (!this.canReorder) {
				return
			}
			// Firefox refuses to start a drag unless something is on the dataTransfer.
			event.dataTransfer.effectAllowed = 'move'
			event.dataTransfer.setData('text/plain', String(this.track.id))
			this.$emit('drag-start')
		},

		/**
		 * Decide which half of the row the pointer is over, so the track lands above or
		 * below it rather than always in one place.
		 *
		 * @param {DragEvent} event
		 */
		onDragOver(event) {
			if (!this.canReorder) {
				return
			}
			const box = this.$el.getBoundingClientRect()
			this.dropEdge = (event.clientY - box.top) < box.height / 2 ? 'before' : 'after'
			event.dataTransfer.dropEffect = 'move'
		},

		onDrop() {
			if (!this.canReorder) {
				return
			}
			const edge = this.dropEdge
			this.dropEdge = null
			this.$emit('drop-on', { trackId: this.track.id, edge: edge ?? 'after' })
		},
	},
}
</script>

<style scoped>
.music-radio-track {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem 0.25rem;
	border-bottom: 1px solid var(--color-border);
}

/* Out of the rotation — skipped by the owner, or unreadable. Dimmed, never hidden: a
   row you cannot read is a row you cannot put back. */
.music-radio-track--held .music-radio-track__status {
	/* Something to act on, not something that went wrong. */
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.music-radio-track--muted .music-radio-track__title {
	color: var(--color-text-maxcontrast);
}

.music-radio-track__grip {
	flex: none;
	display: flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
	cursor: grab;
	opacity: 0;
	transition: opacity 0.1s ease-in-out;
}

.music-radio-track__grip:active {
	cursor: grabbing;
}

/* The row is the drag source, not just the handle, so the closed hand has to follow the
   whole drag rather than only the few pixels the pointer started on. */
.music-radio-track--dragging,
.music-radio-track--dragging .music-radio-track__grip {
	cursor: grabbing;
}

.music-radio-track:hover .music-radio-track__grip,
.music-radio-track:focus-within .music-radio-track__grip {
	opacity: 1;
}

.music-radio-track--dragging {
	opacity: 0.4;
}

/* The line shows where the track will land, so the drop is not a guess. */
.music-radio-track--drop-before {
	box-shadow: inset 0 2px 0 0 var(--color-primary-element);
}

.music-radio-track--drop-after {
	box-shadow: inset 0 -2px 0 0 var(--color-primary-element);
}

.music-radio-track__index {
	inline-size: 2rem;
	text-align: end;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	flex: none;
}

.music-radio-track__play,
.music-radio-track__onair-icon {
	flex: none;
	inline-size: 2.75rem;
	display: flex;
	justify-content: center;
}

.music-radio-track__onair-icon {
	color: var(--color-primary-element);
}

/* Read aloud, never drawn. */
.music-radio-track__sr-only {
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

.music-radio-track--onair {
	background-color: var(--color-background-hover);
}

.music-radio-track--onair .music-radio-track__title {
	font-weight: 600;
	color: var(--color-primary-element);
}

.music-radio-track__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1 1 auto;
}

.music-radio-track__title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-track__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-track__status {
	flex: none;
	font-size: 0.8em;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 1rem);
	padding: 0.1rem 0.5rem;
}

/*
 * Quieter than the title, and a fixed width so the column does not jitter as counts
 * change under it — the whole list would shuffle sideways otherwise.
 */
.music-radio-track__votes {
	display: flex;
	align-items: center;
	gap: 0.1rem;
	flex: none;
}

.music-radio-track__vote-count {
	min-inline-size: 1.25rem;
	font-size: 0.85em;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
}

.music-radio-track__duration {
	flex: none;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}
</style>
