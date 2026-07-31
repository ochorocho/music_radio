<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio;

/**
 * The app's own permission bitmask.
 *
 * Deliberately not `OCP\Constants::PERMISSION_*`: those carry file semantics (CREATE,
 * UPDATE, DELETE on a node) that do not map onto a broadcast. Every app that shares a
 * custom entity defines its own set for the same reason.
 *
 * The split that matters for this app is ADD_TRACKS vs CONTROL — that is what lets
 * someone contribute music to a channel without being able to decide what is playing.
 *
 * Mirrored in src/utils/permissions.js.
 */
final class Permission {

	/** Tune in and hear the broadcast. */
	public const LISTEN = 1;
	/** Append tracks, and remove tracks you added yourself. */
	public const ADD_TRACKS = 2;
	/** Play, pause, skip, seek — i.e. be the DJ. */
	public const CONTROL = 4;
	/** Reorder the playlist and remove anyone's track. */
	public const EDIT_PLAYLIST = 8;
	/** Manage this channel's shares. */
	public const SHARE = 16;
	/** Rename, re-describe, set the cover. */
	public const MANAGE = 32;
	/**
	 * Retired. Voting is a switch on each share (`music_radio_shares.allow_voting`), not a
	 * bit on its permission mask — see Version001100. The value is kept reserved so a mask
	 * stored while it was in use cannot come to mean something else.
	 */
	public const RETIRED_VOTE = 64;

	public const NONE = 0;
	public const ALL = self::LISTEN | self::ADD_TRACKS | self::CONTROL
		| self::EDIT_PLAYLIST | self::SHARE | self::MANAGE;

	/** The preset behind the "Contributor" option in the sharing UI. */
	public const PRESET_CONTRIBUTOR = self::LISTEN | self::ADD_TRACKS;
	/** The default for a new share. */
	public const PRESET_LISTENER = self::LISTEN;

	/**
	 * Everything a public link may carry.
	 *
	 * A link can be handed the same say over the broadcast as a named person: listening,
	 * uploading, being the DJ, curating the running order. Which of those it actually gets
	 * is the owner's choice per link — a link passed round one room is a different thing
	 * from a link posted in public, and the dialog is where that judgement is made.
	 *
	 * SHARE and MANAGE are the two that stay off the table, and for a different reason
	 * than the rest: they are not about the broadcast but about the channel's existence.
	 * Anyone at all can follow a link, so granting SHARE would let a stranger widen the
	 * audience past whatever the owner decided, and MANAGE would let them rename or
	 * re-cover somebody else's channel. Neither is a thing an owner could sensibly mean
	 * to delegate to "whoever holds this URL".
	 *
	 * ADD_TRACKS on a link means specifically "may upload a file", since someone with no
	 * account has no stored files to pick from.
	 *
	 * Voting is not here, and is not a permission at all any more: it is a switch on the
	 * share itself, which is where approval lives too. Both are questions about what an
	 * audience may do rather than about what a mask grants, and keeping them together is
	 * what stops the two from disagreeing.
	 */
	public const LINK_ALLOWED = self::LISTEN | self::ADD_TRACKS
		| self::CONTROL | self::EDIT_PLAYLIST;

	private function __construct() {
	}

	public static function has(int $permissions, int $required): bool {
		return ($permissions & $required) === $required;
	}

	/**
	 * Fold in the implications, so a stored mask can never describe an incoherent state:
	 * every capability implies being able to hear the channel, and each escalation
	 * implies the lesser one it builds on.
	 */
	public static function normalize(int $permissions): int {
		$permissions &= self::ALL;

		if ($permissions === self::NONE) {
			return self::NONE;
		}
		if (self::has($permissions, self::MANAGE)) {
			$permissions |= self::EDIT_PLAYLIST | self::CONTROL;
		}
		if (self::has($permissions, self::EDIT_PLAYLIST)) {
			$permissions |= self::ADD_TRACKS;
		}

		// Anything at all implies LISTEN: "may add tracks but may not hear them" is not
		// a state a user could have meant to configure.
		return $permissions | self::LISTEN;
	}

	/**
	 * @return array<string, bool> the mask exploded for the frontend
	 */
	public static function describe(int $permissions): array {
		return [
			'listen' => self::has($permissions, self::LISTEN),
			'addTracks' => self::has($permissions, self::ADD_TRACKS),
			'control' => self::has($permissions, self::CONTROL),
			'editPlaylist' => self::has($permissions, self::EDIT_PLAYLIST),
			'share' => self::has($permissions, self::SHARE),
			'manage' => self::has($permissions, self::MANAGE),
		];
	}
}
