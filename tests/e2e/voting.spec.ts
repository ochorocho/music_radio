/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Listeners asking for a track to come round sooner.
 *
 * The thing worth testing here is not "a vote is recorded" — a unique index does that —
 * but the two properties that make voting safe to have at all on a channel that is one
 * continuous programme:
 *
 * - **casting a vote does not disturb the broadcast.** Reordering re-anchors the timeline
 *   and makes every listener refetch, so it happens on the server's own schedule, not on
 *   every press.
 * - **the track people are currently hearing never moves.** Nor does the one their browser
 *   has already loaded, which is why the reorder pins two rather than one.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Browser, Page } from '@playwright/test'

const APP_PATH = '/index.php/apps/music_radio/'
const API = '/index.php/apps/music_radio/api/v1'


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
 * Four tracks, because the reorder pins the current one and the one after it — with three
 * short tracks there is no free position for a voted track to move into, and nothing can
 * be observed.
 */
async function setUpChannel(page: Page, db: any, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })

	const created = await api(page, 'POST', `${API}/channels`, { title })
	const id = created.body.id as number

	const fileRows = await db.query(
		"select fileid, name from oc_filecache where name in ('tone-a.mp3','tone-b.mp3','tone-c.mp3','tone-long.mp3') and path like 'files/Music/%' and path not like 'files/Music/%/%' order by name",
	)
	await api(page, 'POST', `${API}/channels/${id}/tracks`, {
		fileIds: fileRows.map((r: { fileid: number }) => r.fileid),
	})

	// Voting is granted per share, and the channel counts votes exactly when somebody may
	// cast one — there is no channel-wide switch any more. A link is the cheapest share to
	// make, and the owner may vote as soon as the channel is counting.
	await api(page, 'POST', `${API}/channels/${id}/shares`, { shareType: 3 })
	await setVoting(page, id, true)

	return id
}

/**
 * Turn voting on or off for every share of a channel, which is how the channel itself
 * comes to be counting votes at all. See ChannelService::syncVotingMode.
 */
async function setVoting(page: Page, channelId: number, allow: boolean) {
	const shares = (await api(page, 'GET', `${API}/channels/${channelId}/shares`)).body.shares as Array<{ id: number }>
	for (const share of shares) {
		await api(page, 'PUT', `${API}/channels/${channelId}/shares/${share.id}`, { allowVoting: allow })
	}
}

async function openChannel(page: Page, title: string) {
	await page.goto(APP_PATH)
	await expect(page.getByTestId('new-channel')).toBeVisible({ timeout: 20_000 })
	await page.getByRole('link', { name: title }).first().click()
	await expect(page.getByTestId('channel-title')).toHaveText(title, { timeout: 20_000 })
}

/** Ordered track ids as the server would broadcast them. */
async function playOrder(db: any, channelId: number): Promise<number[]> {
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by vote_order asc, id asc',
		[channelId],
	)
	return rows.map((r) => Number(r.id))
}

/** The debounce is 20s; tests must not wait it out. */
async function clearDebounce(db: any, channelId: number) {
	await db.query('update oc_music_radio_channels set vote_ordered_at = 0 where id = ?', [channelId])
}

/**
 * Park the playhead at the top of the programme, as of now.
 *
 * Both fields, not just the offset: on a playing channel the position is
 * `epochOffsetMs + (now - startedAtMs)`, so setting the offset alone leaves the clock
 * still running from the old anchor. Which track is playing decides which two are pinned,
 * so a test that cares where a voted track lands has to say where the playhead is rather
 * than hope the fixtures are still short enough that it has not moved.
 */
async function startOfProgramme(db: any, channelId: number) {
	await db.query(
		'update oc_music_radio_channels set epoch_offset_ms = 0, started_at_ms = ? where id = ?',
		[Date.now(), channelId],
	)
}

async function anonymous(browser: Browser) {
	const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined })
	return { context, page: await context.newPage() }
}

test('a voted track moves up, and the one playing stays put', async ({ page, db }) => {
	const title = `Voting ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		const before = await playOrder(db, channelId)
		expect(before).toHaveLength(4)

		await openChannel(page, title)
		await clearDebounce(db, channelId)

		// The last one, which is the only position with room to improve.
		const voted = before[3]
		const result = await api(page, 'POST', `${API}/channels/${channelId}/tracks/${voted}/vote`)
		expect(result.status).toBe(200)
		expect(result.body).toEqual({ voted: true, votes: 1 })

		const after = await playOrder(db, channelId)
		// Current and next keep their places; the voted track takes the first free one.
		expect(after.slice(0, 2)).toEqual(before.slice(0, 2))
		expect(after[2]).toBe(voted)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('pressing it twice takes the vote back', async ({ page, db }) => {
	const title = `Voting Toggle ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)
		const ids = await playOrder(db, channelId)

		const on = await api(page, 'POST', `${API}/channels/${channelId}/tracks/${ids[3]}/vote`)
		expect(on.body).toEqual({ voted: true, votes: 1 })

		const off = await api(page, 'POST', `${API}/channels/${channelId}/tracks/${ids[3]}/vote`)
		expect(off.body).toEqual({ voted: false, votes: 0 })

		const rows = await db.query('select id from oc_music_radio_votes where channel_id = ?', [channelId])
		expect(rows).toHaveLength(0)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * One vote per person per track is a unique index, not a check — so a double submission
 * cannot produce two.
 */
test('one person cannot vote for the same track twice', async ({ page, db }) => {
	const title = `Voting Once ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)
		const ids = await playOrder(db, channelId)

		// Fired together, so they race in the way a double-click does.
		await Promise.all([
			api(page, 'POST', `${API}/channels/${channelId}/tracks/${ids[3]}/vote`),
			api(page, 'POST', `${API}/channels/${channelId}/tracks/${ids[3]}/vote`),
		])

		const rows = await db.query(
			'select id from oc_music_radio_votes where channel_id = ? and track_id = ?',
			[channelId, ids[3]],
		)
		expect(rows.length).toBeLessThanOrEqual(1)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * The property the whole design turns on. A reorder re-anchors the timeline and bumps both
 * version counters; if that happened on every vote, a channel with a few enthusiastic
 * listeners would never settle.
 */
test('a burst of votes produces one reorder, not one each', async ({ page, db }) => {
	const title = `Voting Debounce ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)
		const ids = await playOrder(db, channelId)
		await clearDebounce(db, channelId)

		const versionOf = async () => {
			const rows = await db.query<Array<{ playlist_version: number }>>(
				'select playlist_version from oc_music_radio_channels where id = ?', [channelId],
			)
			return Number(rows[0].playlist_version)
		}

		await api(page, 'POST', `${API}/channels/${channelId}/tracks/${ids[3]}/vote`)
		const afterFirst = await versionOf()

		// More votes, immediately. The debounce must swallow every one of them.
		for (const id of [ids[2], ids[3], ids[2]]) {
			await api(page, 'POST', `${API}/channels/${channelId}/tracks/${id}/vote`)
		}

		expect(await versionOf()).toBe(afterFirst)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('voting is refused on a channel that has it switched off', async ({ page, db }) => {
	const title = `Voting Off ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		// Taking it away from the last share that had it is what stops the channel counting.
		await setVoting(page, channelId, false)
		await openChannel(page, title)
		const ids = await playOrder(db, channelId)

		const result = await api(page, 'POST', `${API}/channels/${channelId}/tracks/${ids[3]}/vote`)
		expect(result.status).toBe(403)

		// And the control is not offered either.
		await expect(page.getByTestId('track-votes')).toHaveCount(0)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('the owner sees a vote control on every row when voting is on', async ({ page, db }) => {
	const title = `Voting UI ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)

		const controls = page.getByTestId('vote-track')
		await expect(controls).toHaveCount(4, { timeout: 20_000 })

		await clearDebounce(db, channelId)
		await controls.last().click()

		// The row reports the server's count, not a local guess.
		await expect(page.getByTestId('track-votes').last()).toContainText('1', { timeout: 20_000 })
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('a link visitor can vote only when the link grants it', async ({ page, db, browser }) => {
	const title = `Voting Link ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	const share = await api(page, 'POST', `${API}/channels/${channelId}/shares`, { shareType: 3 })
	const token = share.body.token as string
	const shareId = share.body.id as number

	const { context, page: visitor } = await anonymous(browser)

	try {
		const ids = await playOrder(db, channelId)

		// The share page is what issues the per-browser key a vote is keyed on.
		await visitor.goto(`${APP_PATH}s/${token}`)
		await expect(visitor.getByTestId('public-channel-title')).toHaveText(title, { timeout: 20_000 })

		const refused = await api(visitor, 'POST', `${API}/public/${token}/tracks/${ids[3]}/vote`)
		expect(refused.status).toBe(403)
		// The control is deliberately still rendered, just not pressable: somebody who
		// cannot vote can still see what the room has asked for.
		await expect(visitor.getByTestId('vote-track').first()).toBeDisabled()

		// Granted by the share's own switch now, not a bit on its permission mask.
		await api(page, 'PUT', `${API}/channels/${channelId}/shares/${shareId}`, { allowVoting: true })

		const allowed = await api(visitor, 'POST', `${API}/public/${token}/tracks/${ids[3]}/vote`)
		expect(allowed.status).toBe(200)
		expect(allowed.body.voted).toBe(true)

		// Credited to the browser rather than to nobody, which is what makes one vote each
		// mean anything at all here.
		const rows = await db.query<Array<{ voter_key: string }>>(
			'select voter_key from oc_music_radio_votes where channel_id = ?', [channelId],
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].voter_key).toMatch(/^\?link:[a-z0-9]{32}$/)
	} finally {
		await visitor.close()
		await context.close()
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * Deleting a channel must not leave its votes behind — nothing in this schema cascades,
 * and the sweep job only runs hourly.
 */
test('votes do not outlive the tracks they point at', async ({ page, db }) => {
	const title = `Voting Cleanup ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	await openChannel(page, title)
	const ids = await playOrder(db, channelId)
	await api(page, 'POST', `${API}/channels/${channelId}/tracks/${ids[3]}/vote`)

	expect(await db.query('select id from oc_music_radio_votes where channel_id = ?', [channelId])).toHaveLength(1)

	await api(page, 'DELETE', `${API}/channels/${channelId}`)

	const left = await db.query('select id from oc_music_radio_votes where channel_id = ?', [channelId])
	expect(left).toHaveLength(0)
})

/**
 * A vote is only useful if everybody sees it.
 *
 * Casting one deliberately moves no counter that would make listeners refetch — that is
 * what stops voting from re-anchoring the broadcast. The consequence was that a vote was
 * invisible to everyone except the person who cast it, until something unrelated happened
 * to reload the playlist. `voteVersion` is the counter that fixes it: separate from
 * `playlistVersion`, so it disturbs nothing.
 */
test('a vote cast in one browser reaches another without it doing anything', async ({ page, db, browser }) => {
	const title = `Voting Live ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	const second = await browser.newContext({
		ignoreHTTPSErrors: true,
		storageState: 'tests/e2e/.auth/admin.json',
	})
	const watcher = await second.newPage()

	try {
		const ids = await playOrder(db, channelId)

		await openChannel(watcher, title)
		await expect(watcher.getByTestId('track-votes').last()).toContainText('0', { timeout: 20_000 })

		await openChannel(page, title)
		await clearDebounce(db, channelId)
		await api(page, 'POST', `${API}/channels/${channelId}/tracks/${ids[3]}/vote`)

		// The watcher is not touched from here on: it has to notice by itself.
		await expect
			.poll(async () => (await watcher.getByTestId('track-votes').allTextContents()).join(','),
				{ timeout: 30_000, intervals: [500] })
			.toContain('1')
	} finally {
		await watcher.close()
		await second.close()
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * The reward is spent when the track reaches the front, whether or not anybody is still
 * voting.
 *
 * There is no track-boundary event to hang this on, and it used to run only when somebody
 * voted — so a track that played with no further voting kept its votes indefinitely, and
 * the next recompute treated them as current. The state poll drives it now.
 */
test('votes are spent when the track plays, with nobody voting', async ({ page, db }) => {
	const title = `Voting Spent ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)
		await clearDebounce(db, channelId)

		const ids = await playOrder(db, channelId)
		const voted = ids[3]
		await api(page, 'POST', `${API}/channels/${channelId}/tracks/${voted}/vote`)

		expect(await db.query('select id from oc_music_radio_votes where track_id = ?', [voted]))
			.toHaveLength(1)

		// Wind the programme forward to the moment that track is on air. There is no way to
		// ask for that through the API — position is a scalar the server derives — so the
		// anchor is moved directly, which is what a few seconds of real playback would do.
		const before = await db.query<Array<{ total: number }>>(
			`select coalesce(sum(duration_ms), 0) as total from oc_music_radio_tracks
			 where channel_id = ? and vote_order < (select vote_order from oc_music_radio_tracks where id = ?)`,
			[channelId, voted],
		)
		// Both fields, not just the offset: position is `epochOffsetMs + (now - startedAtMs)`
		// on a playing channel, so setting the offset alone leaves the clock still running
		// from the old anchor and the programme sails past the track under test.
		await db.query(
			'update oc_music_radio_channels set epoch_offset_ms = ?, started_at_ms = ?, vote_ordered_at = 0 where id = ?',
			[Number(before[0].total) + 2000, Date.now(), channelId],
		)

		// Reopened rather than reloaded: the app keeps the selected channel in component
		// state with no route behind it, so a reload silently lands on whichever channel
		// happens to be first — and then this would be watching the wrong one.
		await openChannel(page, title)

		// Confirm the programme really is inside the voted track — everything after this
		// depends on it, and getting the anchor wrong would look like the fix not working.
		await expect
			.poll(async () => JSON.parse((await page.getByTestId('sync-debug').textContent()) ?? '{}').trackId,
				{ timeout: 20_000, intervals: [500] })
			.toBe(voted)

		await expect
			.poll(async () => (await db.query('select id from oc_music_radio_votes where track_id = ?', [voted])).length,
				{ timeout: 30_000, intervals: [1000] })
			.toBe(0)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * Shuffle used to switch voting off in all but name.
 *
 * The recompute refused to run on a shuffled channel, and the play order came from the
 * shuffle column regardless — so a vote cast there was accepted, counted, shown on screen
 * and then silently discarded. They do not conflict: the shuffle decides the order of
 * everything nobody has asked for, which is all of it until somebody does.
 */
test('votes are honoured while the channel is shuffling', async ({ page, db }) => {
	const title = `Voting Shuffled ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true })
		await openChannel(page, title)
		await startOfProgramme(db, channelId)

		const before = await playOrder(db, channelId)
		expect(before).toHaveLength(4)

		const voted = before[3]
		const result = await api(page, 'POST', `${API}/channels/${channelId}/tracks/${voted}/vote`)
		expect(result.status).toBe(200)

		const after = await playOrder(db, channelId)
		// Current and next are pinned; the request takes the first free place.
		expect(after.slice(0, 2)).toEqual(before.slice(0, 2))
		expect(after[2]).toBe(voted)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * Equal votes are separated by which track was asked for first.
 *
 * The tie used to be settled by position in the playlist — precisely the thing a vote
 * exists to override, so the lower of two tied tracks could never get ahead however early
 * its supporters had voted.
 */
test('a tie goes to whichever track was voted for first', async ({ page, db }) => {
	const title = `Voting Tie ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)
		const before = await playOrder(db, channelId)

		// One vote each, so the counts tie and only the timestamps can separate them.
		await api(page, 'POST', `${API}/channels/${channelId}/tracks/${before[2]}/vote`)
		await api(page, 'POST', `${API}/channels/${channelId}/tracks/${before[3]}/vote`)

		// The later of the two rows in the playlist asked first. Backdated rather than
		// waited out, and by a minute, so a same-second tie cannot decide it.
		await db.query(
			'update oc_music_radio_votes set created_at = created_at - 60 where track_id = ?', [before[3]],
		)

		await startOfProgramme(db, channelId)
		await clearDebounce(db, channelId)
		await api(page, 'GET', `${API}/channels/${channelId}/state`)

		const after = await playOrder(db, channelId)
		expect(after.slice(0, 2)).toEqual(before.slice(0, 2))
		expect(after[2]).toBe(before[3])
		expect(after[3]).toBe(before[2])
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * The churn the rewrite exists to stop.
 *
 * The running order used to be derived from itself, with the playing track rotated to the
 * front — so every track boundary produced "a different order", rewrote every row, bumped
 * `playlistVersion` and sent every listener back for the track list. On a channel nobody
 * had voted on. Deriving from the arrangement instead makes an unvoted recompute a
 * comparison that finds nothing, wherever the playhead happens to be.
 */
test('a channel nobody is voting on never reorders itself as it plays', async ({ page, db }) => {
	const title = `Voting Quiet ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await openChannel(page, title)
		const before = await playOrder(db, channelId)

		const versionOf = async () => {
			const rows = await db.query<Array<{ playlist_version: number }>>(
				'select playlist_version from oc_music_radio_channels where id = ?', [channelId],
			)
			return Number(rows[0].playlist_version)
		}

		const total = (await db.query<Array<{ total: number }>>(
			'select coalesce(sum(duration_ms), 0) as total from oc_music_radio_tracks where channel_id = ?',
			[channelId],
		))[0].total

		await startOfProgramme(db, channelId)
		await clearDebounce(db, channelId)
		await api(page, 'GET', `${API}/channels/${channelId}/state`)
		const settled = await versionOf()

		// Walk the playhead across every track boundary in the programme, recomputing at
		// each one. Not a single one of them is allowed to rewrite anything.
		for (let at = 0; at < Number(total); at += Math.max(1, Math.floor(Number(total) / 8))) {
			await db.query(
				'update oc_music_radio_channels set epoch_offset_ms = ?, started_at_ms = ?, vote_ordered_at = 0 where id = ?',
				[at, Date.now(), channelId],
			)
			await api(page, 'GET', `${API}/channels/${channelId}/state`)
		}

		expect(await versionOf()).toBe(settled)
		expect(await playOrder(db, channelId)).toEqual(before)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * Turning voting on used to drop a playlist into the order its rows had been inserted in.
 *
 * `vote_order` defaulted to zero and nothing wrote it until somebody voted, so every row
 * held the same number and the ordering fell through to the `id` tiebreak — which is the
 * arrangement only until the first time anybody drags a row.
 */
test('switching voting on leaves the arrangement alone', async ({ page, db }) => {
	const title = `Voting Arrangement ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await setVoting(page, channelId, false)

		// Rearranged so that the author's order and the row ids disagree, which is the only
		// state in which the two can be told apart.
		const ids = await playOrder(db, channelId)
		const arranged = [...ids].reverse()
		await api(page, 'PUT', `${API}/channels/${channelId}/tracks/order`, { trackIds: arranged })

		await setVoting(page, channelId, true)
		await startOfProgramme(db, channelId)
		await clearDebounce(db, channelId)
		await api(page, 'GET', `${API}/channels/${channelId}/state`)

		expect(await playOrder(db, channelId)).toEqual(arranged)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * A newly added track used to be given no `vote_order` at all, so it sat at zero and went
 * straight to the front of the queue — ahead of tracks people had actually voted for.
 */
test('a track added to a voting channel joins the back of the queue', async ({ page, db }) => {
	const title = `Voting Append ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		const before = await playOrder(db, channelId)
		const removed = before[1]
		const [{ file_id: file }] = await db.query<Array<{ file_id: number }>>(
			'select file_id from oc_music_radio_tracks where id = ?', [removed],
		)
		await api(page, 'DELETE', `${API}/channels/${channelId}/tracks/${removed}`)

		await api(page, 'POST', `${API}/channels/${channelId}/tracks`, { fileIds: [Number(file)] })

		const after = await playOrder(db, channelId)
		const added = after.filter((id) => !before.includes(id))
		expect(added).toHaveLength(1)
		expect(after[after.length - 1]).toBe(added[0])
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})
