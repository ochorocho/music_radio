<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - What someone sees when they open a shared link without an account.
  -
  - The same synchronised player as the signed-in view, addressing the token endpoints
  - instead: an anonymous listener hears exactly what everyone else hears, at the same
  - moment. They can never control the broadcast, and can only contribute a track if the
  - owner turned uploading on for the link they followed.
-->
<template>
	<div class="music-radio-public">
		<div class="music-radio-public__inner">
			<header class="music-radio-public__header">
				<div class="music-radio-public__heading">
					<h1 class="music-radio-public__title" data-testid="public-channel-title">
						{{ channel.title }}
					</h1>
					<p v-if="channel.description" class="music-radio-public__description">
						{{ channel.description }}
					</p>
				</div>

				<div v-if="canUpload || canImport" class="music-radio-public__actions">
					<NcButton
						v-if="canUpload"
						variant="primary"
						data-testid="public-upload-open"
						@click="showUpload = true">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('music_radio', 'Add a track') }}
					</NcButton>

					<!--
						Only when the server says so. Three switches have to agree — the
						administrator's, the channel's and this link's — and the answer comes
						from the server rather than being worked out here, so the button
						cannot offer what the endpoint will refuse.
					-->
					<NcButton
						v-if="canImport"
						data-testid="public-add-youtube"
						:aria-label="t('music_radio', 'Add a track from a YouTube link')"
						@click="showImport = true">
						<template #icon>
							<YoutubeIcon :size="20" />
						</template>
						{{ t('music_radio', 'From YouTube') }}
					</NcButton>
				</div>
			</header>

			<ImportQueue
				v-if="imports.length > 0"
				:imports="imports"
				@dismiss="onDismissImport" />

			<OnAir
				ref="onAir"
				:channel="channel"
				:public-token="token"
				:playable-count="playableCount"
				@playlist-changed="loadTracks"
				@votes-changed="loadTracks"
				@on-air-changed="onAirTrackId = $event" />

			<!--
				The same playlist component as the signed-in view, rather than a second
				hand-rolled list beside it. A link can now be granted control and curation,
				which means drag-to-reorder, keyboard move up/down, drop indicators and a
				per-row play button — all of which already exist here, and none of which is
				worth writing twice.

				`curation-available` is the one thing held back: skipping a track and letting
				a held one play go through an endpoint with no anonymous counterpart, and
				approving is the owner's review of what strangers uploaded.
			-->
			<section v-if="tracks.length > 0" class="music-radio-public__playlist">
				<h2 class="music-radio-public__subheading">
					{{ t('music_radio', 'On this channel') }}
				</h2>
				<Playlist
					:channel="channel"
					:tracks="tracks"
					:on-air-track-id="onAirTrackId"
					:show-votes="votingEnabled"
					:can-vote="canVote"
					:curation-available="false"
					@vote="vote"
					@reorder="onReorder"
					@remove="removeTrack"
					@play="onPlayTrack" />
			</section>

		</div>

		<!-- Outside the scrolling column: it is a dialog, teleported to the body, and has
		     no business being laid out as page content. -->
		<YoutubeImportDialog
			v-if="showImport"
			:channel-id="channel.id"
			:public-token="token"
			:capabilities="importCapabilities"
			@close="showImport = false"
			@started="onImportStarted" />

		<PublicUpload
			v-if="canUpload && showUpload"
			:token="token"
			@uploaded="loadTracks"
			@close="showUpload = false" />

		<!-- Renders nothing; it is what actually plays the audio. The signed-in app
		     mounts one too, from App.vue. -->
		<GlobalPlayer />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'

import NcButton from '@nextcloud/vue/components/NcButton'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import YoutubeIcon from 'vue-material-design-icons/Youtube.vue'
import { showError } from '@nextcloud/dialogs'

import GlobalPlayer from './components/GlobalPlayer.vue'
import ImportQueue from './components/ImportQueue.vue'
import OnAir from './components/OnAir.vue'
import Playlist from './components/Playlist.vue'
import PublicUpload from './components/PublicUpload.vue'
import YoutubeImportDialog from './components/YoutubeImportDialog.vue'
import { dismissImport, errorMessage, fetchImports, removeFromPublicChannel, reorderTracks, tracksUrl, voteOnPublicChannel } from './utils/api.js'
import { ADD_TRACKS, can } from './utils/permissions.js'

/** How long a finished import stays on the list. Mirrors ChannelView. */
const AUTO_DISMISS_MS = 5000

export default {
	name: 'PublicApp',

	components: {
		GlobalPlayer,
		ImportQueue,
		NcButton,
		OnAir,
		Playlist,
		PlusIcon,
		PublicUpload,
		YoutubeIcon,
		YoutubeImportDialog,
	},

	data() {
		let initial = { token: null, channel: { id: 0, title: '', description: null, permissions: 1 } }
		try {
			initial = loadState('music_radio', 'music_radio-initial-state')
		} catch (error) {
			// Nothing to render without it; the empty defaults keep the page from throwing.
		}

		return {
			token: initial.token,
			channel: initial.channel,
			tracks: [],
			// Which row to mark. The player knows this and says so; the list does not work
			// it out for itself, because the current track is derived from the timeline
			// rather than stored anywhere the page can read.
			onAirTrackId: null,
			votingEnabled: false,
			canVote: false,
			// Whether this link may fetch from YouTube. Answered by the server, for the
			// reason given beside the button.
			canImport: false,
			showUpload: false,
			showImport: false,
			// Imports outlive the dialog that starts them, so they are held here.
			imports: [],
			importCapabilities: {},
			importPoll: null,
			/** Import id → the timer that will clear it away. See scheduleAutoDismiss. */
			autoDismissTimers: {},
		}
	},

	computed: {
		playableCount() {
			return this.tracks.filter((track) => track.playable).length
		},

		/** Off unless the owner switched uploading on for this particular link. */
		canUpload() {
			return can(this.channel.permissions, ADD_TRACKS)
		},
	},

	async mounted() {
		await this.loadTracks()
		await this.refreshImports()
	},

	beforeUnmount() {
		this.stopImportPoll()
		this.clearAutoDismissTimers()
	},

	methods: {
		/**
		 * Imports in flight, polled while any is running.
		 *
		 * A cut-down version of the signed-in view's: a link visitor sees the queue and can
		 * stop what they started, but there is no finished-import announcement — the track
		 * simply appears in the list, which on this page is the whole of the interface.
		 */
		async refreshImports() {
			if (!this.canImport) {
				return
			}

			let landed = false
			try {
				const before = new Map(this.imports.map((item) => [item.id, item.status]))
				const { imports, capabilities } = await fetchImports(this.channel.id, this.token)
				landed = imports.some((item) => item.status === 'done' && before.get(item.id) !== 'done')
				this.imports = imports
				this.importCapabilities = capabilities
			} catch {
				// Quiet on purpose: this runs on a timer, and a message every two seconds
				// because the network blipped would be worse than briefly stale progress.
				this.stopImportPoll()
				return
			}

			if (landed) {
				await this.loadTracks()
			}

			this.scheduleAutoDismiss()

			if (this.imports.some((item) => item.active)) {
				this.startImportPoll()
			} else {
				this.stopImportPoll()
			}
		},

		/**
		 * Clear finished imports off the list a few seconds after they land.
		 *
		 * The same reasoning as the signed-in view: the queue exists to say "this is
		 * coming", and once the track is in the playlist below it has said it. A failure
		 * stays, because the row is the only place its reason is written.
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
		 * Quietly, unlike the button beside it: nobody asked for this one.
		 *
		 * @param {object} item the finished import to clear away
		 */
		async autoDismiss(item) {
			this.imports = this.imports.filter((i) => i.id !== item.id)
			try {
				await dismissImport(this.channel.id, item.id, this.token)
			} catch {
				// Gone from the list either way.
			}
		},

		clearAutoDismissTimers() {
			for (const handle of Object.values(this.autoDismissTimers)) {
				clearTimeout(handle)
			}
			this.autoDismissTimers = {}
		},

		startImportPoll() {
			if (this.importPoll === null) {
				this.importPoll = setInterval(() => this.refreshImports(), 2000)
			}
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
			this.imports = [started, ...this.imports.filter((item) => item.id !== started.id)]
			this.startImportPoll()
		},

		/**
		 * @param {object} item the import to stop or clear away
		 */
		async onDismissImport(item) {
			try {
				await dismissImport(this.channel.id, item.id, this.token)
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'That import could not be stopped')))
				return
			}

			await this.refreshImports()
		},

		/**
		 * Rewrite the running order, from a link its owner granted curation to.
		 *
		 * Applied locally first and rolled back on refusal, matching the signed-in view: a
		 * drag that snaps back a moment later reads as "that did not take", whereas a row
		 * that does not move at all reads as a broken drag.
		 *
		 * @param {number[]} trackIds the whole playlist in its new order
		 */
		async onReorder(trackIds) {
			const previous = this.tracks
			const byId = new Map(this.tracks.map((track) => [track.id, track]))
			this.tracks = trackIds.map((id) => byId.get(id)).filter(Boolean)

			try {
				this.tracks = await reorderTracks(this.channel.id, trackIds, this.token)
			} catch (error) {
				this.tracks = previous
				showError(errorMessage(error, t('music_radio', 'The playlist could not be reordered')))
			}
		},

		/**
		 * Jump the broadcast to a track. Goes through the player rather than straight to the
		 * endpoint, so the state that comes back is applied to the audio as well as the page.
		 *
		 * @param {object} track the row that was pressed
		 */
		onPlayTrack(track) {
			this.$refs.onAir?.sendControl('jumpTo', { trackId: track.id })
		},

		/**
		 * @param {object} track the row that was pressed
		 */
		async vote(track) {
			let result
			try {
				result = await voteOnPublicChannel(this.token, track.id)
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Your vote could not be recorded')))
				return
			}

			const index = this.tracks.findIndex((existing) => existing.id === track.id)
			if (index !== -1) {
				this.tracks.splice(index, 1, { ...this.tracks[index], votes: result.votes, voted: result.voted })
			}
		},

		/**
		 * @param {object} track a row the server said this visitor may take off the channel
		 */
		async removeTrack(track) {
			try {
				await removeFromPublicChannel(this.token, track.id)
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'That track could not be removed')))
				return
			}

			await this.loadTracks()
		},

		async loadTracks() {
			try {
				const { data } = await axios.get(tracksUrl(this.channel.id, this.token))
				this.tracks = data.tracks
				this.votingEnabled = data.votingEnabled === true
				this.canVote = data.canVote === true
				this.canImport = data.canImport === true
			} catch (error) {
				// A failure here means the link has been revoked or has expired. Core
				// answers with its own HTML page rather than JSON, so there is nothing
				// useful to read out of the response — just stop showing a playlist.
				this.tracks = []
			}
		},
	},
}
</script>

<!--
  - Not scoped: this reaches out to core's own public-page wrapper.
  -
  - `#content` reserves a strip at the bottom for the `guest-box` footer. This page turns
  - that footer off (see ChannelPublicController), so the reservation is just dead space
  - under the player.
-->
<style>
#body-public #content {
	padding-block-end: 0;
}
</style>

<style scoped>
.music-radio-public {
	/*
	 * The scroller, and nothing else.
	 *
	 * A public page is a fixed-height shell: core's `#content` is `position: fixed` and
	 * the document never grows, so the window has nothing to scroll. Anything past the
	 * fold — a long playlist, or the player once it is on air — is simply cut off unless
	 * the app scrolls inside that shell itself.
	 *
	 * Deliberately full width. This element used to be the centred column as well, which
	 * put its scrollbar at the right-hand edge of a 48rem column — a scrollbar floating in
	 * the middle of a wide window, where nobody expects one. Separating the two puts it
	 * back where it belongs, at the edge of the viewport.
	 */
	inline-size: 100%;
	block-size: 100%;
	overflow-y: auto;
}

.music-radio-public__inner {
	max-inline-size: 48rem;
	margin-inline: auto;
	padding: 1.8rem 1rem 3rem;
}

.music-radio-public__header {
	/* The same arrangement as the signed-in channel header: the text takes the room it
	   needs and the actions sit beside it, wrapping underneath when there is not enough. */
	display: flex;
	align-items: flex-start;
	gap: 1rem;
	flex-wrap: wrap;
	margin-block-end: 1.5rem;
}

.music-radio-public__heading {
	flex: 1 1 20rem;
	min-width: 0;
}

.music-radio-public__actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

/*
 * The header sits directly on core's guest background, which is a saturated blue. The
 * default text colour is near-black and is unreadable on it — the secondary colour more
 * so, at barely over 1:1. Everything on that background therefore states its own colour
 * rather than inheriting one meant for a white page.
 *
 * `--color-background-plain-text`, not `--color-primary-element-text`. The two are both
 * white in the light theme, which is how the wrong one survived: in the dark theme
 * `--color-primary-element-text` flips to #000, because there the primary *element* is
 * painted light and its text has to be dark. The guest background is not a primary
 * element and stays blue in both themes, so the title turned black on blue in dark mode
 * while looking perfect in light mode. This variable is the one that means "text on the
 * plain background", and it is #fff in both.
 */
.music-radio-public__title {
	margin: 0;
	font-size: 1.75rem;
	color: var(--color-background-plain-text, #fff);
	overflow-wrap: anywhere;
}

.music-radio-public__description {
	margin: 0.5rem 0 0;
	color: var(--color-background-plain-text, #fff);
	opacity: 0.85;
	overflow-wrap: anywhere;
}

/*
 * A surface of its own.
 *
 * The rows were transparent, sitting straight on core's saturated blue guest background:
 * track titles were near-black on blue at about 2.7:1, and the artist names mid-grey on
 * blue at about 1.1:1 — which is to say invisible. Nothing was wrong with the colours
 * themselves; they are the app's ordinary text colours, and they assume the ordinary
 * background. Giving the list that background is what makes them mean what they say.
 */
.music-radio-public__playlist {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 0.5rem);
	padding: 1rem;
}

.music-radio-public__subheading {
	font-size: 1rem;
	margin: 0 0 0.5rem 0.5rem;
	color: var(--color-text-maxcontrast);
}

</style>
