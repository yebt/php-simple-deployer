<?php

declare(strict_types=1);

define('SPHPD_ROOT', dirname(__DIR__));
define('SPHPD_SRC', SPHPD_ROOT . '/src');
define('SPHPD_TEMPLATES', SPHPD_ROOT . '/templates');

require_once SPHPD_ROOT . '/vendor/autoload.php';

// Bootstrap will be wired here as classes are migrated from old/index.php.
// For now this file serves as the entry point for `composer start` and Box.
