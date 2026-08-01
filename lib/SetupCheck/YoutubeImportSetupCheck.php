<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\SetupCheck;

use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\YtDlpLocator;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Tell administrators about YouTube import from the place they already look.
 *
 * This is why the app needs no admin page of its own for the diagnosis half of the
 * problem. "An external program is missing" is exactly what the Overview page is for, and
 * an administrator who never opens the app's settings will still be told that its
 * downloader has gone stale — which is the failure that actually happens in practice.
 *
 * A missing yt-dlp is *info*, not a warning: importing is an optional feature, and a
 * server that never wanted it should not be nagged. An installed-but-stale copy is a
 * warning, because it will fail and the administrator is the only one who can fix it.
 */
class YoutubeImportSetupCheck implements ISetupCheck {

	public function __construct(
		private YtDlpLocator $locator,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * Where the button that fixes this lives.
	 *
	 * Both of the states below used to end by naming an occ command, which is the wrong
	 * answer twice over: there is now a button that does it, and an administrator reading
	 * this on a managed server may have no shell to run anything in. A check that reports a
	 * problem should point at the thing that solves it.
	 */
	private function settingsLink(): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'settings.AdminSettings.index',
			['section' => 'music_radio'],
		);
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('Music Radio: YouTube import');
	}

	public function run(): SetupResult {
		$status = $this->locator->status();

		if (!$status->available) {
			return match ($status->reason) {
				ImportError::DISABLED => SetupResult::success(
					$this->l10n->t('YouTube import is switched off.'),
				),
				ImportError::FFMPEG_MISSING => SetupResult::info(
					$this->l10n->t('ffmpeg and ffprobe are not installed, so channels cannot import audio from YouTube. Both are needed because YouTube does not serve MP3 and the audio has to be transcoded.'),
				),
				ImportError::PROCESS_DISABLED => SetupResult::info(
					$this->l10n->t('proc_open is disabled in this PHP installation, so channels cannot import audio from YouTube.'),
				),
				default => SetupResult::info(
					$this->l10n->t('yt-dlp is not installed, so channels cannot import audio from YouTube. Install it with "Update yt-dlp" in the Music Radio settings.'),
					$this->settingsLink(),
				),
			};
		}

		if ($status->outdated) {
			return SetupResult::warning(
				$this->l10n->t('The installed yt-dlp (%1$s) is more than 90 days old. YouTube changes frequently and imports will start failing; update it with "Update yt-dlp" in the Music Radio settings.', [
					$status->ytDlpVersion ?? '?',
				]),
				$this->settingsLink(),
			);
		}

		return SetupResult::success(
			$this->l10n->t('yt-dlp %1$s and ffmpeg are available.', [$status->ytDlpVersion ?? '?']),
		);
	}
}
