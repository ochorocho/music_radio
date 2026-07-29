/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The audio endpoint's HTTP semantics. Browsers depend on these exactly: Safari will
 * not start a media element whose two-byte probe is answered wrongly, and no browser
 * can seek without correct 206 / Content-Range handling.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

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

/**
 * Issue a raw request from inside the page so it carries the session cookie, and report
 * the status, the headers we care about, and the byte length actually received.
 */
async function fetchRange(page: Page, url: string, range?: string) {
	return await page.evaluate(
		async ({ url, range }) => {
			const response = await fetch(url, {
				headers: range ? { Range: range } : {},
			})
			const buffer = await response.arrayBuffer()
			return {
				status: response.status,
				contentRange: response.headers.get('content-range'),
				contentLength: response.headers.get('content-length'),
				acceptRanges: response.headers.get('accept-ranges'),
				contentType: response.headers.get('content-type'),
				byteLength: buffer.byteLength,
			}
		},
		{ url, range },
	)
}

let channelId: number
let trackId: number
let fileSize: number

test.beforeEach(async ({ page, db }) => {
	await page.goto('/index.php/apps/music_radio/')
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	const created = await api(page, 'POST', `${API}/channels`, { title: 'Stream Test' })
	channelId = created.body.id

	const fileRows = await db.query<Array<{ fileid: number, size: number }>>(
		"select fileid, size from oc_filecache where name = 'tone-c.mp3' and path like 'files/Music/%' limit 1",
	)
	fileSize = Number(fileRows[0].size)

	const added = await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
		fileIds: [fileRows[0].fileid],
	})
	trackId = added.body.tracks[0].id
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('a request without a Range header returns the whole file', async ({ page }) => {
	const url = `${API}/channels/${channelId}/tracks/${trackId}/stream`
	const result = await fetchRange(page, url)

	expect(result.status).toBe(200)
	expect(result.byteLength).toBe(fileSize)
	expect(Number(result.contentLength)).toBe(fileSize)
	expect(result.contentType).toContain('audio/')
	// Without this header the browser will not attempt to seek at all.
	expect(result.acceptRanges).toBe('bytes')
	// A whole-file response must not claim to be partial.
	expect(result.contentRange).toBeNull()
})

test('Safari\'s opening two-byte probe is answered with a 206', async ({ page }) => {
	const url = `${API}/channels/${channelId}/tracks/${trackId}/stream`
	const result = await fetchRange(page, url, 'bytes=0-1')

	expect(result.status).toBe(206)
	expect(result.byteLength).toBe(2)
	expect(result.contentRange).toBe(`bytes 0-1/${fileSize}`)
	expect(Number(result.contentLength)).toBe(2)
})

test('a mid-file range returns exactly the bytes asked for', async ({ page }) => {
	const url = `${API}/channels/${channelId}/tracks/${trackId}/stream`
	const result = await fetchRange(page, url, 'bytes=1000-1999')

	expect(result.status).toBe(206)
	expect(result.byteLength).toBe(1000)
	expect(result.contentRange).toBe(`bytes 1000-1999/${fileSize}`)
})

test('an open-ended range runs to the end of the file', async ({ page }) => {
	const url = `${API}/channels/${channelId}/tracks/${trackId}/stream`
	const result = await fetchRange(page, url, 'bytes=1000-')

	expect(result.status).toBe(206)
	expect(result.byteLength).toBe(fileSize - 1000)
	expect(result.contentRange).toBe(`bytes 1000-${fileSize - 1}/${fileSize}`)
})

test('a suffix range returns the last bytes of the file', async ({ page }) => {
	const url = `${API}/channels/${channelId}/tracks/${trackId}/stream`
	const result = await fetchRange(page, url, 'bytes=-500')

	expect(result.status).toBe(206)
	expect(result.byteLength).toBe(500)
	expect(result.contentRange).toBe(`bytes ${fileSize - 500}-${fileSize - 1}/${fileSize}`)
})

test('a range past the end of the file is refused with 416', async ({ page }) => {
	const url = `${API}/channels/${channelId}/tracks/${trackId}/stream`
	const result = await fetchRange(page, url, `bytes=${fileSize + 1000}-`)

	expect(result.status).toBe(416)
	expect(result.byteLength).toBe(0)
	// The real length must be reported so the client can retry sensibly.
	expect(result.contentRange).toBe(`bytes */${fileSize}`)
})

test('the bytes returned for a range match the same span of the whole file', async ({ page }) => {
	// The strongest check: seeking must land on the actual audio at that offset, not
	// merely return the right *number* of bytes.
	const url = `${API}/channels/${channelId}/tracks/${trackId}/stream`

	const result = await page.evaluate(async ({ url }) => {
		const digest = async (buffer: ArrayBuffer) => {
			const hash = await crypto.subtle.digest('SHA-256', buffer)
			return Array.from(new Uint8Array(hash)).map((b) => b.toString(16).padStart(2, '0')).join('')
		}

		const whole = await (await fetch(url)).arrayBuffer()
		const slice = await (await fetch(url, { headers: { Range: 'bytes=2000-2999' } })).arrayBuffer()

		return {
			fromWhole: await digest(whole.slice(2000, 3000)),
			fromRange: await digest(slice),
		}
	}, { url })

	expect(result.fromRange).toBe(result.fromWhole)
})

test('a track id from another channel is not served', async ({ page }) => {
	// The track exists, but not on this channel — a URL cannot be steered across
	// channels by swapping ids.
	const other = await api(page, 'POST', `${API}/channels`, { title: 'Other Channel' })
	try {
		const result = await fetchRange(
			page,
			`${API}/channels/${other.body.id}/tracks/${trackId}/stream`,
		)
		expect(result.status).toBe(404)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${other.body.id}`)
	}
})

test('a track whose file has gone is marked unavailable and drops out of the broadcast', async ({ page, db }) => {
	// Point the row at a file id that does not exist, as if the file had been deleted.
	await db.query(
		'update oc_music_radio_tracks set file_id = 99999999 where id = ?', [trackId],
	)

	const result = await fetchRange(page, `${API}/channels/${channelId}/tracks/${trackId}/stream`)
	expect(result.status).toBe(404)

	// Serving it failed, so it must have been taken off the timeline rather than left
	// to broadcast silence.
	const rows = await db.query<Array<{ unavailable: number }>>(
		'select unavailable from oc_music_radio_tracks where id = ?', [trackId],
	)
	expect(Number(rows[0].unavailable)).toBe(1)

	const tracks = await api(page, 'GET', `${API}/channels/${channelId}/tracks`)
	expect(tracks.body.tracks[0].playable).toBe(false)
})
