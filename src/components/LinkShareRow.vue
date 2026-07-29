<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - A public link, with its settings on show.
  -
  - Everything is visible without opening anything: a link is one of the more consequential
  - things you can do to a channel, and whether it is password protected should be readable
  - at a glance rather than hidden a click away.
-->
<template>
	<li class="music-radio-link" data-testid="link-share-row">
		<div class="music-radio-link__head">
			<LinkVariantIcon :size="24" />

			<div class="music-radio-link__text">
				<span class="music-radio-link__name">{{ t('music_radio', 'Anyone with the link') }}</span>
				<span class="music-radio-link__role" data-testid="link-protection">
					{{ roleSummary }}
				</span>
			</div>

			<NcButton
				variant="tertiary"
				:aria-label="t('music_radio', 'Copy link')"
				data-testid="link-copy"
				@click="copy">
				<template #icon>
					<ContentCopyIcon :size="20" />
				</template>
			</NcButton>

			<NcButton
				variant="tertiary"
				:aria-label="t('music_radio', 'Remove link')"
				data-testid="link-remove"
				@click="$emit('remove', share)">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<div class="music-radio-link__settings">
			<label class="music-radio-link__url-label" :for="urlFieldId">
				{{ t('music_radio', 'Link') }}
			</label>
			<input
				:id="urlFieldId"
				class="music-radio-link__url"
				type="text"
				readonly
				:value="shareUrl"
				data-testid="link-url"
				@focus="$event.target.select()">

			<span data-testid="link-allow-uploads">
				<NcCheckboxRadioSwitch
					type="switch"
					:model-value="allowsUploads"
					@update:model-value="setUploads">
					{{ t('music_radio', 'Allow uploading music') }}
				</NcCheckboxRadioSwitch>
			</span>
			<p class="music-radio-link__note">
				{{ t('music_radio', 'Anyone with the link can add a track to the channel. Uploads are saved in your Music folder and count against your storage.') }}
			</p>

			<NcPasswordField
				v-model="password"
				:label="share.hasPassword
					? t('music_radio', 'Change the password')
					: t('music_radio', 'Set a password')"
				:placeholder="share.hasPassword
					? t('music_radio', 'A password is set')
					: t('music_radio', 'No password')"
				data-testid="link-password-field" />

			<div class="music-radio-link__buttons">
				<NcButton
					variant="primary"
					:disabled="saving || password === ''"
					data-testid="link-save-password"
					@click="savePassword">
					{{ share.hasPassword ? t('music_radio', 'Change password') : t('music_radio', 'Set password') }}
				</NcButton>

				<NcButton
					v-if="share.hasPassword && !passwordRequired"
					:disabled="saving"
					data-testid="link-clear-password"
					@click="clearPassword">
					{{ t('music_radio', 'Remove password') }}
				</NcButton>
			</div>

			<p v-if="passwordRequired" class="music-radio-link__note">
				{{ t('music_radio', 'This server requires a password on public links, so it cannot be removed.') }}
			</p>
		</div>
	</li>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import { generateUrl, getBaseUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { ADD_TRACKS, LISTEN, can } from '../utils/permissions.js'

export default {
	name: 'LinkShareRow',

	components: {
		ContentCopyIcon,
		DeleteIcon,
		LinkVariantIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcPasswordField,
	},

	props: {
		share: {
			type: Object,
			required: true,
		},
		/** The server forbids links without one, so it cannot be cleared. */
		passwordRequired: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['remove', 'set-password', 'update'],

	data() {
		return {
			password: '',
			saving: false,
		}
	},

	computed: {
		allowsUploads() {
			return can(this.share.permissions, ADD_TRACKS)
		},

		/**
		 * Both halves of what a link grants, in the order they matter: what it lets
		 * people do, then how hard it is to reach.
		 */
		roleSummary() {
			const what = this.allowsUploads
				? t('music_radio', 'Can listen and upload')
				: t('music_radio', 'Can listen')
			const how = this.share.hasPassword
				? t('music_radio', 'password protected')
				: t('music_radio', 'no password')

			return `${what} · ${how}`
		},

		shareUrl() {
			return getBaseUrl() + generateUrl('/apps/music_radio/s/{token}', { token: this.share.token })
		},

		/** Unique per row, so the label points at the right field when several exist. */
		urlFieldId() {
			return `music-radio-link-url-${this.share.id}`
		},
	},

	methods: {
		setUploads(allow) {
			this.$emit('update', this.share, allow ? LISTEN | ADD_TRACKS : LISTEN)
		},

		async copy() {
			try {
				await navigator.clipboard.writeText(this.shareUrl)
				showSuccess(t('music_radio', 'Link copied'))
			} catch (error) {
				// Clipboard access can be refused; the field is selectable as a fallback.
				showError(t('music_radio', 'Could not copy the link — select it and copy manually'))
			}
		},

		savePassword() {
			if (this.password === '') {
				return
			}
			this.saving = true
			this.$emit('set-password', this.share, this.password)
			this.password = ''
			this.saving = false
		},

		clearPassword() {
			this.$emit('set-password', this.share, null)
		},
	},
}
</script>

<style scoped>
.music-radio-link {
	padding-block: 0.5rem;
	border-bottom: 1px solid var(--color-border);
}

.music-radio-link__head {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.music-radio-link__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1 1 auto;
}

.music-radio-link__role {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.music-radio-link__settings {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.5rem 0 0.25rem 2.25rem;
}

.music-radio-link__url-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.music-radio-link__url {
	inline-size: 100%;
	font-family: monospace;
	font-size: 0.85em;
}

.music-radio-link__buttons {
	display: flex;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.music-radio-link__note {
	margin: 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}
</style>
