<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Settings;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Service\SettingsStore;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Where a person's music lands.
 *
 * One field, which is why this was a declarative form — describing it was less code than
 * building it. What that could not offer is a way to pick the folder: the declarative API
 * has no file or folder field type, only text and numbers, so the only way to name a
 * folder was to type its path exactly and hope.
 *
 * So it is a page now, with a picker beside the field rather than instead of it — see the
 * component for why both are needed.
 */
class PersonalSettings implements ISettings {

	public function __construct(
		private SettingsStore $store,
		private IInitialState $initialState,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? '';
		$this->initialState->provideInitialState('personal-settings', $this->store->personalState($userId));

		Util::addScript(Application::APP_ID, Application::APP_ID . '-personalSettings');

		return new TemplateResponse(Application::APP_ID, 'settings/personal');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
