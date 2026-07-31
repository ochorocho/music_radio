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
 * Somewhere to keep what yt-dlp actually said.
 *
 * `error_code` holds one of ImportError's known causes, and YtDlpFailure recognises about
 * ten of them from yt-dlp's stderr. Everything else became `unknown`, and the interface
 * answered "The import failed." — a sentence that tells whoever asked for the import
 * nothing at all, and leaves them nothing to act on. The real reason went only to the
 * Nextcloud log, which is not somewhere a user can look.
 *
 * This column carries the reason yt-dlp gave, cut down to the part worth reading — see
 * YtDlpFailure::detail(), which keeps the text after yt-dlp's `ERROR: [youtube] <id>:`
 * prefix, drops anything path-shaped, and caps the length.
 *
 * 255 is deliberate: enough for any real yt-dlp error line, short enough that it cannot
 * become a place where a stack trace ends up.
 */
class Version001400Date20260731160000 extends SimpleMigrationStep {

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
		if ($table->hasColumn('error_detail')) {
			return null;
		}

		$table->addColumn('error_detail', Types::STRING, [
			'notnull' => false,
			'length' => 255,
			'default' => null,
		]);

		return $schema;
	}
}
