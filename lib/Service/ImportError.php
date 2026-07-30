<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Service;

use OCP\IL10N;

/**
 * Every way an import can fail, and what to say about it.
 *
 * Codes are stored rather than sentences. A failure happens inside a background job, which
 * has no request and therefore no idea what language the person waiting for it reads; the
 * translation has to happen later, when someone asks. Storing a code also means the
 * wording can be improved without a migration.
 *
 * The sentences aim to tell someone what to do next, which for several of these is
 * "nothing, that video cannot be imported" — said plainly rather than dressed up as a
 * temporary error. The two that name an administrator do so because the person reading it
 * genuinely cannot fix it themselves.
 *
 * Raw stderr is never among them: it can contain absolute paths, and it is written for
 * someone debugging yt-dlp, not for someone who pasted a link.
 */
final class ImportError {

	// Refused before anything ran.
	public const NOT_A_YOUTUBE_URL = 'not_a_youtube_url';
	public const DISABLED = 'disabled';
	public const TOO_MANY_IMPORTS = 'too_many_imports';
	public const DUPLICATE_IN_FLIGHT = 'duplicate_in_flight';

	// The server cannot do this at all.
	public const YTDLP_MISSING = 'ytdlp_missing';
	public const FFMPEG_MISSING = 'ffmpeg_missing';
	public const PROCESS_DISABLED = 'process_disabled';

	// The video itself.
	public const VIDEO_UNAVAILABLE = 'video_unavailable';
	public const VIDEO_PRIVATE = 'video_private';
	public const AGE_RESTRICTED = 'age_restricted';
	public const MEMBERS_ONLY = 'members_only';
	public const GEO_BLOCKED = 'geo_blocked';
	public const LIVE_STREAM = 'live_stream';
	public const TOO_LONG = 'too_long';
	public const TOO_LARGE = 'too_large';

	// The download.
	public const BOT_CHECK = 'bot_check';
	public const DOWNLOADER_OUTDATED = 'downloader_outdated';
	public const NETWORK = 'network';
	public const TIMED_OUT = 'timed_out';
	public const NO_AUDIO = 'no_audio';

	// After the download, on the way into someone's files.
	public const QUOTA_EXCEEDED = 'quota_exceeded';
	public const CHANNEL_FULL = 'channel_full';

	// Never finished.
	public const CANCELLED = 'cancelled';
	public const STALLED = 'stalled';
	public const NEVER_STARTED = 'never_started';

	public const UNKNOWN = 'unknown';

	/**
	 * @param int $maxDurationSeconds named in the message, because "too long" without a
	 *                                number tells someone nothing about what would work
	 */
	public static function describe(string $code, IL10N $l, int $maxDurationSeconds = 0): string {
		return match ($code) {
			self::NOT_A_YOUTUBE_URL => $l->t('That does not look like a YouTube video link.'),
			self::DISABLED => $l->t('YouTube import is switched off on this server.'),
			self::TOO_MANY_IMPORTS => $l->t('You already have imports running. Wait for those to finish first.'),
			self::DUPLICATE_IN_FLIGHT => $l->t('That video is already being imported to this channel.'),

			self::YTDLP_MISSING => $l->t('YouTube import is not set up on this server. An administrator needs to install yt-dlp.'),
			self::FFMPEG_MISSING => $l->t('YouTube import needs ffmpeg, which is not installed on this server.'),
			self::PROCESS_DISABLED => $l->t('This server does not allow running external programs, so YouTube import cannot work here.'),

			self::VIDEO_UNAVAILABLE => $l->t('That video is not available.'),
			self::VIDEO_PRIVATE => $l->t('That video is private.'),
			self::AGE_RESTRICTED => $l->t('That video is age-restricted, so it cannot be imported.'),
			self::MEMBERS_ONLY => $l->t('That video is only for members of its channel.'),
			self::GEO_BLOCKED => $l->t('That video is not available in this server\'s country.'),
			self::LIVE_STREAM => $l->t('Live streams cannot be imported.'),
			self::TOO_LONG => $maxDurationSeconds > 0
				? $l->n(
					'That video is longer than the %n minute this server allows.',
					'That video is longer than the %n minutes this server allows.',
					(int)round($maxDurationSeconds / 60),
				)
				: $l->t('That video is too long to import.'),
			self::TOO_LARGE => $l->t('That video is too large to import.'),

			// Named as the server's problem, not the listener's, because it is.
			self::BOT_CHECK => $l->t('YouTube asked this server to prove it is not a bot, so the video could not be fetched.'),
			self::DOWNLOADER_OUTDATED => $l->t('YouTube changed something the downloader on this server cannot handle yet. Ask an administrator to update yt-dlp.'),
			self::NETWORK => $l->t('This server could not reach YouTube.'),
			self::TIMED_OUT => $l->t('That download took too long and was stopped.'),
			self::NO_AUDIO => $l->t('No audio could be taken from that video.'),

			self::QUOTA_EXCEEDED => $l->t('There is not enough space left on this channel.'),
			self::CHANNEL_FULL => $l->t('This channel has reached its track limit.'),

			self::CANCELLED => $l->t('That import was cancelled.'),
			self::STALLED => $l->t('That import stopped unexpectedly. Try again.'),
			self::NEVER_STARTED => $l->t('That import never started. Background jobs may not be running on this server.'),

			default => $l->t('The import failed.'),
		};
	}
}
