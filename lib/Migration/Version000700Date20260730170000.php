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
 * Whether the channel's owner has let this track play.
 *
 * Deliberately not folded into `disabled`, which the two look alike enough to tempt.
 * `disabled` means "the person running the channel listened to this and chose to skip it";
 * this means "nobody has looked at it yet". Keeping them apart is what lets the owner tell
 * a queue of new arrivals from the things they have already decided about — and stops
 * approving something from silently un-skipping it.
 *
 * Default true, so every track that already exists stays exactly as playable as it was.
 * Only tracks added *after* an owner switches `require_approval` on are ever held.
 */
class Version000700Date20260730170000 extends SimpleMigrationStep {

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
		if ($table->hasColumn('approved')) {
			return null;
		}

		$table->addColumn('approved', Types::BOOLEAN, [
			'notnull' => true,
			'default' => true,
		]);

		return $schema;
	}
}
