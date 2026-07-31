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
 * A counter that moves whenever a vote is cast or withdrawn.
 *
 * Deliberately not `playlistVersion`. That one means "the programme changed" — every
 * listener refetches, the poll drops to three seconds for a minute, and whoever is
 * driving has their in-flight action invalidated. Casting a vote does none of that on
 * purpose, which is exactly why nobody else's page ever noticed one: the counts sat
 * stale until something unrelated happened to refetch the playlist.
 *
 * So votes get a counter of their own. Clients watch it and reload the playlist, which is
 * where the counts live; the timeline is untouched, and the broadcast does not so much as
 * flinch.
 */
class Version001000Date20260730234500 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('music_radio_channels')) {
			return null;
		}

		$table = $schema->getTable('music_radio_channels');
		if ($table->hasColumn('vote_version')) {
			return null;
		}

		$table->addColumn('vote_version', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'default' => 0,
		]);

		return $schema;
	}
}
