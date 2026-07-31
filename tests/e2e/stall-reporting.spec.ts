/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * That the player says whether it has run out of data.
 *
 * Reported as "choppy on the iPhone, songs get stuck". The cause was that nothing watched
 * for a stall at all: a `readyState` check in `correctDrift` returned without acting, so
 * the broadcast walked away from a stalled element, and by the time it recovered the gap
 * was past the nudge limit and the correction became a seek — which re-requests the audio
 * and stalls again. The fix is in the player; what is asserted here is the part of it that
 * can be observed from outside.
 *
 * **What this file deliberately does not do** is emulate an iPhone. Two attempts at it
 * were abandoned: Nextcloud refuses an iOS Safari User-Agent server-side with its
 * unsupported-browser page, and rewriting `navigator` in the page to get past that trips
 * the same check client-side. Both were testing Nextcloud's browser gate rather than this
 * app. Nor can a stall itself be induced here — the fixtures are a few seconds long and
 * download instantly, so nothing ever runs short of data.
 *
 * The iOS-specific branches (skipping the idle-element preload) and stall recovery under a
 * poor connection are therefore confirmed on a real device, not here. The `stalled` flag
 * exists to make that confirmation possible at all: without it, "the song got stuck" and
 * "the player is broken" look identical from the outside.
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
	return JSON.parse((await page.getByTestId('sync-debug').textContent()) ?? '{}')
}

test('the player reports whether it has stalled, and does not while healthy', async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	const title = `Stall Readout ${Math.random().toString(36).slice(2, 8)}`
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const channelId = created.body.id as number

	try {
		const rows = await db.query(
			"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
		)
		await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
			fileIds: rows.map((r: { fileid: number }) => r.fileid),
		})
		await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

		await page.goto(APP_PATH)
		await page.getByRole('link', { name: title }).first().click()
		await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })

		await page.getByTestId('tune-in').click()
		await expect
			.poll(async () => (await readSync(page)).trackId, { timeout: 30_000, intervals: [250] })
			.not.toBeNull()

		// Published at all — this is the diagnostic the fix depends on being able to read
		// back from a phone.
		const state = await readSync(page)
		expect(state).toHaveProperty('stalled')

		// And false throughout healthy playback, including across a track boundary, which
		// is where a spurious stall would most plausibly be reported.
		const first = state.trackId
		await expect
			.poll(async () => (await readSync(page)).trackId, { timeout: 30_000, intervals: [500] })
			.not.toBe(first)

		expect((await readSync(page)).stalled).toBe(false)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})
