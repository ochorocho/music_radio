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
			Where the fetching happens.

			First, because it decides what the rest of this page even means: on a server
			that hands imports to another machine, its own yt-dlp is irrelevant and the
			fields describing it are hidden rather than left to mislead.
		-->
		<div class="music-radio-settings__field" data-testid="setting-import-mode">
			<NcCheckboxRadioSwitch
				v-model="values.import_mode"
				value="local"
				name="import_mode"
				type="radio">
				{{ t('music_radio', 'Fetch on this server') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="values.import_mode"
				value="remote"
				name="import_mode"
				type="radio">
				{{ t('music_radio', 'Fetch on another machine') }}
			</NcCheckboxRadioSwitch>
		</div>
		<p class="music-radio-settings__hint">
			{{ t('music_radio', 'YouTube often refuses servers in data centres, and a server may have no yt-dlp at all. A worker running somewhere else — a NAS, a machine at home — collects queued imports over the API and sends the audio back. Everything else stays here: who may import, what is allowed, and whose storage it lands in.') }}
		</p>

		<!-- The remote half: who may collect, whether they are collecting, and cookies. -->
		<template v-if="remote">
			<div class="music-radio-settings__field" data-testid="setting-worker-users">
				<NcTextField
					v-model="values.remote_worker_users"
					:label="t('music_radio', 'Accounts that may collect imports')"
					:placeholder="t('music_radio', 'radio-worker')"
					:error="Boolean(errors.remote_worker_users)"
					:helper-text="errors.remote_worker_users || t('music_radio', 'Comma separated. Use a dedicated account: it can put audio into any channel owner’s files, so it should be able to do nothing else. Give it an app password with “occ user:add-app-password”.')" />
			</div>

			<p class="music-radio-settings__status" data-testid="admin-worker-status">
				<strong>{{ t('music_radio', 'Worker') }}:</strong>
				{{ workerStatus }}
			</p>

			<span data-testid="setting-forward-cookies">
				<NcCheckboxRadioSwitch v-model="values.remote_forward_cookies" type="switch">
					{{ t('music_radio', 'Send stored YouTube cookies to the worker') }}
				</NcCheckboxRadioSwitch>
			</span>
			<p class="music-radio-settings__hint">
				{{ t('music_radio', 'Cookies are the one part of an import that is a secret rather than a job. Off unless you trust the worker machine as much as this server — with it off, imports for channels whose owner stored cookies are made anonymously instead.') }}
			</p>
		</template>

		<!--
			The downloader, and the one button that keeps it working.

			YouTube breaks yt-dlp's extractors every few weeks, so updating it is routine
			maintenance rather than setup — and until now the only way to do it was
			`occ music_radio:ytdlp:install --force`, which the status text above had to
			tell people to go and run. That is a shell away from the page reporting the
			problem, and no help at all to anyone administering a server they have no
			terminal on.
		-->
		<div v-if="!remote" class="music-radio-settings__ytdlp">
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
		<p v-if="!remote" class="music-radio-settings__hint">
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

		<div v-if="!remote" class="music-radio-settings__field" data-testid="setting-ytdlp-path">
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
			remote: { online: false, name: '', seenAt: 0, secondsAgo: null, jsRuntime: null },
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

		/**
		 * Follows the radio buttons rather than the saved setting, so choosing "another
		 * machine" reveals the fields that go with it before Save is pressed.
		 */
		remote() {
			return this.values.import_mode === 'remote'
		},

		/**
		 * Whether anything is out there collecting, in one sentence.
		 *
		 * The only fact on this page that cannot be found out any other way: the worker is
		 * on a machine the administrator may not be sitting at, and the alternative way of
		 * asking is to try an import and wait.
		 */
		workerStatus() {
			const worker = this.state.remote ?? {}

			if (!worker.seenAt) {
				return t('music_radio', 'none has ever checked in')
			}

			const ago = this.since(worker.secondsAgo ?? 0)

			return worker.online
				? t('music_radio', '“{name}” checked in {ago}', { name: worker.name, ago })
				: t('music_radio', '“{name}” last checked in {ago} — it looks stopped', { name: worker.name, ago })
		},
	},

	methods: {
		t,

		/**
		 * How long ago, roughly. The server sends an age in seconds rather than a
		 * timestamp, because the browser's clock is not the server's.
		 */
		since(seconds) {
			if (seconds < 90) {
				return t('music_radio', '{count} seconds ago', { count: Math.max(0, Math.round(seconds)) })
			}
			if (seconds < 5400) {
				return t('music_radio', '{count} minutes ago', { count: Math.round(seconds / 60) })
			}
			return t('music_radio', '{count} hours ago', { count: Math.round(seconds / 3600) })
		},

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
