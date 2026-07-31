<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Approval and voting, decided per share rather than per channel.
 *
 * The two rules that actually differ by audience: an owner may well trust the people they
 * shared with by name while holding anything that arrives through a link handed round a
 * room, and may want accounts voting but not anonymous visitors. As one setting on the
 * channel that was not expressible — every audience got the same answer.
 *
 * The channel columns stay. They are the default a newly created share inherits, and for
 * `allow_voting` the channel-wide switch that turns the feature on at all; the owner has no
 * share row of their own, so something has to speak for them.
 *
 * The per-share values replace `Permission::VOTE`, which was a bit on the share's permission
 * mask. Two ways of saying the same thing invites them to disagree.
 */
class Version001100Date20260731020000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('music_radio_shares')) {
			return null;
		}

		$table = $schema->getTable('music_radio_shares');
		$changed = false;

		// Default true: holding what arrives is the safe answer for a share whose settings
		// nobody has looked at yet. The step below then replaces it with whatever the
		// channel already said, so no existing channel changes behaviour on upgrade.
		if (!$table->hasColumn('require_approval')) {
			$table->addColumn('require_approval', Types::BOOLEAN, [
				'notnull' => true,
				'default' => true,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('allow_voting')) {
			$table->addColumn('allow_voting', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	/**
	 * Give every existing share the answer its channel was already giving.
	 *
	 * Without this, upgrading would silently change what every share does — the point of
	 * moving the setting is to allow different answers, not to impose new ones.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->inherit('require_approval');
		// Voting was granted through the permission mask before this, so a link that had
		// the bit keeps voting and one that did not still does not.
		$this->inheritVoting();
	}

	private function inherit(string $column): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('music_radio_shares', 's')
			->set("s.$column", $qb->createFunction(
				"(select c.$column from `*PREFIX*music_radio_channels` c where c.id = s.channel_id)",
			));

		try {
			$qb->executeStatement();
		} catch (\Throwable) {
			// A fresh install has nothing to carry over, and a failure here must not stop
			// an upgrade over a default that is already safe.
		}
	}

	private function inheritVoting(): void {
		$qb = $this->db->getQueryBuilder();
		// 64 is the old Permission::VOTE bit.
		$qb->update('music_radio_shares')
			->set('allow_voting', $qb->createFunction('case when (permissions & 64) = 64 then 1 else 0 end'));

		try {
			$qb->executeStatement();
		} catch (\Throwable) {
		}
	}
}
