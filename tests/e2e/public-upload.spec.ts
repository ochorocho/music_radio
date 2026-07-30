/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Letting people with no account put music on a channel.
 *
 * This is the one thing a public link can grant beyond listening, and it is off until
 * the owner switches it on: the file lands in the owner's own Music folder and counts
 * against their quota, so it is their cost to agree to. Everything here is about the
 * boundary — that the switch is respected, that the server checks it again rather than
 * trusting the page, and that what arrives is really audio.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Browser, BrowserContext, Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

const LISTEN = 1
const ADD_TRACKS = 2

/** What the server credits an anonymous upload to; mirrors Track::ADDED_BY_PUBLIC_LINK. */
const UPLOADED_BY = '?public-link'

function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

let channelTitle = ''
let channelId = 0
let shareId = 0
let token = ''
let anonContext: BrowserContext
let anonPage: Page

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

/** A browser with no session at all, which is what a link visitor is. */
async function anonymous(browser: Browser): Promise<[BrowserContext, Page]> {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	return [context, await context.newPage()]
}

/**
 * Uploading lives behind a button beside the channel title now, mirroring the signed-in
 * view, so the file field only exists once the dialog is open.
 */
async function openUploadDialog(page: Page) {
	await page.getByTestId('public-upload-open').click()
	await expect(page.getByTestId('public-upload-input')).toBeVisible({ timeout: 20_000 })
}

async function allowUploads(page: Page, allow: boolean) {
	const result = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${shareId}`, {
		permissions: allow ? LISTEN | ADD_TRACKS : LISTEN,
	})
	expect(result.status).toBe(200)
}

/**
 * Where an uploaded track's file ended up, so the test can put the owner's files back
 * the way it found them.
 */
async function uploadedPaths(db: { query: <T>(sql: string, params?: unknown[]) => Promise<T> }): Promise<string[]> {
	// `?public-link` has to be bound, not inlined — knex reads a literal ? in the SQL as
	// another placeholder and complains the bindings do not add up.
	const rows = await db.query<Array<{ path: string }>>(
		`select f.path from oc_filecache f
		 join oc_music_radio_tracks t on t.file_id = f.fileid
		 where t.channel_id = ? and t.added_by = ?`,
		[channelId, UPLOADED_BY],
	)
	return rows.map((r) => r.path)
}

/**
 * Remove them over WebDAV as the owner — the API that created them has no delete.
 *
 * X-NC-Skip-Trashbin, or every run would leave its uploads piling up in the owner's
 * trash, where they still occupy quota and still have to be found and cleared by hand.
 */
async function removeFiles(page: Page, paths: string[]) {
	for (const path of paths) {
		await page.evaluate(async (dav) => {
			await fetch(dav, {
				method: 'DELETE',
				headers: {
					requesttoken: (window as any).OC?.requestToken ?? '',
					'X-NC-Skip-Trashbin': 'true',
				},
			})
		}, `/remote.php/dav/files/admin/${path.replace(/^files\//, '')}`)
	}
}

test.beforeEach(async ({ page, browser }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	channelTitle = uniqueTitle('Upload Channel')
	channelId = (await api(page, 'POST', `${API}/channels`, { title: channelTitle })).body.id

	const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, { shareType: 3 })
	shareId = share.body.id
	token = share.body.token

	;[anonContext, anonPage] = await anonymous(browser)
})

test.afterEach(async ({ page, db }) => {
	// Files first: the channel has to still exist for its tracks to point at them.
	await removeFiles(page, await uploadedPaths(db))
	await anonPage?.close()
	await anonContext?.close()
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('a link grants listening only until the owner says otherwise', async ({ db }) => {
	const rows = await db.query<Array<{ permissions: number }>>(
		'select permissions from oc_music_radio_shares where id = ?', [shareId],
	)
	expect(Number(rows[0].permissions)).toBe(LISTEN)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

	// Nothing is offered, because nothing is allowed.
	await expect(anonPage.getByTestId('public-upload-open')).toHaveCount(0)
})

test('the server refuses an upload on a listen-only link, page or no page', async () => {
	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

	// Straight at the endpoint, as someone who read the API rather than the page would.
	const status = await anonPage.evaluate(async (url) => {
		const form = new FormData()
		form.append('file', new Blob([new Uint8Array([1, 2, 3])], { type: 'audio/mpeg' }), 'sneaky.mp3')
		return (await fetch(url, { method: 'POST', body: form })).status
	}, `${API}/public/${token}/tracks`)

	expect(status).toBe(403)
})

test('with uploads switched on, a visitor can put a track on the channel', async ({ page, db }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

	await openUploadDialog(anonPage)
	await anonPage.getByTestId('public-upload-input').setInputFiles('tests/fixtures/tone-a.mp3')
	await anonPage.getByTestId('public-upload-submit').click()

	await expect(anonPage.getByTestId('public-upload-message')).toContainText(/added/i, { timeout: 30_000 })

	// It is on the channel, credited to nobody in particular, and playable — the server
	// read the duration out of the file it was handed.
	const rows = await db.query<Array<{ added_by: string, duration_ms: number, mimetype: string }>>(
		'select added_by, duration_ms, mimetype from oc_music_radio_tracks where channel_id = ?', [channelId],
	)
	expect(rows).toHaveLength(1)
	expect(rows[0].added_by).toBe(UPLOADED_BY)
	expect(rows[0].mimetype).toBe('audio/mpeg')
	expect(Number(rows[0].duration_ms)).toBeGreaterThan(0)

	// And the playlist on the page caught up without a reload.
	await expect(anonPage.getByTestId('public-playlist').locator('li')).toHaveCount(1)
})

test('the uploaded file lands in the channel owner\'s Music folder', async ({ page, db }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)
	await anonPage.getByTestId('public-upload-input').setInputFiles('tests/fixtures/tone-b.mp3')
	await anonPage.getByTestId('public-upload-submit').click()
	await expect(anonPage.getByTestId('public-upload-message')).toContainText(/added/i, { timeout: 30_000 })

	const paths = await uploadedPaths(db)
	expect(paths).toHaveLength(1)
	expect(paths[0]).toMatch(/^files\/Music\//)

	// It belongs to the owner, not to some anonymous storage.
	const owner = await db.query<Array<{ id: string }>>(
		`select s.id from oc_filecache f
		 join oc_storages s on s.numeric_id = f.storage
		 where f.path = ? limit 1`,
		[paths[0]],
	)
	expect(owner[0].id).toBe('home::admin')
})

test('an upload never overwrites a file the owner already has', async ({ page, db }) => {
	await allowUploads(page, true)

	// The seeded library already contains tone-c.mp3; uploading it again must not
	// replace the owner's copy.
	const before = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where name = 'tone-c.mp3' and path like 'files/Music/%'",
	)
	expect(before.length).toBeGreaterThan(0)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)
	await anonPage.getByTestId('public-upload-input').setInputFiles('tests/fixtures/tone-c.mp3')
	await anonPage.getByTestId('public-upload-submit').click()
	await expect(anonPage.getByTestId('public-upload-message')).toContainText(/added/i, { timeout: 30_000 })

	const paths = await uploadedPaths(db)
	expect(paths).toHaveLength(1)
	// A different name, so the original is untouched.
	expect(paths[0]).not.toBe('files/Music/tone-c.mp3')

	const after = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where path = 'files/Music/tone-c.mp3'",
	)
	expect(after).toHaveLength(1)
	expect(Number(after[0].fileid)).toBe(Number(before[0].fileid))
})

test('something that is not audio is refused however it is labelled', async ({ page, db }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

	// An .mp3 name and an audio content type over a PHP script — the two things a
	// client controls, both lying.
	await openUploadDialog(anonPage)
	await anonPage.getByTestId('public-upload-input').setInputFiles({
		name: 'totally-a-song.mp3',
		mimeType: 'audio/mpeg',
		buffer: Buffer.from('<?php echo shell_exec($_GET["c"]); ?>'),
	})
	await anonPage.getByTestId('public-upload-submit').click()

	await expect(anonPage.getByTestId('public-upload-message')).toContainText(/audio/i, { timeout: 30_000 })

	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ?', [channelId],
	)
	expect(rows).toHaveLength(0)
})

test('an empty file is refused rather than becoming a silent track', async ({ page, db }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)
	await anonPage.getByTestId('public-upload-input').setInputFiles({
		name: 'nothing.mp3',
		mimeType: 'audio/mpeg',
		buffer: Buffer.alloc(0),
	})
	await anonPage.getByTestId('public-upload-submit').click()

	await expect(anonPage.getByTestId('public-upload-message')).toBeVisible({ timeout: 30_000 })

	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ?', [channelId],
	)
	expect(rows).toHaveLength(0)
})

test('switching uploads back off takes the ability away again', async ({ page }) => {
	await allowUploads(page, true)
	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-upload-open')).toBeVisible({ timeout: 20_000 })

	await allowUploads(page, false)

	// The page a visitor already had open is stale, so the server has to be the one
	// saying no.
	const status = await anonPage.evaluate(async (url) => {
		const form = new FormData()
		form.append('file', new Blob([new Uint8Array([1, 2, 3])], { type: 'audio/mpeg' }), 'late.mp3')
		return (await fetch(url, { method: 'POST', body: form })).status
	}, `${API}/public/${token}/tracks`)
	expect(status).toBe(403)

	await anonPage.reload()
	await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
	await expect(anonPage.getByTestId('public-upload-open')).toHaveCount(0)
})

test('a link still cannot be given anything beyond listening and uploading', async ({ page }) => {
	for (const permissions of [4, 8, 16, 32, 63]) {
		const result = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${shareId}`, { permissions })
		expect(result.status, `permissions ${permissions} should be refused`).toBe(400)
	}
})

test('the owner turns uploading on from the sharing dialog', async ({ page, db }) => {
	await page.goto(APP_PATH)
	await page.getByRole('link', { name: channelTitle }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })

	await page.getByTestId('open-sharing').click()
	await expect(page.getByTestId('link-share-row').first()).toBeVisible({ timeout: 20_000 })
	await expect(page.getByTestId('link-protection')).toContainText(/can listen/i)

	// NcCheckboxRadioSwitch renders a hidden input with no <label>, so the click has to
	// be dispatched at the input itself.
	await page.getByTestId('link-allow-uploads').locator('input').dispatchEvent('click')

	await expect(page.getByTestId('link-protection')).toContainText(/upload/i, { timeout: 20_000 })

	await expect
		.poll(async () => {
			const rows = await db.query<Array<{ permissions: number }>>(
				'select permissions from oc_music_radio_shares where id = ?', [shareId],
			)
			return Number(rows[0].permissions)
		}, { timeout: 20_000 })
		.toBe(LISTEN | ADD_TRACKS)
})
