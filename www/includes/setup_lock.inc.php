<?php

declare(strict_types=1);

/**
 * Setup lock: when LDAP setup verification has succeeded, the setup area can be
 * "locked" so that all /setup/ requests show only a minimal success message and
 * no detailed LDAP information (OUs, DNs, members, etc.).
 *
 * Lock file path is configurable via LDAP_SETUP_LOCK_FILE. Force-unlock via
 * LDAP_SETUP_LOCKED=false (e.g. for development).
 */

/**
 * Return the path of the setup lock file.
 *
 * @return string
 */
function get_setup_lock_file_path(): string
{
    $path = getenv('LDAP_SETUP_LOCK_FILE');
    if ($path !== false && $path !== '') {
        return $path;
    }
    return '/tmp/ldap_user_manager_setup_complete';
}

/**
 * Return whether the setup area is locked (setup was completed successfully).
 *
 * @return bool
 */
function is_setup_locked(): bool
{
    if (strcasecmp(getenv('LDAP_SETUP_LOCKED') ?: 'true', 'false') === 0) {
        return false;
    }
    $path = get_setup_lock_file_path();
    return is_file($path) && is_readable($path);
}

/**
 * Create the setup lock file so that future /setup/ requests show only the
 * minimal "Setup complete" page. No-op if already locked.
 *
 * @return bool True if the lock was set or already present, false on write failure.
 */
function set_setup_locked(): bool
{
    if (is_setup_locked()) {
        return true;
    }
    $path = get_setup_lock_file_path();
    $result = @file_put_contents($path, (string) time());
    return $result !== false;
}
