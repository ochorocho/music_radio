/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * Mime types the picker offers. The server independently rejects anything that is not
 * `audio/*`, so this list only shapes what the user sees.
 */
const AUDIO_MIME_TYPES = [
	'audio/mpeg',
	'audio/mp4',
	'audio/aac',
	'audio/ogg',
	'audio/flac',
	'audio/x-flac',
	'audio/wav',
	'audio/x-wav',
	'audio/webm',
]

/** Give up on measuring a single file rather than making the user wait. */
const MEASURE_TIMEOUT_MS = 4000
/** How many files to measure at once. */
const MEASURE_CONCURRENCY = 4

/**
 * Open the Files picker filtered to audio.
 *
 * @return {Promise<Array<{fileid: number, source: string, basename: string}>>}
 */
export async function pickAudioFiles() {
	const picker = getFilePickerBuilder(t('music_radio', 'Add music to this channel'))
		.setMimeTypeFilter(AUDIO_MIME_TYPES)
		.setMultiSelect(true)
		.allowDirectories(false)
		// The confirm button is not optional. The picker renders exactly the buttons it
		// is given, so without this it opens a dialog with no way to complete the
		// selection at all — it looks like the picker simply does not work.
		.setButtonFactory((selected) => [{
			label: selected.length > 0
				? n('music_radio', 'Add %n track', 'Add %n tracks', selected.length)
				: t('music_radio', 'Add'),
			variant: 'primary',
			disabled: selected.length === 0,
			callback: () => {},
		}])
		.build()

	return await picker.pickNodes()
}

/**
 * Best-effort duration measurement in the browser.
 *
 * The server reads durations out of the files themselves and that value wins; this is
 * only a fallback for files whose headers the server cannot parse (a known problem on
 * some external storages). Because it is a fallback, anything that fails or takes too
 * long is simply dropped rather than blocking the add.
 *
 * @param {Array<{fileid: number, source: string}>} nodes
 * @return {Promise<Object<number, number>>} fileId => duration in ms
 */
export async function measureDurations(nodes) {
	const hints = {}
	const queue = [...nodes]

	const worker = async () => {
		while (queue.length > 0) {
			const node = queue.shift()
			const ms = await measureOne(node.source)
			if (ms !== null) {
				hints[node.fileid] = ms
			}
		}
	}

	await Promise.all(
		Array.from({ length: Math.min(MEASURE_CONCURRENCY, nodes.length) }, worker),
	)

	return hints
}

/**
 * @param {string} src
 * @return {Promise<number|null>} duration in ms, or null if it could not be determined
 */
function measureOne(src) {
	return new Promise((resolve) => {
		const audio = new Audio()
		let settled = false

		const finish = (value) => {
			if (settled) {
				return
			}
			settled = true
			clearTimeout(timer)
			audio.removeAttribute('src')
			// Release the pending network request; without this the browser keeps
			// buffering files the user is not going to hear.
			audio.load()
			resolve(value)
		}

		const timer = setTimeout(() => finish(null), MEASURE_TIMEOUT_MS)

		audio.addEventListener('loadedmetadata', () => {
			// Streams of unknown length report Infinity.
			finish(Number.isFinite(audio.duration) ? Math.round(audio.duration * 1000) : null)
		})
		audio.addEventListener('error', () => finish(null))

		audio.preload = 'metadata'
		audio.src = src
	})
}
