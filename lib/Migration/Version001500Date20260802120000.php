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
 * What an import needs in order to be done somewhere else.
 *
 * A Nextcloud host is often the worst machine on the network for fetching from YouTube: a
 * datacentre address that gets asked to prove it is not a bot, a distribution yt-dlp that
 * is a year old, and frequently no ffmpeg at all. The remote mode leaves the queue here and
 * moves the *work* to a machine that has none of those problems — a NAS at home, a laptop —
 * which collects jobs over the API and hands the finished MP3 back.
 *
 * Three columns, one for each thing the queue could not previously say.
 *
 * `remote` records which kind of worker a row was written for, and it is on the row rather
 * than read from the setting at collection time on purpose. The mode can be changed while
 * imports are in flight, and a row queued in local mode already has a background job
 * holding it — a remote worker that took it as well would download the same video twice and
 * file it twice.
 *
 * `lease_token` is what makes the API safe to expose. Every worker request names a row, and
 * the row is not the secret: ids are small integers and any allow-listed account could
 * guess them. The token is minted when the row is claimed and never leaves the worker that
 * claimed it, so reporting progress, failure or a finished file is possible only for
 * whoever is actually doing that job.
 *
 * `worker_id` is for the person reading the queue. With several workers collecting, "which
 * machine has this one" is the first question asked when something is stuck.
 */
class Version001500Date20260802120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('music_radio_imports')) {
			return null;
		}

		$table = $schema->getTable('music_radio_imports');
		$changed = false;

		// False matches every row that already exists: they were all written for a local
		// background job, and one of them may still be running.
		if (!$table->hasColumn('remote')) {
			$table->addColumn('remote', Types::BOOLEAN, [
				'notnull' => true,
				'default' => false,
			]);
			$changed = true;
		}

		// 64 hex characters from ISecureRandom. Null while a row is queued and again once
		// it has finished — a lease outlives nothing.
		if (!$table->hasColumn('lease_token')) {
			$table->addColumn('lease_token', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
		}

		// Whatever the worker calls itself, cut to fit. Advisory only: nothing is decided
		// from it, so a worker that lies about its name gains nothing.
		if (!$table->hasColumn('worker_id')) {
			$table->addColumn('worker_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
		}

		// The collection query: the oldest queued row written for a remote worker. Run
		// every few seconds by every worker, which makes it the most frequent query this
		// table sees.
		if (!$table->hasIndex('mr_imp_remote_idx')) {
			$table->addIndex(['remote', 'status', 'id'], 'mr_imp_remote_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
