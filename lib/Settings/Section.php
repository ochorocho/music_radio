<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MusicRadio\Settings;

use OCA\MusicRadio\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The app's own entry in both the personal and the admin settings lists.
 *
 * One class serving both, because it is the same section as far as anyone reading it is
 * concerned — what differs is which form appears inside it, and that is decided by each
 * form's own `section_type`.
 */
class Section implements IIconSection {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Music Radio');
	}

	/**
	 * Well below the sections Nextcloud ships. This is one app's settings, not something
	 * that belongs near "Personal info" or "Security".
	 */
	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
