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
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The switches an administrator has over importing.
 *
 * A page of its own rather than a declarative form, which is what this used to be. Two
 * things the declarative API cannot do turned out to be the two things wanted here: it
 * saves each field the moment it loses focus and offers no way to add a Save button, so
 * typing a value and clicking away felt like nothing had happened at all; and it has no
 * field type for choosing a folder, which the personal page needs.
 *
 * The trade is a small Vue bundle to keep in step with @nextcloud/vue. Worth it for a form
 * that says whether it saved.
 *
 * The diagnosis proper still lives in the admin Overview page, via the setup check, which
 * is where somebody would look without knowing this app has settings at all.
 *
 * @see \OCA\MusicRadio\SetupCheck\YoutubeImportSetupCheck
 */
class AdminSettings implements ISettings {

	public function __construct(
		private SettingsStore $store,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		// Recomputed on every render, so the yt-dlp path and version an administrator is
		// reading are the ones in force at that moment rather than whenever it was cached.
		$this->initialState->provideInitialState('admin-settings', $this->store->adminState());

		Util::addScript(Application::APP_ID, Application::APP_ID . '-adminSettings');

		return new TemplateResponse(Application::APP_ID, 'settings/admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
