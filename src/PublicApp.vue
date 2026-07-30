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
		<header class="music-radio-public__header">
			<div class="music-radio-public__heading">
				<h1 class="music-radio-public__title" data-testid="public-channel-title">
					{{ channel.title }}
				</h1>
				<p v-if="channel.description" class="music-radio-public__description">
					{{ channel.description }}
				</p>
			</div>

			<div v-if="canUpload" class="music-radio-public__actions">
				<NcButton
					variant="primary"
					data-testid="public-upload-open"
					@click="showUpload = true">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
					{{ t('music_radio', 'Add a track') }}
				</NcButton>
			</div>
		</header>

		<OnAir
			:channel="channel"
			:public-token="token"
			:playable-count="playableCount"
			@playlist-changed="loadTracks" />

		<section v-if="tracks.length > 0" class="music-radio-public__playlist">
			<h2 class="music-radio-public__subheading">
				{{ t('music_radio', 'On this channel') }}
			</h2>
			<ol class="music-radio-public__list" data-testid="public-playlist">
				<li v-for="(track, index) in tracks" :key="track.id" class="music-radio-public__track">
					<span class="music-radio-public__index">{{ index + 1 }}</span>
					<span class="music-radio-public__track-title">{{ track.title }}</span>
					<span v-if="track.artist" class="music-radio-public__track-artist">{{ track.artist }}</span>
					<span class="music-radio-public__track-duration">{{ formatDuration(track.durationMs) }}</span>
				</li>
			</ol>
		</section>

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

import GlobalPlayer from './components/GlobalPlayer.vue'
import OnAir from './components/OnAir.vue'
import PublicUpload from './components/PublicUpload.vue'
import { formatDuration } from './utils/format.js'
import { tracksUrl } from './utils/api.js'
import { ADD_TRACKS, can } from './utils/permissions.js'

export default {
	name: 'PublicApp',

	components: {
		GlobalPlayer,
		NcButton,
		OnAir,
		PlusIcon,
		PublicUpload,
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
			showUpload: false,
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
	},

	methods: {
		formatDuration,

		async loadTracks() {
			try {
				const { data } = await axios.get(tracksUrl(this.channel.id, this.token))
				this.tracks = data.tracks
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
	max-inline-size: 48rem;
	margin-inline: auto;
	padding: 1.5rem 1rem 0;

	/*
	 * A public page is a fixed-height shell: core's `#content` is `position: fixed` and
	 * the document never grows, so the window has nothing to scroll. Anything past the
	 * fold — a long playlist, or the upload panel once the player is on air — is simply
	 * cut off unless the app scrolls inside that shell itself.
	 */
	block-size: 100%;
	overflow-y: auto;
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

.music-radio-public__title {
	margin: 0;
	font-size: 1.75rem;
	overflow-wrap: anywhere;
}

.music-radio-public__description {
	margin: 0.5rem 0 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.music-radio-public__subheading {
	font-size: 1rem;
	margin: 0 0 0.5rem;
	color: var(--color-text-maxcontrast);
}

.music-radio-public__list {
	list-style: none;
	margin: 0;
	padding: 0;
	border-top: 1px solid var(--color-border);
}

.music-radio-public__track {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.5rem 0.25rem;
	border-bottom: 1px solid var(--color-border);
}

.music-radio-public__index {
	inline-size: 2rem;
	text-align: end;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	flex: none;
}

.music-radio-public__track-title {
	flex: 1 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-public__track-artist {
	flex: 0 1 auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-public__track-duration {
	flex: none;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}
</style>
