<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<li class="music-radio-share" data-testid="share-row">
		<div class="music-radio-share__head">
			<NcAvatar
				:user="share.shareType === 0 ? share.receiver : undefined"
				:display-name="displayName"
				:is-no-user="share.shareType !== 0"
				:size="32" />

			<div class="music-radio-share__text">
				<span class="music-radio-share__name" data-testid="share-name">{{ displayName }}</span>
				<span class="music-radio-share__role" data-testid="share-role">{{ roleLabel }}</span>
			</div>

			<NcActions>
				<NcActionButton @click="expanded = !expanded">
					<template #icon>
						<TuneIcon :size="20" />
					</template>
					{{ t('music_radio', 'Change what they can do') }}
				</NcActionButton>
				<NcActionButton data-testid="share-remove" @click="$emit('remove', share)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('music_radio', 'Stop sharing') }}
				</NcActionButton>
			</NcActions>
		</div>

		<div v-if="expanded" class="music-radio-share__detail">
			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="has(ADD_TRACKS)"
				data-testid="perm-add-tracks"
				@update:model-value="toggle(ADD_TRACKS, $event)">
				{{ t('music_radio', 'Can add music') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="has(CONTROL)"
				data-testid="perm-control"
				@update:model-value="toggle(CONTROL, $event)">
				{{ t('music_radio', 'Can control what is playing') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="has(EDIT_PLAYLIST)"
				data-testid="perm-edit-playlist"
				@update:model-value="toggle(EDIT_PLAYLIST, $event)">
				{{ t('music_radio', 'Can reorder and remove any track') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				type="switch"
				:model-value="has(SHARE)"
				data-testid="perm-share"
				@update:model-value="toggle(SHARE, $event)">
				{{ t('music_radio', 'Can share this channel on') }}
			</NcCheckboxRadioSwitch>
		</div>
	</li>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import TuneIcon from 'vue-material-design-icons/Tune.vue'

import { ADD_TRACKS, CONTROL, EDIT_PLAYLIST, LISTEN, SHARE, can } from '../utils/permissions.js'

export default {
	name: 'ShareItem',

	components: {
		DeleteIcon,
		NcActionButton,
		NcActions,
		NcAvatar,
		NcCheckboxRadioSwitch,
		TuneIcon,
	},

	props: {
		share: {
			type: Object,
			required: true,
		},
	},

	emits: ['update', 'remove'],

	data() {
		return {
			expanded: false,
			ADD_TRACKS,
			CONTROL,
			EDIT_PLAYLIST,
			SHARE,
		}
	},

	computed: {
		displayName() {
			return this.share.displayName || this.share.receiver || t('music_radio', 'Public link')
		},

		/**
		 * The shorthand for what this share amounts to. "Contributor" is the interesting
		 * one — it is the combination the app exists for: can put music on, cannot decide
		 * what plays.
		 *
		 * @return {string}
		 */
		roleLabel() {
			if (this.has(CONTROL)) {
				return t('music_radio', 'Co-host')
			}
			if (this.has(ADD_TRACKS)) {
				return t('music_radio', 'Contributor')
			}
			return t('music_radio', 'Listener')
		},
	},

	methods: {
		has(bit) {
			return can(this.share.permissions, bit)
		},

		toggle(bit, enabled) {
			let permissions = this.share.permissions
			permissions = enabled ? (permissions | bit) : (permissions & ~bit)
			// Never leave a share granting nothing at all.
			this.$emit('update', this.share, permissions | LISTEN)
		},
	},
}
</script>

<style scoped>
.music-radio-share {
	padding-block: 0.5rem;
	border-bottom: 1px solid var(--color-border);
}

.music-radio-share__head {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.music-radio-share__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1 1 auto;
}

.music-radio-share__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-share__role {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.music-radio-share__detail {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0.5rem 0 0.25rem 2.75rem;
}
</style>
