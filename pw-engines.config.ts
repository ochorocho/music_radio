/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The suite against Chromium, Firefox and WebKit.
 *
 * Run deliberately rather than on every change — three engines is three times the wall
 * clock, and almost everything this app does behaves identically on all of them:
 *
 *   docker exec -w /var/www/html/app/music_radio \
 *     ddev-nextcloud-app-dev-playwright npx playwright test --config=pw-engines.config.ts
 *
 * It exists because of one bug that only the other two engines could have found. Drift was
 * corrected by nudging `playbackRate` a few percent, which is inaudible on Chromium and
 * interrupts playback on the others — measured over forty seconds of the same track:
 * Chromium 15 rate changes and no interruptions, Firefox 11 changes and 14 interruptions of
 * 60–281 ms, WebKit a `waiting` event 15 ms after one. The suite was Chromium-only, so it
 * had nothing to say, and the report arrived from an iPhone instead.
 *
 * **WebKit is the closest thing to iOS Safari that a container can hold.** A real iPhone is
 * still the only way to be sure — Nextcloud's unsupported-browser gate rejects a spoofed
 * iOS user agent, so mobile Safari cannot be simulated — but WebKit shares the media stack
 * where these differences live, and it found this one.
 *
 * The browsers are already in the ddev-playwright image; nothing needs installing.
 */
import { defineConfig, devices } from '@playwright/test'

import base from './playwright.config.ts'

export default defineConfig({
	...base,
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
		{ name: 'firefox', use: { ...devices['Desktop Firefox'] } },
		{ name: 'webkit', use: { ...devices['Desktop Safari'] } },
	],
})
