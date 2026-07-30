/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Accessibility of the sharing experience: the sharing dialog, the public page an
 * anonymous listener lands on, and the password form guarding a protected link.
 *
 * Scoped to this app's own markup wherever possible. Auditing a whole Nextcloud page
 * would report core's header and navigation too, which this app cannot fix and which
 * would drown out anything real.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

/** WCAG 2.1 A and AA — the level Nextcloud itself targets. */
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

let channelTitle = ''
let channelId = 0
let openToken = ''
let protectedToken = ''
let uploadToken = ''
let uploadShareId = 0

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
 * Report violations with enough detail to act on, rather than just a count.
 *
 * @param results axe results
 * @return {string} a readable summary, empty when clean
 */
function describe(results: { violations: Array<{ id: string, impact?: string | null, help: string, nodes: Array<{ html: string }> }> }): string {
	return results.violations
		.map((v) => `[${v.impact}] ${v.id}: ${v.help}\n    ${v.nodes.map((n) => n.html.slice(0, 160)).join('\n    ')}`)
		.join('\n')
}

test.beforeEach(async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	channelTitle = uniqueTitle('Accessibility Channel')
	channelId = (await api(page, 'POST', `${API}/channels`, { title: channelTitle })).body.id

	const fileRows = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3') and path like 'files/Music/%' order by name",
	)
	await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
		fileIds: fileRows.map((r) => r.fileid),
	})
	await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'play' })

	openToken = (await api(page, 'POST', `${API}/channels/${channelId}/shares`, { shareType: 3 })).body.token
	protectedToken = (await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
		shareType: 3,
		password: 'Listen-To-This-2026!',
	})).body.token

	// A third link with uploading switched on — that panel is only rendered for those,
	// so the audits above would never see it.
	const uploadShare = (await api(page, 'POST', `${API}/channels/${channelId}/shares`, { shareType: 3 })).body
	uploadShareId = uploadShare.id
	uploadToken = uploadShare.token
	await api(page, 'PUT', `${API}/channels/${channelId}/shares/${uploadShareId}`, { permissions: 1 | 2 })
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('the sharing dialog has no accessibility violations', async ({ page }) => {
	await page.goto(APP_PATH)
	await page.getByRole('link', { name: channelTitle }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })

	await page.getByTestId('open-sharing').click()
	await expect(page.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })
	// Wait for the link rows, so their controls are audited too.
	await expect(page.getByTestId('link-share-row').first()).toBeVisible({ timeout: 20_000 })

	// The dialog fades in. Auditing mid-transition makes axe compute contrast against a
	// partly transparent backdrop and report the whole dialog — core's own components
	// included — as failing.
	await page.waitForTimeout(1000)

	const results = await new AxeBuilder({ page })
		.include('[role="dialog"]')
		.withTags(WCAG)
		.analyze()

	expect(describe(results), describe(results)).toBe('')
})

test('the sharing dialog is reachable and dismissable by keyboard alone', async ({ page }) => {
	await page.goto(APP_PATH)
	await page.getByRole('link', { name: channelTitle }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })

	await page.getByTestId('open-sharing').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 20_000 })

	// Focus lands inside the dialog rather than being left behind on the page.
	await expect
		.poll(async () => await dialog.evaluate((el) => el.contains(document.activeElement)), { timeout: 10_000 })
		.toBe(true)

	// There is a keyboard-reachable way out that is not Escape.
	const close = dialog.getByRole('button', { name: /close|schließen/i }).first()
	await expect(close).toBeVisible()

	// And Escape gets out too, from wherever focus happens to be inside the panel.
	await page.keyboard.press('Escape')
	await expect(dialog).toHaveCount(0)
})

test('every control in the sharing dialog has an accessible name', async ({ page }) => {
	await page.goto(APP_PATH)
	await page.getByRole('link', { name: channelTitle }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })
	await page.getByTestId('open-sharing').click()
	await expect(page.getByTestId('link-share-row').first()).toBeVisible({ timeout: 20_000 })
	await page.waitForTimeout(1000)

	// Icon-only buttons are the usual offenders — a button with no text and no label is
	// announced as just "button".
	const unnamed = await page.getByRole('dialog').evaluate((dialog) =>
		Array.from(dialog.querySelectorAll('button, input, textarea, select'))
			// Anything explicitly hidden from assistive tech is not announced at all, so
			// it needs no name — decorative widgets inside third-party components are
			// marked this way on purpose.
			.filter((el) => el.closest('[aria-hidden="true"]') === null)
			.filter((el) => {
				const label = (el.getAttribute('aria-label')
					?? el.getAttribute('aria-labelledby')
					?? el.getAttribute('title')
					?? (el as HTMLElement).innerText
					?? '').trim()
				const described = el.id !== '' && dialog.querySelector(`label[for="${el.id}"]`) !== null
				return label === '' && !described
			})
			.map((el) => el.outerHTML.slice(0, 120)),
	)

	expect(unnamed, `controls without an accessible name:\n${unnamed.join('\n')}`).toEqual([])
})

test('the public share page has no accessibility violations', async ({ browser }) => {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${openToken}`)
		await expect(anon.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

		const results = await new AxeBuilder({ page: anon }).withTags(WCAG).analyze()
		expect(describe(results), describe(results)).toBe('')
	} finally {
		await anon.close()
		await context.close()
	}
})

test('the public page is still accessible once listening', async ({ browser }) => {
	// Tuning in swaps the whole panel over to the playing state, which is different
	// markup and needs auditing in its own right.
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${openToken}`)
		await anon.getByTestId('tune-in').click()
		await expect
			.poll(async () => JSON.parse(await anon.getByTestId('sync-debug').textContent() ?? '{}').tunedIn,
				{ timeout: 30_000, intervals: [250] })
			.toBe(true)

		const results = await new AxeBuilder({ page: anon }).withTags(WCAG).analyze()
		expect(describe(results), describe(results)).toBe('')
	} finally {
		await anon.close()
		await context.close()
	}
})

/**
 * Wait for a dialog to finish appearing.
 *
 * NcModal fades in, and Playwright considers an element visible as soon as it has a box —
 * opacity is not part of that. Scanning too early measures text against a wrapper that is
 * still fully transparent, and every contrast check fails, including the dialog's own
 * title and buttons.
 *
 * @param page playwright page
 */
async function dialogSettled(page: Page) {
	await expect
		.poll(async () => await page.locator('.modal-wrapper').first()
			.evaluate((el) => getComputedStyle(el).opacity), { timeout: 10_000 })
		.toBe('1')
}

test('the public upload panel has no accessibility violations', async ({ browser }) => {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${uploadToken}`)
		await anon.getByTestId('public-upload-open').click()
		await expect(anon.getByTestId('public-upload-input')).toBeVisible({ timeout: 20_000 })
		await dialogSettled(anon)

		// The file field is labelled rather than left to be announced as just "file".
		const id = await anon.getByTestId('public-upload-input').getAttribute('id')
		await expect(anon.locator(`label[for="${id}"]`)).toBeVisible()

		const results = await new AxeBuilder({ page: anon }).withTags(WCAG).analyze()
		expect(describe(results), describe(results)).toBe('')
	} finally {
		await anon.close()
		await context.close()
	}
})

test('a failed upload is announced, not just shown', async ({ browser }) => {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${uploadToken}`)
		await anon.getByTestId('public-upload-open').click()
		await expect(anon.getByTestId('public-upload-input')).toBeVisible({ timeout: 20_000 })
		await dialogSettled(anon)

		await anon.getByTestId('public-upload-input').setInputFiles({
			name: 'not-really.mp3',
			mimeType: 'audio/mpeg',
			buffer: Buffer.from('plain text, not a song'),
		})
		await anon.getByTestId('public-upload-submit').click()

		// role=alert, so a screen reader hears why nothing happened.
		await expect(anon.locator('[role="alert"]')).toBeVisible({ timeout: 30_000 })

		const results = await new AxeBuilder({ page: anon }).withTags(WCAG).analyze()
		expect(describe(results), describe(results)).toBe('')
	} finally {
		await anon.close()
		await context.close()
	}
})

test('the password form has no accessibility violations', async ({ browser }) => {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${protectedToken}`)
		await expect(anon.locator('input[type="password"]')).toBeVisible({ timeout: 20_000 })

		const results = await new AxeBuilder({ page: anon }).withTags(WCAG).analyze()
		expect(describe(results), describe(results)).toBe('')
	} finally {
		await anon.close()
		await context.close()
	}
})

test('the password form labels its field and announces a wrong password', async ({ browser }) => {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${protectedToken}`)

		// The field is labelled, not just placeholder-hinted.
		const field = anon.locator('input[type="password"]')
		await expect(field).toBeVisible({ timeout: 20_000 })
		const id = await field.getAttribute('id')
		await expect(anon.locator(`label[for="${id}"]`)).toBeVisible()

		// A wrong password is announced, not just shown.
		await field.fill('not-the-password')
		await anon.locator('button[type="submit"]').click()
		await expect(anon.locator('[role="alert"]')).toBeVisible({ timeout: 20_000 })

		const results = await new AxeBuilder({ page: anon }).withTags(WCAG).analyze()
		expect(describe(results), describe(results)).toBe('')
	} finally {
		await anon.close()
		await context.close()
	}
})
