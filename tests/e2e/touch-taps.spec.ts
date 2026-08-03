/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * One tap, one action.
 *
 * A phone reported that the first tap on a track's play button only highlighted it and the
 * second one started the song. The cause was CSS, not JavaScript: the playlist row revealed
 * a drag handle on :hover, and WebKit spends the first tap applying :hover whenever doing so
 * changes what is drawn — withholding the click until a second tap. Every button in the row
 * is inside the element being hovered, so every one of them cost two taps.
 *
 * The handle has since been removed outright, which settles that instance. What is asserted
 * here is the general rule it broke: **nothing inside a playlist row may respond to :hover on
 * a device that cannot hover.** A reveal-on-hover control in a list row is an ordinary thing
 * to reach for, and the next one would break the same buttons the same way. Gating it behind
 * `(hover: hover) and (pointer: fine)` is the fix; this test does not care which control it
 * is, only that no such rule is reachable with a coarse pointer.
 *
 * What this file cannot do is reproduce the original bug. Chromium does not implement
 * WebKit's sticky-hover tap suppression, and Nextcloud's unsupported-browser gate rejects a
 * spoofed iOS user agent both server-side and in the page, so mobile Safari cannot be
 * simulated at all (see pw-engines.config.ts). This is a guard on the CSS shape that made
 * the bug possible, not on the symptom. The symptom is checked on a real device.
 *
 * It reads the rules rather than hovering and looking. Hovering works, and an earlier
 * version of this file did exactly that — but the playlist re-renders as state arrives,
 * which replaces the row and drops :hover with it, so the assertion failed perhaps one run
 * in four for a reason that had nothing to do with the CSS. Asking which rules apply is the
 * same question without the race.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

/**
 * A touch screen, but a wide one.
 *
 * Pointer type and viewport width are independent, and the rule under test keys on the
 * pointer alone — so there is nothing to gain here from a phone-sized window, and a good
 * deal to lose. Nextcloud collapses its app navigation below 1024px, which hides the
 * channel list and leaves no channel to click; the rest of the suite works around that by
 * opening wide and resizing afterwards. Staying above the breakpoint skips the dance.
 */
const TOUCH = { viewport: { width: 1280, height: 800 }, hasTouch: true, isMobile: true }

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

function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

let channelTitle = ''
let channelId: number

test.beforeEach(async ({ page, db }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	channelTitle = uniqueTitle('Touch Taps Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title: channelTitle })
	channelId = created.body.id

	const fileRows = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
	)
	await api(page, 'POST', `${API}/channels/${channelId}/tracks`, {
		fileIds: fileRows.map((r) => r.fileid),
	})
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

async function openChannel(page: Page, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
}

/**
 * Every CSS rule that styles a playlist row, split by whether it keys on :hover and whether
 * its media condition currently applies.
 *
 * `styled` is there to keep the real assertion honest: if the app's stylesheet were missing
 * or unreadable, "no hover rule applies" would be true for the wrong reason, and this test
 * would go on passing through anything.
 *
 * :hover only. A `:focus-within` reveal repaints the row too, but focus is a consequence of
 * the click rather than something evaluated before it, so it cannot cost the tap that set it.
 */
async function rowRules(page: Page) {
	return await page.evaluate(() => {
		const styled: string[] = []
		const hover: Array<{ selector: string, condition: string, applies: boolean }> = []

		const walk = (rules: CSSRuleList, condition: string) => {
			for (const rule of Array.from(rules)) {
				const nested = rule as CSSMediaRule
				if (nested.media && nested.cssRules) {
					const inner = nested.conditionText || nested.media.mediaText
					walk(nested.cssRules, condition ? `${condition} and ${inner}` : inner)
					continue
				}

				const style = rule as CSSStyleRule
				if (!style.selectorText || !style.selectorText.includes('music-radio-track')) {
					continue
				}

				styled.push(style.selectorText)
				if (style.selectorText.includes(':hover')) {
					hover.push({
						selector: style.selectorText,
						condition: condition || '(none)',
						applies: condition ? matchMedia(condition).matches : true,
					})
				}
			}
		}

		for (const sheet of Array.from(document.styleSheets)) {
			try {
				walk(sheet.cssRules, sheet.media?.mediaText || '')
			} catch {
				// A cross-origin sheet cannot be read and holds none of this app's rules.
			}
		}
		return { styled, hover }
	})
}

test.describe('with a coarse pointer', () => {
	test.use(TOUCH)

	test('nothing in a playlist row responds to hover', async ({ page }) => {
		await openChannel(page, channelTitle)

		// Without this the emulation could be silently absent and everything below would
		// describe an ordinary desktop page while reading as a touch-device test.
		const emulated = await page.evaluate(() => ({
			hoverNone: matchMedia('(hover: none)').matches,
			pointerCoarse: matchMedia('(pointer: coarse)').matches,
		}))
		expect(emulated, 'touch emulation is actually in effect').toEqual({
			hoverNone: true,
			pointerCoarse: true,
		})

		const { styled, hover } = await rowRules(page)

		// The rows really are styled by rules this test can see.
		expect(styled.length, 'the playlist row stylesheet is readable').toBeGreaterThan(0)

		// This is the assertion the bug failed. Any hover rule reachable here would cost a
		// tap on the play button, the vote button and the actions menu alike.
		expect(hover.filter((rule) => rule.applies), 'no row rule may key on hover with a coarse pointer')
			.toEqual([])
	})

	test('a single tap on a track puts it on air', async ({ page }) => {
		await openChannel(page, channelTitle)

		const before = await api(page, 'GET', `${API}/channels/${channelId}/state`)
		expect(before.body.status).toBe('paused')

		// tap(), not click(): a click would dispatch the mouse events the real device
		// never sends, which is the whole difference this file exists for.
		await page.getByTestId('track').first().getByTestId('play-track').locator('button').tap()

		await expect
			.poll(async () => (await api(page, 'GET', `${API}/channels/${channelId}/state`)).body.status,
				{ timeout: 20_000, intervals: [500] })
			.toBe('playing')
	})
})
