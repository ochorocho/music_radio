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
 * What a channel lets the people it is shared with do.
 *
 * Three switches, all on the channel rather than on each share. A channel can have several
 * links and several people, and rules that differ per recipient would mean explaining why
 * the same track was held for one visitor and not another. The permission mask already says
 * *who may do what*; these say *what the channel does with it*.
 *
 * Every default preserves how channels behave today, so nothing changes for an existing
 * channel until its owner asks for it.
 */
class Version000600Date20260730160000 extends SimpleMigrationStep {

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
		$changed = false;

		// Hold tracks added by anyone other than the owner until the owner approves them.
		// Off by default: turning it on retrospectively must not silence a channel that
		// contributors have already filled.
		if (!$table->hasColumn('require_approval')) {
			$table->addColumn('require_approval', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$changed = true;
		}

		// Whether anyone may see how many people are listening. On by default — it is the
		// sort of thing a radio station shows — but it is somebody's audience, so it can be
		// turned off.
		if (!$table->hasColumn('show_listener_count')) {
			$table->addColumn('show_listener_count', Types::BOOLEAN, [
				'notnull' => true,
				'default' => true,
			]);
			$changed = true;
		}

		// Voting is a deliberate hand-over of "what plays next", so it starts off.
		if (!$table->hasColumn('allow_voting')) {
			$table->addColumn('allow_voting', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
