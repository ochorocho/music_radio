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
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
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

/**
 * Contrast on the public page cannot be left to axe.
 *
 * Core's guest background is an image, and axe declines to compute contrast against one —
 * it reports the check as *incomplete* rather than as a violation, so the scan above stays
 * green no matter how unreadable the text is. The playlist really was unreadable for a
 * while: near-black titles on saturated blue at about 2.7:1, and grey artist names at
 * about 1.1:1.
 *
 * What actually prevents it is the content having a background of its own, so this asserts
 * that directly.
 */
test('the playlist sits on an opaque surface rather than the guest background', async ({ browser }) => {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${openToken}`)
		await expect(anon.getByTestId('playlist').locator('li').first())
			.toBeVisible({ timeout: 20_000 })

		const opaque = await anon.evaluate(() => {
			// Walk up from a row to whatever first paints a background behind it.
			let el: Element | null = document.querySelector('[data-testid="playlist"] li')
			while (el && el !== document.documentElement) {
				const bg = getComputedStyle(el).backgroundColor
				const alpha = bg.startsWith('rgba') ? Number(bg.split(',')[3]) : 1
				if (bg !== 'transparent' && alpha > 0) {
					return { el: el.className || el.tagName, bg }
				}
				el = el.parentElement
			}
			return null
		})

		expect(opaque, 'nothing behind the playlist rows paints a background').not.toBeNull()
		expect(opaque!.bg).not.toContain('rgba(0, 0, 0, 0)')
		// The body's blue is the background this is meant to be sitting on top of, not the
		// one the rows should be reading against.
		expect(opaque!.bg, `rows are painting straight onto ${opaque!.bg}`).not.toBe('rgb(0, 103, 158)')
	} finally {
		await anon.close()
		await context.close()
	}
})

/**
 * The header has to stay readable in the dark theme too.
 *
 * This is a trap the light theme hides completely. The title used
 * `--color-primary-element-text`, which is #fff in the light theme and #000 in the dark
 * one — because there the primary *element* is painted light, so its text must be dark.
 * The guest background is not a primary element and stays the same saturated blue in both,
 * so the title rendered black on blue in dark mode while looking perfect in light mode.
 *
 * Asserted as measured contrast rather than as a colour, so it stays true if the theme's
 * palette moves. Axe cannot do this: core's guest background is an image, and it reports
 * contrast against one as *incomplete* rather than failing.
 */
for (const scheme of ['light', 'dark'] as const) {
	test(`the public header is readable in the ${scheme} theme`, async ({ browser }) => {
		const context = await browser.newContext({
			ignoreHTTPSErrors: true,
			storageState: undefined,
			colorScheme: scheme,
		})
		const anon = await context.newPage()

		try {
			await anon.goto(`${APP_PATH}s/${openToken}`)
			await expect(anon.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

			const ratio = await anon.evaluate(() => {
				const parse = (value: string): [number, number, number] => {
					const [r, g, b] = value.match(/[\d.]+/g)!.map(Number)
					return [r, g, b]
				}
				// WCAG relative luminance.
				const luminance = ([r, g, b]: [number, number, number]): number => {
					const channel = (c: number) => {
						const v = c / 255
						return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
					}
					return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
				}

				const title = document.querySelector('[data-testid="public-channel-title"]') as HTMLElement

				// The first ancestor that actually paints something is what the title is read
				// against — the title itself is transparent.
				let el: HTMLElement | null = title
				let background = 'rgb(255, 255, 255)'
				while (el) {
					const bg = getComputedStyle(el).backgroundColor
					if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
						background = bg
						break
					}
					el = el.parentElement
				}

				const a = luminance(parse(getComputedStyle(title).color))
				const b = luminance(parse(background))

				return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
			})

			// 4.5:1 is WCAG AA for body text; this is large text, where 3:1 is the bar. The
			// stricter one is used because the fix comfortably clears it and a regression
			// here is silent.
			expect(ratio, `title contrast in the ${scheme} theme`).toBeGreaterThan(4.5)
		} finally {
			await anon.close()
			await context.close()
		}
	})
}

/**
 * The scrollbar belongs at the edge of the window.
 *
 * The app scrolls itself because the public shell is a fixed-height box that never does.
 * When the element that scrolls is also the centred column, its scrollbar appears partway
 * across a wide window, which reads as a bug.
 */
test('the page scrolls at the edge of the viewport, not down the middle', async ({ browser }) => {
	const context = await browser.newContext({
		ignoreHTTPSErrors: true,
		storageState: undefined,
		viewport: { width: 1200, height: 700 },
	})
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${openToken}`)
		await expect(anon.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

		const box = await anon.evaluate(() => {
			const r = document.querySelector('.music-radio-public')!.getBoundingClientRect()
			return { left: Math.round(r.left), right: Math.round(r.right), width: window.innerWidth }
		})

		// Within the body's own margin of each edge, rather than inset by half the window.
		expect(box.left).toBeLessThan(32)
		expect(box.width - box.right).toBeLessThan(32)
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

/*
 * The owner's own view.
 *
 * Everything above audits the sharing experience — the dialog, the public page, the
 * password form. The view the channel's owner spends all their time in was covered only
 * incidentally, through the sharing dialog that opens on top of it, so the playlist, the
 * on-air card and the channel dialog had never been audited at all.
 *
 * Scoped to this app's own markup throughout. Auditing the whole page would report core's
 * header and navigation, which this app cannot fix and which would bury anything real.
 */

/** The app's content area, excluding core's chrome. */
const APP_CONTENT = '.app-content'

async function openOwnerChannel(page: Page) {
	await page.goto(APP_PATH)
	await page.getByRole('link', { name: channelTitle }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(channelTitle, { timeout: 20_000 })
	// The on-air card grows when the broadcast state lands; auditing before that measures
	// a layout that is still moving.
	await expect(page.getByTestId('on-air')).toBeVisible({ timeout: 20_000 })
	await expect(page.getByTestId('track').first()).toBeVisible({ timeout: 20_000 })
}

test('the owner channel view has no accessibility violations', async ({ page }) => {
	await openOwnerChannel(page)
	await page.waitForTimeout(500)

	const results = await new AxeBuilder({ page }).include(APP_CONTENT).withTags(WCAG).analyze()

	expect(describe(results), describe(results)).toBe('')
})

/**
 * The on-air card renders two quite different things depending on whether this person is
 * listening — a tall "Tune in" column, or the transport controls, mute and status line.
 * Only the first was ever on screen during an audit.
 */
test('the on-air card has no accessibility violations while listening', async ({ page }) => {
	await openOwnerChannel(page)

	await page.getByTestId('tune-in').click()
	await expect(page.getByTestId('tune-out')).toBeVisible({ timeout: 30_000 })
	await page.waitForTimeout(500)

	const results = await new AxeBuilder({ page }).include('[data-testid="on-air"]').withTags(WCAG).analyze()

	expect(describe(results), describe(results)).toBe('')
})

test('the playlist rows have no accessibility violations, including their actions menu', async ({ page }) => {
	await openOwnerChannel(page)

	// The row actions are a popper rendered outside the list, so it has to be open to be
	// audited at all.
	await page.getByTestId('track').first().getByRole('button').last().click()
	await expect(page.locator('.v-popper__popper--shown')).toBeVisible({ timeout: 20_000 })
	await page.waitForTimeout(500)

	const results = await new AxeBuilder({ page })
		.include(APP_CONTENT)
		.include('.v-popper__popper--shown')
		.withTags(WCAG)
		.analyze()

	expect(describe(results), describe(results)).toBe('')
})

test('the channel settings dialog has no accessibility violations', async ({ page }) => {
	await openOwnerChannel(page)

	// Behind the header's overflow menu; there is no direct control for it. Matched on the
	// German label too — this instance runs translated.
	//
	// Selected as "the menu", not as "the last button in the header". It was the latter,
	// which held only while the menu happened to be last: enabling YouTube import adds a
	// button to that header, and from then on the click landed on Share and waited twenty
	// seconds for a popover that a dialog was never going to produce. The row menus
	// elsewhere in the suite already address this toggle by its class.
	await page.getByTestId('channel-title').locator('.action-item__menutoggle').click()
	await expect(page.locator('.v-popper__popper--shown')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('menuitem', { name: /channel settings|Kanaleinstellungen/i })
		.or(page.getByRole('button', { name: /channel settings|Kanaleinstellungen/i }))
		.first()
		.click()

	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 20_000 })
	await page.waitForTimeout(1000)

	const results = await new AxeBuilder({ page }).include('[role="dialog"]').withTags(WCAG).analyze()

	expect(describe(results), describe(results)).toBe('')
})

/**
 * Every control the owner needs must be operable without a pointer. Asserted through the
 * accessibility tree rather than by driving Tab, which would be describing Nextcloud's
 * focus order rather than testing this app.
 */
test('every control in the owner view has an accessible name', async ({ page }) => {
	await openOwnerChannel(page)

	const unnamed = await page.locator(`${APP_CONTENT} button, ${APP_CONTENT} a[href]`).evaluateAll(
		(elements) => elements
			.filter((el) => (el as HTMLElement).offsetParent !== null)
			.filter((el) => {
				const label = (el.getAttribute('aria-label')
					|| el.getAttribute('title')
					|| el.textContent
					|| '').trim()
				return label === ''
			})
			.map((el) => el.outerHTML.slice(0, 120)),
	)

	expect(unnamed, `unnamed controls:\n${unnamed.join('\n')}`).toEqual([])
})

/**
 * The primary actions must be reachable by keyboard and must respond to it. Focus is set
 * directly and the control activated with Enter — driving Tab from the top of the document
 * would walk through core's header and test Nextcloud rather than this app.
 */
test('the primary owner actions are operable from the keyboard', async ({ page }) => {
	await openOwnerChannel(page)

	for (const id of ['add-tracks', 'open-sharing', 'tune-in']) {
		const control = page.getByTestId(id)
		await expect(control, `${id} should be present`).toBeVisible()
		await control.focus()
		await expect
			.poll(async () => await control.evaluate((el) => el === document.activeElement || el.contains(document.activeElement)))
			.toBe(true)
	}

	// And activating one by keyboard actually does something.
	await page.getByTestId('open-sharing').focus()
	await page.keyboard.press('Enter')
	await expect(page.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })
	await page.keyboard.press('Escape')
})

/**
 * The share view with voting on. The vote control is an icon and a bare number, which is
 * precisely the shape that ends up announced as an unexplained digit — so it carries its
 * own text, and this is what keeps it that way.
 */
test('the public page has no accessibility violations with voting enabled', async ({ page, browser }) => {
	// Voting is the link's own switch, and granting it to anybody is what makes the channel
	// count votes at all — there is no channel-wide switch above it any more.
	await api(page, 'PUT', `${API}/channels/${channelId}/shares/${uploadShareId}`, {
		permissions: 1 | 2, allowVoting: true,
	})

	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${uploadToken}`)
		await expect(anon.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
		await expect(anon.getByTestId('vote-track').first()).toBeVisible({ timeout: 20_000 })
		await anon.waitForTimeout(500)

		const results = await new AxeBuilder({ page: anon }).withTags(WCAG).analyze()
		expect(describe(results), describe(results)).toBe('')

		// The number beside the heart is hidden from the accessibility tree, so the button
		// itself has to carry the whole sentence.
		const label = await anon.getByTestId('vote-track').first()
			.evaluate((el) => el.getAttribute('aria-label') || el.getAttribute('title') || '')
		expect(label).toMatch(/vote|stimme/i)
	} finally {
		await anon.close()
		await context.close()
	}
})

/*
 * The dialogs and pages the audits above still had not reached.
 *
 * Each of these is either a separate route (the settings pages, which are their own Vue
 * bundles and were never covered at all) or a dialog that has to be opened before it
 * exists in the DOM — so none of them were incidentally included by auditing the channel
 * view.
 */

test('the new-channel dialog has no accessibility violations', async ({ page }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	await page.getByTestId('new-channel').click()
	await expect(page.getByRole('dialog')).toBeVisible({ timeout: 20_000 })
	await page.waitForTimeout(1000)

	const results = await new AxeBuilder({ page }).include('[role="dialog"]').withTags(WCAG).analyze()

	expect(describe(results), describe(results)).toBe('')
})

test('the YouTube import dialog has no accessibility violations', async ({ page }) => {
	await openOwnerChannel(page)

	// The button is only rendered when an administrator has switched importing on, which is
	// the state this instance is left in — but do not assume it.
	const open = page.getByTestId('add-youtube')
	if (await open.count() === 0) {
		test.skip(true, 'importing is switched off on this instance')
	}

	await open.click()
	await expect(page.getByTestId('youtube-import-dialog')).toBeVisible({ timeout: 20_000 })
	await page.waitForTimeout(1000)

	const results = await new AxeBuilder({ page }).include('[role="dialog"]').withTags(WCAG).analyze()

	expect(describe(results), describe(results)).toBe('')
})

test('the admin settings page has no accessibility violations', async ({ page }) => {
	await page.goto('/index.php/settings/admin/music_radio')
	await expect(page.getByTestId('setting-max-duration')).toBeVisible({ timeout: 20_000 })
	await page.waitForTimeout(500)

	const results = await new AxeBuilder({ page })
		.include('#music_radio-admin-settings')
		.withTags(WCAG)
		.analyze()

	expect(describe(results), describe(results)).toBe('')
})

test('the personal settings page has no accessibility violations', async ({ page }) => {
	await page.goto('/index.php/settings/user/music_radio')
	await expect(page.getByTestId('setting-music-folder')).toBeVisible({ timeout: 20_000 })
	await page.waitForTimeout(500)

	const results = await new AxeBuilder({ page })
		.include('#music_radio-personal-settings')
		.withTags(WCAG)
		.analyze()

	expect(describe(results), describe(results)).toBe('')
})

/**
 * A refused save has to reach somebody who cannot see the field turn red, which is the
 * whole reason the message is a live region rather than just text near the input.
 *
 * Asserted on the admin page: the personal one has no text input any more — its folder is
 * chosen, not typed — so there is no longer a way to enter something invalid there.
 */
test('a refused setting is announced, not just coloured', async ({ page }) => {
	await page.goto('/index.php/settings/admin/music_radio')
	await expect(page.getByTestId('setting-ytdlp-path')).toBeVisible({ timeout: 20_000 })

	await page.getByTestId('setting-ytdlp-path').locator('input').fill('not-an-absolute-path')
	await page.getByTestId('settings-save').click()

	const alert = page.getByTestId('settings-message')
	await expect(alert).toBeVisible({ timeout: 20_000 })
	await expect(alert).toHaveAttribute('role', 'alert')

	// And the field itself says what is wrong, rather than only being outlined.
	await expect(page.getByTestId('setting-ytdlp-path')).toContainText(/starting with a slash|Schrägstrich/i)
})

/**
 * A track held for approval is marked with a text pill, and a visitor's own upload gets a
 * remove button whose only content is an icon — both are exactly the shapes that end up
 * announced as nothing at all.
 */
test('a held track and its remove control are announced on the public page', async ({ page, browser }) => {
	await api(page, 'PUT', `${API}/channels/${channelId}`, { requireApproval: true })

	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anon = await context.newPage()

	try {
		await anon.goto(`${APP_PATH}s/${uploadToken}`)
		await expect(anon.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

		await anon.getByTestId('public-upload-open').click()
		await anon.getByTestId('public-upload-input').setInputFiles('tests/fixtures/tone-c.mp3')
		await anon.getByTestId('public-upload-submit').click()
		// A successful upload closes the dialog and reports itself in a toast, so there is
		// nothing left to dismiss before reading the playlist underneath.
		await expect(anon.getByTestId('public-upload-dialog')).toHaveCount(0, { timeout: 30_000 })

		// The held row specifically, not merely the first row that carries a status —
		// this channel has older tracks, and "Estimated length" is a status too.
		const held = anon.locator('.music-radio-track--held')
		await expect(held.getByTestId('track-status')).toContainText(/approval|Freigabe/i, { timeout: 20_000 })

		// Removing lives among the row's actions, as it does on the signed-in playlist —
		// the public page renders the same rows now. Remove is the only one this link
		// carries, and NcActions renders a lone action as a plain button rather than
		// building a menu for it, so there is nothing to open here.
		await expect(held.getByTestId('remove-track')).toBeVisible({ timeout: 20_000 })

		const results = await new AxeBuilder({ page: anon }).withTags(WCAG).analyze()
		expect(describe(results), describe(results)).toBe('')

		// The remove control carries an icon; its name has to come from somewhere.
		const label = await held.getByTestId('remove-track')
			.evaluate((el) => el.getAttribute('aria-label') || el.getAttribute('title') || (el.textContent ?? '').trim())
		expect(label).not.toBe('')
	} finally {
		await anon.close()
		await context.close()
	}
})
