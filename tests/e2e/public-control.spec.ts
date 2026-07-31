/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Being the DJ through a public link.
 *
 * A link used to be capped at listening and uploading, so the public page had no transport
 * controls and no way to touch the running order. It can now be given CONTROL and
 * EDIT_PLAYLIST — the same two switches a named person's share offers — and what those
 * promise has to actually work for somebody with no account at all.
 *
 * Every check runs in a context with `storageState: undefined`, a genuinely signed-out
 * browser. Reusing the authenticated one would pass even if the public routes were closed.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Browser, BrowserContext, Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'
const SHARE_TYPE_LINK = 3

const LISTEN = 1
const ADD_TRACKS = 2
const CONTROL = 4
const EDIT_PLAYLIST = 8

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

/** Straight at a public endpoint, with no request token — these routes have none. */
async function publicApi(page: Page, method: string, path: string, body?: unknown) {
	return await page.evaluate(
		async ({ method, path, body }) => {
			const response = await fetch(path, {
				method,
				headers: { 'Content-Type': 'application/json' },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await response.text()
			return { status: response.status, body: text ? JSON.parse(text) : null }
		},
		{ method, path, body },
	)
}

function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

/**
 * Wait until the player has worked out what the channel is doing.
 *
 * The play/pause button sends `pause` only when it already believes the channel is
 * broadcasting, and `play` otherwise — so pressing it before the first state poll has
 * landed sends the opposite of what the test meant, on a channel that is already playing,
 * and nothing appears to happen.
 */
async function waitForStatus(page: Page, status: string) {
	await expect
		.poll(async () => JSON.parse(await page.getByTestId('sync-debug').textContent() ?? '{}').status,
			{ timeout: 30_000, intervals: [250] })
		.toBe(status)
}

/** Ordered track ids as the server would broadcast them for an unvoted channel. */
async function playOrder(db: any, id: number): Promise<number[]> {
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order asc, id asc', [id],
	)
	return rows.map((r: { id: number }) => Number(r.id))
}

let channelTitle = ''
let channelId: number
let shareId: number
let token: string
let anonContext: BrowserContext
let anonPage: Page

async function grant(page: Page, permissions: number) {
	const result = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${shareId}`, { permissions })
	expect(result.status).toBe(200)
}

async function anonymous(browser: Browser): Promise<[BrowserContext, Page]> {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	return [context, await context.newPage()]
}

/**
 * Reveal a playlist row's actions.
 *
 * NcActions only builds a *menu* once a row has several actions; a lone one is rendered as
 * a plain button in the row. What a public row has depends on what the link was granted —
 * an uploader gets only "remove", a curator also gets move up and down — so this has to
 * cope with both rather than clicking the last button and hoping.
 */
async function openRowMenu(page: Page, index: number) {
	const toggle = page.getByTestId('track').nth(index).locator('.action-item__menutoggle')
	if (await toggle.count() > 0) {
		await toggle.click()
	}
}

test.beforeEach(async ({ page, browser, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	channelTitle = uniqueTitle('Public Control')
	channelId = (await api(page, 'POST', `${API}/channels`, { title: channelTitle })).body.id

	const files = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
	)
	await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
		fileIds: files.map((r) => r.fileid),
	})

	const share = (await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
		shareType: SHARE_TYPE_LINK,
	})).body
	shareId = share.id
	token = share.token;

	[anonContext, anonPage] = await anonymous(browser)
})

test.afterEach(async ({ page }) => {
	await anonPage?.close()
	await anonContext?.close()
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('a listen-only link gets no controls, and the endpoint refuses anyway', async () => {
	await anonPage.goto(`${APP_PATH}s/${token}`)

	// Something present first: an absence assertion made before the page has rendered
	// passes for the wrong reason.
	await expect(anonPage.getByTestId('public-channel-title')).toHaveText(channelTitle, { timeout: 20_000 })
	await expect(anonPage.getByTestId('on-air')).toBeVisible()

	await expect(anonPage.getByTestId('player-controls')).toHaveCount(0)

	// And the page is not the only thing saying no.
	const refused = await publicApi(anonPage, 'POST', `${API}/public/${token}/control`, { action: 'pause' })
	expect(refused.status).toBe(403)
})

test('a link given control can drive the broadcast', async ({ page, db }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
	await grant(page, LISTEN | CONTROL)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-channel-title')).toHaveText(channelTitle, { timeout: 20_000 })

	await expect(anonPage.getByTestId('player-controls')).toBeVisible({ timeout: 20_000 })

	// Only once the page agrees the channel is on air — see waitForStatus.
	await waitForStatus(anonPage, 'playing')
	await anonPage.getByTestId('control-playpause').click()

	// The channel really paused, for everybody — not just in this browser.
	await expect
		.poll(async () => {
			const rows = await db.query<Array<{ paused: number }>>(
				'select paused from oc_music_radio_channels where id = ?', [channelId],
			)
			return Number(rows[0].paused)
		}, { timeout: 20_000 })
		.toBe(1)
})

/**
 * Two people on one link is exactly the case optimistic concurrency exists for: a stale
 * tab must be told the channel moved rather than silently overwriting whoever was first.
 */
test('a stale state version is refused with the current state, not applied', async ({ page }) => {
	await grant(page, LISTEN | CONTROL)
	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

	const conflict = await publicApi(anonPage, 'POST', `${API}/public/${token}/control`, {
		action: 'pause',
		expectedStateVersion: -1,
	})

	expect(conflict.status).toBe(409)
	expect(conflict.body.state).toBeTruthy()
	expect(conflict.body.state.stateVersion).toBeGreaterThanOrEqual(0)
})

test('a link given curation can reorder the playlist', async ({ page, db }) => {
	await grant(page, LISTEN | EDIT_PLAYLIST)

	const before = await playOrder(db, channelId)
	expect(before).toHaveLength(3)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('track')).toHaveCount(3, { timeout: 20_000 })

	// The keyboard path rather than a synthetic drag: it exercises the same endpoint and
	// does not depend on coordinates that move while the page settles.
	await openRowMenu(anonPage, 0)
	await anonPage.getByRole('menuitem', { name: /move down|nach unten/i })
		.or(anonPage.getByRole('button', { name: /move down|nach unten/i }))
		.first()
		.click()

	await expect
		.poll(async () => (await playOrder(db, channelId)).join(','), { timeout: 20_000 })
		.toBe([before[1], before[0], before[2]].join(','))
})

test('a link given curation can remove somebody else\'s track', async ({ page, db }) => {
	await grant(page, LISTEN | EDIT_PLAYLIST)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('track')).toHaveCount(3, { timeout: 20_000 })

	// Every one of these was added by the owner, not by this visitor — which is precisely
	// what a curator may now touch and an uploader may not.
	await openRowMenu(anonPage, 0)
	await anonPage.getByTestId('remove-track').click()

	await expect(anonPage.getByTestId('track')).toHaveCount(2, { timeout: 20_000 })
	expect(await playOrder(db, channelId)).toHaveLength(2)
})

test('a link that may only upload still cannot touch anybody else\'s track', async ({ page, db }) => {
	await grant(page, LISTEN | ADD_TRACKS)

	const ids = await playOrder(db, channelId)
	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('track')).toHaveCount(3, { timeout: 20_000 })

	// No remove entry on a row this browser did not add.
	await openRowMenu(anonPage, 0)
	await expect(anonPage.getByTestId('remove-track')).toHaveCount(0)

	// Nor by going straight at the endpoints.
	const removal = await publicApi(anonPage, 'DELETE', `${API}/public/${token}/tracks/${ids[0]}`)
	expect(removal.status).toBe(403)

	const reorder = await publicApi(anonPage, 'PUT', `${API}/public/${token}/tracks/order`, {
		trackIds: [ids[2], ids[1], ids[0]],
	})
	expect(reorder.status).toBe(403)
	expect(await playOrder(db, channelId)).toEqual(ids)
})

/**
 * Curating implies adding, as it does for any other share — Permission::normalize folds
 * that in, so a curator is never left unable to add what they may reorder.
 */
test('curation carries uploading with it', async ({ page, db }) => {
	await grant(page, LISTEN | EDIT_PLAYLIST)

	const rows = await db.query<Array<{ permissions: number }>>(
		'select permissions from oc_music_radio_shares where id = ?', [shareId],
	)
	expect(Number(rows[0].permissions)).toBe(LISTEN | ADD_TRACKS | EDIT_PLAYLIST)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-upload-open')).toBeVisible({ timeout: 20_000 })
})
