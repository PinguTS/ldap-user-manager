<?php

declare(strict_types=1);

// $THIS_MODULE (web_functions.inc.php) is derived from getcwd(); align it with the
// real module directory so $THIS_MODULE_PATH resolves to /setup, not /check.
chdir(dirname(__DIR__));
require __DIR__ . '/../run_checks.php';
