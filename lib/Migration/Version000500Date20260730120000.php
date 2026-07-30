<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Somewhere to keep an import while it is happening.
 *
 * Fetching a video and transcoding it takes tens of seconds, which is far too long to hold
 * a request open, so the work happens in a background job and this table is how the two
 * ends talk: the request writes a row and returns, the job updates it, and the browser
 * polls it.
 *
 * Two columns exist purely because background jobs can die without saying so.
 * `heartbeat_at` is touched while a download runs, which is the only way to tell an import
 * that is still going from one whose worker was killed by an OOM; and `attempts` records
 * that a row was picked up at all. Without them a crashed job would leave a row saying
 * "downloading" for ever, and the person waiting would never be told otherwise.
 *
 * `error_code` holds a code rather than a sentence: a job has no request, and therefore no
 * idea what language the person waiting for it reads.
 */
class Version000500Date20260730120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('music_radio_imports')) {
			return null;
		}

		$table = $schema->createTable('music_radio_imports');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('channel_id', Types::BIGINT, [
			'notnull' => true,
		]);
		// Who asked. Not necessarily whose storage the file lands in — that is the channel
		// owner — but this is who is credited on the track, who the per-user cap counts,
		// and who may cancel it.
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		// Room for another source later without a migration.
		$table->addColumn('source', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'youtube',
		]);
		// The canonical eleven-character id, never the string somebody pasted. Nothing is
		// served from this column, but storing raw input would mean storing an attacker's
		// text for no reason.
		$table->addColumn('video_id', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('status', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		// Which part of the work is happening, so the UI can say "converting" rather than
		// showing a progress bar frozen at 100% while ffmpeg runs.
		$table->addColumn('phase', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		// 0-100, and only meaningful during the download phase.
		$table->addColumn('progress', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		// Learned from the probe pass, so a queued row can be named before anything is
		// downloaded.
		$table->addColumn('title', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('duration_ms', Types::INTEGER, [
			'notnull' => false,
		]);
		$table->addColumn('track_id', Types::BIGINT, [
			'notnull' => false,
		]);
		$table->addColumn('error_code', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		$table->addColumn('attempts', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('started_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('heartbeat_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('finished_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);

		$table->setPrimaryKey(['id']);
		// Listing a channel's imports, newest first.
		$table->addIndex(['channel_id', 'id'], 'mr_imp_chan_idx');
		// The per-user cap, checked on every request.
		$table->addIndex(['user_id', 'status'], 'mr_imp_user_stat_idx');
		// The reaper's scan for rows whose worker stopped talking.
		$table->addIndex(['status', 'heartbeat_at'], 'mr_imp_stat_beat_idx');
		// Refusing the same video twice while the first attempt is still running.
		$table->addIndex(['channel_id', 'video_id'], 'mr_imp_chan_vid_idx');

		return $schema;
	}
}
