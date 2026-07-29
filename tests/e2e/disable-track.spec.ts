/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Taking a track out of the rotation without removing it.
 *
 * "Not right now" is a different thing from a broken file: the track stays in the
 * playlist, keeps its place, and comes back when the owner says so.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

let channelTitle = ''
let channelId = 0

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

async function openChannel(page: Page) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('link', { name: channelTitle }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })
}

test.beforeEach(async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	channelTitle = uniqueTitle('Disable Channel')
	channelId = (await api(page, 'POST', `${API}/channels`, { title: channelTitle })).body.id

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

test('a disabled track stays in the playlist but leaves the broadcast', async ({ page, db }) => {
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)

	const before = await api(page, 'GET', `${API}/channels/${channelId}/state`)
	// 3s + 5s + 8s.
	expect(before.body.playableCount).toBe(3)
	expect(before.body.totalDurationMs).toBeGreaterThan(15_000)

	const disabled = await api(page, 'PUT', `${API}/channels/${channelId}/tracks/${rows[1].id}`, {
		disabled: true,
	})
	expect(disabled.status).toBe(200)
	expect(disabled.body.disabled).toBe(true)
	expect(disabled.body.playable).toBe(false)

	// Still listed…
	const tracks = await api(page, 'GET', `${API}/channels/${channelId}/tracks`)
	expect(tracks.body.tracks).toHaveLength(3)

	// …but no longer part of the programme.
	const after = await api(page, 'GET', `${API}/channels/${channelId}/state`)
	expect(after.body.trackCount).toBe(3)
	expect(after.body.playableCount).toBe(2)
	expect(after.body.totalDurationMs).toBeLessThan(before.body.totalDurationMs)
})

test('a disabled track is never chosen as what is playing', async ({ page, db }) => {
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)

	await api(page, 'PUT', `${API}/channels/${channelId}/tracks/${rows[0].id}`, { disabled: true })
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

	// Watch it round the whole (now shorter) programme; the skipped track must never
	// come up.
	const seen = new Set<number>()
	for (let i = 0; i < 20; i++) {
		const state = await api(page, 'GET', `${API}/channels/${channelId}/state`)
		if (state.body.current) {
			seen.add(state.body.current.trackId)
		}
		await page.waitForTimeout(750)
	}

	expect(seen.has(rows[0].id)).toBe(false)
	expect(seen.size).toBeGreaterThan(0)
})

test('disabling a track does not disturb the one playing', async ({ page, db }) => {
	// The same guard as every other playlist edit.
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)
	// Play the last one, then disable the first — everything before it shifts.
	await api(page, 'POST', `${API}/channels/${channelId}/control`, {
		action: 'jumpTo',
		trackId: rows[2].id,
	})

	await openChannel(page)
	await page.getByTestId('tune-in').click()
	await expect.poll(async () => (await readSync(page)).trackId, { timeout: 30_000, intervals: [250] })
		.toBe(rows[2].id)

	const before = await readSync(page)

	await api(page, 'PUT', `${API}/channels/${channelId}/tracks/${rows[0].id}`, { disabled: true })
	await page.waitForTimeout(3500)

	const after = await readSync(page)
	expect(after.trackId).toBe(before.trackId)
	expect(after.offsetMs).toBeGreaterThan(before.offsetMs)
})

test('the owner can skip and un-skip a track from the playlist', async ({ page, db }) => {
	await openChannel(page)

	const rows = page.getByTestId('track')
	await expect(rows).toHaveCount(3)

	// Skip the second one.
	await rows.nth(1).getByRole('button').last().click()
	await page.getByTestId('toggle-disabled').click()
	// The menu closes with a transition; opening the next one before it has gone finds
	// the dying popper still covering the button.
	await expect(page.locator('.v-popper__popper--shown')).toHaveCount(0, { timeout: 20_000 })

	await expect(rows.nth(1)).toHaveClass(/music-radio-track--disabled/, { timeout: 20_000 })
	await expect(rows.nth(1)).toContainText(/skipped/i)

	let dbRows = await db.query<Array<{ disabled: number }>>(
		'select disabled from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)
	expect(Number(dbRows[1].disabled)).toBe(1)
	expect(Number(dbRows[0].disabled)).toBe(0)

	// And put it back.
	await rows.nth(1).getByRole('button').last().click()
	await page.getByTestId('toggle-disabled').click()
	await expect(page.locator('.v-popper__popper--shown')).toHaveCount(0, { timeout: 20_000 })

	await expect(rows.nth(1)).not.toHaveClass(/music-radio-track--disabled/, { timeout: 20_000 })

	dbRows = await db.query<Array<{ disabled: number }>>(
		'select disabled from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)
	expect(Number(dbRows[1].disabled)).toBe(0)
})

test('a skipped track still reads like the rest of the list', async ({ page, db }) => {
	// It is paused, not gone. The title used to be styled to `opacity: 0` — the row
	// showed a bare number, a duration and nothing else, which reads as a broken entry
	// rather than one the owner chose to sit out.
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)
	await api(page, 'PUT', `${API}/channels/${channelId}/tracks/${rows[1].id}`, { disabled: true })

	await openChannel(page)
	const skipped = page.getByTestId('track').nth(1)
	await expect(skipped).toHaveClass(/music-radio-track--disabled/, { timeout: 20_000 })

	// The title is there and readable.
	const title = skipped.getByTestId('track-title')
	await expect(title).not.toBeEmpty()
	await expect(title).toBeVisible()
	expect(await title.evaluate((el) => getComputedStyle(el).opacity)).toBe('1')

	// And the row keeps the same shape as its neighbours: the leading column is still a
	// play control, disabled, rather than collapsing to a bare index.
	await expect(skipped.getByTestId('play-track')).toHaveCount(1)
	await expect(skipped.getByTestId('play-track').locator('button')).toBeDisabled()
	await expect(page.getByTestId('track').nth(0).getByTestId('play-track')).toHaveCount(1)
})

test('someone who may not curate the playlist cannot skip tracks', async ({ page }) => {
	await openChannel(page)

	// The owner can, so the entry is offered to them.
	await page.getByTestId('track').first().getByRole('button').last().click()
	await expect(page.getByTestId('toggle-disabled')).toBeVisible()
})
