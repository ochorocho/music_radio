<?php

declare(strict_types=1);

require_once './composer/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->notPath('build')
	->notPath('l10n')
	->notPath('src')
	->notPath('node_modules')
	->notPath('composer')
	->in(__DIR__);
return $config;
