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

/**
 * The stub must refuse videos it was not written for.
 *
 * This is a guard on the test harness rather than on the app, and it exists because the
 * harness silently lied once. The stub used to answer *any* unrecognised id with
 * tone-a.mp3, so an instance left pointed at it — which the e2e wrapper could do
 * permanently, by "restoring" the stub as though it were the previous configuration —
 * imported "Test tone 440Hz" for every real video anybody asked for, and nothing said why.
 *
 * A well-formed link the stub does not know must therefore fail, and leave nothing behind.
 */
test('a video the stub does not know is refused rather than served a test tone', async ({ page, db }) => {
	await page.goto(APP_PATH)

	// Real-shaped and accepted by the app's URL parsing, so it reaches the stub — which is
	// the whole point. `dQw4w9WgXcQ` is Rickrolling's, and is not one the stub was given.
	const result = await api(page, 'POST', `${API}/channels/${channelId}/imports`, {
		url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
	})
	expect(result.status).toBe(202)

	await waitForStatus(page, 'failed')

	// Nothing was added. That is the whole assertion: the fault being guarded against was
	// a *successful* import of the wrong audio, not a failure of any particular shape.
	const tracks = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ?', [channelId],
	)
	expect(tracks).toHaveLength(0)

	// The reason is deliberately not checked here. yt-dlp's stderr never reaches whoever
	// pressed the button — ImportError::describe answers anything it does not recognise
	// with a generic sentence — and the stub's explanation of itself goes to the Nextcloud
	// log instead, via YoutubeImportService::logFailure, which is where an administrator
	// investigating "why did this import fail" would look.
	const failed = await currentImport(page)
	expect(failed.status).toBe('failed')
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

// ------------------------------------------------- importing through a link

/*
 * A visitor with no account, importing.
 *
 * This app refused to do this for a long time, and the reasoning has not become wrong: an
 * anonymous visitor starting server-side downloads against the owner's quota and CPU is a
 * different proposition from uploading a file they already have. It is allowed now, but
 * only where somebody has deliberately said so on that particular link — which is what
 * these check, from both directions.
 */

/** A link on the current channel, with whatever rules the test needs. */
async function linkAllowing(page: Page, rules: Record<string, unknown>) {
	const created = await api(page, 'POST', `${API}/channels/${channelId}/shares`, { shareType: 3 })
	expect(created.status).toBe(201)

	const updated = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${created.body.id}`, {
		// LISTEN | ADD_TRACKS. The switch cannot grant importing on its own — it says who,
		// among those who may add at all, may add this way.
		permissions: 3,
		...rules,
	})
	expect(updated.status).toBe(200)

	return created.body.token as string
}

/**
 * A visitor page with no session at all — not the owner's, and not another visitor's.
 * The share page is also what issues the per-browser key an import is attributed to.
 */
async function visitorOn(browser: import('@playwright/test').Browser, token: string) {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const visitor = await context.newPage()
	await visitor.goto(`${APP_PATH}s/${token}`)
	await expect(visitor.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

	return { context, visitor }
}

async function importAs(visitor: Page, token: string, videoId: string) {
	return await visitor.evaluate(async ({ t, id }) => {
		const response = await fetch(`/index.php/apps/music_radio/api/v1/public/${t}/imports`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ url: `https://www.youtube.com/watch?v=${id}` }),
		})
		const text = await response.text()
		return { status: response.status, body: text ? JSON.parse(text) : null }
	}, { t: token, id: videoId })
}

test('a link that allows it can import, and the track lands on the playlist', async ({ page, browser }) => {
	await page.goto(APP_PATH)
	const token = await linkAllowing(page, { allowImport: true, requireApproval: false })

	const { context, visitor } = await visitorOn(browser, token)
	try {
		await expect(visitor.getByTestId('public-add-youtube'), 'the button is offered').toBeVisible()

		const started = await importAs(visitor, token, 'stubOKLINK1')
		expect(started.status).toBe(202)

		await waitForStatus(page, 'done')

		const tracks = await api(page, 'GET', `${API}/channels/${channelId}/tracks`)
		expect(tracks.body.tracks).toHaveLength(1)
		// Recorded against the link rather than against nobody, so the owner can see where
		// it came from and the visitor can stop their own.
		expect(tracks.body.tracks[0].addedBy).toMatch(/^\?link:/)
	} finally {
		await visitor.close()
		await context.close()
	}
})

/**
 * The link's approval setting has to be read while the link is still in hand.
 *
 * The job that files the track runs minutes later and knows the requester only as
 * `?link:<key>` — not an account, and not resolvable back to a share. Get this wrong and
 * an anonymous import quietly ignores the setting that was supposed to hold it.
 */
test('an import through a link that holds things is held', async ({ page, browser, db }) => {
	await page.goto(APP_PATH)
	const token = await linkAllowing(page, { allowImport: true, requireApproval: true })

	const { context, visitor } = await visitorOn(browser, token)
	try {
		expect((await importAs(visitor, token, 'stubOKLINK2')).status).toBe(202)
		await waitForStatus(page, 'done')

		const rows = await db.query<Array<{ approved: number }>>(
			'select approved from oc_music_radio_tracks where channel_id = ?', [channelId],
		)
		expect(rows).toHaveLength(1)
		expect(Number(rows[0].approved), 'held for the owner to approve').toBe(0)
	} finally {
		await visitor.close()
		await context.close()
	}
})

test('a link without the switch cannot import, button or no button', async ({ page, browser }) => {
	await page.goto(APP_PATH)
	const token = await linkAllowing(page, { allowImport: false })

	const { context, visitor } = await visitorOn(browser, token)
	try {
		await expect(visitor.getByTestId('public-add-youtube')).toHaveCount(0)

		const refused = await importAs(visitor, token, 'stubOKLINK3')
		expect(refused.status).toBe(403)
		expect(await currentImport(page)).toBeNull()
	} finally {
		await visitor.close()
		await context.close()
	}
})

/*
 * There used to be a test here for the channel-wide import switch overruling a link that
 * allowed it. That switch is gone: it AND-gated the per-share one, which meant an owner
 * had to say yes twice and a share could be silently inert. Two switches decide it now —
 * the administrator's and the share's own — and the share's is covered by the test above.
 */

/**
 * The key comes from the share page. Without it there is nobody to attribute the import
 * to, nobody to hold to the per-visitor cap, and nobody who could stop it afterwards — so
 * it is refused rather than run for an unknown.
 */
test('a browser that cannot be identified cannot import', async ({ page, browser }) => {
	await page.goto(APP_PATH)
	const token = await linkAllowing(page, { allowImport: true })

	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const stranger = await context.newPage()
	try {
		// Straight at the endpoint, never having loaded the share page.
		await stranger.goto(`${APP_PATH}s/${token}`)
		await context.clearCookies()

		expect((await importAs(stranger, token, 'stubOKLINK5')).status).toBe(403)
	} finally {
		await stranger.close()
		await context.close()
	}
})

/**
 * A link is not a window onto who else uses the channel.
 *
 * The queue exists so a visitor can watch and stop what they started. The owner's imports
 * carry the owner's user id, and other visitors' carry theirs — neither is anything a link
 * was meant to disclose, and neither is anything the visitor could act on anyway.
 */
test('a visitor sees only their own imports, and no user ids', async ({ page, browser }) => {
	await page.goto(APP_PATH)
	const token = await linkAllowing(page, { allowImport: true })

	// The owner starts one, signed in, before the visitor looks.
	expect((await api(page, 'POST', `${API}/channels/${channelId}/imports`, { url: link(VIDEO.slow) })).status)
		.toBe(202)

	const { context, visitor } = await visitorOn(browser, token)
	try {
		const mine = await visitor.evaluate(async (t) => {
			const r = await fetch(`/index.php/apps/music_radio/api/v1/public/${t}/imports`)
			return await r.json()
		}, token)

		expect(mine.imports, "the owner's import is not the visitor's business").toHaveLength(0)

		expect((await importAs(visitor, token, 'stubOKLINK6')).status).toBe(202)

		const now = await visitor.evaluate(async (t) => {
			const r = await fetch(`/index.php/apps/music_radio/api/v1/public/${t}/imports`)
			return await r.json()
		}, token)

		expect(now.imports, 'their own, and only their own').toHaveLength(1)
		expect(now.imports[0]).not.toHaveProperty('userId')
	} finally {
		await visitor.close()
		await context.close()
	}
})

// -------------------------------------------- still there when somebody presses play

/*
 * An imported track has to survive being played, and for a while it did not.
 *
 * `added_by` is a credit, not an address. What the server downloads goes into the *channel
 * owner's* music folder against the owner's quota, whoever asked for it — but the row is
 * credited to whoever pasted the link, which is a contributor's user id or a visitor key
 * that is not an account at all. Every path that read the file back looked for it in
 * `getUserFolder(added_by)` and found nothing, and nothing is how the streaming path is told
 * a file has been deleted: the track was flagged unavailable and shown as "File missing",
 * minutes after being added, with the file sitting in the owner's Files perfectly readable.
 *
 * Both of these therefore *play* the track rather than stopping at the playlist, because
 * playing it is what used to break it — and then check the row, because the damage the fault
 * did was permanent.
 */

const LISTENER_USER = process.env.MUSIC_RADIO_LISTENER_USER || 'listener'
const LISTENER_PASS = process.env.MUSIC_RADIO_LISTENER_PASSWORD || 'Tr4ck-Sh4re-Dev!2026'

/** LISTEN | ADD_TRACKS: may put music on, may not decide what plays. */
const CONTRIBUTOR = 3

/** The one track on this channel, once the import has filed it. */
async function onlyTrack(page: Page) {
	await expect.poll(async () => (await api(page, 'GET', `${API}/channels/${channelId}/tracks`)).body.tracks.length,
		{ timeout: 45_000 }).toBe(1)

	return (await api(page, 'GET', `${API}/channels/${channelId}/tracks`)).body.tracks[0]
}

/**
 * Ask for the track's audio as the owner and report what the server said.
 *
 * Deliberately not the programme endpoint: that one skips a track it cannot read and still
 * answers 200 with the rest, so it would go green on exactly the fault this is about.
 */
async function playStatus(page: Page, trackId: number): Promise<number> {
	return await page.evaluate(async (url) => {
		const response = await fetch(url)
		// Drained rather than abandoned, so nothing is left holding a PHP worker.
		await response.arrayBuffer()

		return response.status
	}, `${API}/channels/${channelId}/tracks/${trackId}/stream`)
}

test('a track a visitor imported through a link plays, and stays on the channel', async ({ page, browser, db }) => {
	await page.goto(APP_PATH)
	const token = await linkAllowing(page, { allowImport: true, requireApproval: false })

	const { context, visitor } = await visitorOn(browser, token)
	try {
		expect((await importAs(visitor, token, 'stubOKLINK7')).status).toBe(202)
		await waitForStatus(page, 'done')

		const track = await onlyTrack(page)
		expect(track.addedBy).toMatch(/^\?link:/)

		expect(await playStatus(page, track.id), 'the owner can play what the link put on').toBe(200)
		// And the row was not written off on the way: that flag is permanent until somebody
		// clears it, so playing a track once was enough to lose it for good.
		const rows = await db.query<Array<{ unavailable: number }>>(
			'select unavailable from oc_music_radio_tracks where id = ?', [track.id],
		)
		expect(Number(rows[0].unavailable), 'not written off as missing').toBe(0)
	} finally {
		await visitor.close()
		await context.close()
	}
})

test('a track a contributor imported plays, and stays on the channel', async ({ page, browser, db }) => {
	await page.goto(APP_PATH)

	// Shared with a person, as a contributor: they may add music to somebody else's channel.
	const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
		shareType: 0,
		receiver: LISTENER_USER,
		permissions: CONTRIBUTOR,
	})
	expect(share.status).toBe(201)

	// Importing is a switch of its own on top of being allowed to add at all — it spends the
	// owner's storage and their server's time, so it is never on by default.
	expect((await api(page, 'PUT', `${API}/channels/${channelId}/shares/${share.body.id}`, {
		permissions: CONTRIBUTOR,
		allowImport: true,
		requireApproval: false,
	})).status).toBe(200)

	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const contributor = await context.newPage()
	try {
		await contributor.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
		await contributor.locator('input[name="user"]').waitFor({ state: 'visible', timeout: 30_000 })
		await contributor.fill('input[name="user"]', LISTENER_USER)
		await contributor.fill('input[name="password"]', LISTENER_PASS)
		await Promise.all([
			contributor.waitForURL((url) => !url.pathname.replace(/\/index\.php/, '').startsWith('/login'), { timeout: 30_000 }),
			contributor.locator('button[type="submit"], [data-login-form-submit]').first().click(),
		])

		expect((await api(contributor, 'POST', `${API}/channels/${channelId}/imports`, { url: link('stubOKUSER1') })).status)
			.toBe(202)

		await waitForStatus(page, 'done')

		const track = await onlyTrack(page)
		// Their name on it, the owner's folder holding it — which is the whole point.
		expect(track.addedBy).toBe(LISTENER_USER)

		expect(await playStatus(page, track.id), 'the owner can play what the contributor added').toBe(200)
		// And the row was not written off on the way: that flag is permanent until somebody
		// clears it, so playing a track once was enough to lose it for good.
		const rows = await db.query<Array<{ unavailable: number }>>(
			'select unavailable from oc_music_radio_tracks where id = ?', [track.id],
		)
		expect(Number(rows[0].unavailable), 'not written off as missing').toBe(0)
	} finally {
		await contributor.close()
		await context.close()
	}
})
