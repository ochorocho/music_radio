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
/**
 * What the server credits an anonymous upload to.
 *
 * A browser that accepts cookies is issued a per-visitor key on the share page, and its
 * uploads are credited `?link:<key>` so it can take them back again. The bare
 * `?public-link` is the fallback for a browser that has no key, and what everything
 * uploaded before visitor keys existed still carries.
 *
 * The `?` prefix is common to both, and is what keeps either from being mistaken for a
 * real user id.
 */
const UPLOADED_BY_PREFIX = '?'
const VISITOR_CREDIT = /^\?link:[a-z0-9]{32}$/

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

/**
 * Wait until an upload has been accepted.
 *
 * Closing is what success looks like now: the outcome goes to a toast and the dialog gets
 * out of the way of the playlist its backdrop was covering. A refusal keeps the dialog —
 * and its message — where the file and the button still are, so the tests that check *why*
 * something was rejected still read `public-upload-message`.
 */
async function expectUploadAccepted(page: Page) {
	await expect(page.getByTestId('public-upload-dialog')).toHaveCount(0, { timeout: 30_000 })
}

/**
 * Reveal a playlist row's actions.
 *
 * The public page renders the same TrackItem the signed-in view does, so removing a track
 * lives among the row's actions rather than in an inline button of its own. NcActions only
 * builds a *menu* once there are several actions, though — a single one is rendered as a
 * plain button in the row. Which of those a public row is depends on what the link was
 * granted: an uploader has only "remove", a curator also has move up and down. Clicking
 * the last button unconditionally would therefore press Remove on the first kind of row.
 */
async function openRowMenu(page: Page, index: number) {
	const toggle = page.getByTestId('track').nth(index).locator('.action-item__menutoggle')
	if (await toggle.count() > 0) {
		await toggle.click()
	}
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
	// The prefix has to be bound, not inlined — knex reads a literal ? in the SQL as
	// another placeholder and complains the bindings do not add up.
	const rows = await db.query<Array<{ path: string }>>(
		`select f.path from oc_filecache f
		 join oc_music_radio_tracks t on t.file_id = f.fileid
		 where t.channel_id = ? and t.added_by like ?`,
		[channelId, UPLOADED_BY_PREFIX + '%'],
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

	await expectUploadAccepted(anonPage)

	// It is on the channel, credited to the browser that sent it — which is what lets that
	// browser take it back — and playable, the server having read the duration out of the
	// file it was handed.
	const rows = await db.query<Array<{ added_by: string, duration_ms: number, mimetype: string }>>(
		'select added_by, duration_ms, mimetype from oc_music_radio_tracks where channel_id = ?', [channelId],
	)
	expect(rows).toHaveLength(1)
	expect(rows[0].added_by).toMatch(VISITOR_CREDIT)
	expect(rows[0].mimetype).toBe('audio/mpeg')
	expect(Number(rows[0].duration_ms)).toBeGreaterThan(0)

	// And the playlist on the page caught up without a reload.
	await expect(anonPage.getByTestId('playlist').locator('li')).toHaveCount(1)
})

test('the uploaded file lands in the channel owner\'s Music folder', async ({ page, db }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)
	await anonPage.getByTestId('public-upload-input').setInputFiles('tests/fixtures/tone-b.mp3')
	await anonPage.getByTestId('public-upload-submit').click()
	await expectUploadAccepted(anonPage)

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
		"select fileid from oc_filecache where name = 'tone-c.mp3' and path like 'files/Music/%' and path not like 'files/Music/%/%'",
	)
	expect(before.length).toBeGreaterThan(0)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)
	await anonPage.getByTestId('public-upload-input').setInputFiles('tests/fixtures/tone-c.mp3')
	await anonPage.getByTestId('public-upload-submit').click()
	await expectUploadAccepted(anonPage)

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

test('a visitor can take back what they uploaded, and nothing else', async ({ page, browser }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
	await openUploadDialog(anonPage)
	await anonPage.getByTestId('public-upload-input').setInputFiles('tests/fixtures/tone-a.mp3')
	await anonPage.getByTestId('public-upload-submit').click()
	// Closing is the success signal, so the playlist underneath is already reachable —
	// this used to need an Escape to get the dialog's backdrop out of the way.
	await expectUploadAccepted(anonPage)

	// Their own row offers a way to undo it, in the row's actions menu — the same place
	// the signed-in playlist keeps it, since the public page now renders the same rows.
	await expect(anonPage.getByTestId('track')).toHaveCount(1, { timeout: 20_000 })
	await openRowMenu(anonPage, 0)
	await expect(anonPage.getByTestId('remove-track')).toHaveCount(1)

	// A different browser has a different key, so the same row is not theirs to remove.
	const other = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	try {
		const otherPage = await other.newPage()
		await otherPage.goto(`${APP_PATH}s/${token}`)
		// Wait for the row itself before asserting the absence of its remove entry, so the
		// assertion cannot pass merely because the playlist has not rendered yet.
		await expect(otherPage.getByTestId('track')).toHaveCount(1, { timeout: 20_000 })
		await openRowMenu(otherPage, 0)
		await expect(otherPage.getByTestId('remove-track')).toHaveCount(0)
	} finally {
		await other.close()
	}

	await anonPage.getByTestId('remove-track').click()
	await expect(anonPage.getByTestId('track')).toHaveCount(0, { timeout: 20_000 })
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

test('a link can be given the broadcast, but never the channel itself', async ({ page }) => {
	// 4 = CONTROL, 8 = EDIT_PLAYLIST. Both are decisions about the music, and an owner
	// makes them per link in the same list of switches a named person's share uses.
	for (const permissions of [1 | 4, 1 | 8]) {
		const result = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${shareId}`, { permissions })
		expect(result.status, `permissions ${permissions} should be allowed`).toBe(200)
	}

	// 16 = SHARE, 32 = MANAGE. These decide who else reaches the channel and what it is,
	// which is not something to delegate to whoever holds a URL.
	for (const permissions of [1 | 16, 1 | 32, 63]) {
		const result = await api(page, 'PUT', `${API}/channels/${channelId}/shares/${shareId}`, { permissions })
		expect(result.status, `permissions ${permissions} should be refused`).toBe(400)
	}

	// Back to listen-only for whatever runs next.
	await api(page, 'PUT', `${API}/channels/${channelId}/shares/${shareId}`, { permissions: LISTEN })
})

test('the owner turns uploading on from the sharing dialog', async ({ page, db }) => {
	await page.goto(APP_PATH)
	await page.getByRole('link', { name: channelTitle }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })

	await page.getByTestId('open-sharing').click()
	await expect(page.getByTestId('link-share-row').first()).toBeVisible({ timeout: 20_000 })
	await expect(page.getByTestId('link-protection')).toContainText(/listener/i)

	// The switches sit behind the chevron now — a link row collapses like every other
	// share row, so a dialog with several of them stays readable.
	await page.getByTestId('link-expand').click()

	// NcCheckboxRadioSwitch renders a hidden input with no <label>, so the click has to
	// be dispatched at the input itself.
	await page.getByTestId('link-share-row').getByTestId('perm-add-tracks')
		.locator('input').dispatchEvent('click')

	await expect(page.getByTestId('link-protection')).toContainText(/contributor/i, { timeout: 20_000 })

	await expect
		.poll(async () => {
			const rows = await db.query<Array<{ permissions: number }>>(
				'select permissions from oc_music_radio_shares where id = ?', [shareId],
			)
			return Number(rows[0].permissions)
		}, { timeout: 20_000 })
		.toBe(LISTEN | ADD_TRACKS)
})

/*
 * Dropping a file onto the dialog.
 *
 * The drop path is additional to the file input, not a replacement for it — the input is
 * what actually carries the file, and every test above still drives it. What these cover
 * is the part a drop can get wrong on its own: what happens when the drag carries
 * something other than one audio file.
 */

/**
 * Build a DataTransfer in the page and drop it on the zone.
 *
 * Playwright has no "drag a file in from the desktop" primitive — the OS-level drag it
 * models is between two elements on the page — so the transfer is constructed in the
 * page's own realm and dispatched. That is exactly what the browser hands the handler for
 * a real drop.
 */
async function dropFiles(page: Page, files: Array<{ name: string, type: string, bytes: number }>) {
	const dataTransfer = await page.evaluateHandle((files) => {
		const transfer = new DataTransfer()
		for (const file of files) {
			transfer.items.add(new File([new Uint8Array(file.bytes)], file.name, { type: file.type }))
		}
		return transfer
	}, files)

	await page.getByTestId('public-upload-dropzone').dispatchEvent('drop', { dataTransfer })
}

test('a dropped file is taken on and can be added', async ({ page, db }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
	await openUploadDialog(anonPage)

	// Not real audio — the server will refuse it — but the point here is the drop being
	// accepted by the page at all, which is what the previous version could not do.
	await dropFiles(anonPage, [{ name: 'dropped.mp3', type: 'audio/mpeg', bytes: 2048 }])

	await expect(anonPage.getByTestId('public-upload-chosen')).toContainText('dropped.mp3')
	await expect(anonPage.getByTestId('public-upload-submit')).toBeEnabled()
})

test('dropping several files at once is refused rather than half-done', async ({ page }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)

	await dropFiles(anonPage, [
		{ name: 'one.mp3', type: 'audio/mpeg', bytes: 1024 },
		{ name: 'two.mp3', type: 'audio/mpeg', bytes: 1024 },
	])

	// One request carries one file, and an anonymous visitor gets ten an hour. Firing off
	// a sequence would spend somebody's whole allowance on one careless drop.
	await expect(anonPage.getByTestId('public-upload-message')).toContainText(/one file at a time/i)
	await expect(anonPage.getByTestId('public-upload-chosen')).toHaveCount(0)
	await expect(anonPage.getByTestId('public-upload-submit')).toBeDisabled()
})

test('a drag carrying no file at all is explained rather than ignored', async ({ page }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)

	await dropFiles(anonPage, [])

	await expect(anonPage.getByTestId('public-upload-message')).toContainText(/not a file/i)
})

test('an oversized file is refused immediately, without uploading it first', async ({ page }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)

	let requests = 0
	anonPage.on('request', (request) => {
		if (request.url().includes('/tracks') && request.method() === 'POST') {
			requests++
		}
	})

	// A file that *reports* being over the 100 MB limit. The check reads `.size`, and
	// describing a huge file is the only way to test the limit without moving 100 MB
	// through the browser to prove a point about not moving it.
	await anonPage.evaluate(() => {
		const zone = document.querySelector('[data-testid="public-upload-dropzone"]') as HTMLElement
		const huge = new File([new Uint8Array(8)], 'enormous.mp3', { type: 'audio/mpeg' })
		Object.defineProperty(huge, 'size', { value: 101 * 1024 * 1024 })
		const transfer = new DataTransfer()
		transfer.items.add(huge)
		zone.dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true }))
	})

	await expect(anonPage.getByTestId('public-upload-message')).toContainText(/too big/i)
	await expect(anonPage.getByTestId('public-upload-submit')).toBeDisabled()
	// The whole point of checking in the browser: nothing was sent.
	expect(requests).toBe(0)
})

test('a progress bar reports an upload in flight', async ({ page }) => {
	await allowUploads(page, true)

	await anonPage.goto(`${APP_PATH}s/${token}`)
	await openUploadDialog(anonPage)

	// Held open until released, so the in-flight state is observable rather than a race
	// against a fast local upload.
	let release: () => void = () => {}
	const held = new Promise<void>((resolve) => {
		release = resolve
	})
	await anonPage.route(`**${API}/public/${token}/tracks`, async (route) => {
		await held
		await route.continue()
	})

	await anonPage.getByTestId('public-upload-input').setInputFiles('tests/fixtures/tone-a.mp3')
	await anonPage.getByTestId('public-upload-submit').click()

	await expect(anonPage.getByTestId('public-upload-progress')).toBeVisible({ timeout: 20_000 })

	release()
	await expectUploadAccepted(anonPage)
	// Gone once there is nothing in flight; a bar left at 100% reads as a stall.
	await expect(anonPage.getByTestId('public-upload-progress')).toHaveCount(0)
	await anonPage.unroute(`**${API}/public/${token}/tracks`)
})
