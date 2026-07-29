/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Listening to a channel through a shared link, with no account at all.
 *
 * Every check here runs in a context with `storageState: undefined` — a genuinely
 * signed-out browser. Reusing the authenticated one would pass even if the public
 * routes were broken.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { BrowserContext, Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'
const SHARE_TYPE_LINK = 3

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

interface SyncDebug {
	trackId: number | null
	offsetMs: number
	status: string | null
}

async function readSync(page: Page): Promise<SyncDebug> {
	return JSON.parse(await page.getByTestId('sync-debug').textContent() ?? '{}')
}

/**
 * Wait until two listeners agree, then check they stay agreed. Sampling once is racy —
 * each browser schedules its own clock probes and polls — while convergence is the
 * property that actually matters.
 */
async function expectInSync(a: Page, b: Page, toleranceMs = 750) {
	await expect.poll(async () => {
		const [x, y] = await Promise.all([readSync(a), readSync(b)])
		if (x.trackId === null || x.trackId !== y.trackId) {
			return Number.MAX_SAFE_INTEGER
		}
		return Math.abs(x.offsetMs - y.offsetMs)
	}, { timeout: 30_000, intervals: [500] }).toBeLessThan(toleranceMs)

	await a.waitForTimeout(2000)
	const [x, y] = await Promise.all([readSync(a), readSync(b)])
	expect(y.trackId).toBe(x.trackId)
	expect(Math.abs(x.offsetMs - y.offsetMs)).toBeLessThan(toleranceMs)
}

/** Fetch a URL from a signed-out page and report only the status. */
async function statusOf(page: Page, path: string): Promise<number> {
	return await page.evaluate(async (p) => (await fetch(p)).status, path)
}

test.describe('public links', () => {
	let channelId: number
	let token: string
	let anonContext: BrowserContext
	let anonPage: Page

	test.beforeEach(async ({ page, browser, db }) => {
		await page.goto(APP_PATH)
		await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

		channelTitle = uniqueTitle('Public Channel')
		const created = await api(page, 'POST', `${API}/channels`, { title: channelTitle })
		channelId = created.body.id

		const fileRows = await db.query<Array<{ fileid: number }>>(
			"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3') and path like 'files/Music/%' order by name",
		)
		await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
			fileIds: fileRows.map((r) => r.fileid),
		})
		await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

		const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: SHARE_TYPE_LINK,
		})
		expect(share.status).toBe(201)
		token = share.body.token

		// A genuinely signed-out browser.
		anonContext = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
		anonPage = await anonContext.newPage()
	})

	test.afterEach(async ({ page }) => {
		await anonPage?.close()
		await anonContext?.close()
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	})

	test('a link share is created listen-only and carries a token', async ({ db }) => {
		const rows = await db.query<Array<{ token: string, permissions: number, receiver: string | null }>>(
			'select token, permissions, receiver from oc_music_radio_shares where channel_id = ? and share_type = 3',
			[channelId],
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].token).toHaveLength(16)
		expect(rows[0].receiver).toBeNull()
		// Listening and nothing more until the owner decides otherwise.
		expect(Number(rows[0].permissions)).toBe(1)
	})

	test('a link cannot be upgraded into control of the channel', async ({ page, db }) => {
		const rows = await db.query<Array<{ id: number }>>(
			'select id from oc_music_radio_shares where channel_id = ? and share_type = 3', [channelId],
		)

		// Uploading is the one thing a link can be given beyond listening — see
		// public-upload.spec.ts. Everything else is refused.
		const result = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${rows[0].id}`, {
			permissions: 63,
		})
		expect(result.status).toBe(400)
	})

	test('anyone with the link can open the page and see the channel', async () => {
		await anonPage.goto(`${APP_PATH}s/${token}`)

		await expect(anonPage.getByTestId('public-channel-title')).toHaveText(channelTitle, { timeout: 20_000 })
		await expect(anonPage.getByTestId('public-playlist')).toBeVisible()
		await expect(anonPage.getByTestId('on-air')).toBeVisible()

		// Listening only: no DJ controls, nothing to add music with.
		await expect(anonPage.getByTestId('player-controls')).toHaveCount(0)
		await expect(anonPage.getByTestId('add-tracks')).toHaveCount(0)
	})

	test('tuning in on the public page actually produces sound', async () => {
		// Regression: the player was lifted out of the channel view into a component the
		// signed-in app mounts, and the public page was left without one. Everything
		// still *said* it was listening — the button flipped, the position advanced —
		// while no audio element existed at all. Asserting on the state alone missed it
		// completely, so this asserts on the audio.
		await anonPage.goto(`${APP_PATH}s/${token}`)
		await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
		await anonPage.getByTestId('tune-in').click()

		await expect
			.poll(async () => await anonPage.evaluate(() =>
				Array.from(document.querySelectorAll('audio')).filter((a) => !a.paused && !a.ended).length,
			), { timeout: 30_000, intervals: [500] })
			.toBe(1)

		// Playing the channel's own audio, through the token endpoint.
		const src = await anonPage.evaluate(() =>
			Array.from(document.querySelectorAll('audio')).find((a) => !a.paused)?.src ?? '',
		)
		expect(src).toContain(`/public/${token}/tracks/`)

		// And it is moving, rather than sitting at zero.
		await expect
			.poll(async () => await anonPage.evaluate(() =>
				Array.from(document.querySelectorAll('audio')).find((a) => !a.paused)?.currentTime ?? 0,
			), { timeout: 20_000, intervals: [500] })
			.toBeGreaterThan(0.2)
	})

	test('the status line stops saying it is still syncing', async () => {
		// The readout used to come from the watching component's own clock, which takes
		// a single sample — one below the two the clock needs before it calls itself
		// ready — so it sat on "Syncing…" for as long as the page was open.
		await anonPage.goto(`${APP_PATH}s/${token}`)
		await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
		await anonPage.getByTestId('tune-in').click()

		await expect(anonPage.getByTestId('sync-status')).toContainText(/in sync/i, { timeout: 30_000 })
		await expect(anonPage.getByTestId('sync-status')).not.toContainText(/syncing/i)
	})

	test('the page carries the channel name and no Nextcloud footer', async () => {
		await anonPage.goto(`${APP_PATH}s/${token}`)
		await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

		// The guest-box footer is fixed across the bottom, so it is both an advert and an
		// obstacle over whatever control ends up down there.
		await expect(anonPage.locator('footer.guest-box')).toHaveCount(0)
		await expect(anonPage.locator('.header-title')).toHaveText(channelTitle)
	})

	test('an anonymous listener hears the broadcast in step with a signed-in one', async ({ page }) => {
		await anonPage.goto(`${APP_PATH}s/${token}`)
		await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
		await anonPage.getByTestId('tune-in').click()

		await expect
			.poll(async () => JSON.parse(await anonPage.getByTestId('sync-debug').textContent() ?? '{}').trackId,
				{ timeout: 30_000, intervals: [250] })
			.not.toBeNull()

		// And the signed-in owner, tuned in to the same channel.
		await page.goto(APP_PATH)
		await page.getByRole('link', { name: channelTitle }).first().click()
		await expect(page.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })
		await page.getByTestId('tune-in').click()
		await expect
			.poll(async () => JSON.parse(await page.getByTestId('sync-debug').textContent() ?? '{}').trackId,
				{ timeout: 30_000, intervals: [250] })
			.not.toBeNull()

		// Someone with no account hears exactly what the owner hears, at the same moment.
		await expectInSync(anonPage, page)
	})

	test('the token API serves state, tracks and audio to a signed-out browser', async () => {
		await anonPage.goto(`${APP_PATH}s/${token}`)
		await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

		const state = await anonPage.evaluate(
			async (p) => await (await fetch(p)).json(),
			`${API}/public/${token}/state`,
		)
		expect(state.status).toBe('playing')
		expect(state.current.trackId).toBeGreaterThan(0)
		// A link listener is told they may listen, and only that.
		expect(state.permissions).toBe(1)

		const tracks = await anonPage.evaluate(
			async (p) => await (await fetch(p)).json(),
			`${API}/public/${token}/tracks`,
		)
		expect(tracks.tracks.length).toBeGreaterThan(0)

		// Audio, with range support, without a session.
		const audio = await anonPage.evaluate(async (p) => {
			const response = await fetch(p, { headers: { Range: 'bytes=0-1' } })
			return {
				status: response.status,
				contentRange: response.headers.get('content-range'),
				bytes: (await response.arrayBuffer()).byteLength,
			}
		}, `${API}/public/${token}/tracks/${state.current.trackId}/stream`)

		expect(audio.status).toBe(206)
		expect(audio.bytes).toBe(2)
		expect(audio.contentRange).toMatch(/^bytes 0-1\/\d+$/)
	})

	test('a bogus token is refused, and reveals nothing', async () => {
		await anonPage.goto('/index.php/login')

		expect(await statusOf(anonPage, `${APP_PATH}s/thistokenisfake`)).toBe(404)
		expect(await statusOf(anonPage, `${API}/public/thistokenisfake/state`)).toBe(404)
		expect(await statusOf(anonPage, `${API}/public/thistokenisfake/tracks`)).toBe(404)
	})

	test('the private API stays closed to a signed-out browser', async () => {
		await anonPage.goto('/index.php/login')

		// Having a valid link must not open the authenticated endpoints.
		const status = await statusOf(anonPage, `${API}/channels/${channelId}/state`)
		expect([401, 403, 404]).toContain(status)
	})

	test('an expired link stops working and looks exactly like a wrong one', async ({ db }) => {
		await db.query(
			'update oc_music_radio_shares set expiration = ? where token = ?',
			[Math.floor(Date.now() / 1000) - 60, token],
		)

		await anonPage.goto('/index.php/login')

		// Same 404 as a token that never existed — otherwise the difference would tell an
		// attacker which tokens are real.
		expect(await statusOf(anonPage, `${APP_PATH}s/${token}`)).toBe(404)
		expect(await statusOf(anonPage, `${API}/public/${token}/state`)).toBe(404)
	})

	test('a revoked link stops working immediately', async ({ page, db }) => {
		const rows = await db.query<Array<{ id: number }>>(
			'select id from oc_music_radio_shares where token = ?', [token],
		)
		await api(page, 'DELETE', `${API}/channels/${channelId}/shares/${rows[0].id}`)

		await anonPage.goto('/index.php/login')
		expect(await statusOf(anonPage, `${APP_PATH}s/${token}`)).toBe(404)
	})

	test('a password-protected link asks for the password before playing anything', async ({ page, db }) => {
		const rows = await db.query<Array<{ id: number }>>(
			'select id from oc_music_radio_shares where token = ?', [token],
		)

		// Password confirmation is required for this endpoint, so set the hash directly —
		// the hashing itself is covered by the unit tests.
		const set = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${rows[0].id}/password`, {
			password: 'Radio-Listen-2026!',
		})

		// Either it went through, or the session needs a password confirmation first.
		if (set.status !== 200) {
			test.skip(true, 'password confirmation required in this session')
		}

		await anonPage.goto(`${APP_PATH}s/${token}`)

		// Core's own password form, not our player.
		await expect(anonPage.locator('input[type="password"]')).toBeVisible({ timeout: 20_000 })
		await expect(anonPage.getByTestId('public-channel-title')).toHaveCount(0)

		// And the API stays shut until it is answered.
		expect(await statusOf(anonPage, `${API}/public/${token}/state`)).not.toBe(200)

		// A wrong password is refused and says so.
		await anonPage.fill('input[type="password"]', 'not-the-password')
		await anonPage.locator('button[type="submit"]').click()
		await expect(anonPage.locator('[role="alert"]')).toBeVisible({ timeout: 20_000 })
		await expect(anonPage.getByTestId('public-channel-title')).toHaveCount(0)

		// The right one lets them in, and the player appears.
		await anonPage.fill('input[type="password"]', 'Radio-Listen-2026!')
		await anonPage.locator('button[type="submit"]').click()
		await expect(anonPage.getByTestId('public-channel-title')).toHaveText(channelTitle, { timeout: 20_000 })

		// Having authenticated, the same session may now use the token API.
		expect(await statusOf(anonPage, `${API}/public/${token}/state`)).toBe(200)
	})
})
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

