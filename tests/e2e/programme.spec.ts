/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The programme endpoint: what a listener's `<audio>` element is actually given.
 *
 * The property every one of these is circling is that the body contains *more than the
 * track at the requested position*. That is the entire reason the endpoint exists — a
 * phone with its screen locked runs no JavaScript, so the only way it reaches the second
 * song is for the audio it is already holding to run on into it.
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

async function fetchProgramme(page: Page, url: string) {
	return await page.evaluate(async (url) => {
		const response = await fetch(url)
		const buffer = await response.arrayBuffer()
		return {
			status: response.status,
			contentType: response.headers.get('content-type'),
			cacheControl: response.headers.get('cache-control'),
			acceptRanges: response.headers.get('accept-ranges'),
			contentLength: response.headers.get('content-length'),
			byteLength: buffer.byteLength,
			// The first four bytes must be an MPEG-1 Layer III frame header, or no decoder
			// will start: 11 sync bits, version 11, layer 01.
			startsOnAFrame: buffer.byteLength >= 4 && (() => {
				const bytes = new Uint8Array(buffer, 0, 2)
				return bytes[0] === 0xFF
					&& (bytes[1] & 0xE0) === 0xE0
					&& (bytes[1] & 0x18) === 0x18
					&& (bytes[1] & 0x06) === 0x02
			})(),
		}
	}, url)
}

/** The first bytes of a body, as hex, so two rotations can be told apart. */
async function fetchProgrammeHead(page: Page, url: string) {
	return await page.evaluate(async (url) => {
		const response = await fetch(url)
		const buffer = await response.arrayBuffer()
		return Array.from(new Uint8Array(buffer, 0, 64)).map((b) => b.toString(16)).join('')
	}, url)
}

/** 128 kbit/s constant, so a millisecond of programme is exactly 16 bytes. */
const BYTES_PER_MS = 16

let channelId: number
let trackSizes: number[]

test.beforeEach(async ({ page, db }) => {
	await page.goto('/index.php/apps/music_radio/')
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	const created = await api(page, 'POST', `${API}/channels`, { title: 'Programme Test' })
	channelId = created.body.id

	// Two tracks, so "crosses into the next one" is a thing the body can be asked about.
	const fileRows = await db.query<Array<{ fileid: number, size: number }>>(
		"select fileid, size from oc_filecache where name in ('tone-a.mp3', 'tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
	)
	expect(fileRows.length).toBe(2)
	trackSizes = fileRows.map((row) => Number(row.size))

	await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
		fileIds: fileRows.map((row) => row.fileid),
	})
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('the programme is audio, and starts on a frame a decoder can pick up', async ({ page }) => {
	const result = await fetchProgramme(page, `${API}/channels/${channelId}/programme?from=0`)

	expect(result.status).toBe(200)
	expect(result.contentType).toContain('audio/mpeg')
	expect(result.byteLength).toBeGreaterThan(0)
	expect(Number(result.contentLength)).toBe(result.byteLength)
	expect(result.startsOnAFrame).toBe(true)
})

/**
 * The opposite of the per-track stream, which is deliberately cacheable.
 *
 * Two requests a second apart are legitimately different audio, because the programme has
 * moved on between them. A cached copy would put a listener wherever they were the last
 * time they asked.
 */
test('the programme is never cached', async ({ page }) => {
	const result = await fetchProgramme(page, `${API}/channels/${channelId}/programme?from=0`)

	expect(result.cacheControl).toContain('no-store')
	// A byte offset into a view of a moving programme means nothing, so ranges are refused
	// outright rather than answered with something that will not line up.
	expect(result.acceptRanges).toBe('none')
})

/**
 * A programme short enough to send whole is sent whole, once.
 *
 * The two fixture tones total a few seconds, so filling a half-hour budget would mean
 * hundreds of repetitions of the same two songs. Sending one lap instead is fewer bytes and
 * — because the element can loop it with no JavaScript at all — playback that never runs
 * out, which is the ceiling every other part of this design has to live with.
 */
test('a short programme comes back as exactly one lap', async ({ page }) => {
	const state = await api(page, 'GET', `${API}/channels/${channelId}/state`)
	const total = state.body.totalDurationMs as number
	expect(state.body.programmeLoops).toBe(true)

	const result = await fetchProgramme(page, `${API}/channels/${channelId}/programme?from=0`)

	// 128 kbit/s constant, so the length of a lap is arithmetic.
	expect(result.byteLength).toBeGreaterThan((total - 1000) * BYTES_PER_MS)
	expect(result.byteLength).toBeLessThan((total + 1000) * BYTES_PER_MS)
})

/**
 * And a lap joined part way through still contains every byte exactly once.
 *
 * The rotation is what makes it loopable: the piece of the first track that was skipped
 * comes back at the end, split at a frame header, so the body joins to its own beginning as
 * cleanly as it joins anywhere else.
 */
test('a lap joined mid-track is the same length as one joined at the start', async ({ page }) => {
	const fromStart = await fetchProgramme(page, `${API}/channels/${channelId}/programme?from=0`)
	const fromMiddle = await fetchProgramme(page, `${API}/channels/${channelId}/programme?from=1500`)

	expect(fromMiddle.byteLength).toBe(fromStart.byteLength)
	expect(fromMiddle.startsOnAFrame).toBe(true)
})

/**
 * The one that matters.
 *
 * A body longer than the track it starts in is the whole mechanism: it means the element
 * crosses the boundary out of its own buffer, with nothing running to help it.
 */
test('the body runs past the end of the track it starts in', async ({ page }) => {
	const result = await fetchProgramme(page, `${API}/channels/${channelId}/programme?from=0`)

	// The prepared copies are re-encoded, so they are not the source files' size — but the
	// programme is both tracks, and cannot be just one of them.
	expect(result.byteLength).toBeGreaterThan(Math.max(...trackSizes))
})

/**
 * Joining part way through gives the same programme, turned to start where the listener is.
 *
 * The length cannot change — a lap is a lap — so the thing to check is that the *audio* is
 * different: the body opens on the middle of a track rather than on its beginning.
 */
test('a lap joined mid-track opens on different audio', async ({ page }) => {
	const fromStart = await fetchProgrammeHead(page, `${API}/channels/${channelId}/programme?from=0`)
	const fromMiddle = await fetchProgrammeHead(page, `${API}/channels/${channelId}/programme?from=1500`)

	expect(fromMiddle).not.toBe(fromStart)
})

/**
 * A looping channel has no end to run off, so a position past the programme's length is
 * the same position as its remainder — and must produce the same audio, byte for byte.
 */
test('a looping channel wraps rather than running out', async ({ page }) => {
	const state = await api(page, 'GET', `${API}/channels/${channelId}/state`)
	const total = state.body.totalDurationMs as number
	expect(total).toBeGreaterThan(0)
	expect(state.body.loop).toBe(true)

	const past = await fetchProgramme(page, `${API}/channels/${channelId}/programme?from=${total * 3 + 500}`)
	const equivalent = await fetchProgramme(page, `${API}/channels/${channelId}/programme?from=500`)

	expect(past.status).toBe(200)
	expect(past.byteLength).toBe(equivalent.byteLength)
})

/**
 * A channel with nothing playable has no programme, and says so rather than handing back
 * an empty body — a client can tell "nothing here" from "here is silence".
 */
test('an empty channel has no programme', async ({ page }) => {
	const empty = await api(page, 'POST', `${API}/channels`, { title: 'Programme Test Empty' })

	const result = await fetchProgramme(page, `${API}/channels/${empty.body.id}/programme?from=0`)
	expect(result.status).toBe(404)

	await api(page, 'DELETE', `${API}/channels/${empty.body.id}`)
})
