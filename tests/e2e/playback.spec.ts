/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Does a browser actually play it? The HTTP-level assertions in stream.spec.ts prove the
 * bytes are right; this proves a real media element accepts them, decodes them, reports
 * the correct duration, and advances.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

async function api(page: Page, method: string, path: string, body?: unknown) {
	return await page.evaluate(
		async ({ method, path, body }) => {
			const response = await fetch(path, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as any).OC?.requestToken ?? '',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await response.text()
			return { status: response.status, body: text ? JSON.parse(text) : null }
		},
		{ method, path, body },
	)
}

test('the browser decodes the stream, reports the right duration, and plays it', async ({ page, db }) => {
	// The element is muted, so Chromium's autoplay policy does not block it.
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	const created = await api(page, 'POST', `${API}/channels`, { title: 'Playback Test' })
	const channelId = created.body.id as number

	try {
		// tone-c.mp3 is a generated sine tone of exactly 8 seconds.
		const fileRows = await db.query<Array<{ fileid: number }>>(
			"select fileid from oc_filecache where name = 'tone-c.mp3' and path like 'files/Music/%' and path not like 'files/Music/%/%' limit 1",
		)
		const added = await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
			fileIds: [fileRows[0].fileid],
		})
		const trackId = added.body.tracks[0].id as number

		const url = `${API}/channels/${channelId}/tracks/${trackId}/stream`

		const result = await page.evaluate(async ({ url }) => {
			const audio = new Audio()
			audio.src = url
			audio.muted = true

			const metadata = await new Promise<{ duration: number }>((resolve, reject) => {
				const timer = setTimeout(() => reject(new Error('loadedmetadata timed out')), 15000)
				audio.addEventListener('loadedmetadata', () => {
					clearTimeout(timer)
					resolve({ duration: audio.duration })
				})
				audio.addEventListener('error', () => {
					clearTimeout(timer)
					reject(new Error('media element reported an error: ' + JSON.stringify(audio.error)))
				})
			})

			await audio.play()
			const startedAt = audio.currentTime
			await new Promise((r) => setTimeout(r, 1200))
			const advancedTo = audio.currentTime

			// Seek, and confirm the element lands where it was told — this is the part
			// that only works because the server answers range requests.
			audio.currentTime = 5
			await new Promise<void>((resolve) => {
				if (audio.seekable.length > 0) {
					audio.addEventListener('seeked', () => resolve(), { once: true })
					setTimeout(() => resolve(), 5000)
				} else {
					resolve()
				}
			})
			const afterSeek = audio.currentTime
			const seekableEnd = audio.seekable.length > 0 ? audio.seekable.end(0) : 0

			audio.pause()

			return {
				duration: metadata.duration,
				startedAt,
				advancedTo,
				afterSeek,
				seekableEnd,
			}
		}, { url })

		// The decoder agrees with what getID3 measured server-side. If these ever
		// disagreed, the broadcast timeline would drift at every track boundary.
		expect(Math.abs(result.duration - 8)).toBeLessThan(0.15)

		// Playback actually progressed rather than stalling on a broken stream.
		expect(result.advancedTo).toBeGreaterThan(result.startedAt)

		// The whole file is seekable, and seeking landed where asked.
		expect(Math.abs(result.seekableEnd - 8)).toBeLessThan(0.2)
		expect(Math.abs(result.afterSeek - 5)).toBeLessThan(0.5)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})
