/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Give the admin account its language back.
 *
 * globalSetup pins it to English so the suite can find things by their accessible name.
 * Without this the borrow would be permanent: a developer working in German would find
 * their instance quietly switched to English by running the tests, which is both rude and
 * the kind of change nobody connects to its cause.
 */
import { chromium, type FullConfig } from '@playwright/test'
import { existsSync, readFileSync, rmSync } from 'node:fs'

import { LANG_FILE, setLanguage } from './global-setup.ts'

const AUTH_FILE = 'tests/e2e/.auth/admin.json'

async function globalTeardown(config: FullConfig): Promise<void> {
	if (!existsSync(LANG_FILE)) {
		return
	}

	let previous: string | null = null
	try {
		previous = JSON.parse(readFileSync(LANG_FILE, 'utf8'))?.previous ?? null
	} catch {
		// Unreadable, so there is nothing trustworthy to restore.
		return
	}

	rmSync(LANG_FILE, { force: true })

	// Nothing to put back: it was already English, or the account has no language set and
	// follows the instance default, which is not something to overwrite with a guess.
	if (previous === null || previous === 'en') {
		return
	}

	const baseURL = config.projects[0]?.use?.baseURL as string
	const browser = await chromium.launch()
	try {
		const context = await browser.newContext({
			ignoreHTTPSErrors: true,
			storageState: existsSync(AUTH_FILE) ? AUTH_FILE : undefined,
		})
		const page = await context.newPage()
		await page.goto(`${baseURL}/index.php/apps/files/`, { waitUntil: 'domcontentloaded' })
		await setLanguage(page, previous)
	} catch {
		// Best effort. A failed restore must not fail a run that otherwise passed — the
		// worst case is an account left in English, which the next run reports and fixes.
	} finally {
		await browser.close()
	}
}

export default globalTeardown
