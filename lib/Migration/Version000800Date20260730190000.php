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
 * Listeners voting for what they want to hear sooner.
 *
 * Two things, because voting needs both a place to put the votes and a place to put their
 * effect.
 *
 * **`music_radio_votes`.** One row per person per track. The unique index on
 * `(track_id, voter_key)` *is* the one-vote-per-track rule — enforced by the database
 * rather than by a check that races itself when somebody double-clicks. `voter_key` holds
 * a user id for signed-in people and the per-browser visitor key for anonymous ones, so
 * both kinds of listener travel the same path; it is sized like `added_by` for the same
 * reason, and carries the same `?` prefix convention for anything that is not a real user.
 *
 * **`vote_order`.** A third ordering column on tracks, alongside `sort_order` (the author's
 * order) and `shuffle_order`. Votes could not simply rewrite `sort_order`: that is the
 * order the owner arranged, and turning voting off has to give it back untouched — the
 * same property that makes shuffle lossless. Reordering is a wholesale rewrite of this
 * column and nothing else.
 */
class Version000800Date20260730190000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('music_radio_votes')) {
			$table = $schema->createTable('music_radio_votes');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('channel_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('track_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			// Wide enough for a Nextcloud user id, which is also what a visitor key plus
			// its `?link:` prefix fits inside.
			$table->addColumn('voter_key', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
				'default' => 0,
			]);

			$table->setPrimaryKey(['id']);
			// The rule itself. A second vote for the same track by the same person is a
			// constraint violation, not something the application has to notice first.
			$table->addUniqueIndex(['track_id', 'voter_key'], 'mr_votes_track_voter');
			// Counting a channel's votes, and clearing them, are both by channel.
			$table->addIndex(['channel_id'], 'mr_votes_channel');
			$changed = true;
		}

		if ($schema->hasTable('music_radio_tracks')) {
			$table = $schema->getTable('music_radio_tracks');
			if (!$table->hasColumn('vote_order')) {
				$table->addColumn('vote_order', Types::INTEGER, [
					'notnull' => true,
					'default' => 0,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('music_radio_channels')) {
			$table = $schema->getTable('music_radio_channels');
			// When the running order was last rewritten. This is what debounces it: a
			// reorder re-anchors the timeline and makes every listener refetch, so a burst
			// of votes has to produce one of them rather than one each.
			if (!$table->hasColumn('vote_ordered_at')) {
				$table->addColumn('vote_ordered_at', Types::BIGINT, [
					'notnull' => true,
					'length' => 20,
					'default' => 0,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
