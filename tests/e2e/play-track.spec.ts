/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Picking a track from the playlist changes what the *channel* is broadcasting, for
 * everyone tuned in — there is no private playback anywhere in the app, so two songs can
 * never be heard at once.
 *
 * And deciding what plays belongs to whoever runs the channel: a listener or contributor
 * gets no play button and is refused by the server if they ask anyway.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { BrowserContext, Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

const LISTENER_USER = process.env.MUSIC_RADIO_LISTENER_USER || 'listener'
const LISTENER_PASS = process.env.MUSIC_RADIO_LISTENER_PASSWORD || 'Tr4ck-Sh4re-Dev!2026'

/** Mirrors lib/Permission.php. */
const LISTEN = 1
const ADD_TRACKS = 2

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

async function readSync(page: Page) {
	return JSON.parse(await page.getByTestId('sync-debug').textContent() ?? '{}')
}

async function openChannel(page: Page, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
}

async function tuneIn(page: Page) {
	await page.getByTestId('tune-in').click()
	await expect
		.poll(async () => (await readSync(page)).trackId, { timeout: 30_000, intervals: [250] })
		.not.toBeNull()
}

/**
 * A title unique to this run.
 *
 * Channels are picked from the navigation by name, so a leftover channel from an earlier
 * aborted run would otherwise be selected instead of the one just created — and every
 * assertion would quietly describe the wrong playlist.
 */
function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

let channelTitle = ''

let channelId: number

test.beforeEach(async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	channelTitle = uniqueTitle('Play Track Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title: channelTitle })
	channelId = created.body.id

	const fileRows = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' order by name",
	)
	await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
		fileIds: fileRows.map((r) => r.fileid),
	})
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('pressing play on a track puts it on air for a second listener too', async ({ page, browser, db }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

	// A second listener, tuned in and hearing whatever is on.
	const second: BrowserContext = await browser.newContext({
		ignoreHTTPSErrors: true,
		storageState: 'tests/e2e/.auth/admin.json',
	})
	const listener = await second.newPage()

	try {
		await openChannel(page, channelTitle)
		await tuneIn(page)
		await openChannel(listener, channelTitle)
		await tuneIn(listener)

		// Pick the last track, which is not the one currently playing.
		const rows = await db.query<Array<{ id: number }>>(
			'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
		)
		const target = rows[rows.length - 1].id

		await page.getByTestId('track').last().getByTestId('play-track').locator('button').click()

		// The person who pressed it hears it…
		await expect
			.poll(async () => (await readSync(page)).trackId, { timeout: 20_000, intervals: [250] })
			.toBe(target)

		// …and so does everyone else, without touching anything.
		await expect
			.poll(async () => (await readSync(listener)).trackId, { timeout: 20_000, intervals: [500] })
			.toBe(target)

		// Both end up playing the same thing at the same moment. Polled, not sampled: the
		// one who pressed play takes the new state from the response immediately, while
		// the other only learns of it on its next poll, so they are briefly apart by
		// design.
		await expect.poll(async () => {
			const [x, y] = await Promise.all([readSync(page), readSync(listener)])
			return x.trackId === y.trackId ? Math.abs(x.offsetMs - y.offsetMs) : Number.MAX_SAFE_INTEGER
		}, { timeout: 30_000, intervals: [500] }).toBeLessThan(750)

		const [a, b] = await Promise.all([readSync(page), readSync(listener)])
		expect(a.status).toBe('playing')
		expect(b.status).toBe('playing')
	} finally {
		await listener.close()
		await second.close()
	}
})

test('pressing play starts a paused channel rather than leaving it silent', async ({ page, db }) => {
	// Never started: the channel is paused from creation.
	const before = await api(page, 'GET', `${API}/channels/${channelId}/state`)
	expect(before.body.status).toBe('paused')

	await openChannel(page, channelTitle)

	await page.getByTestId('track').first().getByTestId('play-track').locator('button').click()

	await expect
		.poll(async () => (await api(page, 'GET', `${API}/channels/${channelId}/state`)).body.status,
			{ timeout: 20_000, intervals: [500] })
		.toBe('playing')

	const rows = await db.query<Array<{ paused: number }>>(
		'select paused from oc_music_radio_channels where id = ?', [channelId],
	)
	expect(Number(rows[0].paused)).toBe(0)
})

test('the track on air is marked in the playlist', async ({ page, db }) => {
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)
	await api(page, 'POST', `${API}/channels/${channelId}/control`, {
		action: 'jumpTo',
		trackId: rows[1].id,
	})

	await openChannel(page, channelTitle)
	await tuneIn(page)

	// Exactly one row is marked, and it is the second.
	await expect(page.locator('[data-testid="track"][data-onair="true"]')).toHaveCount(1, { timeout: 20_000 })
	await expect(page.getByTestId('track').nth(1)).toHaveAttribute('data-onair', 'true')
})

test('there is no private playback: only one audio element is ever playing', async ({ page }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

	await openChannel(page, channelTitle)
	await tuneIn(page)

	// Press play on a different track, as a user would.
	await page.getByTestId('track').last().getByTestId('play-track').locator('button').click()
	await page.waitForTimeout(2500)

	// The old preview player created a second <audio> alongside the broadcast, so two
	// songs could be heard at once. Nothing may play except the channel.
	const audio = await page.evaluate(() => {
		const all = Array.from(document.querySelectorAll('audio'))
		return {
			total: all.length,
			playing: all.filter((a) => !a.paused && !a.ended).length,
		}
	})

	// Assert there is something to count first: this check is worthless if the elements
	// are invisible to the DOM, which is exactly how it once passed against nothing.
	expect(audio.total).toBeGreaterThan(0)
	expect(audio.playing).toBe(1)
})

test.describe('who may decide what plays', () => {
	let listenerContext: BrowserContext
	let listenerPage: Page

	test.beforeEach(async ({ browser }) => {
		listenerContext = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
		listenerPage = await listenerContext.newPage()
		await listenerPage.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
		await listenerPage.locator('input[name="user"]').waitFor({ state: 'visible', timeout: 30_000 })
		await listenerPage.fill('input[name="user"]', LISTENER_USER)
		await listenerPage.fill('input[name="password"]', LISTENER_PASS)
		await Promise.all([
			listenerPage.waitForURL((url) => !url.pathname.replace(/\/index\.php/, '').startsWith('/login'), { timeout: 30_000 }),
			listenerPage.locator('button[type="submit"], [data-login-form-submit]').first().click(),
		])
	})

	test.afterEach(async () => {
		await listenerPage?.close()
		await listenerContext?.close()
	})

	test('a contributor gets no play buttons and is refused by the server', async ({ page, db }) => {
		// They may add music, but not decide what is playing.
		await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN | ADD_TRACKS,
		})

		await openChannel(listenerPage, channelTitle)

		// The playlist is there, with no way to seize the broadcast from it.
		await expect(listenerPage.getByTestId('track')).toHaveCount(3)
		await expect(listenerPage.getByTestId('play-track')).toHaveCount(0)
		await expect(listenerPage.getByTestId('player-controls')).toHaveCount(0)
		// They can still add music — that is the point of a contributor.
		await expect(listenerPage.getByTestId('add-tracks')).toBeVisible()

		// And the API says no even when asked directly.
		const rows = await db.query<Array<{ id: number }>>(
			'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
		)
		const refused = await api(listenerPage, 'POST', `${API}/channels/${channelId}/control`, {
			action: 'jumpTo',
			trackId: rows[0].id,
		})
		expect(refused.status).toBe(403)
	})

	test('a plain listener gets no play buttons either', async ({ page }) => {
		await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN,
		})

		await openChannel(listenerPage, channelTitle)

		await expect(listenerPage.getByTestId('track')).toHaveCount(3)
		await expect(listenerPage.getByTestId('play-track')).toHaveCount(0)
		// They can hear the channel, which is what a listener is for.
		await expect(listenerPage.getByTestId('tune-in')).toBeVisible()
	})
})
