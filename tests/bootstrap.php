<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure unit-test bootstrap. The app's classes autoload from lib/ via Composer.
 * OCP interfaces are provided by the nextcloud/ocp package, which ships the
 * stub files but declares no autoloader (it is meant for static analysis), so
 * we register a small PSR-4 autoloader for the OCP namespace here. No running
 * Nextcloud server is required.
 */

require_once __DIR__ . '/../composer/autoload.php';

spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}
	$path = __DIR__ . '/../composer/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
	if (is_file($path)) {
		require_once $path;
	}
});
