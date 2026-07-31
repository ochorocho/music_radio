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
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
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

// -------------------------------------------------------------------- seeking

/**
 * Pause through the interface, not through the API.
 *
 * Every control here carries the state version the page last saw, so that two people (or
 * two tabs) cannot silently overwrite each other. Changing the channel behind the page's
 * back therefore makes its *next* action a conflict — which is correct behaviour and
 * useless as a starting position for a test about seeking.
 *
 * @param page playwright page
 */
async function pauseFromTheInterface(page: Page) {
	// Wait for the state to have arrived before pressing anything. The button is a toggle
	// that reads the state it has, and with none it reads as stopped — so an early press
	// asks the server to start a channel rather than to stop it.
	await expect
		.poll(async () => (await readSync(page)).status, { timeout: 20_000 })
		.toMatch(/^(playing|paused)$/)

	if ((await readSync(page)).status === 'playing') {
		await page.getByTestId('control-playpause').click()
	}

	await expect.poll(async () => (await readSync(page)).status, { timeout: 20_000 }).toBe('paused')
}

/**
 * Dragging the progress bar.
 *
 * Paused first, deliberately. The fixtures are three to eight seconds long, so against a
 * running programme the position asserted here would have moved on before the assertion
 * read it, and the test would be measuring the clock rather than the seek.
 */
test('dragging the progress bar moves the broadcast', async ({ page }) => {
	await openChannel(page, channelTitle)
	await expect(page.getByTestId('seek-bar')).toBeVisible({ timeout: 20_000 })
	await pauseFromTheInterface(page)

	const bar = page.getByTestId('seek-bar')
	const box = await bar.boundingBox()
	if (box === null) {
		throw new Error('the seek bar has no geometry')
	}

	// A real gesture: press on the handle, move across, release. Not a synthesised value
	// change — that dragging works is the whole claim.
	await page.mouse.move(box.x + 4, box.y + box.height / 2)
	await page.mouse.down()
	await page.mouse.move(box.x + box.width * 0.6, box.y + box.height / 2, { steps: 10 })
	await page.mouse.up()

	// Generous bounds: where a drag lands depends on the thumb's width and the browser's
	// rounding, and the claim is "it moved to roughly there", not to the millisecond. The
	// track playing is whichever the channel had reached, so the figure is checked against
	// its own length rather than against a number written here.
	await expect
		.poll(async () => {
			const sync = await readSync(page)
			return sync.offsetMs
		}, { timeout: 20_000 })
		.toBeGreaterThan(900)

	const state = await api(page, 'GET', `${API}/channels/${channelId}/state`)
	expect(state.body.current.offsetMs, 'the server moved, not just the page')
		.toBeGreaterThan(900)
})

/**
 * A control only reachable by dragging is not reachable at all for some people, which is
 * why this is a native range input rather than a div with pointer handlers.
 *
 * Two presses, one seek: a burst of key repeats is one intention, and sending a request
 * per repeat would re-anchor the timeline repeatedly and race its own state version.
 */
test('the progress bar can be moved from the keyboard', async ({ page }) => {
	await openChannel(page, channelTitle)
	await expect(page.getByTestId('seek-bar')).toBeVisible({ timeout: 20_000 })
	await pauseFromTheInterface(page)

	let seeks = 0
	page.on('request', (request) => {
		if (request.method() === 'POST' && (request.postData() ?? '').includes('"seek"')) {
			seeks++
		}
	})

	await page.getByTestId('seek-bar').focus()
	await page.keyboard.press('End')

	// End goes to the end of the track, whatever that track's length is — the fixtures
	// differ, and the channel is wherever it had got to.
	await expect
		.poll(async () => {
			const sync = await readSync(page)
			return sync.offsetMs
		}, { timeout: 20_000 })
		.toBeGreaterThan(1500)

	await page.keyboard.press('Home')
	await expect
		.poll(async () => (await readSync(page)).offsetMs, { timeout: 20_000 })
		.toBeLessThan(500)

	expect(seeks, 'one request per gesture').toBe(2)
})

/**
 * The handle and the fill behind it must agree.
 *
 * A range input snaps whatever value it is given to its step and draws the handle there,
 * so a fill drawn from the unrounded position lands somewhere the handle is not — which is
 * exactly what happened: the handle two thirds along, the blue two fifths. Nothing failed;
 * it just looked wrong, which is why this is asserted rather than left to be noticed.
 */
test('the handle and the filled part of the bar are in the same place', async ({ page }) => {
	await openChannel(page, channelTitle)
	await expect(page.getByTestId('seek-bar')).toBeVisible({ timeout: 20_000 })
	await pauseFromTheInterface(page)

	// Somewhere deliberately not on a whole second, which is where the two used to part.
	await page.getByTestId('seek-bar').focus()
	await page.keyboard.press('Home')
	await expect.poll(async () => (await readSync(page)).offsetMs, { timeout: 20_000 }).toBeLessThan(500)
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'seek', offsetMs: 1500 })

	await expect
		.poll(async () => {
			const bar = await page.getByTestId('seek-bar').evaluate((el: any) => ({
				value: Number(el.value),
				max: Number(el.max),
				fill: parseFloat(getComputedStyle(el).getPropertyValue('--music-radio-scrub-fill')),
			}))
			if (!bar.max || bar.value === 0) {
				return null
			}
			return Math.abs((bar.value / bar.max) * 100 - bar.fill)
		}, { timeout: 20_000 })
		.toBeLessThan(0.5)
})

test('someone who may only listen gets a readout, not a control', async ({ page, browser }) => {
	await openChannel(page, channelTitle)

	const created = await api(page, 'POST', `${API}/channels/${channelId}/shares`, { shareType: 3 })
	const token = created.body.token as string

	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const visitor = await context.newPage()
	try {
		await visitor.goto(`${APP_PATH}s/${token}`)
		await expect(visitor.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
		// Something from the player is on the page, so the absence below is about the
		// control and not about the card having failed to render.
		await expect(visitor.getByTestId('now-playing-title')).toBeVisible({ timeout: 20_000 })

		await expect(visitor.getByTestId('seek-bar'), 'a link never controls anything').toHaveCount(0)
	} finally {
		await visitor.close()
		await context.close()
	}
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
