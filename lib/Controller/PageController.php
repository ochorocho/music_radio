<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Controller;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Service\YoutubeImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

class PageController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IInitialState $initialState,
		private YoutubeImportService $importService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('music_radio-initial-state', [
			'userId' => $this->userId,
			// Whether importing is possible at all, so the button is decided before the
			// first paint rather than appearing a request later. Cheap: two config reads
			// and no process, because the detected version is cached.
			'importCapabilities' => $this->importService->availability(),
		]);

		return new TemplateResponse(Application::APP_ID, 'main');
	}
}
