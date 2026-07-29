<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Exception;

use OCP\AppFramework\Http;

/**
 * The channel/track does not exist, or the caller may not know that it does. Both cases
 * deliberately produce the same 404 so the API is not an existence oracle.
 */
class NotFoundException extends MusicRadioException {

	public function __construct(string $message = 'Not found') {
		parent::__construct($message, Http::STATUS_NOT_FOUND);
	}
}
