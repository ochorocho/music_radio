<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcContent app-name="music_radio">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew
					:text="t('music_radio', 'New channel')"
					data-testid="new-channel"
					@click="showCreateDialog = true">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
				</NcAppNavigationNew>

				<NcAppNavigationItem
					v-for="channel in channels"
					:key="channel.id"
					:name="channel.title"
					:active="channel.id === selectedId"
					@click="select(channel.id)">
					<template #icon>
						<RadioIcon :size="20" />
					</template>
					<template #counter>
						<NcCounterBubble :count="channel.trackCount" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<NcLoadingIcon v-if="loading" class="music-radio-loading" :size="44" />

			<NcEmptyContent
				v-else-if="channels.length === 0"
				:name="t('music_radio', 'No channels yet')"
				:description="t('music_radio', 'A channel is a playlist that broadcasts. Everyone tuned in hears the same track at the same moment.')">
				<template #icon>
					<RadioIcon />
				</template>
				<template #action>
					<NcButton variant="primary" @click="showCreateDialog = true">
						{{ t('music_radio', 'Create a channel') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<ChannelView
				v-else-if="selectedChannel"
				:key="selectedChannel.id"
				:channel="selectedChannel"
				:initial-import-capabilities="importCapabilities"
				@updated="onChannelUpdated"
				@deleted="onChannelDeleted" />

			<NcEmptyContent
				v-else
				:name="t('music_radio', 'Pick a channel')"
				:description="t('music_radio', 'Choose a channel from the list to see its playlist.')">
				<template #icon>
					<RadioIcon />
				</template>
			</NcEmptyContent>
		</NcAppContent>

		<ChannelDialog
			v-if="showCreateDialog"
			@close="showCreateDialog = false"
			@saved="onChannelCreated" />

		<!-- Mounted once, here, and never unmounted. Anywhere further down the tree it
		     would be destroyed the moment the listener opened a different channel, which
		     is exactly what used to stop the music. -->
		<GlobalPlayer />
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import RadioIcon from 'vue-material-design-icons/Radio.vue'
import { showError } from '@nextcloud/dialogs'

import { loadState } from '@nextcloud/initial-state'

import ChannelDialog from './components/ChannelDialog.vue'
import ChannelView from './components/ChannelView.vue'
import GlobalPlayer from './components/GlobalPlayer.vue'
import { errorMessage, fetchChannels } from './utils/api.js'
import { playerStore } from './utils/playerStore.js'

export default {
	name: 'App',

	components: {
		ChannelDialog,
		ChannelView,
		GlobalPlayer,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcButton,
		NcContent,
		NcCounterBubble,
		NcEmptyContent,
		NcLoadingIcon,
		PlusIcon,
		RadioIcon,
	},

	data() {
		return {
			channels: [],
			selectedId: null,
			loading: true,
			showCreateDialog: false,
			// Read once from the page, so a channel knows before its first paint whether
			// this server can import at all. The imports endpoint returns a fresh copy on
			// every poll, which is what picks up an administrator installing yt-dlp
			// without a reload.
			importCapabilities: loadState('music_radio', 'music_radio-initial-state', {})
				.importCapabilities ?? {},
		}
	},

	computed: {
		selectedChannel() {
			return this.channels.find((channel) => channel.id === this.selectedId) ?? null
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.channels = await fetchChannels()

				// Keep the current selection if it survived the reload, otherwise fall back
				// to the first channel so the pane is never pointlessly empty.
				if (!this.channels.some((channel) => channel.id === this.selectedId)) {
					this.selectedId = this.channels[0]?.id ?? null
				}
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not load your channels')))
			} finally {
				this.loading = false
			}
		},

		select(id) {
			this.selectedId = id
		},

		onChannelCreated(channel) {
			this.channels.push(channel)
			this.selectedId = channel.id
			this.showCreateDialog = false
		},

		onChannelUpdated(channel) {
			const index = this.channels.findIndex((c) => c.id === channel.id)
			if (index !== -1) {
				this.channels.splice(index, 1, channel)
			}
		},

		onChannelDeleted(id) {
			if (playerStore.isListeningTo(id)) {
				playerStore.tuneOut()
			}
			this.channels = this.channels.filter((channel) => channel.id !== id)
			if (this.selectedId === id) {
				this.selectedId = this.channels[0]?.id ?? null
			}
		},
	},
}
</script>

<style scoped>
.music-radio-loading {
	margin-top: 25vh;
}
</style>
