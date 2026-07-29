/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Log in once as admin and persist the session to storageState, so every spec
 * starts authenticated without repeating the Nextcloud login flow.
 */
import { chromium, type FullConfig } from '@playwright/test'
import { mkdirSync } from 'node:fs'
import { dirname } from 'node:path'

const AUTH_FILE = 'tests/e2e/.auth/admin.json'
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
	} finally {
		await browser.close()
	}
}

export default globalSetup
