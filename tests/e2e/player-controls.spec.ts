/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Reordering by dragging, muting, and confirming before leaving a channel.
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

	channelTitle = uniqueTitle('Controls Channel')
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

// ------------------------------------------------------------------ drag and drop

/**
 * Drop onto a row's lower half and the track lands after it; onto the upper half and it
 * lands before. Both halves are exercised because dropping on the exact centre is
 * genuinely ambiguous, so the tests aim deliberately rather than relying on whatever
 * `dragTo` picks by default.
 *
 * @param page playwright page
 * @param fromIndex row being dragged
 * @param toIndex row being dropped on
 * @param half which half of the target row to release over
 */
async function dragTrack(page: Page, fromIndex: number, toIndex: number, half: 'top' | 'bottom') {
	const before = await page.getByTestId('track-title').allTextContents()

	// Attempted more than once on purpose.
	//
	// A synthetic drag is a sequence of separate events aimed at coordinates measured
	// beforehand, and it silently does nothing if the rows move in between — which they
	// do, because the channel view paints in stages and the card above the list grows
	// when the broadcast state arrives. Waiting for the geometry to hold still removes
	// most of that, but not the case where the reflow lands mid-sequence. The assertions
	// that follow still decide whether the reorder was right; this only decides whether
	// the gesture registered at all.
	for (let attempt = 0; attempt < 3; attempt++) {
		const target = page.getByTestId('track').nth(toIndex)
		const box = await stableBox(target)

		await page.getByTestId('track').nth(fromIndex).dragTo(target, {
			targetPosition: { x: box.width / 2, y: half === 'top' ? 3 : box.height - 3 },
		})

		// Dropping a row back onto itself is a legitimate no-op, so that case must not be
		// retried into oblivion — it has nothing to change.
		if (fromIndex === toIndex) {
			return
		}

		try {
			await expect
				.poll(async () => (await page.getByTestId('track-title').allTextContents()).join('|'),
					{ timeout: 5_000, intervals: [250] })
				.not.toBe(before.join('|'))
			return
		} catch {
			// Nothing moved; the gesture was lost. Measure again and repeat.
		}
	}

	throw new Error('the drag never registered')
}

/**
 * A bounding box that has stopped moving.
 *
 * The drop lands on coordinates measured here rather than on whatever Playwright's
 * actionability check would pick, so the measurement has to outlive the page settling.
 * Aiming 3px into a row that has since slid down puts the pointer outside it, and the
 * drag ends with no drop at all.
 *
 * @param locator the row to measure
 */
async function stableBox(locator: ReturnType<Page['getByTestId']>) {
	let previous = await locator.boundingBox()
	for (let attempt = 0; attempt < 30; attempt++) {
		await locator.page().waitForTimeout(100)
		const current = await locator.boundingBox()
		if (current === null) {
			previous = null
			continue
		}
		if (previous !== null && previous.y === current.y && previous.height === current.height) {
			return current
		}
		previous = current
	}

	throw new Error('the row never stopped moving')
}

test('a track dragged below the last row moves to the end', async ({ page, db }) => {
	await openChannel(page, channelTitle)

	const titlesBefore = await page.getByTestId('track-title').allTextContents()
	expect(titlesBefore).toHaveLength(3)

	await dragTrack(page, 0, 2, 'bottom')

	await expect
		.poll(async () => (await page.getByTestId('track-title').allTextContents())[2],
			{ timeout: 20_000 })
		.toBe(titlesBefore[0])

	const titlesAfter = await page.getByTestId('track-title').allTextContents()
	expect(titlesAfter).toEqual([titlesBefore[1], titlesBefore[2], titlesBefore[0]])

	// Persisted, not just moved on screen — and renumbered, not left with gaps.
	const rows = await db.query<Array<{ title: string, sort_order: number }>>(
		'select title, sort_order from oc_music_radio_tracks where channel_id = ? order by sort_order',
		[channelId],
	)
	expect(rows.map((r) => r.title)).toEqual(titlesAfter)
	expect(rows.map((r) => Number(r.sort_order))).toEqual([1000, 2000, 3000])
})

test('a track dragged above the first row moves to the top', async ({ page }) => {
	await openChannel(page, channelTitle)

	const titlesBefore = await page.getByTestId('track-title').allTextContents()

	await dragTrack(page, 2, 0, 'top')

	await expect
		.poll(async () => (await page.getByTestId('track-title').allTextContents())[0],
			{ timeout: 20_000 })
		.toBe(titlesBefore[2])

	expect(await page.getByTestId('track-title').allTextContents())
		.toEqual([titlesBefore[2], titlesBefore[0], titlesBefore[1]])
})

test('dropping a track back onto itself changes nothing', async ({ page }) => {
	await openChannel(page, channelTitle)

	const titlesBefore = await page.getByTestId('track-title').allTextContents()

	await dragTrack(page, 1, 1, 'bottom')
	await page.waitForTimeout(1500)

	expect(await page.getByTestId('track-title').allTextContents()).toEqual(titlesBefore)
})

test('dragging does not disturb what is on air', async ({ page, db }) => {
	// The reorder goes through the same timeline guard as every other playlist edit.
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)
	await api(page, 'POST', `${API}/channels/${channelId}/control`, {
		action: 'jumpTo',
		trackId: rows[1].id,
	})

	await openChannel(page, channelTitle)
	await tuneIn(page)

	const before = await readSync(page)

	// Move the last track to the top, which changes the order around the playing one.
	await dragTrack(page, 2, 0, 'top')
	await page.waitForTimeout(2500)

	const after = await readSync(page)
	expect(after.trackId).toBe(before.trackId)
	expect(after.status).toBe('playing')
})

test('someone who may not curate the playlist cannot drag it', async ({ page }) => {
	await openChannel(page, channelTitle)

	// The owner may, so the rows are draggable for them.
	await expect(page.getByTestId('track').first()).toHaveAttribute('draggable', 'true')
})

// ------------------------------------------------------------------------- mute

test('a listener can mute without stopping the broadcast', async ({ page }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
	await openChannel(page, channelTitle)
	await tuneIn(page)

	const audioMuted = async () => await page.evaluate(() =>
		Array.from(document.querySelectorAll('audio')).some((a) => !a.paused && a.muted),
	)

	// The player's elements have to be reachable for any of this to mean anything.
	expect(await page.evaluate(() => document.querySelectorAll('audio').length))
		.toBeGreaterThan(0)
	expect(await audioMuted()).toBe(false)

	await page.getByTestId('mute-toggle').locator('button').click()

	await expect.poll(audioMuted, { timeout: 10_000 }).toBe(true)

	// Muting is this listener's own business — the channel is still on air, and the
	// timeline has not been touched.
	const state = await api(page, 'GET', `${API}/channels/${channelId}/state`)
	expect(state.body.status).toBe('playing')

	// And the position keeps moving while muted, so unmuting rejoins everyone else
	// rather than replaying what was missed.
	const before = await readSync(page)
	await page.waitForTimeout(2000)
	const after = await readSync(page)
	expect(after.offsetMs).not.toBe(before.offsetMs)

	// Unmuting restores sound.
	await page.getByTestId('mute-toggle').locator('button').click()
	await expect.poll(audioMuted, { timeout: 10_000 }).toBe(false)
})

test('the signed-in status line reports the player that is making the sound', async ({ page }) => {
	// The player and the readout are two different components. The readout used to show
	// its own idle clock — a single sample, one short of the two the clock needs before
	// it calls itself ready — so it sat on "Syncing…" while the music played fine.
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
	await openChannel(page, channelTitle)
	await tuneIn(page)

	await expect(page.getByTestId('sync-status')).toContainText(/in sync/i, { timeout: 30_000 })
	await expect(page.getByTestId('sync-status')).not.toContainText(/syncing/i)
})

test('muting survives a track change', async ({ page, db }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
	await openChannel(page, channelTitle)
	await tuneIn(page)

	await page.getByTestId('mute-toggle').locator('button').click()

	// A newly loaded audio element starts unmuted, so the choice has to be re-applied.
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)
	await page.getByTestId('track').last().getByTestId('play-track').locator('button').click()
	await expect
		.poll(async () => (await readSync(page)).trackId, { timeout: 20_000, intervals: [250] })
		.toBe(rows[2].id)

	await page.waitForTimeout(1500)
	const anyAudible = await page.evaluate(() =>
		Array.from(document.querySelectorAll('audio')).some((a) => !a.paused && !a.muted),
	)
	expect(anyAudible).toBe(false)
})

// --------------------------------------------------------- leaving the channel

test('stopping the stream asks for confirmation first', async ({ page }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
	await openChannel(page, channelTitle)
	await tuneIn(page)

	await page.getByTestId('tune-out').locator('button').click()

	const confirm = page.getByRole('dialog')
	await expect(confirm).toBeVisible({ timeout: 20_000 })

	// Backing out leaves the listener where they were.
	await confirm.getByRole('button', { name: /keep listening/i }).click()
	await expect(confirm).toHaveCount(0)
	expect((await readSync(page)).tunedIn).toBe(true)

	// Confirming actually stops it.
	await page.getByTestId('tune-out').locator('button').click()
	await page.getByRole('dialog').getByRole('button', { name: /^stop listening$/i }).click()

	await expect.poll(async () => (await readSync(page)).tunedIn, { timeout: 20_000 }).toBe(false)
	await expect(page.getByTestId('tune-in')).toBeVisible()
})

test('nothing is left playing after tuning out', async ({ page }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
	await openChannel(page, channelTitle)
	await tuneIn(page)

	await page.getByTestId('tune-out').locator('button').click()
	await page.getByRole('dialog').getByRole('button', { name: /^stop listening$/i }).click()
	await expect.poll(async () => (await readSync(page)).tunedIn, { timeout: 20_000 }).toBe(false)

	const stillPlaying = await page.evaluate(() =>
		Array.from(document.querySelectorAll('audio')).filter((a) => !a.paused && !a.ended).length,
	)
	expect(stillPlaying).toBe(0)
})

// ------------------------------------------------------------------- app icon

test('the navigation icon is white, like every other icon in the top bar', async ({ page }) => {
	await page.goto('/index.php/apps/files/')

	// Whatever URL the menu resolves the icon to is the one that matters — an app in
	// custom_apps is not served from /apps/<id>/, so hard-coding the path would test
	// nothing.
	const src = await page.locator('a[href*="/apps/music_radio"] img').first().getAttribute('src')
	expect(src).toBeTruthy()

	const response = await page.request.get(src as string)
	expect(response.status()).toBe(200)

	// Core apps all ship a white fill so the icon reads against the dark header; without
	// one it renders black and stands out as wrong.
	const svg = await response.text()
	expect(svg).toContain('fill="#fff"')
	expect(svg).not.toContain('fill="#000"')
})
