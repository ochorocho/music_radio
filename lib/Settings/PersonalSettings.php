<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Settings;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Service\MusicLibrary;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * Where a person's music lands.
 *
 * A declarative form rather than an ISettings class with its own Vue bundle: it is one
 * text field, and describing it is less code than building it, with nothing to keep in
 * step when @nextcloud/vue moves on.
 *
 * Storage is `external` — meaning this class reads and writes the value itself — purely so
 * that setValue() can refuse a bad path. The `internal` storage Nextcloud offers would
 * write whatever was typed straight into the config table, and this value ends up in a
 * filesystem call. It is checked again on the way out (see MusicLibrary), because a
 * validating writer is not the same as a trustworthy column.
 */
class PersonalSettings implements IDeclarativeSettingsForm, IDeclarativeSettingsFormWithHandlers {

	public function __construct(
		private IUserConfig $userConfig,
		private IL10N $l10n,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'music_radio_personal',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_PERSONAL,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l10n->t('Music Radio'),
			'description' => $this->l10n->t('Where music lands when it is added to one of your channels by upload or by import.'),
			'fields' => [
				[
					'id' => MusicLibrary::CONFIG_FOLDER,
					'title' => $this->l10n->t('Music folder'),
					'description' => $this->l10n->t('A folder inside your files, created if it is not there yet. Up to four levels deep, for example "Media/Music".'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => MusicLibrary::DEFAULT_FOLDER,
					'default' => MusicLibrary::DEFAULT_FOLDER,
				],
			],
		];
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		if ($fieldId !== MusicLibrary::CONFIG_FOLDER) {
			throw new \InvalidArgumentException('Unknown field ' . $fieldId);
		}

		return $this->userConfig->getValueString(
			$user->getUID(),
			Application::APP_ID,
			MusicLibrary::CONFIG_FOLDER,
			MusicLibrary::DEFAULT_FOLDER,
		);
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		if ($fieldId !== MusicLibrary::CONFIG_FOLDER) {
			throw new \InvalidArgumentException('Unknown field ' . $fieldId);
		}

		$wanted = is_string($value) ? $value : '';
		$safe = MusicLibrary::sanitiseFolderPath($wanted);

		// Refused rather than quietly corrected. Saving "../music" and being shown "Music"
		// afterwards would be confusing; being told why is not. The one exception is an
		// empty value, which plainly means "back to the default".
		if (trim($wanted) !== '' && $safe !== trim(str_replace('\\', '/', $wanted), " \t\n\r\0\x0B/")) {
			throw new \InvalidArgumentException(
				$this->l10n->t('That folder name cannot be used. Give a folder inside your files, such as "Music" or "Media/Music".'),
			);
		}

		$this->userConfig->setValueString($user->getUID(), Application::APP_ID, MusicLibrary::CONFIG_FOLDER, $safe);
	}
}
