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
 * Initial schema: channels, their ordered tracks, and the app's own share ACL.
 */
class Version000100Date20260729120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$schemaChanged = false;

		if (!$schema->hasTable('music_radio_channels')) {
			$table = $schema->createTable('music_radio_channels');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('cover_file_id', Types::BIGINT, [
				'notnull' => false,
			]);
			// The broadcast anchor. `epoch_offset_ms` is the programme position (the
			// offset into the concatenated playlist) that was current at wall-clock
			// `started_at_ms`. Everything else — which track is playing and how far
			// into it — is DERIVED from these two plus the track durations, so there
			// is exactly one source of truth and nothing can drift out of sync.
			$table->addColumn('started_at_ms', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('epoch_offset_ms', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('paused', Types::BOOLEAN, [
				'notnull' => true,
				'default' => true,
			]);
			// Not `loop` — that is a reserved word in several SQL dialects.
			$table->addColumn('loop_enabled', Types::BOOLEAN, [
				'notnull' => true,
				'default' => true,
			]);
			$table->addColumn('shuffle', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$table->addColumn('shuffle_seed', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			// Bumped on any timeline change; doubles as an optimistic-concurrency guard
			// for control actions.
			$table->addColumn('state_version', Types::BIGINT, [
				'notnull' => true,
				'default' => 1,
			]);
			// Bumped only when the track set / order / durations change, so listeners
			// refetch the (larger) track list only when it actually changed.
			$table->addColumn('playlist_version', Types::BIGINT, [
				'notnull' => true,
				'default' => 1,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'mr_chan_user_idx');
			$schemaChanged = true;
		}

		if (!$schema->hasTable('music_radio_tracks')) {
			$table = $schema->createTable('music_radio_tracks');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('channel_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('file_id', Types::BIGINT, [
				'notnull' => true,
			]);
			// Also the storage owner: the file is resolved out of THIS user's folder at
			// stream time. A contributor adds from their own Files, so adding a track
			// deliberately exposes that one file to everyone who can listen.
			$table->addColumn('added_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('sort_order', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('shuffle_order', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('title', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('artist', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('album', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			// NULL means "not probed yet". Such a track is EXCLUDED from the timeline —
			// a NULL in the prefix sums would corrupt every position after it.
			$table->addColumn('duration_ms', Types::INTEGER, [
				'notnull' => false,
			]);
			$table->addColumn('duration_source', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('mimetype', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('size', Types::BIGINT, [
				'notnull' => false,
			]);
			// File deleted or no longer readable -> excluded from the timeline, kept in
			// the list so the owner can see why the playlist got shorter.
			$table->addColumn('unavailable', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['channel_id', 'sort_order'], 'mr_trk_chan_sort_idx');
			$table->addIndex(['channel_id', 'shuffle_order'], 'mr_trk_chan_shuf_idx');
			$table->addIndex(['channel_id', 'file_id'], 'mr_trk_chan_file_idx');
			$schemaChanged = true;
		}

		if (!$schema->hasTable('music_radio_shares')) {
			$table = $schema->createTable('music_radio_shares');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('channel_id', Types::BIGINT, [
				'notnull' => true,
			]);
			// Reuses the VALUES of IShare::TYPE_* (0 user, 1 group, 3 link, 7 team) so the
			// sharee picker's `source` field maps straight across. It is deliberately NOT
			// a core share — see the plan: IShare is typed to Node and cannot hold a channel.
			$table->addColumn('share_type', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('receiver', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('token', Types::STRING, [
				'notnull' => false,
				'length' => 32,
			]);
			$table->addColumn('password', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('permissions', Types::INTEGER, [
				'notnull' => true,
				'default' => 1,
			]);
			$table->addColumn('expiration', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('label', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
			$table->addColumn('created_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['channel_id'], 'mr_shr_chan_idx');
			$table->addIndex(['share_type', 'receiver'], 'mr_shr_recv_idx');
			$table->addUniqueIndex(['token'], 'mr_shr_token_uidx');
			$schemaChanged = true;
		}

		return $schemaChanged ? $schema : null;
	}
}
