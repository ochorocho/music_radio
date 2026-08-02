<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\SetupCheck;

use OCA\MusicRadio\Service\ImportError;
use OCA\MusicRadio\Service\RemoteImportSettings;
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
		private RemoteImportSettings $remote,
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
		// A server that hands imports to another machine is not missing anything by having
		// no yt-dlp, and saying it is would send an administrator to install a program this
		// server will never run. What matters there is whether a worker is answering.
		if ($this->remote->isRemote()) {
			return $this->runRemote();
		}

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

		// Before staleness, because the failures a missing runtime produces look exactly like
		// a stale downloader — and being told to press "Update yt-dlp" would spend the one
		// action an administrator is likely to take on the thing that is not wrong.
		if ($status->jsRuntime === null) {
			return SetupResult::warning(
				$this->l10n->t('No JavaScript runtime is installed, so imports from YouTube will fail unpredictably. YouTube signs its audio links with JavaScript that yt-dlp has to run; without an engine it falls back to a route that yt-dlp has deprecated and YouTube refuses at random. Install Deno or Node on this server.'),
			);
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
			$this->l10n->t('yt-dlp %1$s, ffmpeg and %2$s are available.', [
				$status->ytDlpVersion ?? '?',
				$status->jsRuntime->name,
			]),
		);
	}

	/**
	 * The same check for a server whose imports are fetched elsewhere.
	 *
	 * A worker that has stopped answering is a *warning* rather than info, unlike a missing
	 * yt-dlp: somebody deliberately set this up, so a silent worker is a thing that has
	 * broken rather than a feature nobody wanted. The remaining state — nothing configured
	 * yet — is the halfway point of a setup and says which half is missing.
	 */
	private function runRemote(): SetupResult {
		$status = $this->remote->status();

		if ($status->available) {
			$name = $this->remote->seenName();
			$message = $name === ''
				? $this->l10n->t('Imports are fetched by a separate machine, which is answering.')
				: $this->l10n->t('Imports are fetched by "%1$s", which is answering.', [$name]);

			// The worker's own runtime, because it is the worker that needs one — and
			// without it the same intermittent failures happen there as here.
			return $this->remote->seenJsRuntime() === null
				? SetupResult::warning($message . ' ' . $this->l10n->t('It has no JavaScript runtime, so some imports will fail unpredictably. Install Deno or Node on that machine.'))
				: SetupResult::success($message);
		}

		return match ($status->reason) {
			ImportError::DISABLED => SetupResult::success(
				$this->l10n->t('YouTube import is switched off.'),
			),
			ImportError::REMOTE_NOT_CONFIGURED => SetupResult::info(
				$this->l10n->t('Music Radio is set to have imports fetched by a separate machine, but no account may collect them yet. Name one in the Music Radio settings.'),
				$this->settingsLink(),
			),
			default => SetupResult::warning(
				$this->l10n->t('No import worker has checked in recently, so nothing will fetch audio from YouTube. Start the worker on the machine that does the fetching, or switch Music Radio back to importing on this server.'),
				$this->settingsLink(),
			),
		};
	}
}
