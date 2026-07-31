/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin wrappers over the app's REST endpoints. Components deal in plain objects and
 * never build URLs themselves.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/music_radio/api/v1' + path)

export async function fetchChannels() {
	const { data } = await axios.get(url('/channels'))
	return data.channels
}

export async function createChannel(title, description = null) {
	const { data } = await axios.post(url('/channels'), { title, description })
	return data
}

export async function updateChannel(id, payload) {
	const { data } = await axios.put(url(`/channels/${id}`), payload)
	return data
}

export async function deleteChannel(id) {
	await axios.delete(url(`/channels/${id}`))
}

export async function fetchTracks(channelId) {
	const { data } = await axios.get(url(`/channels/${channelId}/tracks`))
	return data
}

/**
 * @param {number} channelId
 * @param {number[]} fileIds
 * @param {Object<number, number>} durationHints fileId => ms, measured in the browser.
 *   Only used if the server-side probe cannot read the file's headers.
 */
export async function addTracks(channelId, fileIds, durationHints = {}) {
	const { data } = await axios.post(url(`/channels/${channelId}/tracks`), { fileIds, durationHints })
	return data
}

/**
 * Ask the server to fetch the audio from a link and add it to the channel.
 *
 * Answers immediately with a queued import rather than a track: the download and the
 * transcode take tens of seconds, so the work happens in a background job and progress is
 * followed with fetchImports().
 *
 * @param {number} channelId
 * @param {string} videoUrl a YouTube link. Only the video id survives the server's
 *   parsing, so anything else in the string is discarded rather than rejected.
 * @param {string|null} token share token, or null when signed in
 * @return {Promise<object>} the queued import
 */
export async function startImport(channelId, videoUrl, token = null) {
	const { data } = await axios.post(importsUrl(channelId, token), { url: videoUrl })
	return data.import
}

/**
 * Where imports live, for whichever page is asking.
 *
 * The same shape as tracksUrl and streamUrl: a public page addresses the token endpoints
 * and a signed-in one addresses the channel, and nothing above here has to care which.
 *
 * @param {number} channelId
 * @param {string|null} token share token, or null when signed in
 * @return {string}
 */
function importsUrl(channelId, token = null) {
	return token ? url(`/public/${token}/imports`) : url(`/channels/${channelId}/imports`)
}

/**
 * @param {number} channelId
 * @param {string|null} token share token, or null when signed in
 * @return {Promise<{imports: object[], capabilities: object}>} the imports plus whether
 *   this server can import at all, so the UI can explain itself rather than offering a
 *   button that will only ever fail
 */
export async function fetchImports(channelId, token = null) {
	const { data } = await axios.get(importsUrl(channelId, token))
	return { imports: data.imports, capabilities: data.capabilities ?? {} }
}

/**
 * Stop a running import, or clear a finished one off the list. The server decides which
 * of those it is from the import's state.
 *
 * @param {number} channelId
 * @param {number} importId
 * @param {string|null} token share token, or null when signed in
 */
export async function dismissImport(channelId, importId, token = null) {
	await axios.delete(`${importsUrl(channelId, token)}/${importId}`)
}

/**
 * @param {number} channelId
 * @param {number[]} trackIds the complete playlist in its new order — the server rejects
 *   anything that is not a permutation of what it currently holds, which is how a
 *   concurrent append by someone else is caught instead of silently dropped.
 * @param {string|null} [token] share token, when reordering through a public link
 */
export async function reorderTracks(channelId, trackIds, token = null) {
	const { data } = await axios.put(trackOrderUrl(channelId, token), { trackIds })
	return data.tracks
}

/**
 * @param {number} channelId
 * @param {number} trackId
 * @param {object} payload for example `{ disabled: true }` or `{ durationMs: 1000 }`
 */
export async function updateTrack(channelId, trackId, payload) {
	const { data } = await axios.put(url(`/channels/${channelId}/tracks/${trackId}`), payload)
	return data
}

export async function deleteTrack(channelId, trackId) {
	await axios.delete(url(`/channels/${channelId}/tracks/${trackId}`))
}

/**
 * The endpoints a player needs, addressed either as a signed-in user or through a share
 * token. Keeping both forms behind one helper is what lets the whole synchronised player
 * be reused verbatim on the public page.
 *
 * @param {number} channelId
 * @param {string|null} token share token, or null when signed in
 * @return {string}
 */
export function stateUrl(channelId, token = null) {
	return token ? url(`/public/${token}/state`) : url(`/channels/${channelId}/state`)
}

/**
 * @param {number} channelId
 * @param {string|null} token
 * @return {string}
 */
export function tracksUrl(channelId, token = null) {
	return token ? url(`/public/${token}/tracks`) : url(`/channels/${channelId}/tracks`)
}

/**
 * Driving the broadcast. Reachable through a link only when its owner granted control,
 * which the server checks — the token in the URL is not itself permission to do this.
 *
 * @param {number} channelId
 * @param {string|null} token
 * @return {string}
 */
export function controlUrl(channelId, token = null) {
	return token ? url(`/public/${token}/control`) : url(`/channels/${channelId}/control`)
}

/**
 * @param {number} channelId
 * @param {string|null} token
 * @return {string}
 */
export function playbackSettingsUrl(channelId, token = null) {
	return token
		? url(`/public/${token}/playback-settings`)
		: url(`/channels/${channelId}/playback-settings`)
}

/**
 * @param {number} channelId
 * @param {string|null} token
 * @return {string}
 */
export function trackOrderUrl(channelId, token = null) {
	return token ? url(`/public/${token}/tracks/order`) : url(`/channels/${channelId}/tracks/order`)
}

/**
 * URL for an <audio> element. Served with HTTP range support, so the browser can seek
 * and Safari's initial two-byte probe is answered correctly.
 *
 * @param {number} channelId
 * @param {number} trackId
 * @param {string|null} token
 * @return {string}
 */
export function streamUrl(channelId, trackId, token = null) {
	return token
		? url(`/public/${token}/tracks/${trackId}/stream`)
		: url(`/channels/${channelId}/tracks/${trackId}/stream`)
}

/**
 * Upload a file straight onto a channel through a public link.
 *
 * The file is stored in the channel owner's Music folder, so this is only permitted on
 * links whose owner switched uploading on.
 *
 * @param {string} token share token
 * @param {File} file
 * @param {(fraction: number) => void} [onProgress] 0..1, for a progress bar
 * @return {Promise<object>} the track that was created
 */
export async function uploadToPublicChannel(token, file, onProgress = undefined) {
	const form = new FormData()
	form.append('file', file, file.name)

	const { data } = await axios.post(url(`/public/${token}/tracks`), form, {
		onUploadProgress: onProgress === undefined
			? undefined
			: (event) => onProgress(event.total ? event.loaded / event.total : 0),
	})

	return data.track
}

/**
 * Take back a track this browser uploaded through a link.
 *
 * The server decides whether that is allowed — see the `canRemove` flag it puts on each
 * track — so this simply asks.
 *
 * @param {string} token share token
 * @param {number} trackId
 */
export async function removeFromPublicChannel(token, trackId) {
	await axios.delete(url(`/public/${token}/tracks/${trackId}`))
}

/**
 * Vote for a track, or take the vote back.
 *
 * A toggle, and the server answers with the state after — the count includes everybody
 * else's votes, which this browser has no way to know.
 *
 * @param {number} channelId
 * @param {number} trackId
 * @return {Promise<{voted: boolean, votes: number}>}
 */
export async function voteForTrack(channelId, trackId) {
	const { data } = await axios.post(url(`/channels/${channelId}/tracks/${trackId}/vote`))
	return data
}

/**
 * The same, through a public link. Identified by the visitor cookie the share page set.
 *
 * @param {string} token share token
 * @param {number} trackId
 * @return {Promise<{voted: boolean, votes: number}>}
 */
export async function voteOnPublicChannel(token, trackId) {
	const { data } = await axios.post(url(`/public/${token}/tracks/${trackId}/vote`))
	return data
}

/**
 * @return {Promise<{shares: object[], capabilities: object}>} the shares plus what this
 *   server permits, so the UI does not offer options the server will refuse
 */
export async function fetchShares(channelId) {
	const { data } = await axios.get(url(`/channels/${channelId}/shares`))
	return { shares: data.shares, capabilities: data.capabilities ?? {} }
}

export async function createShare(channelId, payload) {
	const { data } = await axios.post(url(`/channels/${channelId}/shares`), payload)
	return data
}

export async function updateShare(channelId, shareId, payload) {
	const { data } = await axios.put(url(`/channels/${channelId}/shares/${shareId}`), payload)
	return data
}

export async function setSharePassword(channelId, shareId, password) {
	const { data } = await axios.put(
		url(`/channels/${channelId}/shares/${shareId}/password`),
		{ password },
	)
	return data
}

export async function deleteShare(channelId, shareId) {
	await axios.delete(url(`/channels/${channelId}/shares/${shareId}`))
}

/**
 * Pull a human-readable message out of a failed request, falling back to the raw error.
 *
 * @param {Error} error
 * @param {string} fallback
 * @return {string}
 */
export function errorMessage(error, fallback) {
	return error?.response?.data?.error || error?.message || fallback
}
