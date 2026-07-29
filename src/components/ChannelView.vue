<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="music-radio-channel" data-testid="channel-view">
		<header class="music-radio-header">
			<div class="music-radio-header__text">
				<h2 class="music-radio-header__title" data-testid="channel-title">
					{{ channel.title }}
				</h2>
				<p v-if="channel.description" class="music-radio-header__description">
					{{ channel.description }}
				</p>
				<p class="music-radio-header__meta" data-testid="channel-meta">
					{{ summary }}
				</p>
			</div>

			<div class="music-radio-header__actions">
				<NcButton
					v-if="can(channel.permissions, ADD_TRACKS)"
					variant="primary"
					:disabled="adding"
					data-testid="add-tracks"
					@click="addTracks">
					<template #icon>
						<NcLoadingIcon v-if="adding" :size="20" />
						<PlusIcon v-else :size="20" />
					</template>
					{{ t('music_radio', 'Add music') }}
				</NcButton>

				<NcButton
					v-if="canShare"
					:aria-label="t('music_radio', 'Share this channel')"
					data-testid="open-sharing"
					@click="showSharing = !showSharing">
					<template #icon>
						<ShareVariantIcon :size="20" />
					</template>
					{{ t('music_radio', 'Share') }}
				</NcButton>

				<NcActions v-if="canManage || channel.isOwner">
					<NcActionButton v-if="canManage" @click="showSettings = true">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('music_radio', 'Channel settings') }}
					</NcActionButton>
					<NcActionButton v-if="channel.isOwner" @click="confirmDelete">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('music_radio', 'Delete channel') }}
					</NcActionButton>
				</NcActions>
			</div>
		</header>

		<OnAir
			ref="onAir"
			:channel="channel"
			:playable-count="playableCount"
			@playlist-changed="loadTracks"
			@on-air-changed="onAirTrackId = $event"
			@tuned-in-changed="onTunedInChanged" />

		<NcNoteCard
			v-if="pendingCount > 0"
			type="warning"
			:text="n('music_radio',
				'%n track has no readable length yet and is left out of the broadcast.',
				'%n tracks have no readable length yet and are left out of the broadcast.',
				pendingCount)" />

		<Playlist
			:channel="channel"
			:tracks="tracks"
			:loading="loadingTracks"
			:on-air-track-id="onAirTrackId"
			:can-preview="!tunedIn"
			:preview-track-id="previewTrack?.id ?? null"
			@reorder="onReorder"
			@remove="onRemove"
			@play="onPlayTrack"
			@preview="onPreview"
			@toggle-disabled="onToggleDisabled" />

		<PreviewPlayer
			v-if="previewTrack"
			:channel-id="channel.id"
			:track="previewTrack"
			@close="previewTrack = null" />

		<NcDialog
			v-if="showSharing"
			:name="t('music_radio', 'Share “{channel}”', { channel: channel.title })"
			size="normal"
			data-testid="sharing-dialog"
			@closing="showSharing = false">
			<!-- Escape is handled here rather than left to the dialog component: with focus
			     inside the panel the key never reached it, so a keyboard user had only the
			     close button. Guarded on defaultPrevented so a widget that legitimately
			     consumes Escape first — a combobox closing its dropdown — still wins. -->
			<div @keydown.esc="onSharingEscape">
				<SharingPanel :channel="channel" />
			</div>
		</NcDialog>

		<ChannelDialog
			v-if="showSettings"
			:channel="channel"
			@close="showSettings = false"
			@saved="onSettingsSaved" />
	</div>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'

import ChannelDialog from './ChannelDialog.vue'
import OnAir from './OnAir.vue'
import Playlist from './Playlist.vue'
import PreviewPlayer from './PreviewPlayer.vue'
import SharingPanel from './SharingPanel.vue'
import { ADD_TRACKS, MANAGE, SHARE, can } from '../utils/permissions.js'
import { addTracks, deleteChannel, deleteTrack, errorMessage, fetchTracks, reorderTracks, updateTrack } from '../utils/api.js'
import { formatDuration, totalDuration } from '../utils/format.js'
import { measureDurations, pickAudioFiles } from '../utils/filePicker.js'

export default {
	name: 'ChannelView',

	components: {
		ChannelDialog,
		DeleteIcon,
		NcActionButton,
		NcActions,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		OnAir,
		PencilIcon,
		Playlist,
		PlusIcon,
		PreviewPlayer,
		ShareVariantIcon,
		SharingPanel,
	},

	props: {
		channel: {
			type: Object,
			required: true,
		},
	},

	emits: ['updated', 'deleted'],

	data() {
		return {
			tracks: [],
			loadingTracks: true,
			adding: false,
			showSettings: false,
			showSharing: false,
			onAirTrackId: null,
			tunedIn: false,
			previewTrack: null,
			ADD_TRACKS,
		}
	},

	computed: {
		canManage() {
			return can(this.channel.permissions, MANAGE)
		},

		canShare() {
			return can(this.channel.permissions, SHARE)
		},

		/** Tracks the server could not measure, and which therefore do not play. */
		pendingCount() {
			return this.tracks.filter((track) => !track.playable).length
		},

		/** Tracks that actually take part in the broadcast. */
		playableCount() {
			return this.tracks.filter((track) => track.playable).length
		},

		summary() {
			const count = n('music_radio', '%n track', '%n tracks', this.tracks.length)
			return `${count} · ${formatDuration(totalDuration(this.tracks))}`
		},
	},

	async mounted() {
		await this.loadTracks()
	},

	methods: {
		can,

		async loadTracks() {
			// The spinner replaces the entire list, so it is only right when there is no
			// list yet. A background refresh — a poll, an upload, a contributor adding
			// something — used to blank every row and build them again: a flash for
			// anyone reading, and fatal to a drag that happened to be in progress, since
			// the row being dragged was unmounted underneath the pointer.
			this.loadingTracks = this.tracks.length === 0
			try {
				const data = await fetchTracks(this.channel.id)
				this.tracks = data.tracks
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not load the playlist')))
			} finally {
				this.loadingTracks = false
			}
		},

		async addTracks() {
			let nodes
			try {
				nodes = await pickAudioFiles()
			} catch (error) {
				// Closing the picker without choosing rejects, and that is not a failure.
				// Anything else is, and must be surfaced — swallowing every rejection here
				// is what made a genuinely broken picker look like a button that simply
				// did nothing.
				if (error?.name !== 'FilePickerClosed' && !/cancel|close/i.test(error?.message ?? '')) {
					showError(errorMessage(error, t('music_radio', 'Could not open the file picker')))
				}
				return
			}
			if (!nodes || nodes.length === 0) {
				return
			}

			this.adding = true
			try {
				// A hint only — the server reads the real length out of the file and
				// prefers that. This covers files whose headers it cannot parse.
				const hints = await measureDurations(nodes)
				const result = await addTracks(
					this.channel.id,
					nodes.map((node) => node.fileid),
					hints,
				)

				this.tracks.push(...result.tracks)
				this.emitTrackCount()

				const skipped = Object.keys(result.skipped ?? {}).length
				if (skipped > 0) {
					showWarning(n('music_radio',
						'%n file was skipped.', '%n files were skipped.', skipped))
				}
				if (result.tracks.length > 0) {
					showSuccess(n('music_radio',
						'Added %n track.', 'Added %n tracks.', result.tracks.length))
				}
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not add the music')))
			} finally {
				this.adding = false
			}
		},

		async onReorder(trackIds) {
			const previous = [...this.tracks]

			// Reorder locally first so dragging feels immediate, then reconcile with
			// whatever the server says the order actually is.
			this.tracks = trackIds.map((id) => this.tracks.find((track) => track.id === id))

			try {
				this.tracks = await reorderTracks(this.channel.id, trackIds)
			} catch (error) {
				this.tracks = previous
				showError(errorMessage(error, t('music_radio', 'Could not reorder the playlist')))
			}
		},

		/**
		 * Put this track on the air.
		 *
		 * There is no private playback: the only way to hear anything is the channel
		 * itself, so choosing a track changes it for everyone listening. Gated on CONTROL
		 * both here and on the server.
		 */
		onPlayTrack(track) {
			this.$refs.onAir?.sendControl('jumpTo', { trackId: track.id })
		},

		/**
		 * Play a track privately, for this person only.
		 *
		 * Only reachable while not tuned in — see PreviewPlayer. Clicking the same track
		 * again stops it.
		 */
		onPreview(track) {
			if (this.tunedIn) {
				return
			}
			this.previewTrack = this.previewTrack?.id === track.id ? null : track
		},

		/**
		 * Tuning in ends any private playback: the channel is the only thing that should
		 * be audible once you are listening to it.
		 */
		onTunedInChanged(tunedIn) {
			this.tunedIn = tunedIn
			if (tunedIn) {
				this.previewTrack = null
			}
		},

		/**
		 * Take a track out of the rotation, or put it back.
		 *
		 * It stays in the playlist either way — this is "not right now", not a removal.
		 */
		async onToggleDisabled(track) {
			try {
				const updated = await updateTrack(this.channel.id, track.id, { disabled: !track.disabled })
				const index = this.tracks.findIndex((t) => t.id === track.id)
				if (index !== -1) {
					this.tracks.splice(index, 1, updated)
				}
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not change that track')))
			}
		},

		async onRemove(track) {
			if (this.previewTrack?.id === track.id) {
				this.previewTrack = null
			}
			try {
				await deleteTrack(this.channel.id, track.id)
				this.tracks = this.tracks.filter((t) => t.id !== track.id)
				this.emitTrackCount()
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not remove the track')))
			}
		},

		/**
		 * @param {KeyboardEvent} event
		 */
		onSharingEscape(event) {
			if (event.defaultPrevented) {
				return
			}
			this.showSharing = false
		},

		onSettingsSaved(channel) {
			this.showSettings = false
			this.$emit('updated', channel)
		},

		async confirmDelete() {
			const ok = await new Promise((resolve) => {
				window.OC.dialogs.confirmDestructive(
					t('music_radio', 'This deletes the channel and its playlist for everyone it is shared with. The music files themselves are not touched.'),
					t('music_radio', 'Delete {name}?', { name: this.channel.title }),
					{
						type: window.OC.dialogs.YES_NO_BUTTONS,
						confirm: t('music_radio', 'Delete channel'),
						confirmClasses: 'error',
						cancel: t('music_radio', 'Cancel'),
					},
					resolve,
					true,
				)
			})
			if (!ok) {
				return
			}

			try {
				await deleteChannel(this.channel.id)
				this.$emit('deleted', this.channel.id)
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not delete the channel')))
			}
		},

		/** Keep the navigation's counter bubble honest after adding or removing. */
		emitTrackCount() {
			this.$emit('updated', { ...this.channel, trackCount: this.tracks.length })
		},
	},
}
</script>

<style scoped>
.music-radio-channel {
	padding: 1rem 1.5rem 3rem;
	max-width: 60rem;
	margin-inline: auto;
}

.music-radio-header {
	display: flex;
	align-items: flex-start;
	gap: 1rem;
	flex-wrap: wrap;
	margin-block-end: 1rem;
}

.music-radio-header__text {
	flex: 1 1 20rem;
	min-width: 0;
}

.music-radio-header__title {
	margin: 0;
	font-size: 1.5rem;
	line-height: 1.3;
	overflow-wrap: anywhere;
}

.music-radio-header__description {
	margin: 0.25rem 0 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.music-radio-header__meta {
	margin: 0.5rem 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.music-radio-header__actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}
</style>
