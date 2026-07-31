/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Sharing a channel with a second, real account.
 *
 * The behaviour this file exists to prove is the one the whole app is built around: a
 * contributor can put music on a channel but cannot decide what is playing.
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
const CONTROL = 4

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

/** A browser signed in as the second account, with its own session. */
async function signInAsListener(context: BrowserContext): Promise<Page> {
	const page = await context.newPage()
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	await page.locator('input[name="user"]').waitFor({ state: 'visible', timeout: 30_000 })
	await page.fill('input[name="user"]', LISTENER_USER)
	await page.fill('input[name="password"]', LISTENER_PASS)
	await Promise.all([
		page.waitForURL((url) => !url.pathname.replace(/\/index\.php/, '').startsWith('/login'), { timeout: 30_000 }),
		page.locator('button[type="submit"], [data-login-form-submit]').first().click(),
	])
	return page
}

test.describe('sharing a channel', () => {
	let channelId: number
	let listenerContext: BrowserContext
	let listenerPage: Page

	test.beforeEach(async ({ page, browser, db }) => {
		await page.goto(APP_PATH)
		await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

		channelTitle = uniqueTitle('Shared Channel')
		const created = await api(page, 'POST', `${API}/channels`, { title: channelTitle })
		channelId = created.body.id

		const fileRows = await db.query<Array<{ fileid: number }>>(
			"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
		)
		await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
			fileIds: fileRows.map((r) => r.fileid),
		})

		listenerContext = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
		listenerPage = await signInAsListener(listenerContext)
	})

	test.afterEach(async ({ page }) => {
		await listenerPage?.close()
		await listenerContext?.close()
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	})

	test('an unshared channel is invisible to everyone else', async ({ page }) => {
		await listenerPage.goto(APP_PATH)
		await expect(listenerPage.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

		// Scoped to this test's channel rather than counting everything the account can
		// see: the listener may legitimately have other channels shared with them, and a
		// bare count would make this test depend on unrelated data.
		const list = await api(listenerPage, 'GET', `${API}/channels`)
		expect(list.body.channels.filter((c: { id: number }) => c.id === channelId)).toHaveLength(0)

		// Not 403 — the API must not confirm that this channel id exists.
		const direct = await api(listenerPage, 'GET', `${API}/channels/${channelId}`)
		expect(direct.status).toBe(404)
	})

	test('a listener share grants hearing it and nothing else', async ({ page, db }) => {
		const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN,
		})
		expect(share.status).toBe(201)

		const rows = await db.query<Array<{ permissions: number, receiver: string }>>(
			'select permissions, receiver from oc_music_radio_shares where channel_id = ?', [channelId],
		)
		expect(rows[0].receiver).toBe(LISTENER_USER)
		expect(Number(rows[0].permissions)).toBe(LISTEN)

		await listenerPage.goto(APP_PATH)
		await expect(listenerPage.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

		// They can see it and read its state…
		const list = await api(listenerPage, 'GET', `${API}/channels`)
		const mine = list.body.channels.filter((c: { id: number }) => c.id === channelId)
		expect(mine).toHaveLength(1)
		expect(mine[0].can.listen).toBe(true)
		expect(mine[0].can.addTracks).toBe(false)
		expect(mine[0].can.control).toBe(false)

		const state = await api(listenerPage, 'GET', `${API}/channels/${channelId}/state`)
		expect(state.status).toBe(200)

		// …but they cannot add to it or drive it.
		const add = await api(listenerPage, 'POST', `${API}/channels/${channelId}/tracks`, { fileIds: [1] })
		expect(add.status).toBe(403)

		const control = await api(listenerPage, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
		expect(control.status).toBe(403)
	})

	/**
	 * The combination the app exists for.
	 */
	test('a contributor can add music but still cannot decide what plays', async ({ page, db }) => {
		await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN | ADD_TRACKS,
		})

		await listenerPage.goto(APP_PATH)
		await expect(listenerPage.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

		// The contributor adds a track out of their OWN files.
		const ownFile = await db.query<Array<{ fileid: number }>>(
			`select fc.fileid from oc_filecache fc
			 join oc_storages s on s.numeric_id = fc.storage
			 where s.id = ? and fc.name like '%.mp3' limit 1`,
			[`home::${LISTENER_USER}`],
		)

		if (ownFile.length > 0) {
			const add = await api(listenerPage, 'POST', `${API}/channels/${channelId}/tracks`, {
				fileIds: [ownFile[0].fileid],
			})
			expect(add.status).toBe(201)
			expect(add.body.tracks).toHaveLength(1)

			// The row records who put it there — that is what lets them take it back
			// again without being able to touch anyone else's.
			const rows = await db.query<Array<{ added_by: string }>>(
				'select added_by from oc_music_radio_tracks where channel_id = ? order by id desc limit 1',
				[channelId],
			)
			expect(rows[0].added_by).toBe(LISTENER_USER)
		}

		// Adding music does not make them the DJ.
		const control = await api(listenerPage, 'POST', `${API}/channels/${channelId}/control`, { action: 'next' })
		expect(control.status).toBe(403)

		// Nor does it let them rearrange the owner's playlist…
		const reorder = await api(listenerPage, 'PUT', `${API}/channels/${channelId}/tracks/order`, { trackIds: [] })
		expect(reorder.status).toBe(403)

		// …or remove the owner's tracks.
		const ownerTracks = await api(page, 'GET', `${API}/channels/${channelId}/tracks`)
		const ownerTrackId = ownerTracks.body.tracks.find(
			(t: { addedBy: string }) => t.addedBy === 'admin',
		).id
		const remove = await api(listenerPage, 'DELETE', `${API}/channels/${channelId}/tracks/${ownerTrackId}`)
		expect(remove.status).toBe(403)
	})

	test('a contributor sees no player controls in the interface', async ({ page }) => {
		await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN | ADD_TRACKS,
		})

		await listenerPage.goto(APP_PATH)
		await listenerPage.getByRole('link', { name: channelTitle }).first().click()
		await expect(listenerPage.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })

		// The broadcast panel is there — they are allowed to listen…
		await expect(listenerPage.getByTestId('on-air')).toBeVisible()
		// …but the DJ controls are not rendered at all.
		await expect(listenerPage.getByTestId('player-controls')).toHaveCount(0)
		// Adding music is offered, because that they may do.
		await expect(listenerPage.getByTestId('add-tracks')).toBeVisible()
	})

	test('granting control lets them drive the broadcast', async ({ page }) => {
		const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN | ADD_TRACKS,
		})

		await listenerPage.goto(APP_PATH)
		await expect(listenerPage.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
		expect((await api(listenerPage, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })).status).toBe(403)

		// The owner promotes them.
		const updated = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${share.body.id}`, {
			permissions: LISTEN | ADD_TRACKS | CONTROL,
		})
		expect(updated.status).toBe(200)

		expect((await api(listenerPage, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })).status).toBe(200)
	})

	test('revoking a share takes access away immediately', async ({ page }) => {
		const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN,
		})

		await listenerPage.goto(APP_PATH)
		await expect(listenerPage.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
		expect((await api(listenerPage, 'GET', `${API}/channels/${channelId}`)).status).toBe(200)

		await api(page, 'DELETE', `${API}/channels/${channelId}/shares/${share.body.id}`)

		expect((await api(listenerPage, 'GET', `${API}/channels/${channelId}`)).status).toBe(404)
		expect((await api(listenerPage, 'GET', `${API}/channels/${channelId}/state`)).status).toBe(404)
	})

	test('an expired share is invisible', async ({ page, db }) => {
		const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN,
		})

		await listenerPage.goto(APP_PATH)
		await expect(listenerPage.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
		expect((await api(listenerPage, 'GET', `${API}/channels/${channelId}`)).status).toBe(200)

		// Backdate it, as though the expiry had passed.
		await db.query(
			'update oc_music_radio_shares set expiration = ? where id = ?',
			[Math.floor(Date.now() / 1000) - 60, share.body.id],
		)

		expect((await api(listenerPage, 'GET', `${API}/channels/${channelId}`)).status).toBe(404)
		const list = await api(listenerPage, 'GET', `${API}/channels`)
		expect(list.body.channels.filter((c: { id: number }) => c.id === channelId)).toHaveLength(0)
	})

	test('a share cannot be created that grants nothing, or to a stranger', async ({ page }) => {
		const nothing = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: 0,
		})
		expect(nothing.status).toBe(400)

		const ghost = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: 'nobody-by-that-name',
			permissions: LISTEN,
		})
		expect(ghost.status).toBe(400)

		// Sharing a channel with its own owner is meaningless.
		const self = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: 'admin',
			permissions: LISTEN,
		})
		expect(self.status).toBe(400)
	})

	test('the same person cannot be added twice', async ({ page }) => {
		const first = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN,
		})
		expect(first.status).toBe(201)

		const second = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN,
		})
		expect(second.status).toBe(409)
	})

	test('a sharee cannot manage the shares unless allowed to', async ({ page }) => {
		await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			permissions: LISTEN | ADD_TRACKS,
		})

		await listenerPage.goto(APP_PATH)
		await expect(listenerPage.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

		const shares = await api(listenerPage, 'GET', `${API}/channels/${channelId}/shares`)
		expect(shares.status).toBe(403)

		const reshare = await api(listenerPage, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: 'admin',
			permissions: LISTEN,
		})
		expect(reshare.status).toBe(403)
	})

	test('only the owner can delete the channel, even with full management rights', async ({ page }) => {
		await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
			shareType: 0,
			receiver: LISTENER_USER,
			// Everything short of ownership.
			permissions: 63,
		})

		await listenerPage.goto(APP_PATH)
		await expect(listenerPage.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

		// They can rename it…
		const rename = await api(listenerPage, 'PUT', `${API}/channels/${channelId}`, { title: 'Renamed By Sharee' })
		expect(rename.status).toBe(200)

		// …but destroying someone else's channel is not a thing management should allow.
		const destroy = await api(listenerPage, 'DELETE', `${API}/channels/${channelId}`)
		expect(destroy.status).toBe(404)
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

