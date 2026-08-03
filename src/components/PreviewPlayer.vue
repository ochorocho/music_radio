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
	<!--
		Hidden rather than unmounted, and that is the whole point of the shape of this
		component.

		iOS grants playback to a play() call made inside a user gesture, and it grants it
		to *that element* — so the element has to already exist when the gesture happens.
		A `v-if` here built a fresh <audio> on the tick after the click, which is a tick
		too late: the `autoplay` attribute it used to carry was refused every time, and
		the listener had to press the native play control. That was a second tap for the
		same reason the playlist rows needed one.
	-->
	<div v-show="track" class="music-radio-preview" data-testid="preview-player">
		<div class="music-radio-preview__text">
			<span class="music-radio-preview__label">{{ t('music_radio', 'Just for you') }}</span>
			<span class="music-radio-preview__title" data-testid="preview-title">{{ track ? track.title : '' }}</span>
		</div>

		<!-- The source is set by play() rather than bound, because binding it would land a
		     render later — after the gesture — and because the element is reused. -->
		<!-- eslint-disable-next-line vuejs-accessibility/media-has-caption -->
		<audio
			ref="audio"
			class="music-radio-preview__audio"
			controls
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
		/** Null while nothing is being previewed, which is when this bar is hidden. */
		track: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	watch: {
		/**
		 * Whoever cleared the track wants the sound to stop — tuning in, removing the
		 * track, or pressing the close button. Stopping needs no gesture, so unlike
		 * starting it can safely happen here rather than in the click.
		 */
		track(value) {
			if (!value) {
				this.stop()
			}
		},
	},

	methods: {
		/**
		 * Start a track.
		 *
		 * **Must be called from the click that asked for it.** The element is reused and
		 * outlives any one preview, so once this has succeeded the browser will let it
		 * play again later; the first call is the one that has to be in a gesture.
		 *
		 * @param {object} track the track to play
		 */
		play(track) {
			const audio = this.$refs.audio
			if (!audio) {
				return
			}

			audio.src = streamUrl(this.channelId, track.id)
			audio.play().catch((error) => {
				// A refusal leaves the native controls sitting there ready to be pressed,
				// which is the same place the listener was before — no worse, and there is
				// nothing useful to say about it that the controls do not already show.
				if (error?.name === 'NotAllowedError' || error?.name === 'AbortError') {
					return
				}
				showError(t('music_radio', 'Could not play “{title}”.', { title: track.title }))
				this.$emit('close')
			})
		},

		/** Silence the element and let go of the file, without discarding the element. */
		stop() {
			const audio = this.$refs.audio
			if (!audio || !audio.hasAttribute('src')) {
				return
			}
			audio.pause()
			audio.removeAttribute('src')
			audio.load()
		},

		onError() {
			// Nothing is loaded between previews, and tearing the source down in stop()
			// is itself reported as an error by some engines. Only a failure while
			// something is meant to be playing is a failure worth showing.
			if (!this.track) {
				return
			}
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
