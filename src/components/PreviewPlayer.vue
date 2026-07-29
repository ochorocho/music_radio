<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Listening to one track on your own, without touching the broadcast.
  -
  - Only ever offered while not tuned in. That is the whole rule that keeps the earlier
  - problem from coming back: a private player and the channel could otherwise be audible
  - at the same time, so tuning in closes this and previewing is not offered while
  - listening.
-->
<template>
	<div class="music-radio-preview" data-testid="preview-player">
		<div class="music-radio-preview__text">
			<span class="music-radio-preview__label">{{ t('music_radio', 'Just for you') }}</span>
			<span class="music-radio-preview__title" data-testid="preview-title">{{ track.title }}</span>
		</div>

		<!-- eslint-disable-next-line vuejs-accessibility/media-has-caption -->
		<audio
			ref="audio"
			class="music-radio-preview__audio"
			:src="src"
			controls
			autoplay
			preload="metadata"
			data-testid="preview-audio"
			@ended="$emit('close')"
			@error="onError" />

		<NcButton
			variant="tertiary"
			:aria-label="t('music_radio', 'Stop')"
			data-testid="preview-close"
			@click="$emit('close')">
			<template #icon>
				<CloseIcon :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { showError } from '@nextcloud/dialogs'

import { streamUrl } from '../utils/api.js'

export default {
	name: 'PreviewPlayer',

	components: {
		CloseIcon,
		NcButton,
	},

	props: {
		channelId: {
			type: Number,
			required: true,
		},
		track: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	computed: {
		src() {
			return streamUrl(this.channelId, this.track.id)
		},
	},

	methods: {
		onError() {
			// Most likely the file has been moved or deleted; the server marks such a
			// track unavailable the next time it tries to serve it.
			showError(t('music_radio', 'Could not play “{title}”.', { title: this.track.title }))
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.music-radio-preview {
	position: sticky;
	bottom: 0;
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 0.75rem 1rem;
	margin-top: 1rem;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 0.5rem);
	box-shadow: 0 0 10px var(--color-box-shadow);
}

.music-radio-preview__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 0 1 14rem;
}

.music-radio-preview__label {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.music-radio-preview__title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-preview__audio {
	flex: 1 1 auto;
	min-width: 0;
	block-size: 2.25rem;
}
</style>
