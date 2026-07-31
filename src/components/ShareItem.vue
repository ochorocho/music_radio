<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - One person, group or team this channel is shared with.
  -
  - The options themselves live in ShareOptions, which the public-link row renders too —
  - see that component for why there is only one list now.
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

			<NcButton
				variant="tertiary"
				:aria-label="expanded
					? t('music_radio', 'Hide what {name} can do', { name: displayName })
					: t('music_radio', 'Change what {name} can do', { name: displayName })"
				:aria-expanded="expanded"
				data-testid="share-expand"
				@click="expanded = !expanded">
				<template #icon>
					<ChevronUpIcon v-if="expanded" :size="20" />
					<ChevronDownIcon v-else :size="20" />
				</template>
			</NcButton>

			<NcActions>
				<NcActionButton data-testid="share-remove" @click="$emit('remove', share)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('music_radio', 'Stop sharing') }}
				</NcActionButton>
			</NcActions>
		</div>

		<div v-if="expanded" class="music-radio-share__detail">
			<ShareOptions
				:share="share"
				:server-can-import="serverCanImport"
				@update="(...args) => $emit('update', ...args)"
				@settings="(...args) => $emit('settings', ...args)" />
		</div>
	</li>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import ShareOptions from './ShareOptions.vue'
import { roleLabel } from '../utils/shareRoles.js'

export default {
	name: 'ShareItem',

	components: {
		ChevronDownIcon,
		ChevronUpIcon,
		DeleteIcon,
		NcActionButton,
		NcActions,
		NcAvatar,
		NcButton,
		ShareOptions,
	},

	props: {
		/** Whether this server can fetch from YouTube at all; the switch is moot otherwise. */
		serverCanImport: {
			type: Boolean,
			default: false,
		},

		share: {
			type: Object,
			required: true,
		},
	},

	emits: ['update', 'remove', 'settings'],

	data() {
		return {
			expanded: false,
		}
	},

	computed: {
		displayName() {
			return this.share.displayName || this.share.receiver || t('music_radio', 'Public link')
		},

		roleLabel() {
			return roleLabel(this.share.permissions)
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
	padding: 0.5rem 0 0.25rem 2.75rem;
}
</style>
