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
 * Let a track be skipped without removing it.
 *
 * Distinct from `unavailable`, which the server sets when a file can no longer be read.
 * This one is a deliberate choice by whoever runs the channel — "not right now" — and
 * has to survive independently, so that a track re-appearing on disk does not silently
 * un-disable it, and disabling something does not look like a broken file.
 */
class Version000400Date20260729190000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('music_radio_tracks')) {
			return null;
		}

		$table = $schema->getTable('music_radio_tracks');
		if ($table->hasColumn('disabled')) {
			return null;
		}

		$table->addColumn('disabled', Types::BOOLEAN, [
			'notnull' => true,
			'default' => false,
		]);

		return $schema;
	}
}
