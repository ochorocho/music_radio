/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Log in once as admin and persist the session to storageState, so every spec
 * starts authenticated without repeating the Nextcloud login flow.
 *
 * It also pins that account's language to English for the duration of the run, and puts
 * back whatever it was afterwards — see setLanguage() for why that is not optional.
 */
import { chromium, type FullConfig, type Page } from '@playwright/test'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'

const AUTH_FILE = 'tests/e2e/.auth/admin.json'
export const LANG_FILE = 'tests/e2e/.auth/language.json'
const USER = process.env.MUSIC_RADIO_ADMIN_USER || 'admin'
const PASS = process.env.MUSIC_RADIO_ADMIN_PASSWORD || 'admin'

async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = config.projects[0]?.use?.baseURL as string
	mkdirSync(dirname(AUTH_FILE), { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ ignoreHTTPSErrors: true })
	const page = await context.newPage()
	try {
		// This instance serves /index.php/… URLs (pretty URLs are off).
		await page.goto(`${baseURL}/index.php/login`, { waitUntil: 'domcontentloaded' })
		// The Nextcloud login form is a Vue app; wait for the fields to render.
		await page.locator('input[name="user"]').waitFor({ state: 'visible', timeout: 30_000 })
		await page.fill('input[name="user"]', USER)
		await page.fill('input[name="password"]', PASS)
		await Promise.all([
			page.waitForURL((url) => !url.pathname.replace(/\/index\.php/, '').startsWith('/login'), { timeout: 30_000 }),
			page.locator('button[type="submit"], [data-login-form-submit]').first().click(),
		])
		await context.storageState({ path: AUTH_FILE })

		const previous = await readLanguage(page)
		writeFileSync(LANG_FILE, JSON.stringify({ previous }), 'utf8')
		if (previous !== 'en') {
			await setLanguage(page, 'en')
		}
	} finally {
		await browser.close()
	}
}

/**
 * The account this suite drives is the same one a developer browses with, and almost every
 * assertion here finds things by their accessible name — "Tune in", "Choose", "Settings".
 * Those names are translated. Set that account to German in the browser, as anyone working
 * on this in a German locale eventually does, and the suite stops being able to find
 * anything: menus never open, and the failures arrive as timeouts pointing at popovers
 * rather than at the language.
 *
 * That happened, and the diagnosis cost far more than this function does. So the language
 * is pinned here rather than assumed, and restored in globalTeardown so the account is
 * left as it was found — the same borrow-and-return the yt-dlp wrapper does around the
 * downloader path.
 *
 * @param page an authenticated page
 */
async function setLanguage(page: Page, language: string): Promise<void> {
	await page.evaluate(async ({ language }) => {
		await fetch('/ocs/v2.php/cloud/users/admin', {
			method: 'PUT',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'OCS-APIRequest': 'true',
				requesttoken: (window as any).OC?.requestToken ?? '',
			},
			body: `key=language&value=${encodeURIComponent(language)}`,
		})
	}, { language })
}

/** @param page an authenticated page */
async function readLanguage(page: Page): Promise<string | null> {
	return await page.evaluate(async () => {
		const response = await fetch('/ocs/v2.php/cloud/users/admin?format=json', {
			headers: {
				'OCS-APIRequest': 'true',
				requesttoken: (window as any).OC?.requestToken ?? '',
			},
		})
		const body = await response.json()

		return body?.ocs?.data?.language ?? null
	})
}

export { setLanguage, readLanguage }
export default globalSetup
