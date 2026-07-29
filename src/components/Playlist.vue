<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="music-radio-playlist">
		<NcLoadingIcon v-if="loading" :size="32" class="music-radio-playlist__loading" />

		<NcEmptyContent
			v-else-if="tracks.length === 0"
			:name="t('music_radio', 'Nothing queued up')"
			:description="canAdd
				? t('music_radio', 'Add music from your Files to start building this channel.')
				: t('music_radio', 'The people who run this channel have not added anything yet.')">
			<template #icon>
				<PlaylistMusicIcon />
			</template>
		</NcEmptyContent>

		<ol v-else class="music-radio-playlist__list" data-testid="playlist">
			<TrackItem
				v-for="(track, index) in tracks"
				:key="track.id"
				:track="track"
				:index="index"
				:is-first="index === 0"
				:is-last="index === tracks.length - 1"
				:can-reorder="canReorder"
				:can-remove="canRemoveTrack(track)"
				:can-control="canControl"
				:is-on-air="onAirTrackId === track.id"
				:is-dragging="draggingTrackId === track.id"
				:can-preview="canPreview && track.playable"
				:is-previewing="previewTrackId === track.id"
				@move-up="move(index, -1)"
				@move-down="move(index, 1)"
				@remove="$emit('remove', track)"
				@play="$emit('play', track)"
				@preview="$emit('preview', track)"
				@toggle-disabled="$emit('toggle-disabled', track)"
				@drag-start="draggingTrackId = track.id"
				@drag-end="draggingTrackId = null"
				@drop-on="onDropOn" />
		</ol>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PlaylistMusicIcon from 'vue-material-design-icons/PlaylistMusic.vue'
import { getCurrentUser } from '@nextcloud/auth'

import TrackItem from './TrackItem.vue'
import { ADD_TRACKS, CONTROL, EDIT_PLAYLIST, can } from '../utils/permissions.js'

export default {
	name: 'Playlist',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		PlaylistMusicIcon,
		TrackItem,
	},

	props: {
		channel: {
			type: Object,
			required: true,
		},
		tracks: {
			type: Array,
			required: true,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		/** The track the channel is broadcasting right now, if any. */
		onAirTrackId: {
			type: Number,
			default: null,
		},
		/** Private playback is only possible while not tuned in to the channel. */
		canPreview: {
			type: Boolean,
			default: false,
		},
		/** The track being played privately, if any. */
		previewTrackId: {
			type: Number,
			default: null,
		},
	},

	emits: ['reorder', 'remove', 'play', 'preview', 'toggle-disabled'],

	data() {
		return {
			/** The track being dragged, so its row can be dimmed. */
			draggingTrackId: null,
		}
	},

	computed: {
		canAdd() {
			return can(this.channel.permissions, ADD_TRACKS)
		},

		canReorder() {
			return can(this.channel.permissions, EDIT_PLAYLIST)
		},

		/** Deciding what plays is the owner's, unless they hand it out explicitly. */
		canControl() {
			return can(this.channel.permissions, CONTROL)
		},

		currentUser() {
			return getCurrentUser()?.uid ?? null
		},
	},

	methods: {
		/**
		 * Mirrors the server's rule: curating the playlist lets you remove anything,
		 * while a contributor may only take back what they added themselves.
		 *
		 * @param {object} track
		 * @return {boolean}
		 */
		canRemoveTrack(track) {
			if (can(this.channel.permissions, EDIT_PLAYLIST)) {
				return true
			}
			return can(this.channel.permissions, ADD_TRACKS) && track.addedBy === this.currentUser
		},

		/**
		 * Move the dragged track next to the row it was dropped on.
		 *
		 * Emits the whole new order rather than a pair of positions: the server rejects
		 * anything that is not a permutation of the playlist it currently holds, which is
		 * how a track appended by somebody else mid-drag gets caught instead of silently
		 * dropped.
		 *
		 * @param {{trackId: number, edge: string}} target the row dropped on, and which
		 *   side of it
		 */
		onDropOn({ trackId, edge }) {
			const dragged = this.draggingTrackId
			this.draggingTrackId = null

			if (dragged === null || dragged === trackId) {
				return
			}

			const ids = this.tracks.map((track) => track.id)
			const from = ids.indexOf(dragged)
			if (from === -1) {
				return
			}

			ids.splice(from, 1)

			// Recomputed after the removal, since taking the dragged row out shifts
			// everything below it up by one.
			const targetIndex = ids.indexOf(trackId)
			if (targetIndex === -1) {
				return
			}

			ids.splice(edge === 'before' ? targetIndex : targetIndex + 1, 0, dragged)

			this.$emit('reorder', ids)
		},

		/**
		 * @param {number} index position of the track being moved
		 * @param {number} delta -1 to move it earlier, 1 to move it later
		 */
		move(index, delta) {
			const target = index + delta
			if (target < 0 || target >= this.tracks.length) {
				return
			}

			const ids = this.tracks.map((track) => track.id)
			;[ids[index], ids[target]] = [ids[target], ids[index]]

			this.$emit('reorder', ids)
		},
	},
}
</script>

<style scoped>
.music-radio-playlist__loading {
	margin-block: 3rem;
}

.music-radio-playlist__list {
	list-style: none;
	margin: 0;
	padding: 0;
	border-top: 1px solid var(--color-border);
}
</style>
