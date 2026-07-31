<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Where this person's music lands.
  -
  - The folder is chosen, not typed. It was both for a while, and the text field caused more
  - trouble than it solved: it let somebody name a folder that did not exist, or one four
  - levels deep that the sanitiser would silently refuse, and the only feedback was an error
  - after pressing Save. A picker can only produce a real folder, so the whole class of
  - mistake goes away — and the server enforces the same rule, because the endpoint is
  - reachable without this page.
  -
  - The path is therefore shown, not edited.
-->
<template>
	<NcSettingsSection
		:name="t('music_radio', 'Music Radio')"
		:description="t('music_radio', 'Where music lands when it is added to one of your channels by upload or by import.')">
		<div class="music-radio-settings__folder">
			<span :id="labelId" class="music-radio-settings__label">
				{{ t('music_radio', 'Music folder') }}
			</span>

			<div class="music-radio-settings__row">
				<!--
					A read-only presentation of the stored value, not a disabled input: a
					disabled field reads as "you may not change this", which is wrong — it is
					changed with the button beside it.
				-->
				<span
					class="music-radio-settings__path"
					:class="{ 'music-radio-settings__path--error': Boolean(errors.download_folder) }"
					data-testid="setting-music-folder">
					<FolderIcon :size="20" class="music-radio-settings__path-icon" />
					<span class="music-radio-settings__path-text">{{ values.download_folder }}</span>
				</span>

				<NcButton
					variant="secondary"
					class="music-radio-settings__choose"
					:aria-describedby="labelId"
					data-testid="pick-music-folder"
					@click="pick">
					{{ t('music_radio', 'Choose folder') }}
				</NcButton>
			</div>

			<p
				v-if="errors.download_folder"
				class="music-radio-settings__hint music-radio-settings__hint--error"
				role="alert"
				data-testid="setting-music-folder-error">
				{{ errors.download_folder }}
			</p>
			<p v-else class="music-radio-settings__hint">
				{{ t('music_radio', 'Any folder that already exists in your files, at any depth.') }}
			</p>
		</div>

		<div class="music-radio-settings__actions">
			<NcButton
				variant="primary"
				:disabled="saving || !dirty"
				data-testid="settings-save"
				@click="save">
				{{ saving ? t('music_radio', 'Saving…') : t('music_radio', 'Save') }}
			</NcButton>
		</div>

			<!--
				Core's own component rather than a coloured <span>. The success case had no
				styling at all — plain text beside the button — and the error case was a bare
				colour, which is exactly the pair of things NcNoteCard exists to standardise.
				It is what this app already uses for the same job in the import dialog.
			-->
			<NcNoteCard
				v-if="message"
				:type="failed ? 'error' : 'success'"
				:text="message"
				data-testid="settings-message" />

	</NcSettingsSection>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'

import { pickFolder } from '../utils/filePicker.js'

export default {
	name: 'PersonalSettings',

	components: {
		FolderIcon,
		NcButton,
		NcNoteCard,
		NcSettingsSection,
	},

	data() {
		const state = loadState('music_radio', 'personal-settings', {
			values: { download_folder: 'Music' }, defaultFolder: 'Music',
		})

		return {
			state,
			values: { ...state.values },
			saved: JSON.stringify(state.values),
			errors: {},
			saving: false,
			message: '',
			failed: false,
		}
	},

	computed: {
		/** Ties the Choose button to the label, since it is not a labelled form control. */
		labelId() {
			return 'music-radio-folder-label'
		},

		dirty() {
			return JSON.stringify(this.values) !== this.saved
		},
	},

	methods: {
		t,

		/**
		 * Choose an existing folder.
		 *
		 * Fills the field rather than saving, so the picker is one way of answering the
		 * question and Save is still the thing that commits it — which is the same promise
		 * the rest of the page makes.
		 */
		async pick() {
			let folder
			try {
				folder = await pickFolder(t('music_radio', 'Choose where your music is kept'))
			} catch (error) {
				this.failed = true
				this.message = t('music_radio', 'The folder picker could not be opened')
				return
			}

			// Dismissed without choosing; nothing to say about that.
			if (folder === null) {
				return
			}

			this.errors = {}
			this.message = ''
			// Stored relative to the user's files, which is what the picker's path already
			// is once its leading slash is gone.
			this.values.download_folder = folder.replace(/^\/+/, '')
		},

		async save() {
			this.saving = true
			this.errors = {}
			this.message = ''
			this.failed = false

			let data
			try {
				const response = await axios.post(
					generateUrl('/apps/music_radio/settings/personal'),
					{ values: this.values },
				)
				data = response.data
			} catch (error) {
				this.failed = true
				this.message = t('music_radio', 'That could not be saved')
				this.saving = false
				return
			}

			this.saving = false
			this.errors = data.errors ?? {}
			this.state = data.state ?? this.state

			if (Object.keys(this.errors).length > 0) {
				this.failed = true
				this.message = t('music_radio', 'That was not saved — see below')
				return
			}

			// Re-seeded from the server, which normalises what was typed: a trailing slash
			// or a bit of extra whitespace is accepted and tidied, and the field should show
			// what was actually stored rather than what was entered.
			this.values = { ...this.state.values }
			this.saved = JSON.stringify(this.values)
			this.message = t('music_radio', 'Saved')
		},
	},
}
</script>

<style scoped>
.music-radio-settings__folder {
	max-inline-size: 40rem;
	margin-block-end: 1.5rem;
}

.music-radio-settings__label {
	display: block;
	margin-block-end: 0.35rem;
	font-weight: bold;
}

/*
 * Centred, and the button fixed at its natural width.
 *
 * Previously this row was `align-items: flex-start` around a labelled text field, so the
 * button sat against the top of a taller control — and because it was allowed to shrink,
 * its label was truncated to "Cho...". Neither is a size problem: the button simply must
 * not be the flexible item.
 */
.music-radio-settings__row {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.music-radio-settings__path {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	/* Takes the leftover width; min-width lets it shrink so a long path ellipsises
	   instead of pushing the button off the row. */
	flex: 1 1 auto;
	min-inline-size: 0;
	block-size: var(--default-clickable-area, 44px);
	padding-inline: 0.75rem;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, var(--border-radius-large, 0.5rem));
	background-color: var(--color-main-background);
}

.music-radio-settings__path--error {
	border-color: var(--color-error);
}

.music-radio-settings__path-icon {
	flex: none;
	color: var(--color-text-maxcontrast);
}

.music-radio-settings__path-text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-settings__choose {
	flex: none;
}

.music-radio-settings__hint {
	margin: 0.4rem 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.music-radio-settings__hint--error {
	color: var(--color-error-text);
}

.music-radio-settings__actions {
	display: flex;
	align-items: center;
	gap: 1rem;
}
</style>
