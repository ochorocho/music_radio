/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The playlist as the user actually sees it. Everything is asserted through test ids
 * rather than visible text — this instance runs in German, and the app is translated.
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

/**
 * Open the app and select a channel by name.
 *
 * Necessary rather than just reloading: the app auto-selects the first channel it is
 * given, so a leftover channel from another test would otherwise be the one on screen
 * and every assertion would silently describe the wrong playlist.
 */
/**
 * Wait until the channel view has stopped changing shape.
 *
 * Opening a channel paints in two stages: the tracks arrive, then the broadcast state,
 * and the on-air card grows by about a hundred pixels when it does. Anything clicked in
 * between is a moving target — Playwright rejects the click as unstable, retries, and by
 * then the first attempt has already opened a menu that covers the button it was
 * retrying against.
 *
 * @param page playwright page
 */
async function noMenuOpen(page: Page) {
	// NcActions teleports its menu into a popper and closes it with a transition. Under
	// load that transition outlives the click that triggered it, and the next attempt to
	// open a menu is refused because the dying popper still covers the button — which
	// Playwright retries until the test times out, never reporting the real obstacle.
	await expect(page.locator('.v-popper__popper--shown')).toHaveCount(0, { timeout: 20_000 })
}

async function settled(page: Page) {
	let previous = -1
	for (let attempt = 0; attempt < 40; attempt++) {
		const height = await page.evaluate(() =>
			Math.round(document.querySelector('.music-radio-onair')?.getBoundingClientRect().height ?? -1),
		)
		if (height === previous && height > 0) {
			return
		}
		previous = height
		await page.waitForTimeout(100)
	}

	throw new Error('the channel view never settled')
}

async function openChannel(page: Page, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
}

/**
 * Creating a channel the way a user actually does — through the dialog.
 *
 * Every other spec creates channels through the API, which is faster but meant this
 * path went untested: a Vue-2-style `:value.sync` binding on the name field silently
 * bound nothing, so the Create button stayed disabled and no channel could ever be made
 * from the interface at all.
 */
test('a channel can be created through the dialog', async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	await page.getByTestId('new-channel').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible()

	const createButton = dialog.getByRole('button', { name: /create|erstellen/i })

	// Nothing typed yet, so there is nothing to create.
	await expect(createButton).toBeDisabled()

	// Targeted by control rather than test id: the @nextcloud/vue field components do not
	// forward arbitrary attributes to the rendered input.
	const nameInput = dialog.locator('input[type="text"]').first()
	const descriptionInput = dialog.locator('textarea').first()

	// Typing a name must actually reach the component's state.
	await nameInput.fill('Dialog Made Channel')
	await expect(createButton).toBeEnabled()

	await descriptionInput.fill('Created from the UI')
	await createButton.click()

	// The new channel is selected and shown.
	await expect(page.getByTestId('channel-title')).toHaveText('Dialog Made Channel', { timeout: 20_000 })

	const rows = await db.query<Array<{ id: number, title: string, description: string }>>(
		"select id, title, description from oc_music_radio_channels where title = 'Dialog Made Channel'",
	)
	expect(rows).toHaveLength(1)
	expect(rows[0].description).toBe('Created from the UI')

	await api(page, 'DELETE', `${API}/channels/${rows[0].id}`)
})

/**
 * Adding music the way a user does — through the "Add music" button and the Files picker.
 *
 * The other specs add tracks over the API, which meant this path went untested: the
 * picker renders exactly the buttons it is handed, and none were configured, so it opened
 * a dialog with no way to confirm a selection. The button did nothing and said nothing.
 */
test('music can be added through the file picker', async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	pickerTitle = uniqueTitle('Picker Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title: pickerTitle })
	const id = created.body.id as number

	try {
		await openChannel(page, pickerTitle)
		await expect(page.getByTestId('track')).toHaveCount(0)

		await page.getByTestId('add-tracks').click()

		const picker = page.getByRole('dialog')
		await expect(picker).toBeVisible({ timeout: 20_000 })

		// There must be a way to confirm, and it must start disabled with nothing chosen.
		const confirm = picker.getByRole('button', { name: /^(Add|Hinzufügen)/ })
		await expect(confirm).toBeVisible()
		await expect(confirm).toBeDisabled()

		// Navigate into the seeded Music folder and choose a track.
		await picker.getByText('Music', { exact: true }).first().click()
		const row = picker.getByText('tone-a', { exact: false }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.click()

		// Choosing something enables it.
		await expect(confirm).toBeEnabled()
		await confirm.click()

		// The track lands in the playlist, and in the database.
		await expect(page.getByTestId('track')).toHaveCount(1, { timeout: 30_000 })

		const rows = await db.query<Array<{ added_by: string, duration_ms: number }>>(
			'select added_by, duration_ms from oc_music_radio_tracks where channel_id = ?', [id],
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].added_by).toBe('admin')
		expect(Number(rows[0].duration_ms)).toBeGreaterThan(0)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

/**
 * Changing your mind is not a failure.
 *
 * The picker reports "closed without choosing" by throwing, and the exception it throws is
 * an empty `extends Error` whose message reads "FilePicker: No nodes selected". Anything
 * that tries to recognise it by name or message fails to — which put that string in front
 * of the user, in red, every time they opened the picker and backed out.
 */
test('closing the file picker without choosing reports nothing', async ({ page }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	const title = uniqueTitle('Picker Cancel Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	try {
		await openChannel(page, title)

		await page.getByTestId('add-tracks').click()
		const picker = page.getByRole('dialog')
		await expect(picker).toBeVisible({ timeout: 20_000 })

		await page.keyboard.press('Escape')
		await expect(picker).toBeHidden({ timeout: 20_000 })

		// Give the toast time to appear before asserting it did not.
		//
		// Not optional: a toast lands a moment after the picker closes, so checking
		// straight away passes whether or not the bug is present.
		await page.waitForTimeout(2000)

		// Snapshotted, not asserted through a live locator.
		//
		// `expect(locator).toHaveCount(0)` retries until it succeeds, and a toast dismisses
		// itself after a few seconds — so it waits for the toast to vanish and then reports
		// success. That assertion passed against a build with the bug deliberately put back,
		// which is how this was caught. Reading the DOM once and asserting on the value does
		// not retry, and so cannot be satisfied by simply waiting.
		const toasts = await page.locator('.toastify').evaluateAll(
			(els) => els.map((el) => (el.textContent ?? '').trim()),
		)
		expect(toasts).toEqual([])

		// And the channel is untouched and still usable.
		await expect(page.getByTestId('track')).toHaveCount(0)
		await expect(page.getByTestId('add-tracks')).toBeEnabled()
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

test('a channel and its playlist render, and tracks can be reordered and removed', async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	playlistTitle = uniqueTitle('UI Playlist Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title: playlistTitle })
	const id = created.body.id as number

	try {
		const fileRows = await db.query<Array<{ fileid: number, name: string }>>(
			"select fileid, name from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
		)
		await api(page, 'POST', `${API}/channels/${id}/tracks`, {
			fileIds: fileRows.map((r) => r.fileid),
		})

		await openChannel(page, playlistTitle)
		await settled(page)

		const rows = page.getByTestId('track')
		await expect(rows).toHaveCount(3)

		// Durations came from the files: 3s, 5s, 8s.
		await expect(page.getByTestId('track-duration').nth(0)).toHaveText('0:03')
		await expect(page.getByTestId('track-duration').nth(1)).toHaveText('0:05')
		await expect(page.getByTestId('track-duration').nth(2)).toHaveText('0:08')

		const firstTitle = await page.getByTestId('track-title').nth(0).textContent()
		const secondTitle = await page.getByTestId('track-title').nth(1).textContent()

		// Chosen by name rather than position: the menu's contents vary with what the
		// viewer may do, so an index silently points at the wrong entry as soon as
		// another action is added.
		await rows.nth(0).getByRole('button').last().click()
		await page.getByRole('menuitem', { name: /move down/i }).click()
		await noMenuOpen(page)

		await expect(page.getByTestId('track-title').nth(0)).toHaveText(secondTitle!)
		await expect(page.getByTestId('track-title').nth(1)).toHaveText(firstTitle!)

		// The new order is persisted, not just local state.
		const order = await db.query<Array<{ sort_order: number }>>(
			'select sort_order from oc_music_radio_tracks where channel_id = ? order by sort_order', [id],
		)
		expect(order.map((o) => Number(o.sort_order))).toEqual([1000, 2000, 3000])

		await rows.nth(0).getByRole('button').last().click()
		await page.getByRole('menuitem', { name: /remove from channel/i }).click()
		await noMenuOpen(page)
		await expect(page.getByTestId('track')).toHaveCount(2)

		const remaining = await db.query<Array<{ n: number }>>(
			'select count(*) as n from oc_music_radio_tracks where channel_id = ?', [id],
		)
		expect(Number(remaining[0].n)).toBe(2)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

test('a track whose length could not be read is flagged and left out of the broadcast', async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	pendingTitle = uniqueTitle('Pending Duration Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title: pendingTitle })
	const id = created.body.id as number

	try {
		const fileRows = await db.query<Array<{ fileid: number }>>(
			"select fileid from oc_filecache where name = 'tone-a.mp3' and path like 'files/Music/%' and path not like 'files/Music/%/%' limit 1",
		)
		await api(page, 'POST', `${API}/channels/${id}/tracks`, { fileIds: [fileRows[0].fileid] })

		// Simulate a file the probe could not measure. Such a track must stay visible in
		// the playlist but must NOT take part in the timeline, or it would insert a
		// zero-length gap that shifts every listener.
		await db.query(
			'update oc_music_radio_tracks set duration_ms = null, duration_source = 0 where channel_id = ?',
			[id],
		)

		await openChannel(page, pendingTitle)

		await expect(page.getByTestId('track')).toHaveCount(1)
		await expect(page.getByTestId('track-duration').first()).toHaveText('–')

		const tracks = await api(page, 'GET', `${API}/channels/${id}/tracks`)
		expect(tracks.body.tracks[0].playable).toBe(false)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
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

let pickerTitle = ''

let pendingTitle = ''

let playlistTitle = ''

