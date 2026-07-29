<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="music-radio-sharing" data-testid="sharing-panel">
		<ShareeSelect :exclude="internalShares" @pick="onPick" />

		<span data-testid="share-as-contributor">
			<NcCheckboxRadioSwitch
				class="music-radio-sharing__preset"
				type="switch"
				:model-value="shareAsContributor"
				@update:model-value="shareAsContributor = $event">
				{{ t('music_radio', 'Let them add music too') }}
			</NcCheckboxRadioSwitch>
		</span>
		<p class="music-radio-sharing__hint">
			{{ t('music_radio', 'Contributors can put tracks on the channel, but not decide what is playing.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="24" class="music-radio-sharing__loading" />

		<ul v-else-if="internalShares.length > 0" class="music-radio-sharing__list">
			<ShareItem
				v-for="share in internalShares"
				:key="share.id"
				:share="share"
				@update="onUpdate"
				@remove="onRemove" />
		</ul>

		<NcEmptyContent
			v-else
			:name="t('music_radio', 'Not shared with anyone yet')"
			:description="t('music_radio', 'Share this channel to let other people listen along.')">
			<template #icon>
				<AccountGroupIcon />
			</template>
		</NcEmptyContent>
		<div class="music-radio-sharing__links">
			<h4 class="music-radio-sharing__heading">{{ t('music_radio', 'Public link') }}</h4>

			<ul v-if="linkShares.length > 0" class="music-radio-sharing__list">
				<LinkShareRow
					v-for="share in linkShares"
					:key="share.id"
					:share="share"
					:password-required="linkPasswordEnforced"
					@remove="onRemove"
					@update="onUpdate"
					@set-password="onSetPassword" />
			</ul>

			<template v-else-if="linksAllowed">
				<!-- Wrapped: the switch component does not forward arbitrary attributes
				     such as data-testid to anything in the DOM. -->
				<span data-testid="link-protect">
					<NcCheckboxRadioSwitch
						type="switch"
						:model-value="protectLink"
						:disabled="linkPasswordEnforced"
						@update:model-value="protectLink = $event">
						{{ t('music_radio', 'Protect the link with a password') }}
					</NcCheckboxRadioSwitch>
				</span>
				<p v-if="linkPasswordEnforced" class="music-radio-sharing__hint">
					{{ t('music_radio', 'This server requires a password on public links.') }}
				</p>

				<NcPasswordField
					v-if="protectLink"
					v-model="linkPassword"
					:label="t('music_radio', 'Password')"
					:error="linkPasswordError !== ''"
					:helper-text="linkPasswordError"
					class="music-radio-sharing__password"
					@update:model-value="linkPasswordError = ''" />

				<NcButton
					:disabled="creatingLink || (protectLink && linkPassword === '')"
					data-testid="create-link"
					@click="createLink">
					<template #icon>
						<NcLoadingIcon v-if="creatingLink" :size="20" />
						<LinkVariantIcon v-else :size="20" />
					</template>
					{{ protectLink
						? t('music_radio', 'Create a password-protected link')
						: t('music_radio', 'Create a link anyone can listen with') }}
				</NcButton>
			</template>

			<p v-else class="music-radio-sharing__hint">
				{{ t('music_radio', 'Public links are disabled on this server.') }}
			</p>
		</div>

	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import LinkShareRow from './LinkShareRow.vue'
import ShareItem from './ShareItem.vue'
import ShareeSelect from './ShareeSelect.vue'
import { createShare, deleteShare, errorMessage, fetchShares, setSharePassword, updateShare } from '../utils/api.js'
import { PRESET_CONTRIBUTOR, PRESET_LISTENER } from '../utils/permissions.js'

const SHARE_TYPE_LINK = 3

export default {
	name: 'SharingPanel',

	components: {
		AccountGroupIcon,
		LinkShareRow,
		LinkVariantIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcPasswordField,
		ShareItem,
		ShareeSelect,
	},

	props: {
		channel: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			shares: [],
			loading: true,
			shareAsContributor: false,
			creatingLink: false,
			protectLink: false,
			linkPassword: '',
			linkPasswordError: '',
			capabilities: {},
		}
	},

	computed: {
		/** Everything except public links, which the picker must not offer again. */
		internalShares() {
			return this.shares.filter((share) => share.shareType !== SHARE_TYPE_LINK)
		},

		linkShares() {
			return this.shares.filter((share) => share.shareType === SHARE_TYPE_LINK)
		},

		linksAllowed() {
			return this.capabilities.linksAllowed !== false
		},

		/** Some servers insist every public link carries one. */
		linkPasswordEnforced() {
			return this.capabilities.linkPasswordEnforced === true
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		async load() {
			this.loading = true
			try {
				const { shares, capabilities } = await fetchShares(this.channel.id)
				this.shares = shares
				this.capabilities = capabilities
				// When the server insists on one, the choice is not the user's to make.
				if (this.linkPasswordEnforced) {
					this.protectLink = true
				}
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not load who this is shared with')))
			} finally {
				this.loading = false
			}
		},

		async onPick(option) {
			try {
				const share = await createShare(this.channel.id, {
					shareType: option.shareType,
					receiver: option.receiver,
					permissions: this.shareAsContributor ? PRESET_CONTRIBUTOR : PRESET_LISTENER,
				})
				this.shares.push(share)
				showSuccess(t('music_radio', 'Shared with {name}', { name: option.label }))
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not share the channel')))
			}
		},

		async createLink() {
			if (this.protectLink && this.linkPassword === '') {
				this.linkPasswordError = t('music_radio', 'Type a password first')
				return
			}

			this.creatingLink = true
			try {
				// A public link is always listen-only; the server enforces that too. The
				// password goes in with the create so the link never exists unprotected,
				// not even for the moment between creating it and securing it.
				const share = await createShare(this.channel.id, {
					shareType: SHARE_TYPE_LINK,
					password: this.protectLink ? this.linkPassword : null,
				})
				this.shares.push(share)
				this.linkPassword = ''
				showSuccess(share.hasPassword
					? t('music_radio', 'Password-protected link created')
					: t('music_radio', 'Link created'))
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not create a link')))
			} finally {
				this.creatingLink = false
			}
		},

		async onSetPassword(share, password) {
			try {
				const updated = await setSharePassword(this.channel.id, share.id, password)
				const index = this.shares.findIndex((s) => s.id === share.id)
				if (index !== -1) {
					this.shares.splice(index, 1, updated)
				}
				showSuccess(password
					? t('music_radio', 'Password set')
					: t('music_radio', 'Password removed'))
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not change the password')))
			}
		},

		async onUpdate(share, permissions) {
			try {
				const updated = await updateShare(this.channel.id, share.id, { permissions })
				const index = this.shares.findIndex((s) => s.id === share.id)
				if (index !== -1) {
					this.shares.splice(index, 1, updated)
				}
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not change what they can do')))
			}
		},

		async onRemove(share) {
			try {
				await deleteShare(this.channel.id, share.id)
				this.shares = this.shares.filter((s) => s.id !== share.id)
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not stop sharing')))
			}
		},
	},
}
</script>

<style scoped>
.music-radio-sharing {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.5rem 0;
}

.music-radio-sharing__preset {
	margin-block-start: 0.5rem;
}

.music-radio-sharing__hint {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.music-radio-sharing__links {
	margin-block-start: 0.5rem;
	padding-block-start: 0.5rem;
	border-top: 1px solid var(--color-border);
}

.music-radio-sharing__heading {
	margin: 0 0 0.5rem;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.music-radio-sharing__list {
	list-style: none;
	margin: 0.5rem 0 0;
	padding: 0;
	border-top: 1px solid var(--color-border);
}

.music-radio-sharing__password {
	margin-block: 0.25rem;
}

.music-radio-sharing__loading {
	margin-block: 1.5rem;
}
</style>
