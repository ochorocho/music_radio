/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The sharing interface itself: the Share button opens a modal, and a public link can be
 * given a password at the moment it is created rather than afterwards.
 *
 * Creating the link already protected matters — a link created bare and secured a second
 * later is unprotected for that second, and the URL is already generated.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'

const SHARE_TYPE_LINK = 3

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

async function openChannel(page: Page, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
}

/**
 * A title unique to this run.
 *
 * Channels are picked from the navigation by name, so a leftover channel from an earlier
 * aborted run would otherwise be selected instead of the one just created — and every
 * assertion would quietly describe the wrong playlist.
 */
function uniqueTitle(base: string): string {
	return `${base} ${Math.random().toString(36).slice(2, 8)}`
}

let channelTitle = ''

let channelId: number

test.beforeEach(async ({ page }) => {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	channelTitle = uniqueTitle('Sharing UI Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title: channelTitle })
	channelId = created.body.id
})

test.afterEach(async ({ page }) => {
	await api(page, 'DELETE', `${API}/channels/${channelId}`)
})

test('the Share button opens a modal, not a sidebar', async ({ page }) => {
	await openChannel(page, channelTitle)

	await expect(page.getByTestId('sharing-dialog')).toHaveCount(0)

	await page.getByTestId('open-sharing').click()

	// A real modal: a dialog element with the sharing panel inside it.
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 20_000 })
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible()

	// And it closes again.
	await page.keyboard.press('Escape')
	await expect(dialog).toHaveCount(0)
})

test('a public link can be created with a password in one step', async ({ page, db }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })

	// No password by default, and the toggle is offered.
	const protect = dialog.getByTestId('link-protect').locator('input[type="checkbox"]')
	await expect(protect).not.toBeChecked()

	// Turning it on demands a password before the link can be made.
	await protect.dispatchEvent('click')
	await expect(protect).toBeChecked()

	const createLink = dialog.getByTestId('create-link')
	await expect(createLink).toBeDisabled()

	await dialog.locator('input[type="password"]').first().fill('Listen-To-This-2026!')
	await expect(createLink).toBeEnabled()
	await createLink.click()

	// The link exists and was stored already hashed — never written in the clear, and
	// never present unprotected.
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	const rows = await db.query<Array<{ token: string, password: string | null }>>(
		'select token, password from oc_music_radio_shares where channel_id = ? and share_type = 3',
		[channelId],
	)
	expect(rows).toHaveLength(1)
	expect(rows[0].password).not.toBeNull()
	expect(rows[0].password).not.toContain('Listen-To-This-2026!')

	// And it is described as protected.
	await expect(dialog.getByTestId('link-protection')).toContainText(/password/i)
})

test('a link created without the option is not password protected', async ({ page, db }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })

	await dialog.getByTestId('create-link').click()
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	const rows = await db.query<Array<{ password: string | null }>>(
		'select password from oc_music_radio_shares where channel_id = ? and share_type = 3',
		[channelId],
	)
	expect(rows[0].password).toBeNull()
})

test('the password set at creation actually gates the public page', async ({ page, browser, db }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })

	await dialog.getByTestId('link-protect').locator('input[type="checkbox"]').dispatchEvent('click')
	await dialog.locator('input[type="password"]').first().fill('Listen-To-This-2026!')
	await dialog.getByTestId('create-link').click()
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	const rows = await db.query<Array<{ token: string }>>(
		'select token from oc_music_radio_shares where channel_id = ? and share_type = 3', [channelId],
	)
	const token = rows[0].token

	const anon = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	const anonPage = await anon.newPage()
	try {
		await anonPage.goto(`${APP_PATH}s/${token}`)

		// Asked for the password rather than shown the channel.
		await expect(anonPage.locator('input[type="password"]')).toBeVisible({ timeout: 20_000 })
		await expect(anonPage.getByTestId('public-channel-title')).toHaveCount(0)

		// The right one gets in.
		await anonPage.fill('input[type="password"]', 'Listen-To-This-2026!')
		await anonPage.locator('button[type="submit"]').click()
		await expect(anonPage.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })
	} finally {
		await anonPage.close()
		await anon.close()
	}
})

test('a password supplied at creation is stored and never echoed back', async ({ page }) => {
	const withPassword = await api(page, 'POST', `${API}/channels/${channelId}/shares`, {
		shareType: 3,
		password: 'Listen-To-This-2026!',
	})

	expect(withPassword.status).toBe(201)
	expect(withPassword.body.hasPassword).toBe(true)
	// Only the fact of a password crosses the wire, never the hash and never the value.
	expect(withPassword.body.password).toBeUndefined()
	expect(JSON.stringify(withPassword.body)).not.toContain('Listen-To-This-2026!')
})

/**
 * What a link is, and where it points, without opening anything — a link is one of the
 * more consequential things you can do to a channel, and that much should be readable at
 * a glance. What it *allows* is behind the chevron, like every other share row, so a
 * dialog with several of them stays readable.
 */
test('a link shows what it is and where it points without opening anything', async ({ page }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })
	await dialog.getByTestId('create-link').click()
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	// The URL is simply there, as is what the link amounts to and whether it has a password.
	await expect(dialog.getByTestId('link-url')).toBeVisible()
	await expect(dialog.getByTestId('link-protection')).toContainText(/no password/i)

	// The rest is one click away, and collapsed to begin with.
	await expect(dialog.getByTestId('link-password-field')).toHaveCount(0)
	await dialog.getByTestId('link-expand').click()
	await expect(dialog.getByTestId('link-password-field')).toBeVisible()
	await expect(dialog.getByTestId('link-save-password')).toBeVisible()
})

test('a password can be set and then removed from an existing link', async ({ page, db }) => {
	await openChannel(page, channelTitle)
	await page.getByTestId('open-sharing').click()

	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })
	await dialog.getByTestId('create-link').click()
	await expect(dialog.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })

	// The password controls live behind the chevron, with everything else the link allows.
	await dialog.getByTestId('link-expand').click()
	await expect(dialog.getByTestId('link-save-password')).toBeVisible({ timeout: 20_000 })

	// Nothing to remove while there is no password.
	await expect(dialog.getByTestId('link-clear-password')).toHaveCount(0)

	// The password component puts the test id on the input itself rather than on a
	// wrapper, so there is no descendant to reach for.
	await dialog.locator('input[type="password"]').first().fill('Listen-To-This-2026!')
	await dialog.getByTestId('link-save-password').click()

	await expect(dialog.getByTestId('link-protection')).toContainText(/password protected/i, { timeout: 20_000 })
	await expect(dialog.getByTestId('link-clear-password')).toBeVisible()

	let rows = await db.query<Array<{ password: string | null }>>(
		'select password from oc_music_radio_shares where channel_id = ? and share_type = 3', [channelId],
	)
	expect(rows[0].password).not.toBeNull()

	// And it can be taken off again.
	await dialog.getByTestId('link-clear-password').click()
	await expect(dialog.getByTestId('link-protection')).toContainText(/no password/i, { timeout: 20_000 })

	rows = await db.query<Array<{ password: string | null }>>(
		'select password from oc_music_radio_shares where channel_id = ? and share_type = 3', [channelId],
	)
	expect(rows[0].password).toBeNull()
})

test('the sharing panel reports what the server permits', async ({ page }) => {
	const shares = await api(page, 'GET', `${API}/channels/${channelId}/shares`)

	expect(shares.status).toBe(200)
	// The UI reflects these rather than guessing; server-side enforcement of each is
	// covered deterministically by ShareServiceTest.
	expect(shares.body.capabilities).toMatchObject({
		sharingEnabled: expect.any(Boolean),
		groupSharingAllowed: expect.any(Boolean),
		linksAllowed: expect.any(Boolean),
		linkPasswordEnforced: expect.any(Boolean),
	})
})

/*
 * The channel's own rules, now that they live in the share dialog.
 *
 * They were in the channel settings dialog, a menu away from the shares they govern —
 * "approve tracks other people add" only means anything once somebody else can add tracks.
 * Moving them is the whole change; the endpoint behind them is unchanged.
 */

/**
 * The option switches on one expanded share row, in the order they are rendered.
 *
 * Read in one go rather than asserted one at a time, because the order is a decision: the
 * YouTube switch belongs directly under the switch it hangs off, and a test that only
 * checked each switch was present would not notice it drifting back to the bottom.
 */
async function switchOrder(row: ReturnType<Page['getByTestId']>): Promise<string[]> {
	return await row.locator('[data-testid^="perm-"], [data-testid^="share-allow-"], [data-testid^="share-require-"], [data-testid^="share-show-"]')
		.evaluateAll((nodes) => nodes.map((n) => n.getAttribute('data-testid') ?? ''))
}

async function openSharing(page: Page, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	const link = page.getByRole('link', { name: title }).first()
	await link.scrollIntoViewIfNeeded()
	await link.click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
	await page.getByTestId('open-sharing').click()
	await expect(page.getByTestId('sharing-panel')).toBeVisible({ timeout: 20_000 })
}

/**
 * There is no channel-wide section left at all.
 *
 * Voting and YouTube importing used to sit at the bottom of this dialog as master
 * switches AND-gating the per-share ones above them, which meant an owner had to say yes
 * twice and a share whose switch was on could be silently inert. Both are questions about
 * what one audience may do, so both are answered on the row for that audience.
 */
test('every rule is answered on a share, with no channel-wide section', async ({ page }) => {
	const title = uniqueTitle('Rules Per Share')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	try {
		await openSharing(page, title)

		// Asserted after something that is definitely there, so these cannot pass merely
		// because the panel has not rendered yet.
		await expect(page.getByTestId('create-link')).toBeVisible({ timeout: 20_000 })

		await expect(page.getByTestId('sharing-settings')).toHaveCount(0)
		for (const gone of ['setting-allow-voting', 'setting-allow-import',
			'setting-require-approval', 'setting-show-listener-count']) {
			await expect(page.getByTestId(gone), `${gone} moved onto each share`).toHaveCount(0)
		}

		// And a link offers the whole list, on the row, behind the chevron.
		await page.getByTestId('create-link').click()
		await expect(page.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })
		await page.getByTestId('link-expand').click()

		// Read the rendered order once rather than asserting each switch separately. A
		// freshly created link may only listen, so the two switches that hang off adding —
		// approval and YouTube — are not offered yet; see the YouTube test below for the
		// order once it can add.
		const row = page.getByTestId('link-share-row')
		await expect(row.getByTestId('perm-add-tracks')).toBeVisible()

		expect(await switchOrder(row)).toEqual([
			'perm-add-tracks',
			'perm-control',
			'perm-edit-playlist',
			'share-allow-voting',
			'share-show-listener-count',
		])

		// Except the one a link genuinely cannot be given: widening the audience further is
		// the owner's to hold.
		await expect(row.getByTestId('perm-share')).toHaveCount(0)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

test('a rule applies as soon as it is switched, with no Save step', async ({ page, db }) => {
	const title = uniqueTitle('Rules Apply')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	try {
		await openSharing(page, title)
		await page.getByTestId('create-link').click()
		await expect(page.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })
		await page.getByTestId('link-expand').click()

		// NcCheckboxRadioSwitch renders a hidden input with no <label>, so the click has to
		// be dispatched at the input itself.
		await page.getByTestId('link-share-row').getByTestId('share-allow-voting')
			.locator('input').dispatchEvent('click')

		await expect
			.poll(async () => {
				const rows = await db.query<Array<{ allow_voting: number }>>(
					'select allow_voting from oc_music_radio_shares where channel_id = ?', [id],
				)
				return rows.length === 0 ? -1 : Number(rows[0].allow_voting)
			}, { timeout: 20_000 })
			.toBe(1)

		// And the channel follows: granting voting to anybody is what puts the playlist in
		// vote order, which is a fact about the channel rather than a second switch. See
		// ChannelService::syncVotingMode.
		await expect
			.poll(async () => {
				const rows = await db.query<Array<{ allow_voting: number }>>(
					'select allow_voting from oc_music_radio_channels where id = ?', [id],
				)
				return Number(rows[0].allow_voting)
			}, { timeout: 20_000 })
			.toBe(1)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

/**
 * Taking the last voting share away puts the author's running order back — the same thing
 * turning the old channel-wide switch off used to do, now derived rather than set.
 */
test('the channel stops counting votes when the last share that could vote does', async ({ page, db }) => {
	const title = uniqueTitle('Voting Derived')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	const votingOnChannel = async () => {
		const rows = await db.query<Array<{ allow_voting: number }>>(
			'select allow_voting from oc_music_radio_channels where id = ?', [id],
		)
		return Number(rows[0].allow_voting)
	}

	try {
		const link = (await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: SHARE_TYPE_LINK })).body
		expect(await votingOnChannel()).toBe(0)

		await api(page, 'PUT', `${API}/channels/${id}/shares/${link.id}`, { allowVoting: true })
		expect(await votingOnChannel()).toBe(1)

		await api(page, 'PUT', `${API}/channels/${id}/shares/${link.id}`, { allowVoting: false })
		expect(await votingOnChannel()).toBe(0)

		// Deleting the share is the other way to get there.
		await api(page, 'PUT', `${API}/channels/${id}/shares/${link.id}`, { allowVoting: true })
		expect(await votingOnChannel()).toBe(1)
		await api(page, 'DELETE', `${API}/channels/${id}/shares/${link.id}`)
		expect(await votingOnChannel()).toBe(0)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

/**
 * The YouTube switch is only worth offering where the server can actually import — that is
 * the administrator's decision, and it is off until they make it. Beyond that it is the
 * share's own answer, and the owner's own imports are subject to nothing else.
 */
test('the YouTube switch appears only when the server can import, and governs one share', async ({ page, db }) => {
	const title = uniqueTitle('Import Toggle')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	try {
		const link = (await api(page, 'POST', `${API}/channels/${id}/shares`, {
			shareType: SHARE_TYPE_LINK, permissions: 3,
		})).body

		await openSharing(page, title)
		await expect(page.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })
		await page.getByTestId('link-expand').click()

		const toggle = page.getByTestId('link-share-row').getByTestId('share-allow-import')
		const available = await page.getByTestId('add-youtube').count() > 0

		if (!available) {
			await expect(toggle, 'no switch when the server cannot import').toHaveCount(0)
			test.skip(true, 'importing is switched off on this server')
		}

		await expect(toggle).toBeVisible()
		await toggle.locator('input').dispatchEvent('click')

		await expect
			.poll(async () => {
				const rows = await db.query<Array<{ allow_import: number }>>(
					'select allow_import from oc_music_radio_shares where id = ?', [link.id],
				)
				return Number(rows[0].allow_import)
			}, { timeout: 20_000 })
			.toBe(1)

		// The owner imports on their own channel regardless — it spends their storage and
		// their server's time, so there is nobody left for them to be asking.
		const mine = await api(page, 'POST', `${API}/channels/${id}/imports`, {
			url: 'https://www.youtube.com/watch?v=stubOK00001',
		})
		expect(mine.status).toBe(202)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

/**
 * A page left open after the channel has gone.
 *
 * Reported as "deleting a public link says Channel not found" — which is what the server
 * genuinely answers for any share operation on a channel it cannot read, deliberately
 * using the same words as "does not exist" so a stranger cannot probe which ids are real.
 * Surfaced raw it names the wrong object and suggests nothing to do: the person pressed
 * delete on a *link*.
 *
 * Easy to reach in ordinary use — another tab, another device, or a page open a while.
 */
test('a share action on a vanished channel says so plainly and gets out of the way', async ({ page }) => {
	const title = uniqueTitle('Vanished Channel')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number
	await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: SHARE_TYPE_LINK })

	await page.goto(APP_PATH)
	const link = page.getByRole('link', { name: title }).first()
	await link.scrollIntoViewIfNeeded()
	await link.click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })

	await page.getByTestId('open-sharing').click()
	await expect(page.getByTestId('link-share-row').first()).toBeVisible({ timeout: 20_000 })

	// It goes away underneath the open dialog.
	await api(page, 'DELETE', `${API}/channels/${id}`)

	await page.getByTestId('link-remove').first().click()

	// Named for what it is, not "Channel not found" in the middle of deleting a link.
	await expect
		.poll(async () => (await page.locator('.toastify').allTextContents()).join(' '),
			{ timeout: 20_000, intervals: [500] })
		.toMatch(/no longer exists|nicht mehr/i)

	// And the dialog does not sit there offering to manage a channel that is gone.
	await expect(page.getByTestId('sharing-panel')).toHaveCount(0, { timeout: 20_000 })
})

/**
 * The point of moving approval and voting onto the share.
 *
 * As one answer on the channel this was not expressible: an owner may well trust the
 * people they named while holding whatever arrives through a link handed round a room, and
 * may want accounts voting but not anonymous visitors. Two links on one channel, given
 * opposite answers, is the shortest way to prove it.
 */
test('two links on one channel can be given different rules', async ({ page, db }) => {
	const title = uniqueTitle('Per Share Rules')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	try {
		// No channel-wide switch to turn on first: granting voting to a share is what
		// makes the channel count votes at all.
		const a = (await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: SHARE_TYPE_LINK })).body
		const b = (await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: SHARE_TYPE_LINK })).body

		const lenient = await api(page, 'PUT', `${API}/channels/${id}/shares/${a.id}`, {
			permissions: 3, requireApproval: false, allowVoting: true,
		})
		const strict = await api(page, 'PUT', `${API}/channels/${id}/shares/${b.id}`, {
			permissions: 3, requireApproval: true, allowVoting: false,
		})

		expect(lenient.body.requireApproval).toBe(false)
		expect(lenient.body.allowVoting).toBe(true)
		expect(strict.body.requireApproval).toBe(true)
		expect(strict.body.allowVoting).toBe(false)

		// Stored per share, not per channel.
		const rows = await db.query<Array<{ id: number, require_approval: number, allow_voting: number }>>(
			'select id, require_approval, allow_voting from oc_music_radio_shares where channel_id = ? order by id',
			[id],
		)
		expect(rows).toHaveLength(2)
		expect(Number(rows[0].require_approval)).toBe(0)
		expect(Number(rows[1].require_approval)).toBe(1)
		expect(Number(rows[0].allow_voting)).toBe(1)
		expect(Number(rows[1].allow_voting)).toBe(0)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

/**
 * Each link is told only what it may itself do — the other link's settings must not leak
 * into the answer.
 */
test('each link is told what it, specifically, may do', async ({ page, browser }) => {
	const title = uniqueTitle('Per Share Answers')
	const created = await api(page, 'POST', `${API}/channels/`.replace(/\/$/, ''), { title })
	const id = created.body.id as number

	try {
		// No channel-wide switch to turn on first: granting voting to a share is what
		// makes the channel count votes at all.
		const a = (await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: SHARE_TYPE_LINK })).body
		const b = (await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: SHARE_TYPE_LINK })).body
		await api(page, 'PUT', `${API}/channels/${id}/shares/${a.id}`, { allowVoting: true })
		await api(page, 'PUT', `${API}/channels/${id}/shares/${b.id}`, { allowVoting: false })

		for (const [token, expected] of [[a.token, true], [b.token, false]] as Array<[string, boolean]>) {
			const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
			const visitor = await context.newPage()
			try {
				// The share page is what issues the per-browser key a vote is keyed on.
				await visitor.goto(`${APP_PATH}s/${token}`)
				await expect(visitor.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

				const canVote = await visitor.evaluate(async (t) => {
					const r = await fetch(`/index.php/apps/music_radio/api/v1/public/${t}/tracks`)
					return (await r.json()).canVote
				}, token)

				expect(canVote, `link expecting canVote=${expected}`).toBe(expected)
			} finally {
				await visitor.close()
				await context.close()
			}
		}
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

/**
 * The last two settings to move off the channel.
 *
 * The listener count and YouTube importing are the same kind of question as approval and
 * voting — "who, of my audience, gets this" — and had no business being answered once for
 * everybody. Two links given opposite answers, and each told only its own.
 */
test('the listener count and importing are settled per link too', async ({ page, browser, db }) => {
	const title = uniqueTitle('Per Share Extras')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	try {
		const a = (await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: SHARE_TYPE_LINK })).body
		const b = (await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: SHARE_TYPE_LINK })).body

		// ADD_TRACKS as well as LISTEN — importing is an adding permission before it is
		// anything else, and the link switch cannot grant it on its own.
		await api(page, 'PUT', `${API}/channels/${id}/shares/${a.id}`, {
			permissions: 3, showListenerCount: true, allowImport: true,
		})
		await api(page, 'PUT', `${API}/channels/${id}/shares/${b.id}`, {
			permissions: 3, showListenerCount: false, allowImport: false,
		})

		const rows = await db.query<Array<{ show_listener_count: number, allow_import: number }>>(
			'select show_listener_count, allow_import from oc_music_radio_shares where channel_id = ? order by id',
			[id],
		)
		expect(rows).toHaveLength(2)
		expect(Number(rows[0].show_listener_count)).toBe(1)
		expect(Number(rows[1].show_listener_count)).toBe(0)
		expect(Number(rows[0].allow_import)).toBe(1)
		expect(Number(rows[1].allow_import)).toBe(0)

		for (const [token, expected] of [[a.token, true], [b.token, false]] as Array<[string, boolean]>) {
			const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
			const visitor = await context.newPage()
			try {
				await visitor.goto(`${APP_PATH}s/${token}`)
				await expect(visitor.getByTestId('public-channel-title')).toBeVisible({ timeout: 20_000 })

				const answers = await visitor.evaluate(async (t) => {
					const tracks = await (await fetch(`/index.php/apps/music_radio/api/v1/public/${t}/tracks`)).json()
					const state = await (await fetch(`/index.php/apps/music_radio/api/v1/public/${t}/state`)).json()
					return { canImport: tracks.canImport, listenerCount: state.listenerCount }
				}, token)

				expect(answers.canImport, `link expecting canImport=${expected}`).toBe(expected)

				// null means "not for you"; a real count means the link publishes it. Where
				// there is no distributed cache the count is null for everybody, and there is
				// nothing to tell apart — so only the withholding link is asserted absolutely.
				if (!expected) {
					expect(answers.listenerCount).toBeNull()
				}

				// The button follows the same answer, so it never promises what the server refuses.
				await expect(visitor.getByTestId('public-add-youtube')).toHaveCount(expected ? 1 : 0)
			} finally {
				await visitor.close()
				await context.close()
			}
		}
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

/*
 * There used to be a test here for a sharee holding ADD_TRACKS on a channel whose
 * import switch was off — the button offered, the endpoint refusing.
 *
 * The channel switch is gone, so the state it described cannot be reached from an owner's
 * session any more: an owner may always import on their own channel, since it spends
 * their storage and their server's time and there is nobody left for them to be asking.
 * The rule that replaced it — a sharee may import exactly when their own share says so,
 * which ImportController now reads instead of the channel — needs a second account to
 * exercise through the interface, and is covered by PermissionServiceTest and by the
 * per-link tests in youtube-import.spec.ts.
 */

test('the sharing panel offers the YouTube switch only where the server can import', async ({ page }) => {
	const title = uniqueTitle('Import Switch Offered')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	try {
		// A link that may add music: the YouTube switch hangs off adding, since importing
		// *is* adding.
		await api(page, 'POST', `${API}/channels/${id}/shares`, {
			shareType: SHARE_TYPE_LINK, permissions: 3,
		})

		await openSharing(page, title)
		await expect(page.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })
		await page.getByTestId('link-expand').click()

		const row = page.getByTestId('link-share-row')
		// Asserted after a switch that is always there, so neither branch can pass merely
		// because the row has not finished rendering.
		await expect(row.getByTestId('perm-add-tracks')).toBeVisible()

		// Read from the capabilities the panel itself reads, not from whether the channel's
		// YouTube button is showing. Those are two different questions now — the button
		// asks whether an import could start this second, the switch whether this server
		// does imports at all — and they part company the moment a remote worker stops
		// answering. See "a remote worker that is not answering" below.
		const capabilities = (await api(page, 'GET', `${API}/channels/${id}/imports`)).body.capabilities
		const configured = capabilities.configured !== false

		await expect(row.getByTestId('share-allow-import')).toHaveCount(configured ? 1 : 0)

		if (!configured) {
			test.skip(true, 'importing is switched off on this server')
		}

		// Directly under the switch it hangs off. Importing from YouTube is a way of adding
		// music, not a separate capability, and it used to sit at the very bottom of the
		// list where it read as an afterthought.
		expect(await switchOrder(row)).toEqual([
			'perm-add-tracks',
			'share-allow-import',
			'perm-control',
			'perm-edit-playlist',
			'share-require-approval',
			'share-allow-voting',
			'share-show-listener-count',
		])

		// And it goes away with the switch it hangs off, rather than sitting there promising
		// something the server would refuse.
		await row.getByTestId('perm-add-tracks').locator('input').dispatchEvent('click')
		await expect(row.getByTestId('share-allow-import')).toHaveCount(0, { timeout: 20_000 })
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

/**
 * The switch is a permission, not a prediction.
 *
 * In remote mode the fetching is done by a machine somewhere else, which is switched off,
 * rebooting, or simply between polls a good deal of the time. That used to take the
 * per-share YouTube switch away with it: `available` answered both "can an import start
 * now" and "does this server do imports", and the sharing panel read the wrong one. An
 * owner could then neither see nor change a permission that was still in force, and could
 * not prepare shares before starting a worker for the first time.
 *
 * The button that *starts* an import still goes away, because that one would be refused.
 */
test('a remote worker that is not answering hides the YouTube button but not the share switch', async ({ page }) => {
	const title = uniqueTitle('Offline Worker Switch')
	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	const settings = async (values: Record<string, unknown>) =>
		await api(page, 'POST', '/index.php/apps/music_radio/settings/admin', { values })

	try {
		await api(page, 'POST', `${API}/channels/${id}/shares`, {
			shareType: SHARE_TYPE_LINK, permissions: 3,
		})

		// Remote mode with an allow-listed account and no worker that has ever checked in:
		// configured by every decision an administrator makes, and unable to take a job.
		await settings({ import_mode: 'remote', remote_worker_users: 'admin' })

		const capabilities = (await api(page, 'GET', `${API}/channels/${id}/imports`)).body.capabilities
		expect(capabilities.available, 'no worker, so no import could start').toBe(false)
		expect(capabilities.configured, 'but the server is set up to import').toBe(true)

		await openSharing(page, title)
		await expect(page.getByTestId('link-share-row')).toBeVisible({ timeout: 20_000 })
		await page.getByTestId('link-expand').click()

		const row = page.getByTestId('link-share-row')
		// Asserted after a switch that is always there, so this cannot pass merely because
		// the row has not finished rendering.
		await expect(row.getByTestId('perm-add-tracks')).toBeVisible()
		await expect(row.getByTestId('share-allow-import')).toHaveCount(1)

		// …while the thing that would actually be refused is not offered.
		await expect(page.getByTestId('add-youtube')).toHaveCount(0)
	} finally {
		// Back to how the rest of the suite expects this instance to be. The harness runs
		// a local import worker and every other import spec assumes local mode.
		await settings({ import_mode: 'local', remote_worker_users: '' })
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})
