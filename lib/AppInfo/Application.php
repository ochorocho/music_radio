<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\AppInfo;

use OCA\MusicRadio\Process\IProcessRunner;
use OCA\MusicRadio\Process\ProcOpenRunner;
use OCA\MusicRadio\Settings\AdminSettings;
use OCA\MusicRadio\Settings\PersonalSettings;
use OCA\MusicRadio\SetupCheck\YoutubeImportSetupCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'music_radio';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// One implementation, deliberately behind an interface: it is what lets every
		// decision about running yt-dlp be unit-tested against a fake, leaving only the
		// fork itself to the end-to-end suite.
		$context->registerServiceAlias(IProcessRunner::class, ProcOpenRunner::class);

		// Puts "yt-dlp is missing" and "yt-dlp is stale" on the admin Overview page, where
		// an administrator will see it without ever opening this app — and staleness is
		// the failure that actually happens in practice.
		$context->registerSetupCheck(YoutubeImportSetupCheck::class);

		// Described rather than built: both are a handful of fields, and a declarative
		// form needs no Vue bundle to keep in step with @nextcloud/vue.
		$context->registerDeclarativeSettings(PersonalSettings::class);
		$context->registerDeclarativeSettings(AdminSettings::class);
	}

	public function boot(IBootContext $context): void {
	}
}
