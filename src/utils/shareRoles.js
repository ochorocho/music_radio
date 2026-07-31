/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The one-word summary of what a share amounts to, for the collapsed row.
 *
 * Shared between the two kinds of row rather than written twice. It used to live only on
 * the named-person row, because a public link could not be given anything a summary would
 * have had to distinguish — it was "can listen", possibly "and upload", and that was the
 * whole vocabulary. A link can now carry control and curation, so it needs the same words
 * a colleague's share gets, and they had better be the same words.
 */

import { ADD_TRACKS, CONTROL, EDIT_PLAYLIST, can } from './permissions.js'

/**
 * "Contributor" is the interesting one — it is the combination the app exists for: can put
 * music on, cannot decide what plays.
 *
 * @param {number} permissions the share's mask
 * @return {string}
 */
export function roleLabel(permissions) {
	if (can(permissions, CONTROL)) {
		return t('music_radio', 'Co-host')
	}
	if (can(permissions, EDIT_PLAYLIST)) {
		return t('music_radio', 'Curator')
	}
	if (can(permissions, ADD_TRACKS)) {
		return t('music_radio', 'Contributor')
	}
	return t('music_radio', 'Listener')
}
