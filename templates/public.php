<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\MusicRadio\AppInfo\Application;

\OCP\Util::addScript(Application::APP_ID, Application::APP_ID . '-public');
