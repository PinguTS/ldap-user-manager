#!/usr/bin/env php
<?php

/**
 * CLI script: run email (SMTP) verification and write the email status file.
 * Used by the Docker entrypoint on container startup (e.g. after verify-and-lock-setup).
 * Requires config (env); no web/session dependencies. Exit 0 on success or when
 * SMTP is not configured; exit 1 when SMTP is configured but verification fails.
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
require_once $includes . '/email_verify.inc.php';
require_once $includes . '/email_status.inc.php';

$host = isset($SMTP['host']) ? trim((string) $SMTP['host']) : '';
if ($host === '') {
    exit(0);
}

$result = run_email_verification();
set_email_verified($result['passed']);

if (!$result['passed']) {
    fwrite(STDERR, "Email verification failed: " . $result['message'] . "\n");
    exit(1);
}

exit(0);
