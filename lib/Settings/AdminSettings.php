<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Settings;

use OCA\MusicRadio\AppInfo\Application;
use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\YoutubeImportService;
use OCA\MusicRadio\Service\YtDlpLocator;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * The switches an administrator has over importing.
 *
 * Declarative forms have only input types — there is no "just show them this" field. That
 * turns out not to matter, because getSchema() is called each time the page is rendered:
 * the live detection result goes into the descriptions, so the yt-dlp path and version an
 * administrator needs to see sit next to the field that overrides them, and are current
 * every time they look.
 *
 * The diagnosis proper lives in the admin Overview page, via the setup check, which is
 * where somebody would look without knowing this app has settings at all.
 *
 * @see \OCA\MusicRadio\SetupCheck\YoutubeImportSetupCheck
 */
class AdminSettings implements IDeclarativeSettingsForm, IDeclarativeSettingsFormWithHandlers {

	public function __construct(
		private IAppConfig $appConfig,
		private YtDlpLocator $locator,
		private IL10N $l10n,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'music_radio_admin',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => Application::APP_ID,
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l10n->t('YouTube import'),
			'description' => $this->summary(),
			'fields' => [
				[
					'id' => YtDlpLocator::CONFIG_ENABLED,
					'title' => $this->l10n->t('Allow importing from YouTube'),
					'description' => $this->l10n->t('People who may add tracks to a channel can paste a link, and the server fetches the audio as an MP3. It is stored in the channel owner\'s files and counts against their quota.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => true,
				],
				[
					'id' => YtDlpLocator::CONFIG_YTDLP_PATH,
					'title' => $this->l10n->t('Path to yt-dlp'),
					'description' => $this->describeYtDlp(),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l10n->t('Leave empty to detect automatically'),
					'default' => '',
				],
				[
					'id' => YoutubeImportService::CONFIG_MAX_DURATION,
					'title' => $this->l10n->t('Longest video, in minutes'),
					'description' => $this->l10n->t('Anything longer is refused before it is downloaded.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => (int)(YoutubeImportService::DEFAULT_MAX_DURATION_SECONDS / 60),
				],
				[
					'id' => YoutubeImportService::CONFIG_MAX_SOURCE_BYTES,
					'title' => $this->l10n->t('Largest download, in megabytes'),
					'description' => $this->l10n->t('Measured on what is fetched from YouTube, before it is converted.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => (int)(YoutubeImportService::DEFAULT_MAX_SOURCE_BYTES / 1024 / 1024),
				],
			],
		];
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		return match ($fieldId) {
			YtDlpLocator::CONFIG_ENABLED
				=> $this->appConfig->getValueBool(Application::APP_ID, YtDlpLocator::CONFIG_ENABLED, true),
			YtDlpLocator::CONFIG_YTDLP_PATH
				=> $this->appConfig->getValueString(Application::APP_ID, YtDlpLocator::CONFIG_YTDLP_PATH),
			YoutubeImportService::CONFIG_MAX_DURATION
				=> (int)round($this->appConfig->getValueInt(
					Application::APP_ID,
					YoutubeImportService::CONFIG_MAX_DURATION,
					YoutubeImportService::DEFAULT_MAX_DURATION_SECONDS,
				) / 60),
			YoutubeImportService::CONFIG_MAX_SOURCE_BYTES
				=> (int)round($this->appConfig->getValueInt(
					Application::APP_ID,
					YoutubeImportService::CONFIG_MAX_SOURCE_BYTES,
					YoutubeImportService::DEFAULT_MAX_SOURCE_BYTES,
				) / 1024 / 1024),
			default => throw new \InvalidArgumentException('Unknown field ' . $fieldId),
		};
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		switch ($fieldId) {
			case YtDlpLocator::CONFIG_ENABLED:
				$this->appConfig->setValueBool(Application::APP_ID, YtDlpLocator::CONFIG_ENABLED, (bool)$value);

				return;
			case YtDlpLocator::CONFIG_YTDLP_PATH:
				$this->setYtDlpPath(is_string($value) ? trim($value) : '');

				return;
				// Stored in seconds and bytes, shown in minutes and megabytes: the units people
				// think in are not the units the rest of the code works in.
			case YoutubeImportService::CONFIG_MAX_DURATION:
				$this->appConfig->setValueInt(
					Application::APP_ID,
					YoutubeImportService::CONFIG_MAX_DURATION,
					max(1, (int)$value) * 60,
				);

				return;
			case YoutubeImportService::CONFIG_MAX_SOURCE_BYTES:
				$this->appConfig->setValueInt(
					Application::APP_ID,
					YoutubeImportService::CONFIG_MAX_SOURCE_BYTES,
					max(1, (int)$value) * 1024 * 1024,
				);

				return;
			default:
				throw new \InvalidArgumentException('Unknown field ' . $fieldId);
		}
	}

	/**
	 * An override is only useful if it points at something that will run, so it is checked
	 * before it is accepted rather than becoming a broken feature to diagnose later.
	 */
	private function setYtDlpPath(string $path): void {
		if ($path !== '') {
			if (!str_starts_with($path, '/')) {
				throw new \InvalidArgumentException($this->l10n->t('Give the full path, starting with a slash.'));
			}
			if (!is_file($path) || !is_executable($path)) {
				throw new \InvalidArgumentException($this->l10n->t('There is no program the server can run at that path.'));
			}
		}

		$this->appConfig->setValueString(Application::APP_ID, YtDlpLocator::CONFIG_YTDLP_PATH, $path);
		// The previous binary's version is cached; keeping it would describe the old one.
		$this->appConfig->setValueInt(Application::APP_ID, YtDlpLocator::CONFIG_CHECKED_AT, 0);
	}

	// ------------------------------------------------------------ what is true now

	private function summary(): string {
		$status = $this->locator->status();

		if ($status->available) {
			return $status->outdated
				? $this->l10n->t('Working, but the downloader is out of date and some videos will fail. Update it with "occ music_radio:ytdlp:install --force".')
				: $this->l10n->t('Working.');
		}

		return match ($status->reason) {
			ImportError::DISABLED => $this->l10n->t('Switched off below.'),
			ImportError::FFMPEG_MISSING => $this->l10n->t('Not usable: ffmpeg and ffprobe are not installed. Both are needed, because YouTube does not serve MP3 and the audio has to be converted.'),
			ImportError::PROCESS_DISABLED => $this->l10n->t('Not usable: this PHP installation does not allow running external programs (proc_open is disabled).'),
			default => $this->l10n->t('Not usable: yt-dlp was not found. Install it with "occ music_radio:ytdlp:install", or give its path below.'),
		};
	}

	private function describeYtDlp(): string {
		$path = $this->locator->ytDlpPath();
		if ($path === null) {
			return $this->l10n->t('Nothing found. Looked for an override here, then for the copy "occ music_radio:ytdlp:install" manages, then on the system path.');
		}

		$version = $this->locator->version($path);

		return $version === null
			? $this->l10n->t('Using %1$s, which did not report a version — it may be incomplete.', [$path])
			: $this->l10n->t('Using %1$s, version %2$s.', [$path, $version]);
	}
}
