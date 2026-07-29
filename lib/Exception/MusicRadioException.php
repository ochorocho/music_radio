<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Exception;

use OCP\AppFramework\Http;

/**
 * Base for the app's expected failures. Controllers map `getStatus()` straight onto the
 * HTTP response, so services can fail meaningfully without knowing about HTTP.
 */
class MusicRadioException extends \RuntimeException {

	/**
	 * @param Http::STATUS_* $status
	 */
	public function __construct(
		string $message,
		private int $status = Http::STATUS_BAD_REQUEST,
	) {
		parent::__construct($message);
	}

	/**
	 * Narrowed to the known status constants so it can be handed straight to a
	 * DataResponse, whose status parameter is a literal union rather than a plain int.
	 *
	 * @return Http::STATUS_*
	 */
	public function getStatus(): int {
		return $this->status;
	}
}
