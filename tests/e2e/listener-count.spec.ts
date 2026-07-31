/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * How many people are listening.
 *
 * The number is only worth showing if it is right, and the two ways it could plausibly be
 * wrong are both structural rather than incidental:
 *
 * - **Watching is not listening.** `OnAir` starts polling the moment the page mounts,
 *   whether or not anybody tuned in. A count taken from traffic would report an audience
 *   for a channel nobody is hearing.
 * - **One browser polls twice.** Once tuned in, `GlobalPlayer` polls alongside `OnAir`,
 *   from the same tab, for the same channel. A count taken from traffic would double
 *   every listener.
 *
 * So the assertions below are about *who is counted*, not merely that a number appears.
 * The figure is read from the `sync-debug` payload rather than the rendered pill, so a
 * restyling does not silently turn these into no-ops.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

/** Long enough for a tuned-out client to be pruned would be 30 s; these wait on polls. */
const SETTLE_TIMEOUT_MS = 30_000

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

async function listenerCount(page: Page): Promise<number | null> {
	const raw = await page.getByTestId('sync-debug').textContent()
	return JSON.parse(raw ?? '{}').listenerCount ?? null
}

/**
 * Wait for the count to reach a value and settle there.
 *
 * Polled rather than sampled: presence is refreshed on each client's own poll, so the
 * number moves a beat after the action that caused it. Requiring it to *arrive* is the
 * honest assertion.
 */
async function expectCount(page: Page, expected: number | null) {
	await expect
		.poll(() => listenerCount(page), { timeout: SETTLE_TIMEOUT_MS, intervals: [500] })
		.toBe(expected)
}

async function openChannel(page: Page, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
}

async function tuneIn(page: Page) {
	await page.getByTestId('tune-in').click()
	await expect(page.getByTestId('tune-out')).toBeVisible({ timeout: 30_000 })
}

async function setUpChannel(page: Page, db: any, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	const fileRows = await db.query(
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
	)
	await api(page, 'POST', `${API}/channels/${id}/tracks`, {
		fileIds: fileRows.map((r: { fileid: number }) => r.fileid),
	})
	await api(page, 'POST', `${API}/channels/${id}/control`, { action: 'play' })

	return id
}

test('nobody is listening until somebody tunes in', async ({ page, db }) => {
	const title = 'Listener Count Channel'
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)

		// The page is open and polling. That is not an audience.
		await expectCount(page, 0)

		await tuneIn(page)
		await expectCount(page, 1)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('one browser polling from two components counts as one person', async ({ page, db }) => {
	const title = 'Double Poll Channel'
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)
		await tuneIn(page)

		// Once tuned in, OnAir and GlobalPlayer are both polling this channel from this
		// one tab. Held over several poll intervals so a second poller adding itself
		// would have every chance to show up.
		await expectCount(page, 1)
		await page.waitForTimeout(6_000)
		expect(await listenerCount(page)).toBe(1)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('a second listener is counted, and stops being counted on tuning out', async ({ page, db, browser }) => {
	const title = 'Two Listener Channel'
	const channelId = await setUpChannel(page, db, title)

	const second = await browser.newContext({
		ignoreHTTPSErrors: true,
		storageState: 'tests/e2e/.auth/admin.json',
	})
	const pageB = await second.newPage()

	try {
		await openChannel(page, title)
		await openChannel(pageB, title)

		await tuneIn(page)
		await expectCount(page, 1)

		await tuneIn(pageB)
		// Both browsers agree on the figure — it is the server's, not each page's guess.
		await expectCount(page, 2)
		await expectCount(pageB, 2)

		// Tuning out is reported immediately rather than left to expire.
		await pageB.getByTestId('tune-out').click()
		await pageB.getByRole('button', { name: 'Stop listening' }).last().click()
		await expectCount(page, 1)
	} finally {
		await pageB.close()
		await second.close()
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('the owner sees the count even when the channel does not publish it', async ({ page, db }) => {
	const title = 'Private Count Channel'
	const channelId = await setUpChannel(page, db, title)

	try {
		await api(page, 'PUT', `${API}/channels/${channelId}`, { showListenerCount: false })

		await openChannel(page, title)
		await tuneIn(page)

		// The switch governs what a share link shows. It was never meant to hide the
		// number from the person running the channel, who is who asked for it.
		await expectCount(page, 1)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('a link visitor sees the count only when the channel publishes it', async ({ page, db, browser }) => {
	const title = 'Shared Count Channel'
	const channelId = await setUpChannel(page, db, title)

	const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, { shareType: 3 })
	const token = share.body.token as string

	// No stored session: a genuine anonymous visitor, not a signed-in user in disguise.
	const anonymous = await browser.newContext({ ignoreHTTPSErrors: true })
	const visitor = await anonymous.newPage()

	try {
		await openChannel(page, title)
		await tuneIn(page)
		await expectCount(page, 1)

		await visitor.goto(`${APP_PATH}s/${token}`)
		await expect(visitor.getByTestId('public-channel-title')).toHaveText(title, { timeout: 20_000 })
		await expectCount(visitor, 1)

		await api(page, 'PUT', `${API}/channels/${channelId}`, { showListenerCount: false })
		await visitor.reload()
		await expect(visitor.getByTestId('public-channel-title')).toHaveText(title, { timeout: 20_000 })

		// Withheld, not zeroed: a channel keeping the figure to itself must not read as
		// a channel nobody is listening to.
		await expectCount(visitor, null)
		await expect(visitor.getByTestId('listener-count')).toHaveCount(0)

		// …and the owner still has it.
		await expectCount(page, 1)
	} finally {
		await visitor.close()
		await anonymous.close()
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})
