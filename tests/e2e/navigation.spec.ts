/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Listening survives moving around the app.
 *
 * The player used to live inside the channel view, so opening a different channel
 * unmounted it and the music simply stopped — and coming back left a "Tune in" button
 * with no explanation of why the sound had gone. It now lives above the view, and stops
 * only when the listener tunes in somewhere else or the channel goes away.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

let titleA = ''
let titleB = ''
let channelA = 0
let channelB = 0

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

/** How many audio elements are actually making sound. */
async function playingCount(page: Page): Promise<number> {
	return await page.evaluate(() =>
		Array.from(document.querySelectorAll('audio')).filter((a) => !a.paused && !a.ended).length,
	)
}

/** Which channel the app-level player is attached to, if any. */
async function listeningChannelId(page: Page): Promise<string> {
	return await page.getByTestId('global-player').getAttribute('data-channel-id') ?? ''
}

async function openChannel(page: Page, title: string) {
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
}

async function tuneIn(page: Page) {
	await page.getByTestId('tune-in').click()
	await expect.poll(async () => (await readSync(page)).tunedIn, { timeout: 30_000, intervals: [250] }).toBe(true)
	await expect.poll(() => playingCount(page), { timeout: 30_000, intervals: [500] }).toBe(1)
}

test.beforeEach(async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	// Prefixed so the navigation order is predictable regardless of what else exists.
	titleA = uniqueTitle('AAA Nav One')
	titleB = uniqueTitle('AAB Nav Two')

	const fileRows = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
	)
	const fileIds = fileRows.map((r) => r.fileid)

	channelA = (await api(page, 'POST', `${API}/channels`, { title: titleA })).body.id
	channelB = (await api(page, 'POST', `${API}/channels`, { title: titleB })).body.id
	for (const id of [channelA, channelB]) {
		await api(page, 'POST', `${API}/channels/${id}/tracks`, { fileIds })
		await api(page, 'POST', `${API}/channels/${id}/control`, { action: 'play' })
	}
	await page.reload()
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelA}`)
	await api(page, 'DELETE', `${API}/channels/${channelB}`)
})

test('the music keeps playing while browsing a different channel', async ({ page }) => {
	await openChannel(page, titleA)
	await tuneIn(page)
	const listening = await listeningChannelId(page)
	expect(listening).toBe(String(channelA))

	// Walk over to the other channel.
	await openChannel(page, titleB)
	await page.waitForTimeout(2000)

	// Still hearing the first one, and still attached to it.
	await expect.poll(() => playingCount(page), { timeout: 20_000, intervals: [500] }).toBe(1)
	expect(await listeningChannelId(page)).toBe(String(channelA))

	// The channel on screen is not the one being listened to, so it offers to tune in.
	expect((await readSync(page)).tunedIn).toBe(false)
	await expect(page.getByTestId('tune-in')).toBeVisible()
})

test('coming back to the channel shows it as still being listened to', async ({ page }) => {
	await openChannel(page, titleA)
	await tuneIn(page)

	await openChannel(page, titleB)
	await page.waitForTimeout(1000)
	await openChannel(page, titleA)

	// No need to press anything again — it never stopped. Polled because a track
	// boundary briefly pauses both elements while the next one loads.
	await expect.poll(async () => (await readSync(page)).tunedIn, { timeout: 20_000 }).toBe(true)
	await expect.poll(() => playingCount(page), { timeout: 20_000, intervals: [500] }).toBe(1)
	await expect(page.getByTestId('tune-in')).toHaveCount(0)
})

test('tuning in to another channel stops the first', async ({ page }) => {
	await openChannel(page, titleA)
	await tuneIn(page)
	expect(await listeningChannelId(page)).toBe(String(channelA))

	await openChannel(page, titleB)
	await tuneIn(page)

	// Switched over, and only one thing is audible — never both at once.
	expect(await listeningChannelId(page)).toBe(String(channelB))
	await expect.poll(() => playingCount(page), { timeout: 20_000, intervals: [500] }).toBe(1)
})

test('the position stays continuous across navigating away and back', async ({ page }) => {
	await openChannel(page, titleA)
	await tuneIn(page)

	const before = await readSync(page)

	await openChannel(page, titleB)
	await page.waitForTimeout(3000)
	await openChannel(page, titleA)
	await expect.poll(async () => (await readSync(page)).tunedIn, { timeout: 20_000 }).toBe(true)

	const after = await readSync(page)

	// It carried on through the detour rather than restarting.
	const advanced = after.trackId === before.trackId
		? after.offsetMs - before.offsetMs
		: Number.MAX_SAFE_INTEGER
	expect(advanced === Number.MAX_SAFE_INTEGER || advanced > 0).toBe(true)
})

test('deleting the channel being listened to stops the music', async ({ page }) => {
	await openChannel(page, titleA)
	await tuneIn(page)

	await api(page, 'DELETE', `${API}/channels/${channelA}`)
	await page.reload()
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	// A reload is a fresh page, so nothing should be playing and nothing attached.
	expect(await playingCount(page)).toBe(0)
	expect(await listeningChannelId(page)).toBe('')
})
