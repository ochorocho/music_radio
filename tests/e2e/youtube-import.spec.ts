/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Importing a track from a YouTube link.
 *
 * Driven against tests/fixtures/fake-yt-dlp rather than the real thing. Real downloads
 * need egress, are rate-limited, break whenever YouTube changes, and cannot be made to
 * fail on demand — so every one of these would be flaky and the failure cases would be
 * untestable entirely. The stub makes the whole pipeline deterministic: endpoint, queue,
 * background job, progress, the file landing in the owner's music folder, and the track
 * appearing in the playlist.
 *
 * What the stub does is chosen by the video id in the link, so each test carries its own
 * scenario in the request it makes. An earlier version used a shared control file and was
 * intermittent: the file is written from this container and read from the web one, and the
 * sync between them loses to a worker that picks the job up a moment later.
 *
 * Run them with `ddev music-radio-e2e`, which points the app at the stub, starts an import
 * worker, and puts the previous configuration back afterwards. Run against a normal
 * instance these would try to fetch video ids that do not exist, so the first test checks
 * the setup and says so rather than letting sixteen tests fail obscurely.
 *
 * The one thing this cannot cover is whether yt-dlp itself still works, which no test here
 * could establish anyway — that is what `occ music_radio:ytdlp:status` and the admin setup
 * check are for.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

/**
 * Eleven characters each, so they survive the app's own link parsing and reach the stub
 * as a real video id would.
 */
const VIDEO = {
	ok: 'stubOK00000',
	private: 'stubPRIVATE',
	unavailable: 'stubUNAVAIL',
	broken: 'stubBROKEN0',
	geoBlocked: 'stubGEOBLOK',
	live: 'stubLIVE000',
	tooLong: 'stubTOOLONG',
	noFile: 'stubNOFILE0',
	slow: 'stubSLOW000',
} as const

const link = (id: string) => `https://www.youtube.com/watch?v=${id}`

let channelTitle = ''
let channelId = 0

function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

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

/** The one import on this channel, whatever state it has reached. */
async function currentImport(page: Page) {
	const result = await api(page, 'GET', `${API}/channels/${channelId}/imports`)
	return result.body.imports[0] ?? null
}

async function waitForStatus(page: Page, status: string) {
	await expect.poll(async () => (await currentImport(page))?.status, { timeout: 45_000 })
		.toBe(status)
}

/**
 * Fail once, clearly, rather than sixteen times obscurely.
 *
 * Without the stub these tests ask the real YouTube for video ids that do not exist, and
 * every one of them times out with a message about polling rather than about setup.
 */
test('the suite is set up to run', async ({ page }) => {
	await page.goto(APP_PATH)

	const probe = await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url: link(VIDEO.ok) })
	expect(probe.status).toBe(202)

	await expect
		.poll(async () => (await currentImport(page))?.status, { timeout: 30_000 })
		.not.toBe('queued')

	const state = await currentImport(page)
	expect(
		state.status,
		'These tests need the yt-dlp stub and an import worker. Run them with `ddev music-radio-e2e`.',
	).not.toBe('failed')
})

test.beforeEach(async ({ page }) => {
	channelTitle = uniqueTitle('Import')
	await page.goto(APP_PATH)
	const created = await api(page, 'POST', `${API}/channels`, { title: channelTitle })
	expect(created.status).toBe(201)
	channelId = created.body.id
})

test.afterEach(async ({ page }) => {
	if (channelId) {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

// ------------------------------------------------------------------ the link

test('a link that is not a YouTube video is refused without asking the server', async ({ page }) => {
	await page.goto(APP_PATH)
	await page.getByRole('listitem').filter({ hasText: channelTitle }).click()

	await page.getByTestId('add-youtube').click()
	await expect(page.getByTestId('youtube-import-dialog')).toBeVisible()

	// Watched so the assertion is about the request never happening, not merely about the
	// message that follows it.
	let posted = false
	page.on('request', (request) => {
		if (request.method() === 'POST' && request.url().includes('/imports')) {
			posted = true
		}
	})

	await page.getByTestId('youtube-import-url').locator('input').fill('https://vimeo.com/12345')
	await page.getByRole('button', { name: 'Add', exact: true }).click()

	await expect(page.getByTestId('youtube-import-dialog')).toContainText(/youtube/i)
	expect(posted).toBe(false)
})

test('the server refuses anything that is not a YouTube video link', async ({ page }) => {
	await page.goto(APP_PATH)

	for (const url of [
		'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
		'https://www.youtube.com/watch?v=dQw4w9WgXcQ --exec=id',
		'https://169.254.169.254/watch?v=dQw4w9WgXcQ',
		'https://www.youtube.com@evil.example/watch?v=dQw4w9WgXcQ',
		'file:///etc/passwd',
		'https://www.youtube.com/playlist?list=PLabcdefghijklmnop',
	]) {
		const result = await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url })
		expect(result.status, `${url} should be refused`).toBe(400)
	}
})

// ------------------------------------------------------------- the happy path

test('a link becomes a track on the playlist', async ({ page, db }) => {
	await page.goto(APP_PATH)
	await page.getByRole('listitem').filter({ hasText: channelTitle }).click()

	await page.getByTestId('add-youtube').click()
	await page.getByTestId('youtube-import-url').locator('input').fill(link(VIDEO.ok))
	await page.getByRole('button', { name: 'Add', exact: true }).click()

	// Shown straight away, before any polling has happened.
	await expect(page.getByTestId('import-queue')).toBeVisible()
	await expect(page.getByTestId('import-row')).toHaveCount(1)

	// And resolves on its own, without the page being reloaded.
	await expect(page.getByTestId('import-state'))
		.toContainText(/added to the playlist/i, { timeout: 45_000 })

	// The stub reports the video's title as "Stub Test Track", but the file it produces
	// carries its own ID3 tags — and those are what the track is named after. That is the
	// point of --embed-metadata plus a server-side probe: what ends up on the playlist is
	// read out of the audio, not taken on trust from whatever the downloader said.
	await expect(page.getByTestId('playlist')).toContainText('Test tone 440Hz', { timeout: 15_000 })

	const rows = await db.query<Array<{ title: string, artist: string, mimetype: string, added_by: string, duration_source: number }>>(
		'select title, artist, mimetype, added_by, duration_source from oc_music_radio_tracks where channel_id = ?',
		[channelId],
	)
	expect(rows).toHaveLength(1)
	expect(rows[0].title).toBe('Test tone 440Hz')
	// Typed from the bytes by the server, not from the extension or anything claimed.
	expect(rows[0].mimetype).toBe('audio/mpeg')
	// 1 is DURATION_SOURCE_PROBE: the length was measured from the file itself.
	expect(rows[0].duration_source).toBe(1)
	expect(rows[0].added_by).toBe('admin')
})

test('the imported file lands in the channel owner\'s music folder', async ({ page, db }) => {
	await page.goto(APP_PATH)

	const started = await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url: link(VIDEO.ok) })
	expect(started.status).toBe(202)

	await waitForStatus(page, 'done')

	const paths = await db.query<Array<{ path: string }>>(
		`select f.path from oc_filecache f
		 join oc_music_radio_tracks t on t.file_id = f.fileid
		 where t.channel_id = ?`,
		[channelId],
	)
	expect(paths).toHaveLength(1)
	expect(paths[0].path).toMatch(/^files\/Music\//)
})

// ----------------------------------------------------------------- failures

const failures: Array<[string, string, RegExp]> = [
	['a private video', VIDEO.private, /private/i],
	['a video that is gone', VIDEO.unavailable, /not available/i],
	['a broken extractor', VIDEO.broken, /downloader|update/i],
	['a geo-blocked video', VIDEO.geoBlocked, /country/i],
	['a live stream', VIDEO.live, /live/i],
	['a video over the length limit', VIDEO.tooLong, /longer than/i],
	['a download that produced nothing', VIDEO.noFile, /no audio/i],
]

for (const [name, videoId, expected] of failures) {
	test(`${name} is explained rather than just failing`, async ({ page }) => {
		await page.goto(APP_PATH)

		const started = await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url: link(videoId) })
		expect(started.status).toBe(202)

		await waitForStatus(page, 'failed')

		const failed = await currentImport(page)
		expect(failed.error).toMatch(expected)
		// A code as well as a sentence: the sentence is for the person, the code is what
		// survives translation and what anything else would key off.
		expect(failed.errorCode).toBeTruthy()
		expect(failed.trackId).toBeNull()
	})
}

test('a failed import leaves nothing behind in the owner\'s files', async ({ page, db }) => {
	await page.goto(APP_PATH)

	await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url: link(VIDEO.noFile) })
	await waitForStatus(page, 'failed')

	const tracks = await api(page, 'GET', `${API}/channels/${channelId}/tracks`)
	expect(tracks.body.tracks).toHaveLength(0)
})

// ---------------------------------------------------------------- cancelling

test('a running import can be stopped', async ({ page }) => {
	await page.goto(APP_PATH)

	const started = await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url: link(VIDEO.slow) })
	expect(started.status).toBe(202)
	const importId = started.body.import.id

	// Wait until it is actually running: cancelling a queued import takes a different path
	// through the service than stopping one mid-download.
	await waitForStatus(page, 'running')

	const cancelled = await api(page, 'DELETE', `${API}/channels/${channelId}/imports/${importId}`)
	expect(cancelled.status).toBe(204)

	await waitForStatus(page, 'cancelled')

	// The point of stopping it.
	const tracks = await api(page, 'GET', `${API}/channels/${channelId}/tracks`)
	expect(tracks.body.tracks).toHaveLength(0)
})

test('a finished import can be cleared off the list', async ({ page }) => {
	await page.goto(APP_PATH)

	const started = await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url: link(VIDEO.ok) })
	await waitForStatus(page, 'done')

	const cleared = await api(page, 'DELETE', `${API}/channels/${channelId}/imports/${started.body.import.id}`)
	expect(cleared.status).toBe(204)

	expect(await currentImport(page)).toBeNull()

	// Clearing the record must not remove the track it produced.
	const tracks = await api(page, 'GET', `${API}/channels/${channelId}/tracks`)
	expect(tracks.body.tracks).toHaveLength(1)
})

// -------------------------------------------------------------------- limits

test('the same video is not imported twice at once', async ({ page }) => {
	await page.goto(APP_PATH)

	const first = await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url: link(VIDEO.slow) })
	expect(first.status).toBe(202)

	// The same video written a different way, to show the refusal is about the video and
	// not about the string.
	const second = await api(page, 'POST', `${API}/channels/${channelId}/imports`, {
		url: `https://youtu.be/${VIDEO.slow}`,
	})
	expect(second.status).toBe(409)

	await api(page, 'DELETE', `${API}/channels/${channelId}/imports/${first.body.import.id}`)
	await waitForStatus(page, 'cancelled')
})

test('a channel that is not yours cannot be imported into', async ({ page }) => {
	await page.goto(APP_PATH)

	// Not 403: saying "forbidden" would confirm the channel exists.
	const result = await api(page, 'POST', `${API}/channels/999999/imports`, { url: link(VIDEO.ok) })
	expect(result.status).toBe(404)
})
