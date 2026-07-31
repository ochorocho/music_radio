<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Migration;

use Closure;
use OCA\MusicRadio\Db\Share;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The last two channel-wide settings, moved onto the share — and an import's approval
 * decision, recorded when it is asked for.
 *
 * **The share columns** finish what Version001100 started. Approval and voting moved first
 * because they were the ones an owner most obviously wants to answer differently for people
 * they named than for whoever ends up with a link; the listener count and YouTube import
 * are the same kind of question and had no business staying behind.
 *
 * `allow_import` defaults to **false**, unlike the channel column it replaces. It is no
 * longer only "may a signed-in contributor paste a link" — it is also what lets an
 * anonymous visitor make this server download and transcode audio into the owner's
 * storage. That is not a capability to inherit by surprise, so existing internal shares
 * take the channel's answer and links start off.
 *
 * **`imports.approved`** exists because the decision cannot be made where it is used. An
 * import is filed minutes after it is asked for, by a background job that has only the
 * import row — and for a link that row identifies the requester as `?link:<key>`, which is
 * not an account and cannot be resolved back to a share. So the answer is taken while the
 * share is still in hand and carried on the row.
 */
class Version001200Date20260731050000 extends SimpleMigrationStep {

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
		$changed = false;

		if ($schema->hasTable('music_radio_shares')) {
			$table = $schema->getTable('music_radio_shares');

			if (!$table->hasColumn('show_listener_count')) {
				$table->addColumn('show_listener_count', Types::BOOLEAN, [
					'notnull' => true,
					'default' => true,
				]);
				$changed = true;
			}

			// Off, deliberately — see the class docblock.
			if (!$table->hasColumn('allow_import')) {
				$table->addColumn('allow_import', Types::BOOLEAN, [
					'notnull' => true,
					'default' => false,
				]);
				$changed = true;
			}
		}

		if ($schema->hasTable('music_radio_imports')) {
			$table = $schema->getTable('music_radio_imports');
			if (!$table->hasColumn('approved')) {
				// True matches what every import did before this existed: filed and playable.
				$table->addColumn('approved', Types::BOOLEAN, [
					'notnull' => true,
					'default' => true,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}

	/**
	 * Carry the channel's answers over, so no existing share changes behaviour.
	 *
	 * Only the listener count is inherited. Importing is not: a link that could not import
	 * yesterday must not be able to today merely because the channel allowed it for
	 * signed-in contributors.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->inheritListenerCount();
		$this->inheritImportForNamedSharesOnly();
	}

	private function inheritListenerCount(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('music_radio_shares', 's')
			->set('s.show_listener_count', $qb->createFunction(
				'(select c.show_listener_count from `*PREFIX*music_radio_channels` c where c.id = s.channel_id)',
			));

		try {
			$qb->executeStatement();
		} catch (\Throwable) {
			// A fresh install has nothing to carry over, and the default is already right.
		}
	}

	private function inheritImportForNamedSharesOnly(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('music_radio_shares', 's')
			->set('s.allow_import', $qb->createFunction(
				'(select c.allow_import from `*PREFIX*music_radio_channels` c where c.id = s.channel_id)',
			))
			->where($qb->expr()->neq(
				's.share_type',
				$qb->createNamedParameter(Share::TYPE_LINK, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
			));

		try {
			$qb->executeStatement();
		} catch (\Throwable) {
		}
	}
}
