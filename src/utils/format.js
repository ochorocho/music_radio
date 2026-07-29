/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Milliseconds as m:ss, or h:mm:ss once it runs past an hour.
 *
 * @param {number|null} ms
 * @return {string} an en dash when the duration is not known yet
 */
export function formatDuration(ms) {
	if (ms === null || ms === undefined || !Number.isFinite(ms) || ms < 0) {
		return '–'
	}

	const totalSeconds = Math.round(ms / 1000)
	const seconds = totalSeconds % 60
	const minutes = Math.floor(totalSeconds / 60) % 60
	const hours = Math.floor(totalSeconds / 3600)

	const pad = (n) => String(n).padStart(2, '0')

	return hours > 0
		? `${hours}:${pad(minutes)}:${pad(seconds)}`
		: `${minutes}:${pad(seconds)}`
}

/**
 * Total playing time of a track list, ignoring tracks that have no known duration.
 *
 * @param {Array<{durationMs: number|null, playable: boolean}>} tracks
 * @return {number} milliseconds
 */
export function totalDuration(tracks) {
	return tracks.reduce((sum, track) => sum + (track.playable ? track.durationMs : 0), 0)
}
