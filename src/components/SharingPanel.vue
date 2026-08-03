<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="music-radio-sharing" data-testid="sharing-panel">
		<!--
			The picker stands alone. There used to be a "Let them add music too" switch under
			it, deciding at the moment somebody was picked whether their share was created as
			a Contributor or a Listener — which was a second place to answer a question the
			row itself now answers, a click away behind its chevron. See onPick.
		-->
		<ShareeSelect :exclude="internalShares" @pick="onPick" />

		<NcLoadingIcon v-if="loading" :size="24" class="music-radio-sharing__loading" />

		<ul v-else-if="internalShares.length > 0" class="music-radio-sharing__list">
			<ShareItem
				v-for="share in internalShares"
				:key="share.id"
				:share="share"
				:server-can-import="importAvailable"
				@update="onUpdate"
				@settings="onShareSettings"
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
					:server-can-import="importAvailable"
					@remove="onRemove"
					@settings="onShareSettings"
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

		<!--
			There is deliberately no "This channel" section any more.

			It used to hold two switches — whether listeners may vote, and whether the
			channel takes tracks from YouTube — which AND-gated the per-share ones above
			them. That meant the same question was asked in two places: an owner had to say
			yes twice, and a share whose switch was on could be silently inert because the
			channel's was off. Both are questions about what one audience may do, so both
			are now answered where the audience is, on the row.

			Voting still has a channel-wide fact behind it — whether the playlist is in vote
			order at all — but that is derived from the shares rather than set here. See
			ChannelService::syncVotingMode.
		-->
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
import { PRESET_LISTENER } from '../utils/permissions.js'

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

		/**
		 * What this server can import, as the channel view already knows it. Only used to
		 * decide whether the YouTube switch is worth offering at all.
		 */
		importCapabilities: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['rules-changed', 'channel-gone'],

	data() {
		return {
			shares: [],
			loading: true,
			creatingLink: false,
			protectLink: false,
			linkPassword: '',
			linkPasswordError: '',
			capabilities: {},
		}
	},

	computed: {
		/**
		 * Whether this server does YouTube imports at all — the administrator's switch plus
		 * the setup behind it. Distinct from the channel's own preference, which is what the
		 * switch sets, and deliberately *not* the same question as whether an import could
		 * start this second.
		 *
		 * `configured` rather than `available`, because what this decides is whether to show
		 * a stored permission. A remote worker that is switched off, rebooting, or simply
		 * between polls makes `available` false — and gating on that took the switch away
		 * from owners while it was still in force, so it could be neither seen nor changed,
		 * and shares could not be set up before a worker was started for the first time.
		 * Falls back to `available` for a server too old to send `configured`.
		 */
		importAvailable() {
			const capabilities = this.importCapabilities

			return capabilities.configured !== undefined
				? capabilities.configured !== false
				: capabilities.available !== false
		},

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
		/**
		 * Report a failure, and notice when the channel itself has gone.
		 *
		 * The server answers "Channel not found" for any share operation on a channel that
		 * is no longer there — or no longer readable, which it reports identically so that
		 * a stranger cannot probe which ids exist. Surfaced raw, that is a puzzling thing to
		 * read after pressing delete on a *link*: it names the wrong object and suggests
		 * nothing to do about it.
		 *
		 * A page left open while the channel was removed elsewhere is the ordinary way to
		 * get here, so it is worth saying plainly and getting out of.
		 *
		 * @param {Error} error the rejected request
		 * @param {string} fallback what to say for anything else
		 */
		reportShareFailure(error, fallback) {
			if (error?.response?.status === 404) {
				showError(t('music_radio', 'This channel no longer exists, or is no longer shared with you.'))
				this.$emit('channel-gone')
				return
			}

			showError(errorMessage(error, fallback))
		},

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
				this.reportShareFailure(error, t('music_radio', 'Could not load who this is shared with'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Share with whoever was picked.
		 *
		 * Listen-only, deliberately, and the same default the server applies when a caller
		 * says nothing — see ShareController::create. Everything beyond listening is granted
		 * on the row afterwards, where it can be seen and taken back. Sharing more than was
		 * asked for is the mistake worth designing against; sharing too little is one press
		 * away from being fixed.
		 *
		 * @param {object} option the person, group or team the picker returned
		 */
		async onPick(option) {
			try {
				const share = await createShare(this.channel.id, {
					shareType: option.shareType,
					receiver: option.receiver,
					permissions: PRESET_LISTENER,
				})
				this.shares.push(share)
				showSuccess(t('music_radio', 'Shared with {name}', { name: option.label }))
			} catch (error) {
				this.reportShareFailure(error, t('music_radio', 'Could not share the channel'))
			}
		},

		async createLink() {
			if (this.protectLink && this.linkPassword === '') {
				this.linkPasswordError = t('music_radio', 'Type a password first')
				return
			}

			this.creatingLink = true
			try {
				// A new link starts listen-only, whatever it can later be given — nobody
				// should have to remember to take something away. The password goes in with
				// the create so the link never exists unprotected, not even for the moment
				// between creating it and securing it.
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
				this.reportShareFailure(error, t('music_radio', 'Could not create a link'))
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
				this.reportShareFailure(error, t('music_radio', 'Could not change the password'))
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
				this.reportShareFailure(error, t('music_radio', 'Could not change what they can do'))
			}
		},

		/**
		 * Save approval, voting, the listener count or importing for one share.
		 *
		 * Separate from onUpdate, which writes the permission mask: these are not
		 * permissions, they are rules applied to one audience. Keeping them apart is what
		 * stopped voting from being expressible two ways at once.
		 *
		 * @param {object} share the row that changed
		 * @param {object} values the fields to write
		 */
		async onShareSettings(share, values) {
			try {
				const updated = await updateShare(this.channel.id, share.id, values)
				const index = this.shares.findIndex((s) => s.id === share.id)
				if (index !== -1) {
					this.shares.splice(index, 1, updated)
				}
				// Granting voting to anybody is what puts the whole channel in vote order, so
				// the view behind this dialog has just changed shape: every playlist row grows
				// a vote control, and the running order is not the author's any more. It reads
				// from its own copy and would otherwise not find out until its next poll.
				this.$emit('rules-changed')
			} catch (error) {
				this.reportShareFailure(error, t('music_radio', 'That could not be saved'))
			}
		},

		async onRemove(share) {
			try {
				await deleteShare(this.channel.id, share.id)
				this.shares = this.shares.filter((s) => s.id !== share.id)
				// Removing the last share that could vote puts the author's order back.
				this.$emit('rules-changed')
			} catch (error) {
				this.reportShareFailure(error, t('music_radio', 'Could not stop sharing'))
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
