/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * How much of a phone screen the on-air card is allowed to take.
 *
 * The card is sticky, so whatever height it occupies is taken from the playlist for the
 * whole session rather than only at the top of the page. On a desktop that is a detail; on
 * a phone it decides whether the list underneath is usable at all, and it is the kind of
 * thing that creeps back a few pixels at a time as controls are added, with nothing to
 * notice it.
 *
 * The bound is a fraction of the viewport rather than a pixel count, so it keeps meaning
 * the same thing on a different device — and it is deliberately loose enough that ordinary
 * changes pass and only a real regression trips it.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

/** iPhone 14-ish, and narrow enough to be under the 600px breakpoint. */
const PHONE = { width: 390, height: 844 }

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

let channelId: number
const title = `Mobile Height ${Math.random().toString(36).slice(2, 8)}`

test.beforeEach(async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	const created = await api(page, 'POST', `${API}/channels`, { title })
	channelId = created.body.id

	const rows = await db.query(
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
	)
	await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
		fileIds: rows.map((r: { fileid: number }) => r.fileid),
	})
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

/**
 * Opened wide, then narrowed.
 *
 * Nextcloud collapses its app navigation below this width, which hides the channel list —
 * so a phone-sized browser cannot reach a channel by clicking one. Resizing after the
 * channel is open is what the rest of the suite does for the same reason.
 */
async function openChannelOnAPhone(page: Page) {
	await page.goto(APP_PATH)
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
	await page.setViewportSize(PHONE)
	await expect(page.getByTestId('on-air')).toBeVisible({ timeout: 20_000 })

	// Measured only once the card is carrying everything it will carry.
	//
	// Without this the height is whatever the card happened to be before the first state
	// response landed — no title, no progress bar, no hint — and an assertion about how
	// little space it takes passes for the wrong reason. The two tests here disagreed by
	// 120px until this was added, which is how it was noticed.
	await expect(page.getByTestId('now-playing-title')).not.toBeEmpty({ timeout: 20_000 })
	await expect(page.getByTestId('now-playing-time')).toContainText(/\d+:\d\d/, { timeout: 20_000 })
}

test('the on-air card leaves most of a phone screen for the playlist', async ({ page }) => {
	await openChannelOnAPhone(page)

	const box = await page.getByTestId('on-air').boundingBox()
	expect(box).not.toBeNull()

	console.log(`[measure] card height not listening: ${Math.round(box!.height)}px of ${PHONE.height}`)
	// Under a third of the screen while not listening.
	expect(box!.height).toBeLessThan(PHONE.height / 3)
})

test('it does not grow when listening', async ({ page }) => {
	await openChannelOnAPhone(page)

	const before = (await page.getByTestId('on-air').boundingBox())!.height

	await page.getByTestId('tune-in').click()
	await expect(page.getByTestId('tune-out')).toBeVisible({ timeout: 30_000 })

	const after = (await page.getByTestId('on-air').boundingBox())!.height

	// Tuning in swaps the hint and the button for the transport controls and the status
	// line. That is a fair trade in content; it must not become a worse one in height.
	console.log(`[measure] card height listening: ${Math.round(after)}px (was ${Math.round(before)}px)`)
	expect(after).toBeLessThanOrEqual(before + 24)
	expect(after).toBeLessThan(PHONE.height / 3)
})

test('the whole card fits without the page scrolling sideways', async ({ page }) => {
	await openChannelOnAPhone(page)

	// A control that overflows the viewport is the other way this card goes wrong on a
	// phone, and it is invisible in a screenshot taken at desktop width.
	const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)
	expect(overflows).toBe(false)
})
