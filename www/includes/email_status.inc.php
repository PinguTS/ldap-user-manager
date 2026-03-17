<?php

declare(strict_types=1);

/**
 * Email status file: stores the result of the last SMTP verification (run by
 * bin/verify-email.php or setup/verify.php). Config reads this file to set
 * EMAIL_SENDING_ENABLED. Path configurable via EMAIL_STATUS_FILE; optional
 * TTL via EMAIL_STATUS_TTL (seconds); no credentials stored.
 * When TTL is set, config may trigger a lazy refresh so the status is
 * re-checked automatically after expiry.
 */

/**
 * Return the path of the email status file.
 *
 * @return string
 */
function get_email_status_file_path(): string
{
    $path = getenv('EMAIL_STATUS_FILE');
    if ($path !== false && $path !== '') {
        return $path;
    }
    $sessionPath = getenv('SESSION_SAVE_PATH');
    $base = ($sessionPath !== false && $sessionPath !== '') ? $sessionPath : '/tmp';
    return rtrim($base, '/') . '/ldap_user_manager_email_status.json';
}

/**
 * Return whether email is currently considered verified (status file exists,
 * is readable, and indicates success). Optionally treat as expired after
 * EMAIL_STATUS_TTL seconds; if TTL is not set, status stays until overwritten.
 *
 * @return bool
 */
function is_email_verified(): bool
{
    $path = get_email_status_file_path();
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return false;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['ok'])) {
        return false;
    }
    $ttl = getenv('EMAIL_STATUS_TTL');
    if ($ttl !== false && $ttl !== '' && is_numeric($ttl)) {
        $checkedAt = isset($data['checked_at']) ? (int) $data['checked_at'] : 0;
        if ($checkedAt <= 0 || (time() - $checkedAt) > (int) $ttl) {
            return false;
        }
    }
    return true;
}

/**
 * Return whether the status file is missing or expired (TTL passed), so that
 * config should run verification and update the file. Does not inspect 'ok';
 * a recent failure still has a recent checked_at and should not trigger a
 * refresh until TTL has passed (avoids hammering the mail server when down).
 *
 * @return bool True if status file is missing/unreadable, or TTL is set and checked_at is too old
 */
function email_status_needs_refresh(): bool
{
    $path = get_email_status_file_path();
    if (!is_file($path) || !is_readable($path)) {
        return true;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return true;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return true;
    }
    $ttl = getenv('EMAIL_STATUS_TTL');
    if ($ttl === false || $ttl === '' || !is_numeric($ttl)) {
        return false;
    }
    $ttlSeconds = (int) $ttl;
    $checkedAt = isset($data['checked_at']) ? (int) $data['checked_at'] : 0;
    if ($checkedAt <= 0 || (time() - $checkedAt) > $ttlSeconds) {
        return true;
    }
    return false;
}

/**
 * Write the email verification status file. No credentials are stored.
 *
 * @param bool $ok Whether the last verification succeeded
 * @return bool True if the file was written, false on failure
 */
function set_email_verified(bool $ok): bool
{
    $path = get_email_status_file_path();
    $data = [
        'ok' => $ok,
        'checked_at' => time(),
    ];
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $result = @file_put_contents($path, $json);
    return $result !== false;
}
