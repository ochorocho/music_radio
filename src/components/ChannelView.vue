<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="music-radio-channel" data-testid="channel-view">
		<header class="music-radio-header">
			<div class="music-radio-header__text">
				<h2 class="music-radio-header__title" data-testid="channel-title">
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
					v-if="canImport"
					:aria-label="t('music_radio', 'Add a track from a YouTube link')"
					data-testid="add-youtube"
					@click="showImport = true">
					<template #icon>
						<YoutubeIcon :size="20" />
					</template>
					{{ t('music_radio', 'YouTube') }}
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
			</div>
		</header>

		<OnAir
			ref="onAir"
			:channel="channel"
			:playable-count="playableCount"
			@playlist-changed="loadTracks"
			@votes-changed="loadTracks"
			@on-air-changed="onAirTrackId = $event"
			@tuned-in-changed="onTunedInChanged" />

		<ImportQueue
			v-if="imports.length > 0"
			:imports="imports"
			@dismiss="onDismissImport" />

		<NcNoteCard
			v-if="heldCount > 0"
			type="info"
			data-testid="held-note"
			:text="n('music_radio',
				'%n track is waiting for you to let it play.',
				'%n tracks are waiting for you to let them play.',
				heldCount)" />

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
			:show-votes="votingEnabled"
			:can-vote="canVote"
			@vote="onVote"
			@reorder="onReorder"
			@remove="onRemove"
			@play="onPlayTrack"
			@preview="onPreview"
			@toggle-disabled="onToggleDisabled"
			@approve="onApprove" />

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
				<SharingPanel
					:channel="channel"
					:import-capabilities="importCapabilities"
					@rules-changed="loadTracks"
					@channel-gone="onChannelGone" />
			</div>
		</NcDialog>

		<ChannelDialog
			v-if="showSettings"
			:channel="channel"
			@close="showSettings = false"
			@saved="onSettingsSaved" />

		<YoutubeImportDialog
			v-if="showImport"
			:channel-id="channel.id"
			:capabilities="importCapabilities"
			@close="showImport = false"
			@started="onImportStarted" />
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
import YoutubeIcon from 'vue-material-design-icons/Youtube.vue'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'

import ChannelDialog from './ChannelDialog.vue'
import ImportQueue from './ImportQueue.vue'
import OnAir from './OnAir.vue'
import Playlist from './Playlist.vue'
import PreviewPlayer from './PreviewPlayer.vue'
import SharingPanel from './SharingPanel.vue'
import YoutubeImportDialog from './YoutubeImportDialog.vue'
import { ADD_TRACKS, MANAGE, SHARE, can } from '../utils/permissions.js'
import { addTracks, deleteChannel, deleteTrack, dismissImport, errorMessage, fetchImports, fetchTracks, reorderTracks, updateTrack, voteForTrack } from '../utils/api.js'
import { formatDuration, totalDuration } from '../utils/format.js'
import { measureDurations, pickAudioFiles } from '../utils/filePicker.js'

/**
 * How long a finished import stays on the list before tidying itself away.
 *
 * Long enough to notice and read, short enough that a session's worth of imports does not
 * pile up into a panel nobody asked for. Failures are exempt — see scheduleAutoDismiss.
 */
const AUTO_DISMISS_MS = 5000

export default {
	name: 'ChannelView',

	components: {
		ChannelDialog,
		DeleteIcon,
		ImportQueue,
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
		YoutubeIcon,
		YoutubeImportDialog,
	},

	props: {
		channel: {
			type: Object,
			required: true,
		},

		/**
		 * What the page said this server can import, before any request has been made.
		 * Replaced by whatever the imports endpoint reports on the first poll.
		 */
		initialImportCapabilities: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['updated', 'deleted'],

	data() {
		return {
			tracks: [],
			// Both come from the server with the playlist rather than being worked out
			// here: whether the channel allows voting, and whether this person may.
			votingEnabled: false,
			canVote: false,
			mayImport: false,
			loadingTracks: true,
			adding: false,
			showSettings: false,
			showSharing: false,
			onAirTrackId: null,
			tunedIn: false,
			previewTrack: null,
			// Imports outlive the dialog that starts them, so they are held here — closing
			// the dialog, or never opening it after a reload, must not lose track of one.
			imports: [],
			importCapabilities: this.initialImportCapabilities,
			showImport: false,
			importPoll: null,
			/** Import id → the timer that will clear it away. See scheduleAutoDismiss. */
			autoDismissTimers: {},
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

		/**
		 * Offered only when the server says this viewer may import *and* it can actually
		 * do it. A button that always fails is worse than no button.
		 *
		 * The permission half is not worked out here. Importing now depends on the channel's
		 * switch and on the share that let this viewer in, and deriving that in the page
		 * would be a second implementation of a rule the endpoint already applies — which
		 * is precisely how a button comes to promise what the server refuses. So the
		 * playlist payload answers it and this reads the answer; only the server's ability,
		 * which the payload does not carry, is added on top.
		 */
		canImport() {
			return this.mayImport && this.importCapabilities.available !== false
		},

		/**
		 * Tracks the server could not measure, and which therefore do not play.
		 *
		 * Counted on the *reason*, not on `playable`. Anything unplayable used to land in
		 * this number, so a track the owner had deliberately skipped was reported back to
		 * them as one whose length could not be read.
		 */
		pendingCount() {
			return this.tracks.filter((track) => !track.durationMs).length
		},

		/** Added by somebody else, waiting for the owner to let it play. */
		heldCount() {
			return this.tracks.filter((track) => track.awaitingApproval).length
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
		// Once on open, so an import started before a reload — or by somebody else — is
		// picked up rather than appearing from nowhere when it finishes.
		await this.refreshImports()

		// And again whenever this tab is returned to.
		//
		// What the server can do is read once, into the initial state, and then only
		// re-read while an import is running. That leaves a tab open across an
		// administrator switching YouTube import *on* permanently convinced it is off —
		// and unrecoverably so, because the button that would start an import is hidden by
		// the very flag that is wrong, so nothing ever asks again. Only a reload escaped
		// it, which is not something anyone would think to try.
		//
		// Coming back to the tab is the natural moment to ask: it is exactly when
		// something may have changed elsewhere, and it costs one request.
		this.capabilityHandler = () => {
			if (document.visibilityState === 'visible') {
				this.refreshImports()
			}
		}
		document.addEventListener('visibilitychange', this.capabilityHandler)
	},

	beforeUnmount() {
		this.stopImportPoll()
		this.clearAutoDismissTimers()
		if (this.capabilityHandler) {
			document.removeEventListener('visibilitychange', this.capabilityHandler)
			this.capabilityHandler = null
		}
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
				this.votingEnabled = data.votingEnabled === true
				this.canVote = data.canVote === true
				this.mayImport = data.canImport === true
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not load the playlist')))
			} finally {
				this.loadingTracks = false
			}
		},

		async addTracks() {
			let nodes
			try {
				// Dismissing the picker comes back as an empty array, not a rejection —
				// pickAudioFiles absorbs that. So anything caught here is a real fault and
				// is surfaced, rather than being guessed at from the message.
				nodes = await pickAudioFiles()
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not open the file picker')))
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

		// ----------------------------------------------------------- importing

		/**
		 * Pull the current state of this channel's imports.
		 *
		 * Also refreshes what the server says it can do, so an administrator installing
		 * yt-dlp mid-session makes the button appear without a reload.
		 */
		async refreshImports() {
			const previous = this.imports

			try {
				const { imports, capabilities } = await fetchImports(this.channel.id)
				this.imports = imports
				this.importCapabilities = capabilities
			} catch {
				// Deliberately quiet. This runs on a timer, and a toast every two seconds
				// because the network blipped would be worse than briefly stale progress.
				this.stopImportPoll()
				return
			}

			this.announceFinished(previous)
			this.scheduleAutoDismiss()
			this.syncImportPoll()
		},

		/**
		 * Clear finished imports off the list a few seconds after they land.
		 *
		 * The queue exists to say "this is coming"; once the track is on the playlist it has
		 * said it, and a row reading "Added to the playlist" for the rest of the session is
		 * a box that has to be tidied up by hand. The delay is what makes it an answer
		 * rather than a flicker — long enough to read, short enough not to accumulate.
		 *
		 * Only `done`. A failure has to stay: it is the only place the reason is written,
		 * and taking it away after five seconds would be taking away the explanation.
		 *
		 * Driven off the current list rather than off the transition, so an import already
		 * finished when the page loaded is tidied away too.
		 */
		scheduleAutoDismiss() {
			for (const item of this.imports) {
				if (item.status !== 'done' || this.autoDismissTimers[item.id] !== undefined) {
					continue
				}

				this.autoDismissTimers[item.id] = setTimeout(() => {
					delete this.autoDismissTimers[item.id]
					this.autoDismiss(item)
				}, AUTO_DISMISS_MS)
			}
		},

		/**
		 * Quietly, unlike the button: nobody asked for this one, so a toast explaining that
		 * tidying up did not work would be noise about something they were not doing.
		 *
		 * @param {object} item the finished import to clear away
		 */
		async autoDismiss(item) {
			this.imports = this.imports.filter((i) => i.id !== item.id)
			try {
				await dismissImport(this.channel.id, item.id)
			} catch {
				// It stays gone from the list either way; the next poll would bring it back
				// only if something else starts an import, by which time it is stale anyway.
			}
		},

		/** Timers outlive the component otherwise, and fire against a dead channel. */
		clearAutoDismissTimers() {
			for (const handle of Object.values(this.autoDismissTimers)) {
				clearTimeout(handle)
			}
			this.autoDismissTimers = {}
		},

		/**
		 * Say something when an import finishes, exactly once.
		 *
		 * Comparing against the previous snapshot rather than reacting to the current
		 * state is what stops a completed import announcing itself on every poll.
		 *
		 * @param {object[]} previous the imports as they were before this refresh
		 */
		announceFinished(previous) {
			const before = new Map(previous.map((item) => [item.id, item.status]))
			let landed = false

			for (const item of this.imports) {
				const was = before.get(item.id)
				if (was === undefined || was === item.status) {
					continue
				}

				if (item.status === 'done') {
					landed = true
					showSuccess(t('music_radio', 'Added “{title}” to the playlist', { title: item.title }))
				} else if (item.status === 'failed') {
					showError(item.error || t('music_radio', 'An import failed'))
				}
			}

			if (landed) {
				// The track exists now, so the playlist is out of date.
				this.loadTracks()
				this.emitTrackCount()
			}
		},

		/** Poll only while there is something to watch. */
		syncImportPoll() {
			if (this.imports.some((item) => item.active)) {
				this.startImportPoll()
			} else {
				this.stopImportPoll()
			}
		},

		startImportPoll() {
			if (this.importPoll !== null) {
				return
			}
			this.importPoll = setInterval(() => this.refreshImports(), 2000)
		},

		stopImportPoll() {
			if (this.importPoll !== null) {
				clearInterval(this.importPoll)
				this.importPoll = null
			}
		},

		/**
		 * @param {object} started the queued import the server just accepted
		 */
		onImportStarted(started) {
			// Shown immediately rather than waiting for the next poll, so pressing Add has
			// a visible effect straight away.
			this.imports = [started, ...this.imports]
			this.startImportPoll()
		},

		/**
		 * Stop a running import, or clear a finished one away.
		 *
		 * @param {object} item
		 */
		async onDismissImport(item) {
			try {
				await dismissImport(this.channel.id, item.id)
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not stop that import')))
				return
			}

			this.imports = this.imports.filter((existing) => existing.id !== item.id)
			this.syncImportPoll()
		},

		/**
		 * Let a held track into the rotation.
		 *
		 * @param {object} track
		 */
		async onApprove(track) {
			try {
				const updated = await updateTrack(this.channel.id, track.id, { approved: true })
				const index = this.tracks.findIndex((existing) => existing.id === track.id)
				if (index !== -1) {
					this.tracks.splice(index, 1, updated)
				}
				this.emitTrackCount()
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not approve that track')))
			}
		},

		/**
		 * Cast or withdraw a vote.
		 *
		 * The row is updated from the server's answer rather than guessed at, because the
		 * count includes everyone else's votes and this browser cannot know them. The
		 * playlist is deliberately *not* reloaded: casting a vote does not reorder
		 * anything by itself — the running order is recomputed on the server's own
		 * schedule, and the poll's playlistVersion is what tells this page it moved.
		 *
		 * @param {object} track the row that was pressed
		 */
		async onVote(track) {
			let result
			try {
				result = await voteForTrack(this.channel.id, track.id)
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Your vote could not be recorded')))
				return
			}

			const index = this.tracks.findIndex((existing) => existing.id === track.id)
			if (index !== -1) {
				this.tracks.splice(index, 1, { ...this.tracks[index], votes: result.votes, voted: result.voted })
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

		/**
		 * A switch in the share dialog changed the channel.
		 *
		 * The playlist is reloaded as well as the channel emitted upwards, because whether
		 * a row shows a vote control is decided by the tracks endpoint rather than by the
		 * channel — so without this, turning voting on left the rows unchanged until
		 * something else happened to refetch them.
		 *
		 * @param {object} channel the channel as saved
		 */
		/**
		 * The channel this view is showing is not there any more.
		 *
		 * Reported by the sharing panel, which is the first thing to find out — it is the
		 * only part of this view that keeps talking to the server about the channel itself.
		 * Closing the dialog and telling the app is what stops the page sitting on a
		 * channel that has been gone for some time.
		 */
		onChannelGone() {
			this.showSharing = false
			this.$emit('deleted', this.channel.id)
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
	padding: 3.2rem .5rem 3rem 2.2rem;
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
	gap: 0.3rem;
  overflow: auto;
}
</style>
