<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Exception;

use OCP\AppFramework\Http;

/**
 * The caller can see the channel but lacks the permission bit for this operation.
 */
class ForbiddenException extends MusicRadioException {

	public function __construct(string $message = 'Not allowed') {
		parent::__construct($message, Http::STATUS_FORBIDDEN);
	}
}
