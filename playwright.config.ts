/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Playwright E2E config, run inside the ddev-playwright container.
 *
 * The add-on bakes a single working dir from PLAYWRIGHT_TEST_DIR (.ddev/.env.playwright),
 * so `ddev playwright` targets whichever app that names. The container mounts the whole
 * harness root at /var/www/html, though, so this app's suite can be run without
 * disturbing that setting:
 *
 *   docker exec -w /var/www/html/app/music_radio \
 *     ddev-nextcloud-app-dev-playwright npx playwright test
 *
 * See tests/e2e/README.md.
 */
import { defineConfig, devices } from '@playwright/test'
import type { DbConnectorConfig } from '@ochorocho/playwright-db-connector'

// Nextcloud is pinned to overwritehost https://nextcloud-app-dev.ddev.site, so that
// URL (not http://web) is what the app generates redirects for. The ddev-router
// resolves it from inside the DDEV network; the cert is self-signed.
const BASE_URL = process.env.MUSIC_RADIO_BASE_URL || 'https://nextcloud-app-dev.ddev.site'

// The DB, reachable inside the DDEV network. DDEV's default creds are db/db/db on host `db`.
const dbConfig: DbConnectorConfig = {
	client: 'mysql2',
	connection: { host: 'db', port: 3306, user: 'db', password: 'db', database: 'db' },
	cleanupStrategy: 'none',
}

export default defineConfig<object, { dbConfig: DbConnectorConfig }>({
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	globalSetup: './tests/e2e/global-setup.ts',
	// Puts the admin account's language back; see global-setup.ts.
	globalTeardown: './tests/e2e/global-teardown.ts',
	reporter: [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: BASE_URL,
		ignoreHTTPSErrors: true,
		storageState: 'tests/e2e/.auth/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		dbConfig,
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
})
