<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - The administrator's switches over YouTube importing.
  -
  - Replaces a declarative settings form. The reason is the Save button: declarative forms
  - write each field the moment it loses focus, with no button and no confirmation, so
  - typing a number and clicking elsewhere gave no sign of having done anything — and if
  - the value was refused, the complaint arrived detached from any action the person had
  - taken. Here nothing is written until Save is pressed, and what happened is said plainly.
-->
<template>
	<NcSettingsSection
		:name="t('music_radio', 'Music Radio')"
		:description="t('music_radio', 'Importing audio from YouTube. Off until it is switched on here.')">
		<p class="music-radio-settings__status" data-testid="admin-status">
			<strong>{{ t('music_radio', 'Status') }}:</strong> {{ state.summary }}
		</p>

		<!--
			The downloader, and the one button that keeps it working.

			YouTube breaks yt-dlp's extractors every few weeks, so updating it is routine
			maintenance rather than setup — and until now the only way to do it was
			`occ music_radio:ytdlp:install --force`, which the status text above had to
			tell people to go and run. That is a shell away from the page reporting the
			problem, and no help at all to anyone administering a server they have no
			terminal on.
		-->
		<div class="music-radio-settings__ytdlp">
			<p class="music-radio-settings__version" data-testid="admin-ytdlp-version">
				<strong>{{ t('music_radio', 'Downloader') }}:</strong>
				{{ state.ytDlpVersion
					? t('music_radio', 'yt-dlp {version}', { version: state.ytDlpVersion })
					: t('music_radio', 'not installed') }}
			</p>

			<NcButton
				:disabled="installing"
				data-testid="admin-ytdlp-update"
				@click="updateYtDlp">
				<template #icon>
					<NcLoadingIcon v-if="installing" :size="20" />
					<DownloadIcon v-else :size="20" />
				</template>
				{{ installing
					? t('music_radio', 'Downloading…')
					: (state.ytDlpVersion
						? t('music_radio', 'Update yt-dlp')
						: t('music_radio', 'Install yt-dlp')) }}
			</NcButton>
		</div>
		<p class="music-radio-settings__hint">
			{{ t('music_radio', 'Downloads the current release into this server’s data directory. Do this when videos start failing to import — it is usually the fix.') }}
		</p>

		<span data-testid="setting-import-enabled">
			<NcCheckboxRadioSwitch v-model="values.import_enabled" type="switch">
				{{ t('music_radio', 'Allow importing from YouTube') }}
			</NcCheckboxRadioSwitch>
		</span>
		<p class="music-radio-settings__hint">
			{{ t('music_radio', 'People who may add tracks to a channel can paste a link, and the server fetches the audio as an MP3. It is stored in the channel owner’s files and counts against their quota.') }}
		</p>

		<div class="music-radio-settings__field" data-testid="setting-ytdlp-path">
			<NcTextField
				v-model="values.ytdlp_path"
				:label="t('music_radio', 'Path to yt-dlp')"
				:placeholder="t('music_radio', 'Leave empty to detect automatically')"
				:error="Boolean(errors.ytdlp_path)"
				:helper-text="errors.ytdlp_path || state.ytDlp" />
		</div>

		<div class="music-radio-settings__field" data-testid="setting-max-duration">
			<NcTextField
				v-model.number="values.import_max_duration"
				type="number"
				:label="t('music_radio', 'Longest video, in minutes')"
				:error="Boolean(errors.import_max_duration)"
				:helper-text="errors.import_max_duration || t('music_radio', 'Anything longer is refused before it is downloaded.')" />
		</div>

		<div class="music-radio-settings__field" data-testid="setting-max-bytes">
			<NcTextField
				v-model.number="values.import_max_source_bytes"
				type="number"
				:label="t('music_radio', 'Largest download, in megabytes')"
				:error="Boolean(errors.import_max_source_bytes)"
				:helper-text="errors.import_max_source_bytes || t('music_radio', 'Measured on what is fetched from YouTube, before it is converted.')" />
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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'AdminSettings',

	components: {
		DownloadIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		const state = loadState('music_radio', 'admin-settings', {
			values: {}, summary: '', ytDlp: '', ytDlpVersion: null,
		})

		return {
			state,
			values: { ...state.values },
			// What was last saved, so the Save button can tell whether anything changed.
			saved: JSON.stringify(state.values),
			errors: {},
			saving: false,
			installing: false,
			message: '',
			failed: false,
		}
	},

	computed: {
		dirty() {
			return JSON.stringify(this.values) !== this.saved
		},
	},

	methods: {
		t,

		/**
		 * Fetch the current yt-dlp, replacing whatever is there.
		 *
		 * Deliberately not behind the Save button. Nothing here is a *setting* — pressing
		 * it downloads a file — and folding it into Save would mean an unsaved change to
		 * an unrelated field either blocked the update or got written along with it.
		 *
		 * The whole state comes back rather than just the version, because installing
		 * changes the status line too: a server that could not import a moment ago can now.
		 */
		async updateYtDlp() {
			this.installing = true
			this.message = ''
			this.failed = false

			try {
				const { data } = await axios.post(
					generateUrl('/apps/music_radio/settings/admin/ytdlp'),
				)
				this.state = data.state ?? this.state
				this.message = t('music_radio', 'Installed yt-dlp {version}', {
					version: data.installed?.version ?? '',
				})
			} catch (error) {
				this.failed = true
				// The server's own words where it has them: they name the reason, which a
				// generic sentence here would throw away.
				this.message = error?.response?.data?.error
					?? t('music_radio', 'yt-dlp could not be installed')
			} finally {
				this.installing = false
			}
		},

		async save() {
			this.saving = true
			this.errors = {}
			this.message = ''
			this.failed = false

			let data
			try {
				const response = await axios.post(
					generateUrl('/apps/music_radio/settings/admin'),
					{ values: this.values },
				)
				data = response.data
			} catch (error) {
				this.failed = true
				this.message = t('music_radio', 'Those settings could not be saved')
				this.saving = false
				return
			}

			this.saving = false
			this.errors = data.errors ?? {}
			this.state = data.state ?? this.state

			if (Object.keys(this.errors).length > 0) {
				this.failed = true
				this.message = t('music_radio', 'Some settings were not saved — see the fields below')
				// Only the accepted ones are now stored, so the form is re-seeded from the
				// server rather than left claiming to hold values it does not.
				this.values = { ...this.values, ...this.state.values }
				return
			}

			this.values = { ...this.state.values }
			this.saved = JSON.stringify(this.values)
			this.message = t('music_radio', 'Saved')
		},
	},
}
</script>

<style scoped>
.music-radio-settings__status {
	margin-block-end: 1rem;
}

/* The version and the button that changes it belong on one line, wrapping on a narrow
   window rather than pushing the button off the edge. */
.music-radio-settings__ytdlp {
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.music-radio-settings__version {
	margin: 0;
}

.music-radio-settings__hint {
	margin: 0.25rem 0 1rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	max-inline-size: 40rem;
}

.music-radio-settings__field {
	margin-block-end: 1rem;
	max-inline-size: 25rem;
}

.music-radio-settings__actions {
	display: flex;
	align-items: center;
	gap: 1rem;
	margin-block-start: 1rem;
}
</style>
