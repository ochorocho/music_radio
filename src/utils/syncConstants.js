/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tuning constants for keeping listeners together. Collected in one place because they
 * are the knobs worth turning if sync ever feels wrong on real hardware.
 */

/**
 * How far out of step this client has to be before anything is done about it.
 *
 * Deliberately large, and deliberately the *only* correction there is while a track plays.
 *
 * There used to be a second, gentler one: nudge `playbackRate` a few percent and let the
 * element catch up. It is inaudible on Chromium — and it is not on the others. Measured
 * over forty seconds of real playback, with the same channel and the same track:
 *
 *     chromium   15 rate changes    0 interruptions
 *     firefox    11 rate changes   14 interruptions, 60–281 ms
 *     webkit      3 rate changes    `waiting` fired 15 ms after one of them
 *
 * So it was traded away. Nothing touches the element between track boundaries now, which
 * costs perhaps half a second of alignment between listeners and buys playback that does
 * not break up on Safari or Firefox. The places a correction still happens — tuning in, a
 * track boundary, a resume — all load or seek anyway, so a gap there is already expected.
 *
 * A second is comfortably past what anyone notices on a channel they are listening to
 * alone, and comfortably short of the gap that would make two people in one room hear an
 * echo.
 */
export const RESEEK_MS = 1000

/** How often the local position is recomputed and compared against the audio element. */
export const TICK_MS = 250

/** Clock samples kept; the one with the lowest round-trip wins. */
export const CLOCK_SAMPLES = 8

/** Probes fired back-to-back when tuning in, to fill the window quickly. */
export const CLOCK_BURST = 4
export const CLOCK_BURST_SPACING_MS = 200

/**
 * Probes fired when only watching a channel rather than listening to it. Fewer, because
 * nothing is being kept in step — but never one, because the clock does not report
 * itself ready below two samples and the status line would sit on "Syncing…" forever.
 */
export const WATCH_CLOCK_BURST = 2

/** Steady-state re-probe interval. */
export const CLOCK_REFRESH_MS = 45_000

/** Start loading the next track this long before the current one ends. */
export const PRELOAD_LEAD_MS = 15_000

/**
 * How long to wait for a stalled element to recover before forcing the issue.
 *
 * A stall usually clears on its own once the network catches up, and doing nothing is the
 * correct response to almost all of them. This is the backstop for the ones that never
 * come back — long enough that it is not competing with ordinary buffering.
 */
export const STALL_RECOVERY_MS = 10_000
