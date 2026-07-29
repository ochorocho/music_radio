<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Picking who to share a channel with.
  -
  - Written by hand rather than reused: SharingInput lives inside the Files app and is not
  - importable, and NcSelectUsers only renders options it is handed. The lookup itself is
  - core's autocomplete endpoint.
-->
<template>
	<NcSelect
		:model-value="null"
		:options="options"
		:loading="loading"
		:filterable="false"
		:placeholder="t('music_radio', 'Name, group or team…')"
		:input-label="t('music_radio', 'Share with a person, group or team')"
		label="label"
		data-testid="sharee-select"
		@search="onSearch"
		@update:model-value="onPick">
		<template #option="option">
			<span class="music-radio-sharee">
				<NcAvatar
					:user="option.shareType === 0 ? option.receiver : undefined"
					:display-name="option.label"
					:is-no-user="option.shareType !== 0"
					:size="24" />
				<span class="music-radio-sharee__label">{{ option.label }}</span>
				<span v-if="option.typeLabel" class="music-radio-sharee__type">{{ option.typeLabel }}</span>
			</span>
		</template>
	</NcSelect>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Core reports what it found as a `source` string; these are the ones this app can act
 * on, mapped onto the share types stored in the database.
 */
const SOURCE_TO_SHARE_TYPE = {
	users: 0,
	groups: 1,
	circles: 7,
	teams: 7,
}

const SEARCH_DEBOUNCE_MS = 300

export default {
	name: 'ShareeSelect',

	components: {
		NcAvatar,
		NcSelect,
	},

	props: {
		/** Receivers already shared with, so they are not offered again. */
		exclude: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['pick'],

	data() {
		return {
			options: [],
			loading: false,
		}
	},

	created() {
		this.searchTimer = null
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		onSearch(query) {
			clearTimeout(this.searchTimer)
			if (!query || query.length < 1) {
				this.options = []
				return
			}
			this.searchTimer = setTimeout(() => this.search(query), SEARCH_DEBOUNCE_MS)
		},

		async search(query) {
			this.loading = true
			try {
				// core/autocomplete/get, rather than the files_sharing sharees endpoint:
				// that one demands an itemType it recognises and quietly forces email
				// shares into the results for anything it does not.
				const { data } = await axios.get(generateOcsUrl('core/autocomplete/get'), {
					params: {
						search: query,
						itemType: 'music_radio_channel',
						shareTypes: [0, 1, 7],
						limit: 20,
					},
				})

				const excluded = new Set(this.exclude.map((e) => `${e.shareType}:${e.receiver}`))

				this.options = (data.ocs?.data ?? [])
					.map((entry) => {
						const shareType = SOURCE_TO_SHARE_TYPE[entry.source]
						if (shareType === undefined) {
							return null
						}
						return {
							label: entry.label,
							receiver: entry.id,
							shareType,
							typeLabel: this.typeLabel(shareType),
						}
					})
					.filter(Boolean)
					.filter((option) => !excluded.has(`${option.shareType}:${option.receiver}`))
			} catch (error) {
				this.options = []
			} finally {
				this.loading = false
			}
		},

		typeLabel(shareType) {
			switch (shareType) {
			case 1: return t('music_radio', 'Group')
			case 7: return t('music_radio', 'Team')
			default: return ''
			}
		},

		onPick(option) {
			if (option) {
				this.$emit('pick', option)
				this.options = []
			}
		},
	},
}
</script>

<style scoped>
.music-radio-sharee {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	min-width: 0;
}

.music-radio-sharee__label {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.music-radio-sharee__type {
	margin-inline-start: auto;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}
</style>
