/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Estimating how far this browser's clock is from the server's.
 *
 * Everyone tuned in derives their playback position from a server timestamp, so a
 * client that is wrong about the time is wrong about the music. The estimate follows
 * Jellyfin's SyncPlay approach: take several samples and keep the one whose round trip
 * was fastest, rather than averaging them.
 *
 * Averaging is the obvious thing and the wrong thing. The offset calculation assumes the
 * request and response legs took equally long; on a jittery connection that assumption
 * is violated badly and often, and averaging folds every one of those bad samples in.
 * The fastest round trip is the one where the assumption is least strained.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import { CLOCK_SAMPLES } from './syncConstants.js'

const TIME_URL = generateUrl('/apps/music_radio/api/v1/time')

/**
 * Absolute wall-clock milliseconds from a monotonic source.
 *
 * `performance.now()` is used rather than `Date.now()` because it cannot jump: an NTP
 * correction mid-handshake would otherwise corrupt the very measurement meant to
 * correct for clock error.
 *
 * @return {number}
 */
export function monotonicNow() {
	return performance.timeOrigin + performance.now()
}

export class ServerClock {

	constructor() {
		/** @type {Array<{offset: number, rtt: number}>} */
		this.samples = []
	}

	/** Whether enough samples have landed to trust the estimate. */
	get ready() {
		return this.samples.length >= 2
	}

	/** Milliseconds to add to local time to get server time. */
	get offset() {
		const best = this.best()
		return best ? best.offset : 0
	}

	/** Round-trip of the sample currently being trusted — useful for a status readout. */
	get rtt() {
		const best = this.best()
		return best ? best.rtt : 0
	}

	/** Server time, as best this client can tell. */
	now() {
		return monotonicNow() + this.offset
	}

	best() {
		if (this.samples.length === 0) {
			return null
		}
		return this.samples.reduce((a, b) => (b.rtt < a.rtt ? b : a))
	}

	/**
	 * Fire one probe and fold the result in.
	 *
	 * @return {Promise<boolean>} whether the sample was usable
	 */
	async probe() {
		const sentAt = monotonicNow()
		let serverTimeMs
		try {
			({ data: { serverTimeMs } } = await axios.get(TIME_URL))
		} catch (error) {
			return false
		}
		const receivedAt = monotonicNow()

		if (!Number.isFinite(serverTimeMs)) {
			return false
		}

		const rtt = receivedAt - sentAt
		// Assume the server answered at the midpoint of the round trip.
		const offset = serverTimeMs - (sentAt + receivedAt) / 2

		this.samples.push({ offset, rtt })
		if (this.samples.length > CLOCK_SAMPLES) {
			this.samples.shift()
		}

		return true
	}

	/**
	 * Several probes in quick succession, to fill the window before playback starts.
	 *
	 * @param {number} count
	 * @param {number} spacingMs
	 */
	async burst(count, spacingMs) {
		for (let i = 0; i < count; i++) {
			await this.probe()
			if (i < count - 1) {
				await new Promise((resolve) => setTimeout(resolve, spacingMs))
			}
		}
	}

	reset() {
		this.samples = []
	}
}
