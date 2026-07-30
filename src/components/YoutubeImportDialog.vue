<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Asking the server to fetch a track from a YouTube link.
  -
  - Only a form. The import itself outlives this dialog — it takes tens of seconds and
  - people close things — so the queue and its polling live in ChannelView, and closing
  - this while something is downloading does not interrupt it.
-->
<template>
	<NcDialog
		:name="t('music_radio', 'Add from YouTube')"
		:buttons="buttons"
		size="normal"
		@closing="$emit('close')">
		<!-- The test hook lives inside the dialog, on an element this component owns:
		     NcDialog does not pass attributes through to what it renders. -->
		<div data-testid="youtube-import-dialog">
		<!--
			When the server cannot do this at all, say so instead of showing a field that
			leads nowhere. The reason comes from the server, so it names the actual missing
			piece rather than guessing.
		-->
		<NcNoteCard
			v-if="!available"
			type="error"
			data-testid="youtube-import-unavailable"
			:text="unavailableText" />

		<form v-else class="music-radio-import" @submit.prevent="submit">
			<!-- The hook is on a wrapper rather than on NcTextField, which does not put
			     attributes where a test would look for them. -->
			<div data-testid="youtube-import-url">
				<NcTextField
					ref="urlField"
					v-model="url"
					:label="t('music_radio', 'YouTube link')"
					placeholder="https://www.youtube.com/watch?v=…"
					:error="error !== ''"
					:helper-text="error"
					@update:model-value="error = ''" />
			</div>

			<p class="music-radio-import__hint">
				{{ t('music_radio', 'The audio is saved as an MP3 in the channel owner\'s music folder and added to the end of the playlist. It counts against their storage.') }}
			</p>

			<NcNoteCard
				v-if="outdated"
				type="warning"
				:text="t('music_radio', 'The downloader on this server is out of date, so some videos may fail. An administrator can update it.')" />
		</form>
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { errorMessage, startImport } from '../utils/api.js'
import { isYoutubeLink } from '../utils/youtube.js'

export default {
	name: 'YoutubeImportDialog',

	components: {
		NcDialog,
		NcNoteCard,
		NcTextField,
	},

	props: {
		channelId: {
			type: Number,
			required: true,
		},

		/** What the server said it can do, from the imports endpoint. */
		capabilities: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['close', 'started'],

	data() {
		return {
			url: '',
			error: '',
			submitting: false,
		}
	},

	computed: {
		available() {
			return this.capabilities.available !== false
		},

		outdated() {
			return this.capabilities.outdated === true
		},

		unavailableText() {
			return this.capabilities.reasonText
				|| t('music_radio', 'YouTube import is not set up on this server.')
		},

		buttons() {
			return [
				{
					label: t('music_radio', 'Cancel'),
					callback: () => this.$emit('close'),
				},
				{
					label: t('music_radio', 'Add'),
					variant: 'primary',
					disabled: !this.available || this.submitting || this.url.trim() === '',
					callback: () => this.submit(),
				},
			]
		},
	},

	mounted() {
		// Pasting a link is the only thing to do here.
		this.$refs.urlField?.focus?.()
	},

	methods: {
		/**
		 * @return {Promise<false|undefined>} false to keep the dialog open. NcDialog closes
		 *   on any button click unless the callback returns exactly false — so every path
		 *   that leaves a message in the field has to say so, or the dialog would vanish
		 *   taking the message and the typed link with it.
		 */
		async submit() {
			const url = this.url.trim()
			if (url === '') {
				return false
			}

			// Checked here as well as on the server, purely so an obvious typo is answered
			// instantly and in the field rather than as a toast a moment later. The server
			// remains the one that decides.
			if (!isYoutubeLink(url)) {
				this.error = t('music_radio', 'That does not look like a YouTube video link.')
				return false
			}

			this.submitting = true
			try {
				this.$emit('started', await startImport(this.channelId, url))
				this.$emit('close')
			} catch (error) {
				// Shown in the field, not as a toast: every refusal here is about the link
				// that is still sitting in it, and this way it can be corrected in place.
				this.error = errorMessage(error, t('music_radio', 'That link could not be added'))
				return false
			} finally {
				this.submitting = false
			}

			return undefined
		},
	},
}
</script>

<style scoped>
.music-radio-import {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding-block: 0.5rem;
}

.music-radio-import__hint {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
