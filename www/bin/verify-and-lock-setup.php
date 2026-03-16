#!/usr/bin/env php
<?php

/**
 * CLI script: run setup verification and create the setup lock file if all
 * checks pass. Used by the Docker entrypoint on container startup. Requires
 * config (env) and LDAP to be reachable; no web/session dependencies.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$app_root = dirname(__DIR__);
chdir($app_root);

$includes = $app_root . '/includes';
require_once $includes . '/config.inc.php';
require_once $includes . '/ldap_functions.inc.php';
require_once $includes . '/setup_lock.inc.php';
require_once $includes . '/setup_verify.inc.php';

$ldap_connection = open_ldap_connection();
if ($ldap_connection === false) {
    fwrite(STDERR, "Setup verification: LDAP connection failed.\n");
    exit(1);
}

$result = run_setup_verification($ldap_connection);
if (!$result['passed']) {
    fwrite(STDERR, "Setup verification: one or more checks failed (missing: " . implode(', ', array_unique($result['missing_components'])) . ").\n");
    exit(1);
}

if (!set_setup_locked()) {
    fwrite(STDERR, "Setup verification: could not create lock file.\n");
    exit(1);
}

exit(0);
