<?php

declare(strict_types=1);

/**
 * Password reset / set-link helpers (stateless signed token).
 *
 * Token format: base64url(json_payload) + '.' + base64url(signature)
 * Signature: HMAC-SHA256(payload_b64, secret, raw=true)
 *
 * Payload fields:
 * - sub: string (account identifier, typically email)
 * - exp: int (unix timestamp)
 * - purpose: string ('set' | 'reset')
 */

/**
 * @return string base64url-encoded data (no padding)
 */
function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * @return string decoded bytes
 */
function base64url_decode(string $data): string
{
    $b64 = strtr($data, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($b64, true);
    return ($decoded === false) ? '' : $decoded;
}

/**
 * @param array{sub:string,exp:int,purpose:string} $payload
 */
function create_password_action_token(array $payload): string
{
    $secret = (string) (getenv('PASSWORD_RESET_TOKEN_SECRET') ?: '');
    if ($secret === '') {
        throw new RuntimeException('PASSWORD_RESET_TOKEN_SECRET is not configured.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode token payload.');
    }

    $payloadB64 = base64url_encode($json);
    $sig = hash_hmac('sha256', $payloadB64, $secret, true);
    $sigB64 = base64url_encode($sig);

    return $payloadB64 . '.' . $sigB64;
}

/**
 * @return array{sub:string,exp:int,purpose:string}|null
 */
function verify_password_action_token(string $token): ?array
{
    $secret = (string) (getenv('PASSWORD_RESET_TOKEN_SECRET') ?: '');
    if ($secret === '') {
        return null;
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$payloadB64, $sigB64] = $parts;
    if ($payloadB64 === '' || $sigB64 === '') {
        return null;
    }

    $expectedSigB64 = base64url_encode(hash_hmac('sha256', $payloadB64, $secret, true));
    if (!hash_equals($expectedSigB64, $sigB64)) {
        return null;
    }

    $payloadJson = base64url_decode($payloadB64);
    if ($payloadJson === '') {
        return null;
    }

    $decoded = json_decode($payloadJson, true);
    if (!is_array($decoded)) {
        return null;
    }

    $sub = $decoded['sub'] ?? null;
    $exp = $decoded['exp'] ?? null;
    $purpose = $decoded['purpose'] ?? null;

    if (!is_string($sub) || $sub === '') {
        return null;
    }
    if (!is_int($exp)) {
        if (is_numeric($exp)) {
            $exp = (int) $exp;
        } else {
            return null;
        }
    }
    if (!is_string($purpose) || ($purpose !== 'set' && $purpose !== 'reset')) {
        return null;
    }

    if (time() >= $exp) {
        return null;
    }

    // Reject tokens that have already been consumed (single-use enforcement).
    if (isPasswordTokenConsumed($token)) {
        return null;
    }

    return [
        'sub' => $sub,
        'exp' => $exp,
        'purpose' => $purpose,
    ];
}

function is_password_reset_link_enabled(): bool
{
    $secret = (string) (getenv('PASSWORD_RESET_TOKEN_SECRET') ?: '');
    return $secret !== '';
}

/**
 * Return the file path used to mark a token as consumed.
 * We store a SHA-256 hash of the token (not the token itself) so that the file
 * cannot be reversed back to the original token.
 */
function getConsumedTokenFilePath(string $token): string
{
    $hash = hash('sha256', $token);
    // lumStateDir() is defined in web_functions.inc.php and uses APP_STATE_DIR.
    // If the caller hasn't loaded web_functions.inc.php, fall back to /tmp.
    $dir = function_exists('lumStateDir') ? lumStateDir() : sys_get_temp_dir();
    return $dir . '/lum_token_used_' . $hash;
}

/**
 * Record that a password-reset / set-link token has been consumed so that it
 * cannot be replayed.  The file is given a TTL so the state directory does not
 * grow indefinitely.
 */
function markPasswordTokenConsumed(string $token, int $ttlSeconds = 7200): void
{
    $path = getConsumedTokenFilePath($token);
    @file_put_contents($path, (string) (time() + $ttlSeconds));
    // Opportunistic cleanup of stale consumed-token files.
    $dir = function_exists('lumStateDir') ? lumStateDir() : sys_get_temp_dir();
    foreach (glob($dir . '/lum_token_used_*') ?: [] as $f) {
        $exp = (int) @file_get_contents($f);
        if ($exp > 0 && time() > $exp) {
            @unlink($f);
        }
    }
}

/**
 * Return true if the given token has already been consumed (i.e. used once).
 */
function isPasswordTokenConsumed(string $token): bool
{
    $path = getConsumedTokenFilePath($token);
    if (!is_file($path)) {
        return false;
    }
    $exp = (int) @file_get_contents($path);
    if ($exp > 0 && time() > $exp) {
        @unlink($path);
        return false;
    }
    return true;
}

function get_password_reset_token_ttl_seconds(): int
{
    $ttl = getenv('PASSWORD_RESET_TOKEN_TTL_SECONDS');
    if ($ttl === false || $ttl === '') {
        return 3600;
    }
    $ttlInt = (int) $ttl;
    return ($ttlInt > 0) ? $ttlInt : 3600;
}

/**
 * @return array{sub:string,exp:int,purpose:string}
 */
function build_password_action_payload(string $accountIdentifier, string $purpose): array
{
    $ttl = get_password_reset_token_ttl_seconds();
    return [
        'sub' => $accountIdentifier,
        'exp' => time() + $ttl,
        'purpose' => $purpose,
    ];
}

function build_password_action_url(string $token): string
{
    global $SITE_PROTOCOL, $SERVER_HOSTNAME, $SERVER_PATH;
    if (function_exists('lumPublicSiteBaseUrl')) {
        $base = lumPublicSiteBaseUrl();
    } else {
        $path = rtrim((string) $SERVER_PATH, '/') . '/';
        $base = (string) $SITE_PROTOCOL . (string) $SERVER_HOSTNAME . $path;
    }
    return rtrim($base, '/') . '/password/set/?token=' . urlencode($token);
}

/**
 * Server-side password validation for flows that accept a password directly.
 *
 * @return array{ok:bool,errors:string[]}
 */
function validate_password_submission(string $password, string $confirm, ?int $passScore = null): array
{
    $errors = [];

    $passwordTrimmed = trim($password);
    if ($passwordTrimmed === '') {
        $errors[] = 'Password is required.';
        return ['ok' => false, 'errors' => $errors];
    }

    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Disallow quotes (legacy constraint used by change_password)
    if (preg_match("/\"|'/", $password) === 1) {
        $errors[] = 'Password contains invalid characters.';
    }

    $acceptWeak = (strcasecmp((string) (getenv('ACCEPT_WEAK_PASSWORDS') ?: ''), 'TRUE') === 0);
    $minScore = (int) (getenv('PASSWORD_STRENGTH_MIN_SCORE') ?: 0);

    if (!$acceptWeak) {
        if ($passScore === null) {
            $errors[] = 'Password strength score is missing.';
        } elseif ($passScore < $minScore) {
            $errors[] = 'Password is not strong enough.';
        }
    }

    return ['ok' => count($errors) === 0, 'errors' => $errors];
}
