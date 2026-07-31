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
 * A byte count as something readable.
 *
 * Written here rather than taken from `@nextcloud/files`, which the app does not depend on
 * and which would be a large addition for one formatter. Binary units, matching how
 * Nextcloud reports file sizes elsewhere.
 *
 * @param {number} bytes
 * @return {string}
 */
export function formatBytes(bytes) {
	if (!Number.isFinite(bytes) || bytes < 0) {
		return '–'
	}

	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let value = bytes
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}

	// Whole bytes are always whole; anything scaled reads better with one decimal, and
	// without a trailing ".0" when it lands exactly.
	const rounded = unit === 0 ? value : Math.round(value * 10) / 10

	return `${rounded} ${units[unit]}`
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
