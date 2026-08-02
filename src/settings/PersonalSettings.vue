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

	<!--
		Only offered where it can do something. With importing switched off server-side the
		field would be a form that changes nothing, and a secret nobody should be asked for
		is worse than a missing feature.
	-->
	<NcSettingsSection
		v-if="importEnabled"
		:name="t('music_radio', 'YouTube cookies')"
		:description="t('music_radio', 'Optional. YouTube sometimes refuses a server with &quot;Sign in to confirm you are not a bot&quot;. Signing the request in as you is the only thing that changes that answer — these cookies are how. They are used for imports into channels you own, whoever asked for them.')">
		<!--
			Said before anything else on this section, because in this state the field below
			does nothing whatever is typed into it — and an ignored setting that does not
			admit to being ignored is worse than an absent one.
		-->
		<NcNoteCard
			v-if="!cookiesUsable"
			type="warning"
			data-testid="cookies-unusable"
			:text="t('music_radio', 'This server has no JavaScript runtime, so cookies are not sent — signing in makes yt-dlp use a route that needs one, and without it no video can be downloaded at all. Imports run anonymously until an administrator installs Deno or Node.')" />

		<!--
			The stored jar is described, never shown. There is no read path for the value:
			the server sends counts and dates, and the field below is always empty because
			there is nothing safe to prefill it with.
		-->
		<div v-if="cookies" class="music-radio-settings__jar" data-testid="cookies-stored">
			<p class="music-radio-settings__jar-line">
				<CheckIcon :size="20" class="music-radio-settings__jar-icon" />
				<span>{{ storedSummary }}</span>
			</p>
			<p v-if="expirySummary" class="music-radio-settings__hint" data-testid="cookies-expiry">
				{{ expirySummary }}
			</p>

			<!--
				The quietest way to get this wrong: exporting from a window that was not
				actually signed in. It stores cleanly, imports cleanly, and changes nothing,
				so nothing else would ever mention it.
			-->
			<NcNoteCard
				v-if="!cookies.signedIn"
				type="warning"
				data-testid="cookies-not-signed-in"
				:text="t('music_radio', 'No sign-in cookie could be found in these. They will be sent, but they probably will not help — export again from a window that is signed in to YouTube.')" />
		</div>

		<NcNoteCard
			v-else
			type="info"
			:text="t('music_radio', 'No cookies stored. Imports ask YouTube anonymously, which usually works.')" />

		<NcTextArea
			v-model="pastedCookies"
			class="music-radio-settings__paste"
			:label="cookies
				? t('music_radio', 'Replace with a new cookies.txt')
				: t('music_radio', 'Paste your cookies.txt')"
			:placeholder="'# Netscape HTTP Cookie File'"
			:error="Boolean(errors.youtube_cookies)"
			:helper-text="errors.youtube_cookies || ''"
			resize="vertical"
			rows="6"
			data-testid="cookies-input"
			@keydown.stop />

		<div class="music-radio-settings__actions">
			<NcButton
				variant="primary"
				:disabled="cookiesSaving || pastedCookies.trim() === ''"
				data-testid="cookies-save"
				@click="saveCookies">
				{{ cookiesSaving ? t('music_radio', 'Saving…') : t('music_radio', 'Save cookies') }}
			</NcButton>

			<NcButton
				v-if="cookies"
				variant="tertiary"
				:disabled="cookiesSaving"
				data-testid="cookies-remove"
				@click="removeCookies">
				{{ t('music_radio', 'Remove stored cookies') }}
			</NcButton>
		</div>

		<NcNoteCard
			v-if="cookiesMessage"
			:type="cookiesFailed ? 'error' : 'success'"
			:text="cookiesMessage"
			data-testid="cookies-message" />

		<!--
			Collapsed rather than omitted. Nobody knows how to produce this file off the top
			of their head, and sending them to a wiki for it is how a setting ends up
			unused — but it is six steps of detail that only matter once.
		-->
		<details class="music-radio-settings__howto">
			<summary>{{ t('music_radio', 'How to export your cookies') }}</summary>

			<NcNoteCard
				type="warning"
				:text="t('music_radio', 'Use a throwaway Google account, not your own. YouTube may lock an account it decides is being automated, and anyone who can read this server\'s database backups would hold a signed-in session for whatever account you use.')" />

			<ol class="music-radio-settings__steps">
				<li>{{ t('music_radio', 'Install a cookies.txt exporter for your browser — one that writes the Netscape format, such as "Get cookies.txt LOCALLY".') }}</li>
				<li>{{ t('music_radio', 'Open a private or incognito window and sign in to YouTube with the throwaway account.') }}</li>
				<li>{{ t('music_radio', 'In that same window, open youtube.com/robots.txt. This parks the session on a page that will not rotate the cookies while you export them.') }}</li>
				<li>{{ t('music_radio', 'Export the cookies for youtube.com with the extension, and open the downloaded file in a text editor.') }}</li>
				<li>{{ t('music_radio', 'Close the private window without signing out. Signing out invalidates the session you just exported.') }}</li>
				<li>{{ t('music_radio', 'Paste the whole file above and press "Save cookies".') }}</li>
			</ol>

			<p class="music-radio-settings__hint">
				{{ t('music_radio', 'Cookies expire, and YouTube ends the session if you sign in to that account elsewhere. When imports start failing with a sign-in message, export them again.') }}
			</p>
		</details>
	</NcSettingsSection>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import { pickFolder } from '../utils/filePicker.js'

export default {
	name: 'PersonalSettings',

	components: {
		CheckIcon,
		FolderIcon,
		NcButton,
		NcNoteCard,
		NcSettingsSection,
		NcTextArea,
	},

	data() {
		const state = loadState('music_radio', 'personal-settings', {
			values: { download_folder: 'Music' },
			defaultFolder: 'Music',
			cookies: null,
			importEnabled: false,
			cookiesUsable: true,
		})

		return {
			state,
			values: { ...state.values },
			saved: JSON.stringify(state.values),
			errors: {},
			saving: false,
			message: '',
			failed: false,
			// Never seeded from the server — there is nothing to seed it with, by design.
			pastedCookies: '',
			cookiesSaving: false,
			cookiesMessage: '',
			cookiesFailed: false,
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

		importEnabled() {
			return this.state.importEnabled === true
		},

		/** The description of the stored jar, or null when there is none. */
		cookies() {
			return this.state.cookies ?? null
		},

		/** Whether a stored jar would actually be sent, as opposed to held back. */
		cookiesUsable() {
			return this.state.cookiesUsable !== false
		},

		storedSummary() {
			const count = this.cookies.count

			return t('music_radio', '{count} stored on {date} for {domains}', {
				count: n('music_radio', '%n cookie', '%n cookies', count),
				date: this.formatDate(this.cookies.storedAt),
				domains: this.cookies.domains.join(', '),
			})
		},

		/**
		 * When the sign-in dies, when that is knowable.
		 *
		 * The date is the server's, computed over the login cookies alone — YouTube adds
		 * short-lived cookies of its own during an import, and the soonest expiry across the
		 * whole jar would declare a healthy session dead within the hour.
		 *
		 * Phrased in the past tense once the date has gone by, because a session that ended
		 * last week is the answer to "why did imports start failing" and should read like
		 * one rather than like a schedule.
		 */
		expirySummary() {
			const expires = this.cookies?.expiresAt
			if (!expires) {
				return ''
			}

			return expires * 1000 < Date.now()
				? t('music_radio', 'The sign-in expired on {date}. Export them again.', { date: this.formatDate(expires) })
				: t('music_radio', 'The sign-in expires on {date}.', { date: this.formatDate(expires) })
		},
	},

	methods: {
		t,
		n,

		formatDate(seconds) {
			return new Date(seconds * 1000).toLocaleDateString()
		},

		/**
		 * Store or replace the jar.
		 *
		 * Posts only the cookie field. The page's other section has its own Save, and
		 * sending the folder along would make one button quietly commit the other's
		 * unsaved edits.
		 */
		async saveCookies() {
			await this.postCookies(
				{ youtube_cookies: this.pastedCookies },
				t('music_radio', 'Cookies saved'),
			)
		},

		async removeCookies() {
			await this.postCookies(
				{ youtube_cookies_clear: true },
				t('music_radio', 'Cookies removed'),
			)
		},

		/**
		 * @param {object} values the cookie fields to send
		 * @param {string} success what to say when it worked
		 */
		async postCookies(values, success) {
			this.cookiesSaving = true
			this.cookiesMessage = ''
			this.cookiesFailed = false
			this.errors = { ...this.errors, youtube_cookies: undefined }

			let data
			try {
				const response = await axios.post(
					generateUrl('/apps/music_radio/settings/personal'),
					{ values },
				)
				data = response.data
			} catch (error) {
				this.cookiesSaving = false
				this.cookiesFailed = true
				this.cookiesMessage = t('music_radio', 'That could not be saved')
				return
			}

			this.cookiesSaving = false
			this.errors = data.errors ?? {}
			this.state = data.state ?? this.state

			if (this.errors.youtube_cookies) {
				this.cookiesFailed = true
				// The reason is already beside the field; repeating it here would say the
				// same sentence twice on one screen.
				this.cookiesMessage = ''
				return
			}

			// Cleared on success either way. Leaving a signed-in session sitting in a
			// textarea after it has been stored is the one place this page could leak the
			// secret it is otherwise careful never to render.
			this.pastedCookies = ''
			this.cookiesMessage = success
		},

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

.music-radio-settings__jar {
	margin-block-end: 1rem;
}

.music-radio-settings__jar-line {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin: 0;
}

.music-radio-settings__jar-icon {
	flex: none;
	color: var(--color-success, var(--color-primary-element));
}

.music-radio-settings__paste {
	max-inline-size: 40rem;
	margin-block-end: 1rem;
}

/* The pasted jar is one long unwrapped line per cookie; let it scroll rather than
   reflow into something that no longer looks like the file that was copied. */
.music-radio-settings__paste :deep(textarea) {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85em;
	white-space: pre;
	overflow-wrap: normal;
	overflow-x: auto;
}

.music-radio-settings__howto {
	margin-block-start: 1.5rem;
	max-inline-size: 40rem;
}

.music-radio-settings__howto summary {
	cursor: pointer;
	padding-block: 0.35rem;
	color: var(--color-primary-element);
}

.music-radio-settings__steps {
	margin: 0.5rem 0 0;
	padding-inline-start: 1.5rem;
}

.music-radio-settings__steps li {
	margin-block-end: 0.5rem;
	list-style: decimal;
}
</style>
