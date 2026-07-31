<!--
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Putting a track on the channel from the public page.
  -
  - Only rendered when the link its visitor followed says uploading is allowed; the server
  - checks the same thing again on every request. Whoever uploads has no account, so there
  - is no library to pick from — a file off their device is the only thing they can offer.
  -
  - A dialog rather than a panel on the page, for two reasons. It matches the signed-in
  - view, where adding music is a button beside the channel title. And a public page is a
  - fixed-height shell whose `#content` is `position: fixed` with `overflow: clip`, so a
  - panel below the playlist is the first thing to be cut off — while a dialog is teleported
  - to `<body>` and is unaffected by any of that.
  -
  - The two outcomes of pressing Add want opposite things, and that is what shapes this.
  - A refusal needs the file, the reason and the button all still in front of you. A track
  - that landed wants the playlist it just joined, which the dialog's own backdrop was
  - covering — so it closes and says so in a toast.
  -
  - Both are expressible from the footer because NcDialogButton awaits its callback and
  - closes unless it returns exactly `false`. The upload used to sit in the body precisely
  - because that was not understood: anything in the button row was assumed to close
  - unconditionally, which would have taken the failure message with it.
-->
<template>
	<NcDialog
		:name="t('music_radio', 'Add a track')"
		:buttons="buttons"
		size="normal"
		@closing="$emit('close')">
		<!-- The hook is on an element this component owns: NcDialog does not pass
		     attributes through to what it renders. -->
		<div class="music-radio-upload" data-testid="public-upload-dialog">
			<p class="music-radio-upload__hint">
				{{ t('music_radio', 'Anyone listening will hear it. It joins the end of the playlist, and you can take it back off again from this browser.') }}
			</p>

			<!--
				The drop zone wraps the real file input rather than replacing it. A div that
				only listens for `drop` is unreachable by keyboard and invisible to a screen
				reader, and dropping a file is not something every pointer can do at all —
				so the input stays, does the actual work, and the surface around it is an
				additional way in for anyone who wants it.
			-->
			<div
				class="music-radio-upload__dropzone"
				:class="{ 'music-radio-upload__dropzone--over': draggingOver }"
				data-testid="public-upload-dropzone"
				@dragenter.prevent="draggingOver = true"
				@dragover.prevent="draggingOver = true"
				@dragleave="onDragLeave"
				@drop.prevent="onDrop">
				<label class="music-radio-upload__label" :for="inputId">
					{{ t('music_radio', 'Drop an audio file here, or choose one') }}
				</label>
				<input
					:id="inputId"
					ref="input"
					class="music-radio-upload__input"
					type="file"
					accept="audio/*"
					:disabled="uploading"
					data-testid="public-upload-input"
					@change="onPick">

				<p v-if="file" class="music-radio-upload__chosen" data-testid="public-upload-chosen">
					{{ file.name }} · {{ formattedSize }}
				</p>
			</div>

			<!--
				Only while something is actually in flight. A bar sitting at 0% before anyone
				has pressed anything reads as a stalled upload.

				It reaches 100% when the last byte has been *sent*, which is not when the
				track is ready — the server still has to read the tags and work out how long
				it is. Hence the label change rather than a bar that appears to finish and
				then does nothing.
			-->
			<div v-if="uploading" class="music-radio-upload__progress" data-testid="public-upload-progress">
				<NcProgressBar :value="progressPercent" size="medium" />
				<p class="music-radio-upload__hint">
					{{ progressPercent < 100
						? t('music_radio', 'Uploading… {percent}%', { percent: progressPercent })
						: t('music_radio', 'Processing…') }}
				</p>
			</div>

			<!--
				Assertive rather than polite: the outcome is the whole point of pressing the
				button, and a failure needs to interrupt rather than queue behind whatever
				the player is announcing.
			-->
			<p
				v-if="message !== ''"
				class="music-radio-upload__message"
				:class="{ 'music-radio-upload__message--error': failed }"
				role="alert"
				data-testid="public-upload-message">
				{{ message }}
			</p>
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'

import { showSuccess } from '@nextcloud/dialogs'

import { errorMessage, uploadToPublicChannel } from '../utils/api.js'
import { formatBytes } from '../utils/format.js'

/**
 * The server's own limit, from MusicLibrary::MAX_TRACK_BYTES.
 *
 * Duplicated here on purpose. Checking it in the browser is not the enforcement — the
 * server refuses the file regardless — it is what turns a minute of watching a progress
 * bar fill up before a 413 into an immediate, specific answer.
 */
const MAX_BYTES = 100 * 1024 * 1024

export default {
	name: 'PublicUpload',

	components: {
		NcDialog,
		NcProgressBar,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	emits: ['uploaded', 'close'],

	data() {
		return {
			file: null,
			uploading: false,
			message: '',
			failed: false,
			draggingOver: false,
			progressPercent: 0,
		}
	},

	computed: {
		/** The page can only ever show one of these, but an id must still be unique. */
		inputId() {
			return 'music-radio-upload-input'
		},

		formattedSize() {
			return this.file ? formatBytes(this.file.size) : ''
		},

		/**
		 * The action itself, where a dialog's action belongs.
		 *
		 * There is no separate Close: the header's × and Escape both already do that, and a
		 * row holding "Close" beside nothing else made the actual thing you came here to do
		 * look like part of the form rather than the point of the dialog.
		 *
		 * NcDialogButton awaits the callback and closes unless it returns exactly `false` —
		 * which is the whole reason this can live in the footer now. `upload()` returns
		 * `false` when the file was refused, so the dialog stays put with its message, the
		 * file, and the button to try again; on success it returns nothing and the dialog
		 * closes itself, which is what we wanted anyway.
		 */
		buttons() {
			return [
				{
					label: t('music_radio', 'Add to the channel'),
					variant: 'primary',
					disabled: this.uploading || this.file === null,
					'data-testid': 'public-upload-submit',
					callback: () => this.upload(),
				},
			]
		},
	},

	methods: {
		onPick(event) {
			this.accept(event.target.files?.[0] ?? null)
		},

		/**
		 * `dragleave` also fires when the pointer crosses onto a child element, which would
		 * flicker the highlight off and on for every label and paragraph inside the zone.
		 * Only a departure from the zone itself counts.
		 *
		 * @param {DragEvent} event
		 */
		onDragLeave(event) {
			if (!event.currentTarget.contains(event.relatedTarget)) {
				this.draggingOver = false
			}
		},

		/**
		 * @param {DragEvent} event
		 */
		onDrop(event) {
			this.draggingOver = false

			const files = Array.from(event.dataTransfer?.files ?? [])
			if (files.length === 0) {
				// A drag can carry text, a URL, or a directory, none of which arrive as
				// files. Saying so beats appearing to ignore the drop.
				this.reject(t('music_radio', 'That is not a file this page can add'))
				return
			}

			if (files.length > 1) {
				// One request carries one file — the endpoint reads a single upload — and an
				// anonymous visitor may only make ten an hour, so quietly firing off a
				// sequence would burn somebody's whole allowance on one careless drop.
				this.reject(t('music_radio', 'One file at a time, please. Drop just the one you want to add.'))
				return
			}

			this.accept(files[0])
		},

		/**
		 * Take a file on, or explain why not.
		 *
		 * @param {File|null} file
		 */
		accept(file) {
			this.message = ''
			this.failed = false

			if (file !== null && file.size > MAX_BYTES) {
				this.file = null
				this.reject(t('music_radio', 'That file is too big. The limit is {limit}.', {
					limit: formatBytes(MAX_BYTES),
				}))
				return
			}

			this.file = file
		},

		/**
		 * @param {string} reason
		 */
		reject(reason) {
			this.failed = true
			this.message = reason
		},

		/**
		 * Send the chosen file.
		 *
		 * @return {Promise<boolean|undefined>} `false` when it was refused, which is what
		 *   keeps the dialog open — see the `buttons` computed. Anything else closes it.
		 */
		async upload() {
			if (this.file === null) {
				return false
			}

			this.uploading = true
			this.message = ''
			this.failed = false
			this.progressPercent = 0

			// Held on to for the success message: the field is cleared before that is built,
			// and the server does not always have a better title than the filename.
			const chosenName = this.file.name

			try {
				const track = await uploadToPublicChannel(
					this.token,
					this.file,
					// The helper reports 0..1; NcProgressBar wants 0..100.
					(fraction) => {
						this.progressPercent = Math.round(fraction * 100)
					},
				)
				this.file = null
				// The input keeps showing the old filename otherwise, which reads as if
				// the upload had not gone through.
				if (this.$refs.input) {
					this.$refs.input.value = ''
				}

				// Out of the way once it has worked. The dialog used to stay open on the
				// grounds that somebody adding one track often has another — but it also
				// left its own backdrop over the playlist the new track had just joined,
				// so the usual next move was to dismiss it by hand.
				//
				// The outcome still has to be said, so it moves to a toast: the message
				// used to be the reason for keeping the dialog, and closing without one
				// would leave an upload looking like it had gone nowhere.
				showSuccess(t('music_radio', 'Added “{title}” to the channel', {
					title: track.title ?? chosenName,
				}))
				this.$emit('uploaded')
				// Nothing returned: NcDialog reads that as "close", and its `closing` event
				// is what tells the page to unmount this.
			} catch (error) {
				// A failure stays here, where the file and the button still are. It is the
				// one outcome that needs somewhere to go next.
				this.failed = true
				this.message = errorMessage(error, t('music_radio', 'That file could not be added'))
				return false
			} finally {
				this.uploading = false
			}
		},
	},
}
</script>

<style scoped>
.music-radio-upload {
	display: flex;
	flex-direction: column;
	align-items: start;
	gap: 0.5rem;
	padding-block: 0.5rem;
}

.music-radio-upload__hint,
.music-radio-upload__label {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

/*
 * A dashed outline is the one convention people already read as "you can drop here", and
 * it costs nothing to anyone who never drags anything: it still contains an ordinary file
 * input and an ordinary label.
 */
.music-radio-upload__dropzone {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	inline-size: 100%;
	padding: 1rem;
	border: 2px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large, 0.5rem);
	transition: background-color 0.1s ease-in-out, border-color 0.1s ease-in-out;
}

.music-radio-upload__dropzone--over {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.music-radio-upload__input {
	max-inline-size: 100%;
}

.music-radio-upload__chosen {
	margin: 0;
	font-size: 0.9em;
	overflow-wrap: anywhere;
}

.music-radio-upload__progress {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
	inline-size: 100%;
}

.music-radio-upload__message {
	margin: 0;
	font-size: 0.9em;
}

.music-radio-upload__message--error {
	color: var(--color-error-text);
}
</style>
