<?php

declare(strict_types=1);

/**
 * Path-aware function naming check (see .cursorrules.md §1.1).
 * Domain includes (subset fully on snake_case): enforce snake_case.
 * Strict app includes: enforce camelCase without underscores.
 *
 * Files not listed here (e.g. organization_functions.inc.php, i18n.inc.php) are skipped until migrated.
 */

$repoRoot = dirname(__DIR__);
$includesDir = $repoRoot . '/www/includes';

$domainFiles = [
    'ldap_functions.inc.php',
    'user_functions.inc.php',
    'org_user_helpers.inc.php',
    'mail_functions.inc.php',
    'org_config_functions.inc.php',
    'email_status.inc.php',
];

$appStrictFiles = [
    'access_functions.inc.php',
    'bootstrap_manage.inc.php',
    'web_functions.inc.php',
    'user_table_helpers.inc.php',
];

$snakeCase = '/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/';
$camelCase = '/\A[a-z][a-zA-Z0-9]*\z/';

$errors = [];

foreach ($domainFiles as $base) {
    $path = $includesDir . '/' . $base;
    if (!is_readable($path)) {
        continue;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*function\s+([a-zA-Z0-9_]+)\s*\(/', $line, $m)) {
            $name = $m[1];
            if (!preg_match($snakeCase, $name)) {
                $errors[] = sprintf('%s:%d: domain function %s() must use snake_case', $base, $i + 1, $name);
            }
        }
    }
}

foreach ($appStrictFiles as $base) {
    $path = $includesDir . '/' . $base;
    if (!is_readable($path)) {
        continue;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*function\s+([a-zA-Z0-9_]+)\s*\(/', $line, $m)) {
            $name = $m[1];
            if (str_contains($name, '_') || !preg_match($camelCase, $name)) {
                $errors[] = sprintf('%s:%d: app function %s() must use camelCase (no underscores)', $base, $i + 1, $name);
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Naming check failed:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "Naming check OK.\n";
exit(0);
