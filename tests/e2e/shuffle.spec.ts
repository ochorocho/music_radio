/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shuffle, end to end.
 *
 * A shuffle here is materialised rather than applied per listener — everyone hears one
 * broadcast, so the randomness is drawn once, server-side, and written into a column. That
 * makes *when* it is redrawn a real question, and the answer used to be "never": a looping
 * channel played the identical sequence every cycle until somebody toggled the switch by
 * hand, which from the second time round is indistinguishable from not shuffling at all.
 *
 * The properties worth testing here are the ones a unit test cannot reach, because they
 * involve the ordering columns, the timeline anchor and the state poll all agreeing:
 * that a completed cycle actually redraws, that the redraw does not interrupt anybody,
 * and that switching shuffle off puts the arrangement back.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { Page } from '@playwright/test'

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

	return id
}

/** The order the server would broadcast in: the running order, whatever produced it. */
async function playOrder(db: any, channelId: number): Promise<number[]> {
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by vote_order asc, id asc',
		[channelId],
	)
	return rows.map((r) => Number(r.id))
}

async function shuffleOrder(db: any, channelId: number): Promise<number[]> {
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by shuffle_order asc, id asc',
		[channelId],
	)
	return rows.map((r) => Number(r.id))
}

async function sortOrder(db: any, channelId: number): Promise<number[]> {
	const rows = await db.query<Array<{ id: number }>>(
		'select id from oc_music_radio_tracks where channel_id = ? order by sort_order asc, id asc',
		[channelId],
	)
	return rows.map((r) => Number(r.id))
}

async function totalMs(db: any, channelId: number): Promise<number> {
	const rows = await db.query<Array<{ total: number }>>(
		'select coalesce(sum(duration_ms), 0) as total from oc_music_radio_tracks where channel_id = ?',
		[channelId],
	)
	return Number(rows[0].total)
}

/**
 * Put the channel on air at a given position, as of now.
 *
 * All three fields. A channel is created paused, and a paused one never comes round at all
 * — its position is frozen — so a test about cycles that forgot to start it would assert
 * nothing while appearing to pass. Both anchor fields for the same reason: on a playing
 * channel the position is `epochOffsetMs + (now - startedAtMs)`, so setting the offset
 * alone leaves the clock still running from the old anchor.
 */
async function anchorAt(db: any, channelId: number, positionMs: number) {
	await db.query(
		'update oc_music_radio_channels set paused = 0, epoch_offset_ms = ?, started_at_ms = ? where id = ?',
		[positionMs, Date.now(), channelId],
	)
}

async function playlistVersion(db: any, channelId: number): Promise<number> {
	const rows = await db.query<Array<{ playlist_version: number }>>(
		'select playlist_version from oc_music_radio_channels where id = ?', [channelId],
	)
	return Number(rows[0].playlist_version)
}

test('a looping channel draws a new order each time it comes round', async ({ page, db }) => {
	const title = `Shuffle Cycle ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true, loop: true })
		const total = await totalMs(db, channelId)

		const seedOf = async () => {
			const rows = await db.query<Array<{ shuffle_seed: number }>>(
				'select shuffle_seed from oc_music_radio_channels where id = ?', [channelId],
			)
			return Number(rows[0].shuffle_seed)
		}
		const first = await seedOf()

		// Wind the programme past the end of its own length: that, and only that, is what
		// "this channel has been round" means — the position keeps counting while the
		// broadcast wraps it. There is no way to ask for it through the API, so the anchor
		// is moved directly, which is what waiting out a cycle would do.
		await anchorAt(db, channelId, total + 500)

		// The redraw rides on the state poll. Nothing else drives it — a channel is one
		// continuous programme, so there is no end-of-cycle event for it to hang on.
		await api(page, 'GET', `${API}/channels/${channelId}/state`)

		expect(await seedOf()).not.toBe(first)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

test('it does not redraw part way through a cycle', async ({ page, db }) => {
	const title = `Shuffle Midway ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true, loop: true })
		const total = await totalMs(db, channelId)

		await anchorAt(db, channelId, Math.floor(total / 2))
		const before = await shuffleOrder(db, channelId)
		const version = await playlistVersion(db, channelId)

		// Several polls, because the failure this guards against is a redraw on every one.
		for (let i = 0; i < 4; i++) {
			await api(page, 'GET', `${API}/channels/${channelId}/state`)
		}

		expect(await shuffleOrder(db, channelId)).toEqual(before)
		expect(await playlistVersion(db, channelId)).toBe(version)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * The redraw happens under a playhead that is already moving, so it has to leave the
 * listener exactly where they are: the track playing at the moment of the wrap keeps
 * playing, at the same point in it, and heads the new cycle.
 */
test('coming round does not interrupt what is playing', async ({ page, db }) => {
	const title = `Shuffle Seam ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true, loop: true })
		const total = await totalMs(db, channelId)

		// 1.2s into the first track of the second cycle.
		await anchorAt(db, channelId, total + 1200)
		const headed = (await playOrder(db, channelId))[0]

		const state = await api(page, 'GET', `${API}/channels/${channelId}/state`)
		expect(state.status).toBe(200)

		const after = await playOrder(db, channelId)
		expect(after[0]).toBe(headed)

		const now = await api(page, 'GET', `${API}/channels/${channelId}/state`)
		expect(now.body.current.trackId).toBe(headed)
		// Still inside that track rather than thrown back to zero or forward to the next.
		expect(now.body.current.offsetMs).toBeGreaterThan(1000)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * Having come round and redrawn, the channel sits at the top of a fresh cycle — so the
 * very next poll must not decide it has come round again.
 */
test('it settles after redrawing rather than redrawing every poll', async ({ page, db }) => {
	const title = `Shuffle Settle ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true, loop: true })
		const total = await totalMs(db, channelId)

		await anchorAt(db, channelId, total + 500)
		await api(page, 'GET', `${API}/channels/${channelId}/state`)

		const version = await playlistVersion(db, channelId)
		for (let i = 0; i < 4; i++) {
			await api(page, 'GET', `${API}/channels/${channelId}/state`)
		}

		expect(await playlistVersion(db, channelId)).toBe(version)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * A channel that has run out does not come round, so there is no next cycle to draw for —
 * and redrawing on every poll of a finished channel would be an endless rewrite.
 */
test('a channel that is not looping is left alone at the end', async ({ page, db }) => {
	const title = `Shuffle NoLoop ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true, loop: false })
		const total = await totalMs(db, channelId)

		await anchorAt(db, channelId, total + 5_000)
		const before = await shuffleOrder(db, channelId)

		await api(page, 'GET', `${API}/channels/${channelId}/state`)

		expect(await shuffleOrder(db, channelId)).toEqual(before)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * Switching shuffle off used to clear the flag and nothing else. `sort_order` survives a
 * shuffle untouched, so the arrangement came back on its own — but on a channel that takes
 * votes it is the running order that is broadcast, and that still held the shuffle.
 */
test('switching shuffle off puts the arrangement back', async ({ page, db }) => {
	const title = `Shuffle Off ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		// A share that counts votes, so the running order is the column being played.
		await api(page, 'POST', `${API}/channels/${channelId}/shares`, { shareType: 3 })
		const shares = (await api(page, 'GET', `${API}/channels/${channelId}/shares`)).body.shares as Array<{ id: number }>
		for (const share of shares) {
			await api(page, 'PUT', `${API}/channels/${channelId}/shares/${share.id}`, { allowVoting: true })
		}

		const arranged = await sortOrder(db, channelId)

		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true })
		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: false })

		expect(await playOrder(db, channelId)).toEqual(arranged)
		// And the arrangement itself was never written over on the way through.
		expect(await sortOrder(db, channelId)).toEqual(arranged)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * The same band twice running reads as the shuffle being broken, so a drawn order is
 * relaxed until neighbours differ — see Ordering::spreadArtists, which is pinned down
 * exactly by the unit tests. What this checks is that the relaxation is actually reached
 * from the running app, over enough draws that a fair coin would have failed by now: two
 * of four tracks share an artist, so an unrelaxed draw puts them together about half the
 * time, and eight draws in a row not doing so is not luck.
 */
test('a draw does not put the same band twice in a row', async ({ page, db }) => {
	const title = `Shuffle Artists ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		const ids = await sortOrder(db, channelId)
		const artists: Record<number, string> = {
			[ids[0]]: 'The National',
			[ids[1]]: 'The National',
			[ids[2]]: 'Interpol',
			[ids[3]]: 'Slipknot',
		}
		for (const [id, artist] of Object.entries(artists)) {
			await db.query('update oc_music_radio_tracks set artist = ? where id = ?', [artist, Number(id)])
		}

		// Paused, so no track is pinned at the head and the whole list is free to move.
		await api(page, 'POST', `${API}/channels/${channelId}/control`, { action: 'pause' })

		for (let draw = 0; draw < 8; draw++) {
			await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true })
			const drawn = (await shuffleOrder(db, channelId)).map((id) => artists[id])

			for (let i = 1; i < drawn.length; i++) {
				expect(drawn[i], `draw ${draw} put ${drawn[i]} next to itself`).not.toBe(drawn[i - 1])
			}

			await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: false })
		}
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})

/**
 * A track added while shuffle is on belongs at the end of the cycle, and used to be given
 * a `shuffle_order` derived from the highest `sort_order` — a different column, which
 * drifts. The value could already be in use, in which case the new track appeared beside
 * an unrelated one in the middle of the running order.
 */
test('a track added while shuffling lands at the end of the cycle', async ({ page, db }) => {
	const title = `Shuffle Append ${Math.random().toString(36).slice(2, 8)}`
	const channelId = await setUpChannel(page, db, title)

	try {
		await api(page, 'PUT', `${API}/channels/${channelId}/playback-settings`, { shuffle: true })

		// Take one out so there is a file left to add back — the fixtures are the only
		// audio to hand, and a channel refuses the same file twice.
		const arranged = await sortOrder(db, channelId)
		const removed = arranged[1]
		const [{ file_id: file }] = await db.query<Array<{ file_id: number }>>(
			'select file_id from oc_music_radio_tracks where id = ?', [removed],
		)
		await api(page, 'DELETE', `${API}/channels/${channelId}/tracks/${removed}`)

		// Pull the arrangement's numbers below the draw's. That drift is the whole bug: the
		// two columns are renumbered by different operations — a drag rewrites one, a draw
		// the other — and an append that read the wrong column's maximum handed the new
		// track a position already in use.
		const remaining = await sortOrder(db, channelId)
		for (const [index, id] of remaining.entries()) {
			await db.query('update oc_music_radio_tracks set sort_order = ? where id = ?', [(index + 1) * 100, id])
		}

		const drawnBefore = await shuffleOrder(db, channelId)
		await api(page, 'POST', `${API}/channels/${channelId}/tracks`, { fileIds: [Number(file)] })

		const after = await db.query<Array<{ id: number, shuffle_order: number, vote_order: number }>>(
			'select id, shuffle_order, vote_order from oc_music_radio_tracks where channel_id = ? order by shuffle_order asc, id asc',
			[channelId],
		)
		const added = after.map((row) => Number(row.id)).filter((id) => !drawnBefore.includes(id))
		expect(added, 'the track was not added back').toHaveLength(1)

		// Last in the cycle, not dropped into the middle of it.
		expect(Number(after[after.length - 1].id)).toBe(added[0])

		// And no two rows may claim the same position in either order.
		const shufflePositions = after.map((row) => Number(row.shuffle_order))
		const votePositions = after.map((row) => Number(row.vote_order))
		expect(new Set(shufflePositions).size).toBe(shufflePositions.length)
		expect(new Set(votePositions).size).toBe(votePositions.length)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${channelId}`)
	}
})
