/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Scaffold smoke test: the app is enabled, its route resolves, and the Vue app mounts.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'

// Pretty URLs are off on this instance, so every path carries /index.php.
const APP_PATH = '/index.php/apps/music_radio/'

test('the app page loads and the Vue app mounts', async ({ page }) => {
	const errors: string[] = []
	page.on('pageerror', (e) => errors.push(e.message))

	// Vue catches render errors and reports them through console.error rather than
	// letting them reach `pageerror`, so a broken component prop looks like a perfectly
	// healthy page unless the console is watched too. Noise from core is filtered out —
	// only failures originating in our own code should fail this test.
	page.on('console', (message) => {
		if (message.type() !== 'error') {
			return
		}
		const text = message.text()
		if (/TypeError|ReferenceError|Vue warn|Failed to resolve component/i.test(text)) {
			errors.push(text)
		}
	})

	await page.goto(APP_PATH)

	// NcContent renders #content-vue with the app name as a class.
	await expect(page.locator('#content-vue.app-music_radio')).toBeVisible({ timeout: 20_000 })

	// The "New channel" button is rendered by our own Vue tree, so seeing it means the
	// bundle loaded and mounted. Asserted by test id rather than by label — this
	// instance runs in German, so any English string would be the wrong thing to look
	// for.
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	expect(errors, `uncaught page errors: ${errors.join(' | ')}`).toHaveLength(0)
})

test('the app is registered and enabled in the database', async ({ db }) => {
	const rows = await db.query<Array<{ configvalue: string }>>(
		"select configvalue from oc_appconfig where appid = 'music_radio' and configkey = 'enabled'",
	)
	expect(rows[0]?.configvalue).toBe('yes')
})

test('the navigation entry is reachable', async ({ page }) => {
	await page.goto('/index.php/apps/files/')
	await expect(page.locator('a[href*="/apps/music_radio"]').first()).toBeAttached({ timeout: 20_000 })
})
