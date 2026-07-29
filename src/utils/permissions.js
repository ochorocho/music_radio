/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Mirror of lib/Permission.php. Kept in sync by hand — the values are part of the API
 * contract, so changing one side without the other is a breaking change.
 */

export const LISTEN = 1
export const ADD_TRACKS = 2
export const CONTROL = 4
export const EDIT_PLAYLIST = 8
export const SHARE = 16
export const MANAGE = 32

export const PRESET_LISTENER = LISTEN
export const PRESET_CONTRIBUTOR = LISTEN | ADD_TRACKS

/**
 * @param {number} permissions effective mask for the current user
 * @param {number} required one or more bits, all of which must be present
 * @return {boolean}
 */
export function can(permissions, required) {
	return (permissions & required) === required
}
