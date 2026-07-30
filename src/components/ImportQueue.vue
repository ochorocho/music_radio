<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - What is currently being fetched, and what recently was.
  -
  - Sits above the playlist because an import is a track that is not there yet: showing it
  - anywhere else would leave someone who pasted a link with no evidence anything happened.
  -
  - Note there is no percentage outside the download. yt-dlp reports progress while it is
  - fetching and ffmpeg reports nothing at all while it transcodes, so a bar would sit at
  - 100% for the last stretch of every import and read as stuck. The phase is named
  - instead, and the bar goes indeterminate.
-->
<template>
	<section class="music-radio-imports" data-testid="import-queue">
		<h3 class="music-radio-imports__heading">
			{{ t('music_radio', 'Importing') }}
		</h3>

		<ul class="music-radio-imports__list">
			<li
				v-for="item in imports"
				:key="item.id"
				class="music-radio-imports__item"
				data-testid="import-row"
				:data-import-status="item.status">
				<div class="music-radio-imports__text">
					<span class="music-radio-imports__title" :title="item.title">
						{{ item.title }}
					</span>
					<span
						class="music-radio-imports__state"
						:class="{ 'music-radio-imports__state--error': item.status === 'failed' }"
						data-testid="import-state">
						{{ describe(item) }}
					</span>
				</div>

				<!--
					A bar only while there is a real number behind it. NcProgressBar has no
					indeterminate mode and treats a missing value as 0, so using it during
					the transcode would show an empty bar for the last stretch of every
					import — the same "nothing is happening" impression as a bar stuck at
					100%. A spinner says "working" without claiming to know how far along.
				-->
				<NcProgressBar
					v-if="item.active && item.showProgress"
					class="music-radio-imports__bar"
					:value="item.progress"
					size="medium" />
				<NcLoadingIcon
					v-else-if="item.active"
					class="music-radio-imports__bar"
					:size="20" />
				<span v-else class="music-radio-imports__bar" />

				<NcButton
					variant="tertiary"
					:aria-label="item.active
						? t('music_radio', 'Stop this import')
						: t('music_radio', 'Clear this from the list')"
					data-testid="import-dismiss"
					@click="$emit('dismiss', item)">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import CloseIcon from 'vue-material-design-icons/Close.vue'

export default {
	name: 'ImportQueue',

	components: {
		CloseIcon,
		NcButton,
		NcLoadingIcon,
		NcProgressBar,
	},

	props: {
		imports: {
			type: Array,
			required: true,
		},
	},

	emits: ['dismiss'],

	methods: {
		/**
		 * What this import is doing, in words.
		 *
		 * The server has already turned any error code into a sentence, so a failure just
		 * shows what it said.
		 *
		 * @param {object} item an import as the server returned it
		 * @return {string}
		 */
		describe(item) {
			if (item.status === 'failed') {
				return item.error || t('music_radio', 'The import failed.')
			}
			if (item.status === 'cancelled') {
				return t('music_radio', 'Stopped')
			}
			if (item.status === 'done') {
				return t('music_radio', 'Added to the playlist')
			}

			switch (item.phase) {
			case 'resolving':
				return t('music_radio', 'Looking it up…')
			case 'downloading':
				return t('music_radio', 'Downloading… {percent}%', { percent: item.progress })
			case 'converting':
				return t('music_radio', 'Converting to MP3…')
			case 'saving':
				return t('music_radio', 'Saving…')
			default:
				return t('music_radio', 'Waiting to start…')
			}
		},
	},
}
</script>

<style scoped>
.music-radio-imports {
	margin-block: 1rem;
	padding: 0.75rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
}

.music-radio-imports__heading {
	margin: 0 0 0.5rem;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.music-radio-imports__list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.music-radio-imports__item {
	display: grid;
	/* The bar takes a fixed share so rows do not jump about as titles arrive: the title is
	   the video id until the lookup finishes, then suddenly much longer. */
	grid-template-columns: minmax(0, 1fr) 8rem auto;
	align-items: center;
	gap: 0.75rem;
}

.music-radio-imports__text {
	display: flex;
	flex-direction: column;
	min-inline-size: 0;
}

.music-radio-imports__title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-imports__state {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.music-radio-imports__state--error {
	color: var(--color-error-text);
}

@media (max-width: 600px) {
	.music-radio-imports__item {
		grid-template-columns: minmax(0, 1fr) auto;
	}

	.music-radio-imports__bar {
		grid-column: 1 / -1;
	}
}
</style>
