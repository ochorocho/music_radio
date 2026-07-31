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
 * Whether this channel accepts tracks fetched from YouTube.
 *
 * The administrator's switch decides whether the server will do it at all; this decides
 * whether a particular channel wants it. Both have to say yes, and they are answering
 * different questions — "is this allowed here" is not the same as "is this wanted on my
 * channel", and an owner sharing a channel with contributors may reasonably want the
 * playlist to stay to things people already have.
 *
 * Default true, so nothing changes for a channel that exists today: importing remains
 * governed entirely by the server-wide switch, which is off until an administrator turns
 * it on.
 */
class Version000900Date20260730220000 extends SimpleMigrationStep {

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
		if ($table->hasColumn('allow_import')) {
			return null;
		}

		$table->addColumn('allow_import', Types::BOOLEAN, [
			'notnull' => true,
			'default' => true,
		]);

		return $schema;
	}
}
