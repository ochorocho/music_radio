<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="isEdit ? t('music_radio', 'Channel settings') : t('music_radio', 'New channel')"
		:buttons="buttons"
		size="normal"
		@closing="$emit('close')">
		<form class="music-radio-dialog" @submit.prevent="save">
			<NcTextField
				ref="titleField"
				v-model="title"
				:label="t('music_radio', 'Name')"
				:placeholder="t('music_radio', 'Late night jazz')"
				:error="titleError !== ''"
				:helper-text="titleError"
				required
				@update:model-value="titleError = ''" />

			<NcTextArea
				v-model="description"
				:label="t('music_radio', 'Description')"
				:placeholder="t('music_radio', 'What is this channel for?')"
				resize="vertical" />
		</form>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showError } from '@nextcloud/dialogs'

import { createChannel, errorMessage, updateChannel } from '../utils/api.js'

export default {
	name: 'ChannelDialog',

	components: {
		NcDialog,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Omit to create a new channel. */
		channel: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			title: this.channel?.title ?? '',
			description: this.channel?.description ?? '',
			titleError: '',
			saving: false,
		}
	},

	computed: {
		isEdit() {
			return this.channel !== null
		},

		buttons() {
			return [
				{
					label: t('music_radio', 'Cancel'),
					callback: () => this.$emit('close'),
				},
				{
					label: this.isEdit ? t('music_radio', 'Save') : t('music_radio', 'Create'),
					variant: 'primary',
					disabled: this.saving || this.title.trim() === '',
					callback: () => this.save(),
				},
			]
		},
	},

	methods: {
		async save() {
			const title = this.title.trim()
			if (title === '') {
				this.titleError = t('music_radio', 'Give the channel a name')
				return
			}

			this.saving = true
			try {
				const description = this.description.trim() || null
				const saved = this.isEdit
					? await updateChannel(this.channel.id, { title, description })
					: await createChannel(title, description)

				this.$emit('saved', saved)
			} catch (error) {
				showError(errorMessage(error, t('music_radio', 'Could not save the channel')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.music-radio-dialog {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding-block: 0.5rem;
}
</style>
