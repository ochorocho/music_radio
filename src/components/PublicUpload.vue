<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Putting a track on the channel from the public page.
  -
  - Only rendered when the link its visitor followed says uploading is allowed; the server
  - checks the same thing again on every request. Whoever uploads has no account, so there
  - is no library to pick from — a file off their device is the only thing they can offer.
-->
<template>
	<section class="music-radio-upload">
		<h2 class="music-radio-upload__heading">
			{{ t('music_radio', 'Add a track') }}
		</h2>

		<p class="music-radio-upload__hint">
			{{ t('music_radio', 'Anyone listening will hear it. It joins the end of the playlist and cannot be taken back off.') }}
		</p>

		<label class="music-radio-upload__label" :for="inputId">
			{{ t('music_radio', 'Choose an audio file') }}
		</label>
		<input
			:id="inputId"
			ref="input"
			class="music-radio-upload__input"
			type="file"
			accept="audio/*"
			:disabled="uploading"
			data-testid="public-upload-input"
			@change="onPick">

		<NcButton
			variant="primary"
			:disabled="uploading || file === null"
			data-testid="public-upload-submit"
			@click="upload">
			{{ uploading
				? t('music_radio', 'Uploading…')
				: t('music_radio', 'Add to the channel') }}
		</NcButton>

		<!--
			Assertive rather than polite: the outcome is the whole point of pressing the
			button, and a failure needs to interrupt rather than queue behind whatever
			the player is announcing.
		-->
		<p
			v-if="message !== ''"
			class="music-radio-upload__message"
			:class="{ 'music-radio-upload__message--error': failed }"
			role="alert"
			data-testid="public-upload-message">
			{{ message }}
		</p>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

import { errorMessage, uploadToPublicChannel } from '../utils/api.js'

export default {
	name: 'PublicUpload',

	components: {
		NcButton,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	emits: ['uploaded'],

	data() {
		return {
			file: null,
			uploading: false,
			message: '',
			failed: false,
		}
	},

	computed: {
		/** The page can only ever show one of these, but an id must still be unique. */
		inputId() {
			return 'music-radio-upload-input'
		},
	},

	methods: {
		onPick(event) {
			this.file = event.target.files?.[0] ?? null
			this.message = ''
			this.failed = false
		},

		async upload() {
			if (this.file === null) {
				return
			}

			this.uploading = true
			this.message = ''
			this.failed = false

			try {
				const track = await uploadToPublicChannel(this.token, this.file)
				this.message = t('music_radio', 'Added “{title}” to the channel', {
					title: track.title ?? this.file.name,
				})
				this.file = null
				// The input keeps showing the old filename otherwise, which reads as if
				// the upload had not gone through.
				if (this.$refs.input) {
					this.$refs.input.value = ''
				}
				this.$emit('uploaded')
			} catch (error) {
				this.failed = true
				this.message = errorMessage(error, t('music_radio', 'That file could not be added'))
			} finally {
				this.uploading = false
			}
		},
	},
}
</script>

<style scoped>
.music-radio-upload {
	display: flex;
	flex-direction: column;
	align-items: start;
	gap: 0.5rem;
	margin-block-start: 1.5rem;
	padding-block-start: 1rem;
	border-top: 1px solid var(--color-border);
}

.music-radio-upload__heading {
	font-size: 1rem;
	margin: 0;
}

.music-radio-upload__hint,
.music-radio-upload__label {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.music-radio-upload__input {
	max-inline-size: 100%;
}

.music-radio-upload__message {
	margin: 0;
	font-size: 0.9em;
}

.music-radio-upload__message--error {
	color: var(--color-error-text);
}
</style>
