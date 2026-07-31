/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tuning constants for keeping listeners together. Collected in one place because they
 * are the knobs worth turning if sync ever feels wrong on real hardware.
 */

/** Close enough — doing nothing is better than fidgeting. */
export const DEADBAND_MS = 50

/**
 * Above this, correcting by nudging the playback rate would take too long, so seek
 * instead and accept the momentary silence.
 */
export const MAX_NUDGE_MS = 3000

/**
 * Divisor in `rate = 1 + diff / SYNC_SPEED_TIME`.
 *
 * Note what this actually produces: with the ±5 % clamp below, anything past ~250 ms of
 * drift is already clamped, so in practice the correction behaves as "run 5 % fast or
 * slow until back inside the deadband". That is deliberate — it is inaudible, and it
 * recovers 200 ms in about four seconds — but it is not a proportional ramp, and this is
 * the number to raise if a gentler one is ever wanted.
 */
export const SYNC_SPEED_TIME = 5000

/** Beyond about ±5 % the pitch shift becomes audible. */
export const RATE_CLAMP = 0.05

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

/**
 * Safari freezes `currentTime` for a moment after `playbackRate` changes, so drift
 * measured just after a change is meaningless.
 */
export const RATE_CHANGE_SETTLE_MS = 400
