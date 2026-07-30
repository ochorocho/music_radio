/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pausing a channel and starting it again.
 *
 * Every assertion here is about the `<audio>` element, deliberately. The existing coverage
 * of pausing (`sync.spec.ts`) reads the sync debug readout instead — and that readout is
 * rendered by OnAir, which owns the buttons but owns no audio. It therefore reported a
 * perfectly healthy resume while nothing was making any sound, which is exactly how a
 * channel could stay silent after pause → play with the UI insisting it was on air.
 *
 * `!muted` matters as much as `!paused`: a stuck element is hard-seeked roughly once a
 * second, and every hard seek mutes it for up to a second. A resume that only un-pauses
 * would still be intermittently silent, and `!paused` alone would not notice.
 *
 * These use a single 30-second fixture rather than the 3/5/8-second tones the other specs
 * share, because **a stuck player heals itself at the next track boundary** — the
 * track-change path calls play() unconditionally. Against short tones an entirely broken
 * resume looks fixed within a few seconds. Every test here therefore also asserts the
 * track has *not* changed, so recovering by rolling over into the next track cannot be
 * mistaken for resuming.
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

/** Is anything actually making sound? Not paused, not muted, not finished. */
async function audible(page: Page): Promise<boolean> {
	return await page.evaluate(() =>
		Array.from(document.querySelectorAll('audio'))
			.some((a) => !a.paused && !a.muted && !a.ended),
	)
}

/**
 * The play/pause button sends whichever action is the opposite of what it currently
 * believes. On a freshly loaded page that belief is "not broadcasting" until the first
 * state arrives — so clicking too early sends `play` to an already-playing channel and
 * nothing appears to happen.
 */
async function waitForStatus(page: Page, status: string) {
	await expect
		.poll(async () => (await readSync(page)).status, { timeout: 30_000, intervals: [250] })
		.toBe(status)
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

function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

let channelTitle = ''
let channelId: number

test.beforeEach(async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	channelTitle = uniqueTitle('Pause Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title: channelTitle })
	channelId = created.body.id

	// One long track, on purpose — see the note at the top of this file.
	const fileRows = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where name = 'tone-long.mp3' and path like 'files/Music/%'",
	)
	expect(
		fileRows.length,
		'tests/fixtures/tone-long.mp3 must be in admin\'s Music folder — it is seeded from ./data/Music on a fresh install',
	).toBe(1)

	await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
		fileIds: fileRows.map((r) => r.fileid),
	})
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('the owner hears the channel again after pausing and starting it', async ({ page }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
	await openChannel(page, channelTitle)
	await tuneIn(page)

	await expect.poll(() => audible(page), { timeout: 20_000 }).toBe(true)

	await page.getByTestId('control-playpause').click()
	await expect.poll(() => audible(page), { timeout: 20_000 }).toBe(false)

	const before = (await readSync(page)).trackId

	await page.getByTestId('control-playpause').click()

	// No tuning out and back in. Comfortably longer than a poll interval and its jitter,
	// comfortably shorter than the 30-second track.
	await expect.poll(() => audible(page), { timeout: 15_000 }).toBe(true)

	// And it is still the same track: a broken resume recovers only by rolling over into
	// the next one, which would show up here.
	expect((await readSync(page)).trackId).toBe(before)
})

test('pausing and starting repeatedly keeps working', async ({ page }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })
	await openChannel(page, channelTitle)
	await tuneIn(page)
	await expect.poll(() => audible(page), { timeout: 20_000 }).toBe(true)

	const track = (await readSync(page)).trackId

	// Once is a fix; three times is a fix that holds. Each cycle leaves the element in
	// whatever state the last one produced, which is where a half-fix falls over.
	for (let cycle = 0; cycle < 3; cycle++) {
		await page.getByTestId('control-playpause').click()
		await expect.poll(() => audible(page), { timeout: 20_000 }).toBe(false)

		await page.getByTestId('control-playpause').click()
		await expect.poll(() => audible(page), { timeout: 15_000 }).toBe(true)
	}

	// Still the same track — none of those resumes was a boundary in disguise.
	expect((await readSync(page)).trackId).toBe(track)
})

test('a listener on a share link hears it again too', async ({ page, browser }) => {
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

	const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
		shareType: 3,
		permissions: 1,
	})
	expect([200, 201]).toContain(share.status)
	const token = share.body.token

	// A browser with no session at all, which is what a link visitor is.
	const anonContext = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anonPage = await anonContext.newPage()

	try {
		await anonPage.goto(`/index.php/apps/music_radio/s/${token}`)
		await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

		await anonPage.getByTestId('tune-in').click()
		await expect.poll(() => audible(anonPage), { timeout: 30_000 }).toBe(true)
		const track = (await readSync(anonPage)).trackId

		// The listener never touches a control — the owner does, and it has to reach them.
		await openChannel(page, channelTitle)
		await waitForStatus(page, 'playing')
		await page.getByTestId('control-playpause').click()
		await expect.poll(() => audible(anonPage), { timeout: 20_000 }).toBe(false)

		await page.getByTestId('control-playpause').click()
		await expect.poll(() => audible(anonPage), { timeout: 20_000 }).toBe(true)
		expect((await readSync(anonPage)).trackId).toBe(track)
	} finally {
		await anonContext.close()
	}
})
