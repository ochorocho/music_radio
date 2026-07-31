<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Everything one share may be given, as one list.
  -
  - There used to be three lists: a named person got four permission bits and four rules, a
  - public link got one bit and the same four rules under different names, and two of those
  - rules were additionally gated by a channel-wide switch at the bottom of the dialog. So
  - the same question — may these people vote? — was asked in two places and could be
  - answered twice, and the answer a link got was worded differently from the identical
  - answer a colleague got.
  -
  - One component now, rendered by both kinds of row. A link differs in exactly one way,
  - and it is a deliberate one: it cannot be given SHARE, because anyone at all can follow
  - a link and widening the audience past what the owner decided is not something to
  - delegate to a URL. Everything else a link can be trusted with, if the owner says so.
  -
  - The two emits stay separate. `update` writes the permission mask, `settings` writes the
  - named boolean columns; they are different questions on the server and keeping them
  - apart here is what stopped voting from being expressible two ways at once.
-->
<template>
	<div class="music-radio-share-options">
		<!--
			Wrapped in spans throughout: NcCheckboxRadioSwitch does not forward arbitrary
			attributes such as data-testid to anything in the DOM.
		-->
		<span data-testid="perm-add-tracks">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="has(ADD_TRACKS)"
				@update:model-value="toggle(ADD_TRACKS, $event)">
				{{ t('music_radio', 'Can add music') }}
			</NcCheckboxRadioSwitch>
		</span>
		<p v-if="isLink" class="music-radio-share-options__hint">
			{{ t('music_radio', 'Anyone with the link can add a track to the channel. Uploads are saved in your Music folder and count against your storage.') }}
		</p>

		<!--
			Directly under the switch it extends, because importing from YouTube is a way of
			adding music rather than a separate capability — it is gated on ADD_TRACKS and
			disappears the moment that is switched off.

			It is still the one switch here that spends more than the owner's patience: the
			server does the downloading and the transcoding, on their machine, for whoever
			this share reaches. Off unless they say otherwise, and said so plainly.
		-->
		<template v-if="serverCanImport && has(ADD_TRACKS)">
			<span data-testid="share-allow-import">
				<NcCheckboxRadioSwitch
					type="switch"
					:model-value="share.allowImport === true"
					@update:model-value="$emit('settings', share, { allowImport: $event })">
					{{ t('music_radio', 'Can add tracks from YouTube') }}
				</NcCheckboxRadioSwitch>
			</span>
			<p v-if="share.allowImport === true" class="music-radio-share-options__hint">
				{{ isLink
					? t('music_radio', 'Anyone with the link can have the server fetch audio from YouTube into your files. It costs your storage and your server’s time, so leave this off unless you trust who has the link.')
					: t('music_radio', 'Audio is fetched into your files and counts against your storage.') }}
			</p>
		</template>

		<span data-testid="perm-control">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="has(CONTROL)"
				@update:model-value="toggle(CONTROL, $event)">
				{{ t('music_radio', 'Can control what is playing') }}
			</NcCheckboxRadioSwitch>
		</span>
		<p v-if="isLink && has(CONTROL)" class="music-radio-share-options__hint">
			{{ t('music_radio', 'Anyone with the link can play, pause and skip — for everybody listening, not just for themselves.') }}
		</p>

		<span data-testid="perm-edit-playlist">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="has(EDIT_PLAYLIST)"
				@update:model-value="toggle(EDIT_PLAYLIST, $event)">
				{{ t('music_radio', 'Can reorder and remove any track') }}
			</NcCheckboxRadioSwitch>
		</span>

		<!--
			The one switch a link never gets. Not a judgement about how much a link can be
			trusted with the music — it is the bit that decides who else reaches the channel
			at all, which is the owner's to hold. See Permission::LINK_ALLOWED.
		-->
		<span v-if="!isLink" data-testid="perm-share">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="has(SHARE)"
				@update:model-value="toggle(SHARE, $event)">
				{{ t('music_radio', 'Can share this channel on') }}
			</NcCheckboxRadioSwitch>
		</span>

		<!--
			Below here: not permissions but rules — what this audience may do, rather than
			what their mask grants. Decided per share, which is the point of this dialog: the
			people an owner named are often trusted more than whoever ends up with a link.
		-->
		<span v-if="has(ADD_TRACKS)" data-testid="share-require-approval">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="share.requireApproval !== false"
				@update:model-value="$emit('settings', share, { requireApproval: $event })">
				{{ t('music_radio', 'Approve what they add') }}
			</NcCheckboxRadioSwitch>
		</span>

		<span data-testid="share-allow-voting">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="share.allowVoting === true"
				@update:model-value="$emit('settings', share, { allowVoting: $event })">
				{{ t('music_radio', 'Can vote for tracks') }}
			</NcCheckboxRadioSwitch>
		</span>
		<p v-if="share.allowVoting === true" class="music-radio-share-options__hint">
			{{ isLink
				? t('music_radio', 'The most-wanted tracks come round sooner. One vote each per track, remembered by their browser — so it is a show of hands, not a ballot.')
				: t('music_radio', 'The most-wanted tracks come round sooner, and a track spends its votes once it has played.') }}
		</p>

		<span data-testid="share-show-listener-count">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="share.showListenerCount !== false"
				@update:model-value="$emit('settings', share, { showListenerCount: $event })">
				{{ t('music_radio', 'Can see how many people are listening') }}
			</NcCheckboxRadioSwitch>
		</span>
	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

import { ADD_TRACKS, CONTROL, EDIT_PLAYLIST, LISTEN, SHARE, can } from '../utils/permissions.js'

export default {
	name: 'ShareOptions',

	components: {
		NcCheckboxRadioSwitch,
	},

	props: {
		share: {
			type: Object,
			required: true,
		},
		/**
		 * Whether this is a public link. Changes exactly two things: the sharing-on switch
		 * is not offered, and the hints speak about "anyone with the link" rather than a
		 * person the owner picked.
		 */
		isLink: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether this server can fetch from YouTube at all — the administrator's switch
		 * plus a working yt-dlp. Nothing to offer per share if the answer is no.
		 */
		serverCanImport: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update', 'settings'],

	data() {
		return {
			ADD_TRACKS,
			CONTROL,
			EDIT_PLAYLIST,
			SHARE,
		}
	},

	methods: {
		has(bit) {
			return can(this.share.permissions, bit)
		},

		/**
		 * Flip one bit and leave the others alone.
		 *
		 * @param {number} bit
		 * @param {boolean} enabled
		 */
		toggle(bit, enabled) {
			const permissions = enabled
				? (this.share.permissions | bit)
				: (this.share.permissions & ~bit)

			// Never leave a share granting nothing at all.
			this.$emit('update', this.share, permissions | LISTEN)
		},
	},
}
</script>

<style scoped>
.music-radio-share-options {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.music-radio-share-options__hint {
	margin: 0 0 0.25rem;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}
</style>
