/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The settings pages.
 *
 * Reported as "admin settings cannot be saved". The endpoint was never broken — the pages
 * were declarative settings forms, which write each field the moment it loses focus and
 * cannot be given a Save button. Typing a value and clicking away therefore either saved
 * silently or, if it was refused, complained with no relation to anything the person had
 * done. Both pages are ordinary forms now, and what these assert is the part that was
 * missing: nothing is written until Save is pressed, and the page says what happened.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const ADMIN_PATH = '/index.php/settings/admin/music_radio'
const PERSONAL_PATH = '/index.php/settings/user/music_radio'

async function appConfig(page: Page, key: string): Promise<string | null> {
	return await page.evaluate(async (key) => {
		const response = await fetch(
			`/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/music_radio/${key}?format=json`,
			{ headers: { 'OCS-APIRequest': 'true', requesttoken: (window as any).OC?.requestToken ?? '' } },
		)
		const body = await response.json()
		return body?.ocs?.data?.data ?? null
	}, key)
}

test.describe('admin settings', () => {
	test('a value is not written until Save is pressed, and then it is', async ({ page }) => {
		await page.goto(ADMIN_PATH)
		await expect(page.getByTestId('setting-max-duration')).toBeVisible({ timeout: 20_000 })

		const before = await appConfig(page, 'import_max_duration')

		const field = page.getByTestId('setting-max-duration').locator('input')
		await field.fill('42')
		// Blur, which is exactly what used to save. Nothing may be written by it.
		await page.getByTestId('setting-ytdlp-path').locator('input').click()
		await page.waitForTimeout(1000)

		expect(await appConfig(page, 'import_max_duration'),
			'blurring a field must not save it').toBe(before)

		await page.getByTestId('settings-save').click()
		await expect(page.getByTestId('settings-message')).toContainText(/saved|gespeichert/i, { timeout: 20_000 })

		// 42 minutes, stored as seconds.
		expect(await appConfig(page, 'import_max_duration')).toBe('2520')
	})

	test('Save is offered only when something has changed', async ({ page }) => {
		await page.goto(ADMIN_PATH)
		const save = page.getByTestId('settings-save')
		await expect(save).toBeVisible({ timeout: 20_000 })
		await expect(save).toBeDisabled()

		await page.getByTestId('setting-max-duration').locator('input').fill('55')
		await expect(save).toBeEnabled()
	})

	test('a bad path is explained beside its own field, and the good fields still save', async ({ page }) => {
		await page.goto(ADMIN_PATH)
		await expect(page.getByTestId('setting-ytdlp-path')).toBeVisible({ timeout: 20_000 })

		await page.getByTestId('setting-ytdlp-path').locator('input').fill('not-an-absolute-path')
		await page.getByTestId('setting-max-duration').locator('input').fill('33')
		await page.getByTestId('settings-save').click()

		// The complaint names the field it belongs to rather than being a bare toast.
		await expect(page.getByTestId('setting-ytdlp-path'))
			.toContainText(/starting with a slash|Schrägstrich/i, { timeout: 20_000 })

		// And the value that was fine went in regardless.
		expect(await appConfig(page, 'import_max_duration')).toBe('1980')
		expect(await appConfig(page, 'ytdlp_path')).not.toBe('not-an-absolute-path')
	})

	test('what was saved survives a reload', async ({ page }) => {
		await page.goto(ADMIN_PATH)
		await page.getByTestId('setting-max-duration').locator('input').fill('77')
		await page.getByTestId('settings-save').click()
		await expect(page.getByTestId('settings-message')).toContainText(/saved|gespeichert/i, { timeout: 20_000 })

		await page.reload()
		await expect(page.getByTestId('setting-max-duration').locator('input'))
			.toHaveValue('77', { timeout: 20_000 })
	})

	/**
	 * Updating yt-dlp without a terminal.
	 *
	 * YouTube breaks its extractors every few weeks, so this is routine maintenance — and
	 * the only way to do it used to be `occ music_radio:ytdlp:install --force`, which the
	 * status text on this very page had to tell people to go and run.
	 *
	 * This really does download from GitHub, which is why it is the one test here with a
	 * long timeout. It is skipped rather than failed when the network is not there: a CI
	 * box with no egress should not report a broken button.
	 */
	test('yt-dlp can be updated from the page, and the version it reports changes with it', async ({ page }) => {
		test.setTimeout(180_000)

		await page.goto(ADMIN_PATH)
		await expect(page.getByTestId('admin-ytdlp-version')).toBeVisible({ timeout: 20_000 })

		const button = page.getByTestId('admin-ytdlp-update')
		await expect(button).toBeEnabled()

		await button.click()
		await expect(page.getByTestId('settings-message')).toBeVisible({ timeout: 120_000 })

		const message = (await page.getByTestId('settings-message').textContent() ?? '').trim()
		if (/could not be installed|network|resolve/i.test(message)) {
			test.skip(true, `no network for the download: ${message}`)
		}

		// A version, not just "it worked" — the point of the button is knowing what you now
		// have. Both the message and the line above it must say so.
		expect(message).toMatch(/Installed yt-dlp \d/i)
		await expect(page.getByTestId('admin-ytdlp-version')).toContainText(/yt-dlp \d/i)

		// And it survives a reload, so what is shown is the server's answer rather than
		// something the page remembered from its own request.
		await page.reload()
		await expect(page.getByTestId('admin-ytdlp-version')).toContainText(/yt-dlp \d/i, { timeout: 20_000 })
	})

	test.afterEach(async ({ page }) => {
		await page.evaluate(async () => {
			await fetch('/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/music_radio/import_max_duration', {
				method: 'DELETE',
				headers: { 'OCS-APIRequest': 'true', requesttoken: (window as any).OC?.requestToken ?? '' },
			})
		})
	})
})

test.describe('personal settings', () => {
	/** Drive the picker and take whatever it hands back. */
	async function choose(page: Page, folder: string) {
		await page.getByTestId('pick-music-folder').click()
		const picker = page.getByRole('dialog')
		await expect(picker).toBeVisible({ timeout: 20_000 })
		await picker.getByText(folder, { exact: true }).first().click()
		await picker.getByRole('button', { name: /^(Choose|Auswählen)/ }).click()
	}

	async function shown(page: Page): Promise<string> {
		return ((await page.getByTestId('setting-music-folder').textContent()) ?? '').trim()
	}

	async function setFolder(page: Page, folder: string) {
		await page.evaluate(async (folder) => {
			await fetch('/index.php/apps/music_radio/settings/personal', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as any).OC?.requestToken ?? '',
				},
				body: JSON.stringify({ values: { download_folder: folder } }),
			})
		}, folder)
	}

	/** Whatever the folder was before this file ran, so it can be handed back. */
	let originalFolder: string | null = null

	/**
	 * Establish the starting value rather than assume it.
	 *
	 * These asserted "Music" on the grounds that it is the default — which held until
	 * somebody used the app and set their folder to something else, at which point the
	 * whole group failed on a machine where nothing was wrong. A test that depends on a
	 * user preference has to set that preference.
	 */
	test.beforeEach(async ({ page }) => {
		await page.goto(PERSONAL_PATH)
		await expect(page.getByTestId('setting-music-folder')).toBeVisible({ timeout: 20_000 })

		// Noted once, so running the suite does not quietly take somebody's own setting
		// away — on a development instance this is a real preference, not scratch space.
		if (originalFolder === null) {
			originalFolder = await shown(page)
		}

		await setFolder(page, 'Music')
		await page.reload()
		await expect(page.getByTestId('setting-music-folder')).toBeVisible({ timeout: 20_000 })
	})

	/**
	 * The folder is displayed, not edited. There is deliberately no text input — one used to
	 * exist and let people name folders that were not there.
	 */
	test('the folder is shown as a path, with no way to type one', async ({ page }) => {
		await page.goto(PERSONAL_PATH)
		await expect(page.getByTestId('setting-music-folder')).toBeVisible({ timeout: 20_000 })

		expect(await shown(page)).toBe('Music')
		await expect(page.getByTestId('setting-music-folder').locator('input')).toHaveCount(0)
		await expect(page.getByTestId('pick-music-folder')).toBeVisible()
	})

	test('choosing a folder fills the path, and Save commits it', async ({ page }) => {
		await page.goto(PERSONAL_PATH)
		await expect(page.getByTestId('pick-music-folder')).toBeVisible({ timeout: 20_000 })

		await choose(page, 'Documents')
		expect(await shown(page)).toBe('Documents')

		// Picking does not save; Save does.
		await page.reload()
		expect(await shown(page)).toBe('Music')

		await choose(page, 'Documents')
		await page.getByTestId('settings-save').click()
		await expect(page.getByTestId('settings-message')).toContainText(/saved|gespeichert/i, { timeout: 20_000 })

		await page.reload()
		expect(await shown(page)).toBe('Documents')
	})

	test('picking the folder already stored leaves nothing to save', async ({ page }) => {
		await page.goto(PERSONAL_PATH)
		await expect(page.getByTestId('pick-music-folder')).toBeVisible({ timeout: 20_000 })
		await expect(page.getByTestId('settings-save')).toBeDisabled()

		await choose(page, 'Music')

		expect(await shown(page)).toBe('Music')
		await expect(page.getByTestId('settings-save')).toBeDisabled()
	})

	test('closing the folder picker without choosing reports nothing', async ({ page }) => {
		await page.goto(PERSONAL_PATH)
		await expect(page.getByTestId('pick-music-folder')).toBeVisible({ timeout: 20_000 })

		await page.getByTestId('pick-music-folder').click()
		const picker = page.getByRole('dialog')
		await expect(picker).toBeVisible({ timeout: 20_000 })
		await page.keyboard.press('Escape')
		await expect(picker).toBeHidden({ timeout: 20_000 })

		await page.waitForTimeout(1500)
		// Snapshotted rather than asserted through a live locator, which would retry until a
		// transient message had faded and then report success either way.
		expect(await page.getByTestId('settings-message').count()).toBe(0)
		expect(await shown(page)).toBe('Music')
	})

	/**
	 * The page cannot produce a folder that is not there, but the endpoint is reachable
	 * without the page — and the rule it presents has to be the server's rule.
	 */
	test('the endpoint refuses a folder that does not exist', async ({ page }) => {
		await page.goto(PERSONAL_PATH)
		await expect(page.getByTestId('setting-music-folder')).toBeVisible({ timeout: 20_000 })

		const result = await page.evaluate(async () => {
			const response = await fetch('/index.php/apps/music_radio/settings/personal', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as any).OC?.requestToken ?? '',
				},
				body: JSON.stringify({ values: { download_folder: 'No Such Folder Here' } }),
			})
			return await response.json()
		})

		expect(Object.keys(result.errors)).toContain('download_folder')
		expect(result.state.values.download_folder).toBe('Music')
	})

	test('the endpoint still refuses a path escaping the user files', async ({ page }) => {
		await page.goto(PERSONAL_PATH)
		await expect(page.getByTestId('setting-music-folder')).toBeVisible({ timeout: 20_000 })

		const result = await page.evaluate(async () => {
			const response = await fetch('/index.php/apps/music_radio/settings/personal', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as any).OC?.requestToken ?? '',
				},
				body: JSON.stringify({ values: { download_folder: '../../etc' } }),
			})
			return await response.json()
		})

		expect(Object.keys(result.errors)).toContain('download_folder')
	})

	test.afterEach(async ({ page }) => {
		await setFolder(page, originalFolder ?? 'Music')
	})
})
