/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Being at the channel without listening to it: seeing what is on air anyway, and
 * playing a track privately.
 *
 * The rule that keeps the earlier "two songs at once" problem from returning is that
 * private playback and the channel are mutually exclusive — previewing is only offered
 * while not tuned in, and tuning in ends it.
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

/** Open the actions menu on a playlist row. */
async function openRowMenu(page: Page, index: number) {
	await page.getByTestId('track').nth(index).getByRole('button').last().click()
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

	channelTitle = uniqueTitle('Off Air Channel')
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

// --------------------------------------------- seeing the channel without hearing it

test('what is on air is shown even when not tuned in', async ({ page, db }) => {
	const rows = await db.query<Array<{ id: number, title: string }>>(
		'select id, title from oc_music_radio_tracks where channel_id = ? order by sort_order', [channelId],
	)
	await api(page, 'POST', `${API}/channels/${channelId}/control`, {
		action: 'jumpTo',
		trackId: rows[1].id,
	})

	await openChannel(page, channelTitle)

	// Not listening…
	await expect(page.getByTestId('tune-in')).toBeVisible()
	expect((await readSync(page)).tunedIn).toBe(false)

	// …but the channel's state is on screen anyway.
	await expect(page.getByTestId('off-air-status')).toBeVisible({ timeout: 20_000 })
	await expect(page.getByTestId('now-playing-title')).toHaveText(rows[1].title)
	// The position too, which is the part that proves this is the live broadcast rather
	// than a static "something is playing" placeholder.
	await expect(page.getByTestId('now-playing-time')).toContainText(/\d+:\d\d/)
	// The standing "you are not listening" note is gone — the Tune in button beside it says
	// the same thing in less room — and what remains of that element is about whether the
	// channel *can* go on air, which is a different test's business. Nothing is asserted
	// about it here.

	// And the marked row matches.
	await expect(page.getByTestId('track').nth(1)).toHaveAttribute('data-onair', 'true')
})

test('the position keeps moving while only watching', async ({ page }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

	await openChannel(page, channelTitle)
	await expect(page.getByTestId('off-air-status')).toBeVisible({ timeout: 20_000 })

	const before = await readSync(page)
	await page.waitForTimeout(2000)
	const after = await readSync(page)

	// Derived from the server's clock, so it advances without any audio running.
	expect(after.tunedIn).toBe(false)
	expect(after.offsetMs).toBeGreaterThan(before.offsetMs)

	// Nothing is making sound.
	const playing = await page.evaluate(() =>
		Array.from(document.querySelectorAll('audio')).filter((a) => !a.paused && !a.ended).length,
	)
	expect(playing).toBe(0)
})

test('a paused channel says so rather than looking live', async ({ page }) => {
	await openChannel(page, channelTitle)

	await expect(page.getByTestId('off-air-status')).toBeVisible({ timeout: 20_000 })
	expect((await readSync(page)).status).toBe('paused')
})

// ------------------------------------------------------------- private playback

test('a track can be played privately while not tuned in', async ({ page }) => {
	await openChannel(page, channelTitle)
	await expect(page.getByTestId('off-air-status')).toBeVisible({ timeout: 20_000 })

	// Hidden rather than absent: the bar stays mounted so its <audio> element outlives
	// each preview, which is what lets a phone start one from the tap that asks for it.
	await expect(page.getByTestId('preview-player')).toBeHidden()

	await openRowMenu(page, 0)
	await page.getByTestId('preview-track').click()

	await expect(page.getByTestId('preview-player')).toBeVisible({ timeout: 20_000 })

	const audio = page.getByTestId('preview-audio')
	await expect(audio).toHaveAttribute('src', new RegExp(`/channels/${channelId}/tracks/\\d+/stream`))

	// It really decodes — the first fixture tone is 3s.
	await expect
		.poll(async () => await audio.evaluate((el: HTMLAudioElement) => el.duration), { timeout: 20_000 })
		.toBeGreaterThan(2.5)

	// And it starts on its own, with nothing else pressed. This used to rest on an
	// `autoplay` attribute on an element built a tick after the click, which iOS refuses
	// — leaving the native play control to be pressed as a second tap. It is now a
	// play() call made inside the click itself.
	await expect
		.poll(async () => await audio.evaluate((el: HTMLAudioElement) => !el.paused), { timeout: 20_000 })
		.toBe(true)

	// And the channel is untouched: this is private listening, not a broadcast change.
	const state = await api(page, 'GET', `${API}/channels/${channelId}/state`)
	expect(state.body.status).toBe('paused')
})

test('private playback stops as soon as the channel is tuned in', async ({ page }) => {
	// This is the guarantee that two songs cannot overlap.
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

	await openChannel(page, channelTitle)
	await expect(page.getByTestId('off-air-status')).toBeVisible({ timeout: 20_000 })

	await openRowMenu(page, 0)
	await page.getByTestId('preview-track').click()
	await expect(page.getByTestId('preview-player')).toBeVisible({ timeout: 20_000 })

	await page.getByTestId('tune-in').click()
	await expect
		.poll(async () => (await readSync(page)).tunedIn, { timeout: 30_000, intervals: [250] })
		.toBe(true)

	// The private player is gone from view — the element stays, silent and empty, which
	// the audible-count assertion below is what really pins down.
	await expect(page.getByTestId('preview-player')).toBeHidden()

	// …and exactly one thing ends up audible: the channel. Polled rather than sampled
	// after a fixed wait, because the channel's audio has to load before it starts.
	await expect
		.poll(async () => await page.evaluate(() =>
			Array.from(document.querySelectorAll('audio')).filter((a) => !a.paused && !a.ended).length,
		), { timeout: 20_000, intervals: [500] })
		.toBe(1)
})

test('private playback is not offered at all while tuned in', async ({ page }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

	await openChannel(page, channelTitle)
	await page.getByTestId('tune-in').click()
	await expect
		.poll(async () => (await readSync(page)).tunedIn, { timeout: 30_000, intervals: [250] })
		.toBe(true)

	await openRowMenu(page, 0)
	await expect(page.getByTestId('preview-track')).toHaveCount(0)
})

test('choosing the same track again stops private playback', async ({ page }) => {
	await openChannel(page, channelTitle)
	await expect(page.getByTestId('off-air-status')).toBeVisible({ timeout: 20_000 })

	await openRowMenu(page, 0)
	await page.getByTestId('preview-track').click()
	await expect(page.getByTestId('preview-player')).toBeVisible({ timeout: 20_000 })

	await openRowMenu(page, 0)
	await page.getByTestId('preview-track').click()
	await expect(page.getByTestId('preview-player')).toBeHidden()

	// Hiding the bar is not the point — stopping the sound is. The element is still
	// there, so assert it actually let go of the track rather than merely going invisible.
	const audio = page.getByTestId('preview-audio')
	await expect(audio).not.toHaveAttribute('src', /stream/)
	expect(await audio.evaluate((el: HTMLAudioElement) => el.paused)).toBe(true)
})
