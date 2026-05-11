<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap — entry point for both the dev server and the compiled dist/index.php.
// All request routing will be handled here once the Router class is implemented.

// Temporary: forward to the original index.php during migration.
// Remove this once Phase 3 is complete.
require_once __DIR__ . '/../index.php';
