/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The sharing interface itself: the Share button opens a modal, and a public link can be
 * given a password at the moment it is created rather than afterwards.
 *
 * Creating the link already protected matters — a link created bare and secured a second
 * later is unprotected for that second, and the URL is already generated.
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

async function openChannel(page: Page, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
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

test.beforeEach(async ({ page }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	channelTitle = uniqueTitle('Sharing UI Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title: channelTitle })
	channelId = created.body.id
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('the Share button opens a modal, not a sidebar', async ({ page }) => {
	await openChannel(page, channelTitle)

	await expect(page.getByTestId('sharing-dialog')).toHaveCount(0)

	await page.getByTestId('open-sharing').click()

	// A real modal: a dialog element with the sharing panel inside it.
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 20_000 })
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible()

	// And it closes again.
	await page.keyboard.press('Escape')
	await expect(dialog).toHaveCount(0)
})

test('a public link can be created with a password in one step', async ({ page, db }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })

	// No password by default, and the toggle is offered.
	const protect = dialog.getByTestId('link-protect').locator('input[type="checkbox"]')
	await expect(protect).not.toBeChecked()

	// Turning it on demands a password before the link can be made.
	await protect.dispatchEvent('click')
	await expect(protect).toBeChecked()

	const createLink = dialog.getByTestId('create-link')
	await expect(createLink).toBeDisabled()

	await dialog.locator('input[type="password"]').first().fill('Listen-To-This-2026!')
	await expect(createLink).toBeEnabled()
	await createLink.click()

	// The link exists and was stored already hashed — never written in the clear, and
	// never present unprotected.
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	const rows = await db.query<Array<{ token: string, password: string | null }>>(
		'select token, password from oc_music_radio_shares where channel_id = ? and share_type = 3',
		[channelId],
	)
	expect(rows).toHaveLength(1)
	expect(rows[0].password).not.toBeNull()
	expect(rows[0].password).not.toContain('Listen-To-This-2026!')

	// And it is described as protected.
	await expect(dialog.getByTestId('link-protection')).toContainText(/password/i)
})

test('a link created without the option is not password protected', async ({ page, db }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })

	await dialog.getByTestId('create-link').click()
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	const rows = await db.query<Array<{ password: string | null }>>(
		'select password from oc_music_radio_shares where channel_id = ? and share_type = 3',
		[channelId],
	)
	expect(rows[0].password).toBeNull()
})

test('the password set at creation actually gates the public page', async ({ page, browser, db }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })

	await dialog.getByTestId('link-protect').locator('input[type="checkbox"]').dispatchEvent('click')
	await dialog.locator('input[type="password"]').first().fill('Listen-To-This-2026!')
	await dialog.getByTestId('create-link').click()
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	const rows = await db.query<Array<{ token: string }>>(
		'select token from oc_music_radio_shares where channel_id = ? and share_type = 3', [channelId],
	)
	const token = rows[0].token

	const anon = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anonPage = await anon.newPage()
	try {
		await anonPage.goto(`${APP_PATH}s/${token}`)

		// Asked for the password rather than shown the channel.
		await expect(anonPage.locator('input[type="password"]')).toBeVisible({ timeout: 20_000 })
		await expect(anonPage.getByTestId('public-channel-title')).toHaveCount(0)

		// The right one gets in.
		await anonPage.fill('input[type="password"]', 'Listen-To-This-2026!')
		await anonPage.locator('button[type="submit"]').click()
		await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
	} finally {
		await anonPage.close()
		await anon.close()
	}
})

test('a password supplied at creation is stored and never echoed back', async ({ page }) => {
	const withPassword = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
		shareType: 3,
		password: 'Listen-To-This-2026!',
	})

	expect(withPassword.status).toBe(201)
	expect(withPassword.body.hasPassword).toBe(true)
	// Only the fact of a password crosses the wire, never the hash and never the value.
	expect(withPassword.body.password).toBeUndefined()
	expect(JSON.stringify(withPassword.body)).not.toContain('Listen-To-This-2026!')
})

test('a link shows its settings without needing to open anything', async ({ page }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })
	await dialog.getByTestId('create-link').click()
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	// No "Link settings" step: the URL and the password controls are simply there.
	await expect(dialog.getByTestId('link-url')).toBeVisible()
	await expect(dialog.getByTestId('link-password-field')).toBeVisible()
	await expect(dialog.getByTestId('link-save-password')).toBeVisible()

	// And it says plainly that there is no password yet.
	await expect(dialog.getByTestId('link-protection')).toContainText(/no password/i)
})

test('a password can be set and then removed from an existing link', async ({ page, db }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })
	await dialog.getByTestId('create-link').click()
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	// Nothing to remove while there is no password.
	await expect(dialog.getByTestId('link-clear-password')).toHaveCount(0)

	// The password component puts the test id on the input itself rather than on a
	// wrapper, so there is no descendant to reach for.
	await dialog.locator('input[type="password"]').first().fill('Listen-To-This-2026!')
	await dialog.getByTestId('link-save-password').click()

	await expect(dialog.getByTestId('link-protection')).toContainText(/password protected/i, { timeout: 20_000 })
	await expect(dialog.getByTestId('link-clear-password')).toBeVisible()

	let rows = await db.query<Array<{ password: string | null }>>(
		'select password from oc_music_radio_shares where channel_id = ? and share_type = 3', [channelId],
	)
	expect(rows[0].password).not.toBeNull()

	// And it can be taken off again.
	await dialog.getByTestId('link-clear-password').click()
	await expect(dialog.getByTestId('link-protection')).toContainText(/no password/i, { timeout: 20_000 })

	rows = await db.query<Array<{ password: string | null }>>(
		'select password from oc_music_radio_shares where channel_id = ? and share_type = 3', [channelId],
	)
	expect(rows[0].password).toBeNull()
})

test('the sharing panel reports what the server permits', async ({ page }) => {
	const shares = await api(page, 'GET', `${API}/channels/${channelId}/shares`)

	expect(shares.status).toBe(200)
	// The UI reflects these rather than guessing; server-side enforcement of each is
	// covered deterministically by ShareServiceTest.
	expect(shares.body.capabilities).toMatchObject({
		sharingEnabled: expect.any(Boolean),
		groupSharingAllowed: expect.any(Boolean),
		linksAllowed: expect.any(Boolean),
		linkPasswordEnforced: expect.any(Boolean),
	})
})
