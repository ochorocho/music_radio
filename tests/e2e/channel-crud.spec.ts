/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Channel lifecycle and playlist management, driven through the API the UI uses, with
 * the results checked against the database.
 */
import { test, expect } from '@ochorocho/playwright-db-connector'
import type { APIRequestContext, Page } from '@playwright/test'

const API = '/index.php/apps/music_radio/api/v1'

/**
 * Nextcloud rejects state-changing requests without a CSRF token. Rather than scrape it
 * out of the page, drive the API from inside the authenticated page context, where the
 * app's own axios instance already carries the token.
 */
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
			return {
				status: response.status,
				body: text ? JSON.parse(text) : null,
			}
		},
		{ method, path, body },
	)
}

test.beforeEach(async ({ page }) => {
	await page.goto('/index.php/apps/music_radio/')
	await expect(page.locator('#content-vue.app-music_radio')).toBeVisible({ timeout: 20_000 })
})

test('a channel can be created, renamed and deleted', async ({ page, db }) => {
	const created = await api(page, 'POST', `${API}/channels`, {
		title: 'E2E Test Channel',
		description: 'created by the test suite',
	})
	expect(created.status).toBe(201)
	const id = created.body.id as number

	// It starts paused and at the top of the programme — nothing broadcasts until the
	// owner presses play.
	const rows = await db.query<Array<{ title: string, paused: number, epoch_offset_ms: number, user_id: string }>>(
		'select title, paused, epoch_offset_ms, user_id from oc_music_radio_channels where id = ?',
		[id],
	)
	expect(rows[0].title).toBe('E2E Test Channel')
	expect(rows[0].user_id).toBe('admin')
	expect(Number(rows[0].paused)).toBe(1)
	expect(Number(rows[0].epoch_offset_ms)).toBe(0)

	const renamed = await api(page, 'PUT', `${API}/channels/${id}`, { title: 'Renamed Channel' })
	expect(renamed.status).toBe(200)
	expect(renamed.body.title).toBe('Renamed Channel')

	const deleted = await api(page, 'DELETE', `${API}/channels/${id}`)
	expect(deleted.status).toBe(204)

	const after = await db.query<Array<unknown>>(
		'select id from oc_music_radio_channels where id = ?', [id],
	)
	expect(after).toHaveLength(0)
})

test('a channel with an empty title is rejected', async ({ page }) => {
	const result = await api(page, 'POST', `${API}/channels`, { title: '   ' })
	expect(result.status).toBe(400)
})

test('another user\'s channel is not visible', async ({ page, db }) => {
	// A channel owned by somebody else must 404 rather than 403 — the API should not
	// confirm which channel ids exist.
	await db.query(
		`insert into oc_music_radio_channels
		 (user_id, title, started_at_ms, epoch_offset_ms, paused, loop_enabled, shuffle,
		  shuffle_seed, state_version, playlist_version, created_at, updated_at)
		 values ('someone-else', 'Private Channel', 0, 0, 1, 1, 0, 0, 1, 1, 0, 0)`,
	)
	const rows = await db.query<Array<{ id: number }>>(
		"select id from oc_music_radio_channels where user_id = 'someone-else' order by id desc limit 1",
	)
	const foreignId = rows[0].id

	try {
		const result = await api(page, 'GET', `${API}/channels/${foreignId}`)
		expect(result.status).toBe(404)
	} finally {
		await db.query('delete from oc_music_radio_channels where id = ?', [foreignId])
	}
})

test('tracks are added with durations read from the files themselves', async ({ page, db }) => {
	const created = await api(page, 'POST', `${API}/channels`, { title: 'Playlist Test' })
	const id = created.body.id as number

	try {
		// The seeded fixtures are sine tones of exactly 3s, 5s and 8s.
		const fileRows = await db.query<Array<{ fileid: number, name: string }>>(
			`select fc.fileid, fc.name from oc_filecache fc
			 where fc.name in ('tone-a.mp3', 'tone-b.mp3', 'tone-c.mp3') and fc.path like 'files/Music/%'
			 order by fc.name`,
		)
		expect(fileRows.length).toBe(3)
		const fileIds = fileRows.map((r) => r.fileid)

		// No duration hints: this asserts the SERVER-side probe works on its own.
		const added = await api(page, 'POST', `${API}/channels/${id}/tracks`, { fileIds })
		expect(added.status).toBe(201)
		expect(added.body.tracks).toHaveLength(3)

		const tracks = await db.query<Array<{ duration_ms: number, duration_source: number, title: string, artist: string, sort_order: number }>>(
			'select duration_ms, duration_source, title, artist, sort_order from oc_music_radio_tracks where channel_id = ? order by sort_order',
			[id],
		)
		expect(tracks).toHaveLength(3)

		// Durations must come from the file headers (source 1), not a client guess,
		// and be within a frame or two of the true length.
		const expected = [3000, 5000, 8000]
		tracks.forEach((track, i) => {
			expect(Number(track.duration_source)).toBe(1)
			expect(Math.abs(Number(track.duration_ms) - expected[i])).toBeLessThan(100)
		})

		// ID3 tags are read too.
		expect(tracks[0].artist).toBe('Music Radio Fixtures')

		// Sort order is gapped so appending never has to renumber.
		expect(tracks.map((t) => Number(t.sort_order))).toEqual([1000, 2000, 3000])
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

test('adding the same file twice is skipped rather than duplicated', async ({ page, db }) => {
	const created = await api(page, 'POST', `${API}/channels`, { title: 'Dedupe Test' })
	const id = created.body.id as number

	try {
		const fileRows = await db.query<Array<{ fileid: number }>>(
			"select fileid from oc_filecache where name = 'tone-a.mp3' and path like 'files/Music/%' limit 1",
		)
		const fileId = fileRows[0].fileid

		await api(page, 'POST', `${API}/channels/${id}/tracks`, { fileIds: [fileId] })
		const second = await api(page, 'POST', `${API}/channels/${id}/tracks`, { fileIds: [fileId] })

		expect(second.body.tracks).toHaveLength(0)
		expect(Object.keys(second.body.skipped)).toContain(String(fileId))

		const count = await db.query<Array<{ n: number }>>(
			'select count(*) as n from oc_music_radio_tracks where channel_id = ?', [id],
		)
		expect(Number(count[0].n)).toBe(1)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

test('a non-audio file is refused', async ({ page, db }) => {
	const created = await api(page, 'POST', `${API}/channels`, { title: 'Mime Test' })
	const id = created.body.id as number

	try {
		const fileRows = await db.query<Array<{ fileid: number }>>(
			"select fileid from oc_filecache where name = 'Welcome.md' limit 1",
		)
		test.skip(fileRows.length === 0, 'no seeded non-audio file to test with')

		const result = await api(page, 'POST', `${API}/channels/${id}/tracks`, {
			fileIds: [fileRows[0].fileid],
		})

		expect(result.body.tracks).toHaveLength(0)
		expect(Object.keys(result.body.skipped)).toHaveLength(1)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

test('reordering rewrites sort order, and a stale order is rejected', async ({ page, db }) => {
	const created = await api(page, 'POST', `${API}/channels`, { title: 'Reorder Test' })
	const id = created.body.id as number

	try {
		const fileRows = await db.query<Array<{ fileid: number }>>(
			"select fileid from oc_filecache where name in ('tone-a.mp3', 'tone-b.mp3', 'tone-c.mp3') and path like 'files/Music/%' order by name",
		)
		const added = await api(page, 'POST', `${API}/channels/${id}/tracks`, {
			fileIds: fileRows.map((r) => r.fileid),
		})
		const trackIds = added.body.tracks.map((t: { id: number }) => t.id)

		const reversed = [...trackIds].reverse()
		const result = await api(page, 'PUT', `${API}/channels/${id}/tracks/order`, { trackIds: reversed })
		expect(result.status).toBe(200)
		expect(result.body.tracks.map((t: { id: number }) => t.id)).toEqual(reversed)

		// A partial list is not a permutation of the playlist. This is the guard against
		// silently dropping a track someone else appended mid-drag.
		const stale = await api(page, 'PUT', `${API}/channels/${id}/tracks/order`, {
			trackIds: reversed.slice(0, 2),
		})
		expect(stale.status).toBe(400)
	} finally {
		await api(page, 'DELETE', `${API}/channels/${id}`)
	}
})

test('deleting a channel removes its tracks too', async ({ page, db }) => {
	const created = await api(page, 'POST', `${API}/channels`, { title: 'Cascade Test' })
	const id = created.body.id as number

	const fileRows = await db.query<Array<{ fileid: number }>>(
		"select fileid from oc_filecache where name = 'tone-a.mp3' and path like 'files/Music/%' limit 1",
	)
	await api(page, 'POST', `${API}/channels/${id}/tracks`, { fileIds: [fileRows[0].fileid] })

	await api(page, 'DELETE', `${API}/channels/${id}`)

	const orphans = await db.query<Array<unknown>>(
		'select id from oc_music_radio_tracks where channel_id = ?', [id],
	)
	expect(orphans).toHaveLength(0)
})
