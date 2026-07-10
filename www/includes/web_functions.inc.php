<?php

/**
 * Web interface utility functions
 *
 * This file contains functions for rendering HTML, managing sessions,
 * and handling web-specific functionality.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Wire security_config session params before session_start().
    // $SECURITY_CONFIG may not be loaded yet; fall back to safe defaults.
    $lum_session_config = $SECURITY_CONFIG['session'] ?? [];
    $lum_no_https       = (bool) (getenv('APP_SERVE_HTTP_ONLY') === 'TRUE' || getenv('APP_SERVE_HTTP_ONLY') === 'true');
    session_set_cookie_params([
        'path'     => '/',
        'secure'   => !$lum_no_https && ($lum_session_config['secure_cookies'] ?? true),
        'httponly' => $lum_session_config['http_only'] ?? true,
        'samesite' => $lum_session_config['same_site'] ?? 'Strict',
    ]);
    unset($lum_session_config, $lum_no_https);
    session_start();
}
include_once 'ldap_functions.inc.php';
include_once 'oidc_functions.inc.php';

# Security level vars

$VALIDATED = false;
$IS_ADMIN = false;
$IS_MAINTAINER = false;
$IS_SETUP_ADMIN = false;
$ACCESS_LEVEL_NAME = ['account', 'admin'];
unset($USER_ID);
$USER_DISPLAY_NAME = null;
$CURRENT_PAGE = htmlentities($_SERVER['PHP_SELF']);
$SENT_HEADERS = false;
$SESSION_TIMED_OUT = false;

$paths = explode('/', getcwd());
$THIS_MODULE = end($paths);

$GOOD_ICON = "&#9745;";
$WARN_ICON = "&#9888;";
$FAIL_ICON = "&#9940;";
$INFO_ICON = "&#8505;";

$JS_EMAIL_REGEX = '/^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;';

if (
    isset($_SERVER['HTTPS']) and
    ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1) or
    isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and
    ($_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
) {
    $SITE_PROTOCOL = 'https://';
} else {
    $SITE_PROTOCOL = 'http://';
}

// Load from same directory as this file so config/modules are found regardless of include_path/cwd
include_once __DIR__ . "/config.inc.php";
include_once __DIR__ . "/modules.inc.php";
include_once __DIR__ . "/i18n.inc.php";

/**
 * Remove one query parameter from URL and return relative app path/query.
 */
function lumRemoveQueryParamFromRequestUri(string $paramName): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $parts = parse_url($uri);
    $path = (string) ($parts['path'] ?? '/');
    $query = (string) ($parts['query'] ?? '');

    if ($query === '') {
        return $path;
    }

    parse_str($query, $params);
    unset($params[$paramName]);
    $newQuery = http_build_query($params);
    return $newQuery === '' ? $path : ($path . '?' . $newQuery);
}

/**
 * Apply language request/cookie and bootstrap i18n with proper precedence.
 */
function lumI18nInitFromRequest(): void
{
    global $SERVER_PATH, $NO_HTTPS;

    $cookieName = 'lum_lang';
    $dir = __DIR__ . '/../locales';
    $available = lum_i18n_discover_locales($dir);

    $requestedRaw = isset($_GET['lang']) ? (string) $_GET['lang'] : null;
    $requested = lum_i18n_normalize_locale((string) $requestedRaw);
    $cookieLocaleRaw = isset($_COOKIE[$cookieName]) ? (string) $_COOKIE[$cookieName] : null;
    $cookieLocale = lum_i18n_normalize_locale((string) $cookieLocaleRaw);

    $validRequested = ($requested !== '' && lum_i18n_is_available_locale($requested, $available));
    $validCookieLocale = ($cookieLocale !== '' && lum_i18n_is_available_locale($cookieLocale, $available))
        ? $cookieLocale
        : null;

    lum_i18n_bootstrap(
        null,
        $dir,
        $validRequested ? $requested : null,
        $validCookieLocale
    );
    lum_apply_i18n_field_labels();

    if ($requestedRaw !== null && $validRequested) {
        $cookiePath = (rtrim((string) ($SERVER_PATH ?? '/'), '/') === '') ? '/' : rtrim((string) $SERVER_PATH, '/');
        setcookie(
            $cookieName,
            $requested,
            [
                'expires' => time() + (86400 * 365),
                'path' => $cookiePath,
                'domain' => '',
                'secure' => $NO_HTTPS ? false : true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        $cleanUri = lumRemoveQueryParamFromRequestUri('lang');
        header('Location: ' . $cleanUri);
        exit;
    }
}

lumI18nInitFromRequest();

// When this file is included from inside a function (e.g. bootstrapManage()), config vars are in global scope
global $SERVER_PATH, $SESSION_TIMEOUT, $NO_HTTPS, $COOKIE_PATH, $THIS_MODULE_PATH, $DEFAULT_COOKIE_OPTIONS;

$SERVER_PATH = (string) ($SERVER_PATH ?? '/');
if (substr($SERVER_PATH, -1) !== '/') {
    $SERVER_PATH .= '/';
}
// Cookie path must be / or a path prefix so the cookie is sent for all app URLs (e.g. /manage/users/)
$COOKIE_PATH = (rtrim($SERVER_PATH, '/') === '') ? '/' : rtrim($SERVER_PATH, '/');
$THIS_MODULE_PATH = "{$SERVER_PATH}{$THIS_MODULE}";

/**
 * Public base URL for links in emails and other out-of-band contexts (trailing slash).
 * When APP_PUBLIC_BASE_URL is set (e.g. http://lum.example.org:8080/), it is normalized to a trailing slash
 * so links match the hostname users open in the browser, independent of in-container Host headers.
 * Otherwise falls back to SITE_PROTOCOL + SERVER_HOSTNAME + SERVER_PATH from config.
 */
function lumPublicSiteBaseUrl(): string
{
    global $SITE_PROTOCOL, $SERVER_HOSTNAME, $SERVER_PATH;

    $fromEnv = trim((string) (getenv('APP_PUBLIC_BASE_URL') ?: ''));
    if ($fromEnv !== '') {
        $base = rtrim($fromEnv, '/');

        return ($base === '') ? '/' : ($base . '/');
    }

    $hostname = (string) $SERVER_HOSTNAME;
    if ($hostname === '') {
        error_log('lumPublicSiteBaseUrl() called without APP_HTTP_HOST or APP_PUBLIC_BASE_URL — email links will be broken');
        return '';
    }
    return (string) $SITE_PROTOCOL . $hostname . (string) $SERVER_PATH;
}

$DEFAULT_COOKIE_OPTIONS = [
    'expires' => time() + (60 * $SESSION_TIMEOUT),
    'path' => $COOKIE_PATH,
    'domain' => '',
    // Allow Secure=false if $NO_HTTPS is true (APP_SERVE_HTTP_ONLY in env)
    'secure' => $NO_HTTPS ? false : true,
    'httponly' => true,
    // Lax so cookie is sent when browser follows redirect after login (e.g. to /manage/users/)
    'samesite' => 'Lax'
];

// Initialize OIDC if enabled
init_oidc_config();

// Check OIDC authentication first
if (require_oidc_auth()) {
    redirect_to_oidc_if_required();
} else {
    // One-time URL token: when cookies are not sent on redirect, this authenticates and sets cookies
    if (!empty($_GET['auth_tok'])) {
        tryConsumeOneTimeAuthToken();
    }
    validatePasskeyCookie();
}

######################################################

/**
 * Return the writable state directory used for setup and rate-limit files.
 *
 * Defaults to /var/lib/ldap_user_manager; override with APP_STATE_DIR env var.
 * Creates the directory (with group-writable permissions 0775) if it doesn't
 * exist yet; logs a warning and falls back to sys_get_temp_dir() if that fails.
 */
function lumStateDir(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $env = getenv('APP_STATE_DIR');
    $dir = ($env !== false && $env !== '') ? rtrim($env, '/') : '/var/lib/ldap_user_manager';

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log("lumStateDir: could not create state directory $dir; falling back to " . sys_get_temp_dir());
            $dir = sys_get_temp_dir();
        }
    }

    $resolved = $dir;
    return $resolved;
}

######################################################

/**
 * Generates a cryptographically secure random passkey for session management.
 *
 * @return string 64-character hexadecimal string (256 bits of entropy)
 */
function generatePasskey(): string
{
    return bin2hex(random_bytes(32));
}

######################################################

/**
 * Returns the filesystem path for a session file by hash.
 *
 * @param string $session_hash SHA-256 hash of the user identifier
 * @return string Full path to the session file
 */
function getSessionFilePath(string $session_hash): string
{
    global $SESSION_SAVE_PATH;
    return $SESSION_SAVE_PATH . '/session_' . $session_hash;
}

/**
 * Encrypt the user's LDAP password for storage in the PHP session; the decryption key is stored only in orf_cookie.
 * Returns 64 hex chars, or null if libsodium is unavailable.
 */
function captureUserLdapCredentials(string $user_dn, string $password): ?string
{
    if (!function_exists('sodium_crypto_secretbox') || !function_exists('sodium_crypto_secretbox_open')) {
        return null;
    }
    $key = random_bytes(32);
    $nonce = random_bytes(24);
    $ct = sodium_crypto_secretbox($password, $nonce, $key);
    $_SESSION['lum_ldap_user_dn'] = $user_dn;
    $_SESSION['lum_ldap_pwd_enc'] = base64_encode($nonce . $ct);

    return bin2hex($key);
}

/**
 * @return array{dn: string, password: string}|null
 */
function getUserLdapCredentials(): ?array
{
    if (empty($_SESSION['lum_ldap_user_dn']) || empty($_SESSION['lum_ldap_pwd_enc']) || !isset($_COOKIE['orf_cookie'])) {
        return null;
    }
    if (!is_string($_COOKIE['orf_cookie']) || !function_exists('sodium_crypto_secretbox_open')) {
        return null;
    }
    $parts = explode(':', $_COOKIE['orf_cookie'], 3);
    if (count($parts) < 3 || $parts[2] === '') {
        return null;
    }
    $key = @hex2bin($parts[2]);
    if ($key === false || strlen($key) !== 32) {
        return null;
    }
    $raw = base64_decode((string) $_SESSION['lum_ldap_pwd_enc'], true);
    if ($raw === false || strlen($raw) < 25) {
        return null;
    }
    $nonce = substr($raw, 0, 24);
    $ciphertext = substr($raw, 24);
    $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    if ($plain === false) {
        clearUserLdapCredentials();

        return null;
    }

    return ['dn' => (string) $_SESSION['lum_ldap_user_dn'], 'password' => $plain];
}

/**
 * Remove encrypted LDAP password material from the session.
 */
function clearUserLdapCredentials(): void
{
    unset($_SESSION['lum_ldap_user_dn'], $_SESSION['lum_ldap_pwd_enc']);
}

/**
 * Return the current request's LDAP link for /manage: user bind when credentials exist, else admin bind.
 * Cached for one HTTP request. Sets $GLOBALS['lumManageLdapLink'].
 *
 * @return resource|\LDAP\Connection|false
 */
function getManageLdapConnection()
{
    static $requestCached = null;
    static $resolved = false;
    if ($resolved) {
        return $requestCached;
    }
    $resolved = true;
    global $log_prefix;

    // LDAP_FALLBACK_ADMIN_ON_FAILED_USER_BIND (default true):
    // When true  — if user-bind fails at the LDAP bind level, fall back to admin bind so the
    //              session can continue with reduced LDAP accountability (current default).
    // When false — if user-bind fails, return false for data connections; write operations will
    //              fail visibly. Reads still work (they use open_ldap_connection() directly).
    $allowFallback = strcasecmp((string) (getenv('LDAP_FALLBACK_ADMIN_ON_FAILED_USER_BIND') ?: 'true'), 'true') === 0;

    $creds = getUserLdapCredentials();
    if ($creds !== null) {
        $uconn = open_ldap_connection_as($creds['dn'], $creds['password'], false);
        if ($uconn === false) {
            if ($allowFallback) {
                error_log("{$log_prefix} manage: user LDAP bind failed, clearing stored creds; falling back to admin bind", 0);
                clearUserLdapCredentials();
                $requestCached = open_ldap_connection();
                $GLOBALS['lumManageLdapBindMode'] = 'admin_fallback';
            } else {
                error_log("{$log_prefix} manage: user LDAP bind failed; LDAP_FALLBACK_ADMIN_ON_FAILED_USER_BIND=false — write operations will be denied for this request", 0);
                clearUserLdapCredentials();
                $requestCached = false;
                $GLOBALS['lumManageLdapBindMode'] = 'bind_failed';
            }
        } else {
            $requestCached = $uconn;
            $GLOBALS['lumManageLdapBindMode'] = 'user';
        }
    } else {
        $requestCached = open_ldap_connection();
        $GLOBALS['lumManageLdapBindMode'] = 'admin';
    }
    $GLOBALS['lumManageLdapLink'] = $requestCached;

    return $requestCached;
}

/**
 * @return 'user'|'admin'|'admin_fallback'|'bind_failed'|null null before first getManageLdapConnection() in this request
 */
function lumGetManageLdapBindMode(): ?string
{
    if (!isset($GLOBALS['lumManageLdapBindMode']) || !is_string($GLOBALS['lumManageLdapBindMode'])) {
        return null;
    }
    return match ($GLOBALS['lumManageLdapBindMode']) {
        'user', 'admin', 'admin_fallback', 'bind_failed' => $GLOBALS['lumManageLdapBindMode'],
        default => null,
    };
}

/**
 * Shown on /manage when APP_DEBUG_MANAGE_LDAP_BIND=true and user is global admin or maintainer.
 */
function lumRenderManageLdapBindDebugBar(): void
{
    if (!defined('LUM_MANAGE_CONTEXT') || !LUM_MANAGE_CONTEXT) {
        return;
    }
    if (strcasecmp((string) (getenv('APP_DEBUG_MANAGE_LDAP_BIND') ?: ''), 'true') !== 0) {
        return;
    }
    global $IS_ADMIN, $IS_MAINTAINER, $VALIDATED;
    if ($VALIDATED !== true || (!$IS_ADMIN && !$IS_MAINTAINER)) {
        return;
    }
    if (!function_exists('getManageLdapConnection') || !function_exists('t')) {
        return;
    }
    getManageLdapConnection();
    $mode = lumGetManageLdapBindMode() ?? 'unknown';
    $modeLabel = match ($mode) {
        'user'         => t('manage.debug.ldap_bind_user'),
        'admin'        => t('manage.debug.ldap_bind_admin'),
        'admin_fallback' => t('manage.debug.ldap_bind_admin_fallback'),
        'bind_failed'  => t('manage.debug.ldap_bind_failed'),
        default        => $mode,
    };
    ?>
  <div class="container-fluid py-1 px-0 border-bottom bg-warning-subtle small">
    <div class="container d-flex flex-wrap align-items-center gap-2 text-muted">
      <strong class="text-dark"><?php echo htmlspecialchars(t('manage.debug.ldap_bind_heading'), ENT_QUOTES, 'UTF-8'); ?></strong>
      <code><?php echo htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8'); ?></code>
    </div>
  </div>
    <?php
}

/**
 * After login, if the account is administratively disabled in LDAP, end the web session. Call from /manage bootstrap only.
 */
function lumEnforceManageSessionAccountActive(): void
{
    if (!defined('LUM_MANAGE_CONTEXT') || !LUM_MANAGE_CONTEXT) {
        return;
    }
    global $USER_DN, $VALIDATED;
    if (empty($USER_DN) || $VALIDATED !== true) {
        return;
    }
    if (!function_exists('is_user_account_disabled') || !function_exists('getManageLdapConnection')) {
        return;
    }
    $conn = getManageLdapConnection();
    if ($conn === false) {
        return;
    }
    $r = @ldap_read($conn, (string) $USER_DN, '(objectClass=*)', ['pwdAccountLockedTime']);
    if ($r === false) {
        return;
    }
    $ent = @ldap_get_entries($conn, $r);
    if (!is_array($ent) || (int) ($ent['count'] ?? 0) < 1) {
        return;
    }
    $entry = $ent[0];
    if (!is_array($entry)) {
        return;
    }
    if (is_user_account_disabled($entry)) {
        if (function_exists('logOut')) {
            logOut('auto');
        }
    }
}

/**
 * Store auth in PHP session so the next request can restore login when orf_cookie is not sent (e.g. after 302).
 */
function setLumSessionAuth($user_id, $is_admin, $is_maintainer, $is_org_admin, $org_name, $org_uuid, $display_name = null): void
{
    global $SESSION_TIMEOUT;
    $expiry = time() + (60 * (int) $SESSION_TIMEOUT);
    $_SESSION['lum_user_id'] = $user_id;
    $_SESSION['lum_is_admin'] = $is_admin ? 1 : 0;
    $_SESSION['lum_is_maintainer'] = $is_maintainer ? 1 : 0;
    $_SESSION['lum_is_org_admin'] = $is_org_admin ? 1 : 0;
    $_SESSION['lum_org_name'] = $org_name;
    $_SESSION['lum_org_uuid'] = $org_uuid;
    $_SESSION['lum_display_name'] = $display_name;
    $_SESSION['lum_expiry'] = $expiry;
}

/**
 * Create a one-time auth token and return it. Used in redirect URLs so the next request can authenticate without cookies.
 * @param string|null $display_name Optional display name (mail/cn) for menu; stored in token so menu shows it after redirect.
 */
function createOneTimeAuthToken($user_id, $is_admin, $is_maintainer, $is_org_admin, $org_name = null, $org_uuid = null, $display_name = null): string
{
    global $SESSION_SAVE_PATH;
    $token = bin2hex(random_bytes(24));
    $expiry = time() + 120;
    // LDAP may return false for "no org"; only encode strings to avoid base64_encode(bool) TypeError
    $enc_org_name = (is_string($org_name) && $org_name !== '') ? base64_encode($org_name) : '';
    $enc_org_uuid = (is_string($org_uuid) && $org_uuid !== '') ? base64_encode($org_uuid) : '';
    $enc_display = (is_string($display_name) && $display_name !== '') ? base64_encode($display_name) : '';
    $line = implode('|', [
        $user_id,
        $is_admin ? '1' : '0',
        $is_maintainer ? '1' : '0',
        $is_org_admin ? '1' : '0',
        $enc_org_name,
        $enc_org_uuid,
        (string) $expiry,
        $enc_display
    ]);
    $path = $SESSION_SAVE_PATH . '/auth_tok_' . $token;
    if (@file_put_contents($path, $line) !== false) {
        @chmod($path, 0600);
    }
    return $token;
}

/**
 * If auth_tok is in GET and valid, restore auth, set cookies/session, redirect to same URL without token. Exits on success.
 */
function tryConsumeOneTimeAuthToken(): void
{
    global $SESSION_SAVE_PATH, $SERVER_PATH, $VALIDATED, $USER_ID, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_ORG_UUID, $USER_DISPLAY_NAME;
    $token = $_GET['auth_tok'] ?? '';
    if (!is_string($token) || strlen($token) !== 48 || !ctype_xdigit($token)) {
        return;
    }
    $path = $SESSION_SAVE_PATH . '/auth_tok_' . $token;
    if (!is_file($path)) {
        return;
    }
    $line = @file_get_contents($path);
    @unlink($path);
    if ($line === false || $line === '') {
        return;
    }
    $parts = explode('|', $line, 8);
    if (count($parts) < 7) {
        return;
    }
    $expiry = (int) $parts[6];
    if (time() >= $expiry) {
        return;
    }
    $user_id = $parts[0];
    $IS_ADMIN = ($parts[1] === '1');
    $IS_MAINTAINER = ($parts[2] === '1');
    $IS_ORG_ADMIN = ($parts[3] === '1');
    $USER_ORG_NAME = $parts[4] !== '' ? base64_decode($parts[4], true) : null;
    $USER_ORG_UUID = $parts[5] !== '' ? base64_decode($parts[5], true) : null;
    $USER_ORG_NAME = ($USER_ORG_NAME !== false) ? $USER_ORG_NAME : null;
    $USER_ORG_UUID = ($USER_ORG_UUID !== false) ? $USER_ORG_UUID : null;
    $display_name = (isset($parts[7]) && $parts[7] !== '') ? base64_decode($parts[7], true) : null;
    $USER_DISPLAY_NAME = (is_string($display_name) && $display_name !== false) ? $display_name : null;
    $USER_ID = $user_id;
    $VALIDATED = true;
    // Keep LDAP user-bind key from the login response cookie (3rd segment). Without it, setPasskeyCookie
    // would emit a 2-segment orf cookie and getUserLdapCredentials() could not decrypt the session copy.
    $ldapBindKeyHex = null;
    if (isset($_COOKIE['orf_cookie']) && is_string($_COOKIE['orf_cookie'])) {
        $oc = explode(':', $_COOKIE['orf_cookie'], 3);
        if (
            count($oc) === 3
            && (string) $oc[0] === (string) $user_id
            && $oc[2] !== ''
            && strlen($oc[2]) === 64
            && ctype_xdigit($oc[2])
        ) {
            $ldapBindKeyHex = $oc[2];
        }
    }
    setPasskeyCookie($user_id, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_ORG_UUID, $ldapBindKeyHex);
    setLumSessionAuth($user_id, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_ORG_UUID, $USER_DISPLAY_NAME);
    // Redirect to same path/query without auth_tok (getBaseUrl() already includes app path)
    $uri = $_SERVER['REQUEST_URI'];
    $basePath = rtrim($SERVER_PATH, '/');
    $relative = ($basePath !== '' && strpos($uri, $basePath) === 0)
        ? substr($uri, strlen($basePath)) : ('/' . ltrim($uri, '/'));
    $relative = ltrim($relative, '/');
    $q = strpos($relative, '?');
    if ($q !== false) {
        parse_str(substr($relative, $q + 1), $params);
        unset($params['auth_tok']);
        $relative = substr($relative, 0, $q) . (count($params) ? '?' . http_build_query($params) : '');
    }
    header('Location: ' . getBaseUrl() . $relative);
    exit;
}

/**
 * Sets a passkey cookie for user authentication
 *
 * @param string $user_id User identifier
 * @param bool $is_admin Whether user is admin
 * @param bool $is_maintainer Whether user is maintainer
 * @param bool $is_org_admin Whether user is organization admin
 * @param string|null $org_name Organization name
 * @param string|null $org_uuid Organization UUID
 * @param string|null $ldapBindKeyHex Optional; when set, orf_cookie is user:passkey:hexKey for decrypting stored LDAP password in session
 * @return void
 */
function setPasskeyCookie($user_id, $is_admin, $is_maintainer = false, $is_org_admin = false, $org_name = null, $org_uuid = null, $ldapBindKeyHex = null)
{

    # Create a random value, store it locally and set it in a cookie.

    global $SESSION_TIMEOUT, $VALIDATED, $USER_ID, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_ORG_UUID, $log_prefix, $SESSION_DEBUG, $DEFAULT_COOKIE_OPTIONS, $SESSION_SAVE_PATH;

    $passkey = generatePasskey();
    $this_time = time();
    $admin_val = 0;
    $maintainer_val = 0;
    $org_admin_val = 0;

    if ($is_admin === true) {
        $admin_val = 1;
        $IS_ADMIN = true;
    }

    if ($is_maintainer === true) {
        $maintainer_val = 1;
        $IS_MAINTAINER = true;
    }

    if ($is_org_admin === true) {
        $org_admin_val = 1;
        $IS_ORG_ADMIN = true;
    }

    if ($org_name) {
        $USER_ORG_NAME = $org_name;
    }

    if ($org_uuid) {
        $USER_ORG_UUID = $org_uuid;
    }

    // Clean up any existing session files for this user
    // Use a hash of the user_id to avoid filesystem issues with special characters
    $session_hash = hash('sha256', $user_id ?? '');
    $old_session_file = getSessionFilePath($session_hash);
    if (file_exists($old_session_file)) {
        unlink($old_session_file);
    }

    // Store session data: passkey:admin:maintainer:org_admin:time:org_name:org_uuid
    $session_data = "$passkey:$admin_val:$maintainer_val:$org_admin_val:$this_time";
    if ($org_name) {
        $session_data .= ":" . base64_encode($org_name);
    }
    if ($org_uuid) {
        $session_data .= ":" . base64_encode($org_uuid);
    }

    // Ensure session directory exists and is writable (e.g. when SESSION_SAVE_PATH is not /tmp)
    if (!is_dir($SESSION_SAVE_PATH)) {
        @mkdir($SESSION_SAVE_PATH, 0700, true);
    }
    $written = @file_put_contents($old_session_file, $session_data);
    if ($written === false) {
        error_log("$log_prefix Session: failed to write session file to {$old_session_file} (check SESSION_SAVE_PATH={$SESSION_SAVE_PATH} exists and is writable by the web server)");
    }
    $cookie_opts = is_array($DEFAULT_COOKIE_OPTIONS) ? $DEFAULT_COOKIE_OPTIONS : [
        'expires' => time() + (60 * (int) ($SESSION_TIMEOUT ?? 60)),
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    $orfValue = (string) $user_id . ':' . $passkey;
    if (is_string($ldapBindKeyHex) && $ldapBindKeyHex !== '') {
        $orfValue .= ':' . $ldapBindKeyHex;
    }
    setcookie('orf_cookie', $orfValue, $cookie_opts);
    $sessto_cookie_opts = $cookie_opts;
    $sessto_cookie_opts['expires'] = $this_time + (60 * (int) ($SESSION_TIMEOUT ?? 60));
    setcookie('sessto_cookie', (string)($this_time + (60 * $SESSION_TIMEOUT)), $sessto_cookie_opts);

    if ($SESSION_DEBUG == true) {
        error_log("$log_prefix Session: user $user_id validated (IS_ADMIN={$IS_ADMIN}, IS_MAINTAINER={$IS_MAINTAINER}, IS_ORG_ADMIN={$IS_ORG_ADMIN}, ORG={$org_name}, ORG_UUID={$org_uuid}), sent orf_cookie to the browser.", 0);
    }
    $VALIDATED = true;

    // Regenerate session ID on login to prevent session fixation
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    // Store auth in PHP session so next request can restore if orf_cookie is not sent (e.g. after 302)
    setLumSessionAuth($user_id, $is_admin, $is_maintainer, $is_org_admin, $org_name, $org_uuid, null);

    // Store user info in session for additional security
    $_SESSION['user_id'] = $user_id;
    $_SESSION['is_admin'] = $is_admin;
    $_SESSION['is_maintainer'] = $is_maintainer;
    $_SESSION['is_org_admin'] = $is_org_admin;
    $_SESSION['org_name'] = $org_name;
    $_SESSION['org_uuid'] = $org_uuid;
    $_SESSION['login_time'] = $this_time;
    $_SESSION['last_activity'] = $this_time;
}

######################################################

function validatePasskeyCookie()
{

    global $SESSION_TIMEOUT, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_ORG_UUID, $USER_ID, $USER_DN, $USER_DISPLAY_NAME, $VALIDATED, $log_prefix, $SESSION_TIMED_OUT, $SESSION_DEBUG, $LDAP, $currentUserGroups;

    $this_time = time();
    $VALIDATED = false;
    $IS_ADMIN = false;
    $IS_MAINTAINER = false;
    $IS_ORG_ADMIN = false;
    $USER_ORG_NAME = null;
    $USER_ORG_UUID = null;
    $USER_DN = null;
    $USER_DISPLAY_NAME = null;

    // Fallback: restore auth from PHP session when orf_cookie was not sent (e.g. after 302 to /manage/users/)
    if (!empty($_SESSION['lum_user_id']) && !empty($_SESSION['lum_expiry']) && $this_time < (int) $_SESSION['lum_expiry']) {
        $USER_ID = $_SESSION['lum_user_id'];
        $VALIDATED = true;
        $IS_ADMIN = !empty($_SESSION['lum_is_admin']);
        $IS_MAINTAINER = !empty($_SESSION['lum_is_maintainer']);
        $IS_ORG_ADMIN = !empty($_SESSION['lum_is_org_admin']);
        $USER_ORG_NAME = $_SESSION['lum_org_name'] ?? null;
        $USER_ORG_UUID = $_SESSION['lum_org_uuid'] ?? null;
        $USER_DISPLAY_NAME = $_SESSION['lum_display_name'] ?? null;
        if ($SESSION_DEBUG) {
            error_log("$log_prefix Session: restored from PHP session for user {$USER_ID}");
        }
        return;
    }

    if (isset($_COOKIE['orf_cookie'])) {
        $orfCookieParts = explode(":", (string) $_COOKIE['orf_cookie'], 3);
        $user_id = $orfCookieParts[0] ?? '';
        $c_passkey = $orfCookieParts[1] ?? '';
        if ($user_id === '' || $c_passkey === '') {
            if ($SESSION_DEBUG == true) {
                error_log("$log_prefix Session: orf_cookie missing user_id or passkey segment", 0);
            }
            return;
        }

      // Validate user_id format - allow UUIDs and standard usernames
        if (!preg_match('/^[a-zA-Z0-9@._-]+$/', $user_id) && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $user_id)) {
            if ($SESSION_DEBUG == true) {
                error_log("$log_prefix Session: Invalid user_id format in cookie", 0);
            }
            return;
        }

      // Use a hash of the user_id to find the session file
        $session_hash = hash('sha256', $user_id ?? '');
        $session_file_path = getSessionFilePath($session_hash);
        $session_file = @ file_get_contents($session_file_path);
        if (!$session_file) {
            if ($SESSION_DEBUG == true) {
                error_log("$log_prefix Session: orf_cookie was sent by the client but the session file wasn't found at {$session_file_path}", 0);
            }
        } else {
            $session_fields = explode(":", $session_file);
            $field_count = count($session_fields);

          // Handle backward compatibility for different session formats
            if ($field_count === 3) {
              // Old format: passkey:admin:time
                list($f_passkey,$f_is_admin,$f_time) = $session_fields;
                $f_is_maintainer = 0; // Default to not maintainer for old sessions
                $f_is_org_admin = 0; // Default to not org admin for old sessions
                $f_org_name = null; // No org name in old format
                $f_org_uuid = null; // No org uuid in old format
            } elseif ($field_count === 4) {
              // Format: passkey:admin:maintainer:time
                list($f_passkey,$f_is_admin,$f_is_maintainer,$f_time) = $session_fields;
                $f_is_org_admin = 0; // Default to not org admin for this format
                $f_org_name = null; // No org name in this format
                $f_org_uuid = null; // No org uuid in this format
            } elseif ($field_count === 5) {
              // Format: passkey:admin:maintainer:org_admin:time
                list($f_passkey,$f_is_admin,$f_is_maintainer,$f_is_org_admin,$f_time) = $session_fields;
                $f_org_name = null; // No org name in this format
                $f_org_uuid = null; // No org uuid in this format
            } elseif ($field_count === 6) {
              // Format: passkey:admin:maintainer:org_admin:time:org_name
                list($f_passkey,$f_is_admin,$f_is_maintainer,$f_is_org_admin,$f_time,$f_org_name_encoded) = $session_fields;
                $f_org_name = base64_decode($f_org_name_encoded);
                $f_org_uuid = null; // No org uuid in this format
            } elseif ($field_count === 7) {
              // New format: passkey:admin:maintainer:org_admin:time:org_name:org_uuid
                list($f_passkey,$f_is_admin,$f_is_maintainer,$f_is_org_admin,$f_time,$f_org_name_encoded,$f_org_uuid_encoded) = $session_fields;
                $f_org_name = base64_decode($f_org_name_encoded);
                $f_org_uuid = base64_decode($f_org_uuid_encoded);
            } else {
              // Invalid format
                if ($SESSION_DEBUG == true) {
                    error_log("$log_prefix Session: Invalid session file format for user $user_id - expected 3-7 fields, got $field_count", 0);
                }
                @unlink(getSessionFilePath($session_hash));
                return;
            }

          // Validate session data format
            if (!is_numeric($f_time) || !is_numeric($f_is_admin) || !is_numeric($f_is_maintainer) || !is_numeric($f_is_org_admin)) {
                if ($SESSION_DEBUG == true) {
                    error_log("$log_prefix Session: Invalid session file data types for user $user_id", 0);
                }
              // Clean up corrupted session file
                @unlink(getSessionFilePath($session_hash));
                return;
            }

          // Check if session has expired
            if ($this_time >= $f_time + (60 * $SESSION_TIMEOUT)) {
                if ($SESSION_DEBUG == true) {
                    error_log("$log_prefix Session: Session expired for user $user_id", 0);
                }
                @unlink(getSessionFilePath($session_hash));
                $SESSION_TIMED_OUT = true;
                return;
            }

          // Validate passkey
            if (!empty($c_passkey) && hash_equals((string) $f_passkey, (string) $c_passkey)) {
                if ($f_is_admin == 1) {
                    $IS_ADMIN = true;
                }
                if ($f_is_maintainer == 1) {
                    $IS_MAINTAINER = true;
                }
                if ($f_is_org_admin == 1) {
                    $IS_ORG_ADMIN = true;
                }
                if ($f_org_name) {
                    $USER_ORG_NAME = $f_org_name;
                }
                if ($f_org_uuid) {
                    $USER_ORG_UUID = $f_org_uuid;
                }
                $VALIDATED = true;
                $USER_ID = $user_id;

              // Update last activity time and maintain all session data
                $new_session_data = "$f_passkey:$f_is_admin:$f_is_maintainer:$f_is_org_admin:$f_time";
                if ($f_org_name) {
                    $new_session_data .= ":" . base64_encode($f_org_name);
                }
                if ($f_org_uuid) {
                    $new_session_data .= ":" . base64_encode($f_org_uuid);
                }
                @ file_put_contents(getSessionFilePath($session_hash), $new_session_data);

                if ($SESSION_DEBUG == true) {
                    error_log("$log_prefix Setup session: Cookie and session file values match for user {$user_id} - VALIDATED (ADMIN = {$IS_ADMIN}, MAINTAINER = {$IS_MAINTAINER}, ORG_ADMIN = {$IS_ORG_ADMIN}, ORG = {$f_org_name}, ORG_UUID = {$f_org_uuid})", 0);
                }
                $preserveLdapKey = (isset($orfCookieParts[2]) && is_string($orfCookieParts[2]) && $orfCookieParts[2] !== '') ? $orfCookieParts[2] : null;
                setPasskeyCookie($USER_ID, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_ORG_UUID, $preserveLdapKey);
              // Populate currentUserGroups and display name from LDAP
                $ldap_connection = open_ldap_connection();
                $user_dn = get_user_dn_from_identifier($ldap_connection, $USER_ID);
                if ($user_dn) {
                    $currentUserGroups = ldap_user_group_membership($ldap_connection, $user_dn);
                    $USER_DN = $user_dn;
                    // Resolve display name (cn preferred) for menu
                    $read = @ldap_read($ldap_connection, $user_dn, '(objectClass=*)', ['mail', 'cn']);
                    if ($read) {
                        $entries = ldap_get_entries($ldap_connection, $read);
                        if (!empty($entries[0])) {
                            $e = $entries[0];
                            if (!empty($e['cn'][0])) {
                                $USER_DISPLAY_NAME = $e['cn'][0];
                            } elseif (!empty($e['mail'][0])) {
                                $USER_DISPLAY_NAME = $e['mail'][0];
                            }
                        }
                    }
                } else {
                    $currentUserGroups = array();
                }
                ldap_close($ldap_connection);
                // Store in PHP session so next request can restore auth if orf_cookie is not sent (e.g. after 302)
                setLumSessionAuth($USER_ID, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_ORG_UUID, $USER_DISPLAY_NAME);
            } else {
                if ($SESSION_DEBUG == true) {
                    $this_error = "$log_prefix Session: orf_cookie was sent by the client and the session file was found at {$session_file_path}, but";
                    if (empty($c_passkey)) {
                        $this_error .= " the cookie passkey wasn't set;";
                    }
                    if ($c_passkey != $f_passkey) {
                        $this_error .= " the session file passkey didn't match the cookie passkey;";
                    }
                    $this_error .= ' (passkey mismatch; cookie and session file contents redacted)';
                    error_log($this_error, 0);
                }
            }
        }
    } else {
        if ($SESSION_DEBUG == true) {
            error_log("$log_prefix Session: orf_cookie wasn't sent by the client.", 0);
        }
        if (isset($_COOKIE['sessto_cookie'])) {
            $this_session_timeout = $_COOKIE['sessto_cookie'];
            if ($this_time >= $this_session_timeout) {
                $SESSION_TIMED_OUT = true;
                if ($SESSION_DEBUG == true) {
                    error_log("$log_prefix Session: The session had timed-out (over $SESSION_TIMEOUT mins idle).", 0);
                }
            }
        }
    }
}

######################################################

function setSetupCookie()
{

 # Create a random value, store it locally and set it in a cookie.

    global $SESSION_TIMEOUT, $IS_SETUP_ADMIN, $log_prefix, $SESSION_DEBUG, $DEFAULT_COOKIE_OPTIONS;

    $passkey = generatePasskey();
    $this_time = time();

    $IS_SETUP_ADMIN = true;

    @ file_put_contents(lumStateDir() . '/ldap_setup', "$passkey:$this_time");

    setcookie('setup_cookie', $passkey, $DEFAULT_COOKIE_OPTIONS);

    if ($SESSION_DEBUG == true) {
        error_log("$log_prefix Setup session: sent setup_cookie to the client.", 0);
    }

 // Regenerate session ID on setup login to prevent session fixation
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

######################################################

function validateSetupCookie()
{

    global $SESSION_TIMEOUT, $IS_SETUP_ADMIN, $log_prefix, $SESSION_DEBUG;

    if (isset($_COOKIE['setup_cookie'])) {
        $c_passkey = $_COOKIE['setup_cookie'];
        $session_file = @file_get_contents(lumStateDir() . '/ldap_setup');
        if ($session_file === false || $session_file === '') {
            $IS_SETUP_ADMIN = false;
            if ($SESSION_DEBUG == true) {
                error_log("$log_prefix Setup session: setup_cookie was sent by the client but the session file wasn't found at " . lumStateDir() . "/ldap_setup", 0);
            }
        } else {
            $session_parts = explode(':', $session_file, 2);
            if (count($session_parts) < 2) {
                $IS_SETUP_ADMIN = false;
                if ($SESSION_DEBUG == true) {
                    error_log("$log_prefix Setup session: Invalid session file format at " . lumStateDir() . "/ldap_setup", 0);
                }
            } else {
                list($f_passkey, $f_time) = $session_parts;
                $this_time = time();
                if (!empty($c_passkey) && hash_equals((string) $f_passkey, (string) $c_passkey) && $this_time < $f_time + (60 * $SESSION_TIMEOUT)) {
                    $IS_SETUP_ADMIN = true;
                    if ($SESSION_DEBUG == true) {
                        error_log("$log_prefix Setup session: Cookie and session file values match - VALIDATED ", 0);
                    }
                    setSetupCookie();
                } elseif ($SESSION_DEBUG == true) {
                    $this_error = "$log_prefix Setup session: setup_cookie was sent by the client and the session file was found at " . lumStateDir() . "/ldap_setup, but";
                    if (empty($c_passkey)) {
                        $this_error .= " the cookie passkey wasn't set;";
                    }
                    if ($c_passkey != $f_passkey) {
                        $this_error .= " the session file passkey didn't match the cookie passkey;";
                    }
                    $this_error .= ' (passkey mismatch; cookie and session file contents redacted)';
                    error_log($this_error, 0);
                }
            }
        }
    } elseif ($SESSION_DEBUG == true) {
        error_log("$log_prefix Session: setup_cookie wasn't sent by the client.", 0);
    }
}

######################################################

function logOut(string $method = 'normal'): void
{
    global $USER_ID, $DEFAULT_COOKIE_OPTIONS;

    $this_time = time();

    // Expire both app cookies by setting their expiry to the past
    $expired_opts            = $DEFAULT_COOKIE_OPTIONS;
    $expired_opts['expires'] = $this_time - 20000;

    setcookie('orf_cookie', '', $expired_opts);
    setcookie('sessto_cookie', '', $expired_opts);

    clearUserLdapCredentials();

    // Remove the server-side session file
    $session_hash = hash('sha256', $USER_ID ?? '');
    @unlink(getSessionFilePath($session_hash));

    // Clear ALL auth-related PHP session keys (not just lum_* prefix)
    $auth_keys = [
        'user_id', 'is_admin', 'is_maintainer', 'is_org_admin',
        'org_name', 'org_uuid', 'login_time', 'last_activity',
    ];
    foreach ($auth_keys as $k) {
        unset($_SESSION[$k]);
    }
    // Also clear the lum_* prefixed session fallback keys
    foreach (array_keys($_SESSION) as $k) {
        if (is_string($k) && strpos($k, 'lum_') === 0) {
            unset($_SESSION[$k]);
        }
    }

    // Expire the PHP session cookie itself
    if (session_status() === PHP_SESSION_ACTIVE) {
        $session_params  = session_get_cookie_params();
        $session_name    = (string) session_name();
        // Normalise samesite to one of the values PHP's setcookie() accepts
        $raw_samesite    = (string) ($session_params['samesite']);
        $allowed_samesite = ['Lax', 'lax', 'None', 'none', 'Strict', 'strict'];
        $samesite_value  = in_array($raw_samesite, $allowed_samesite, true) ? $raw_samesite : 'Lax';
        setcookie(
            $session_name,
            '',
            [
                'expires'  => $this_time - 20000,
                'path'     => $session_params['path'],
                'domain'   => $session_params['domain'],
                'secure'   => $session_params['secure'],
                'httponly' => $session_params['httponly'],
                'samesite' => $samesite_value,
            ]
        );
        session_unset();
        session_destroy();
    }

    $options = ($method === 'auto') ? '?logged_out' : '';
    header('Location: ' . getBaseUrl() . 'index.php' . $options);
}

######################################################

function renderHeader($title = "", $menu = true)
{

    global $SITE_NAME, $IS_ADMIN, $SENT_HEADERS, $SERVER_PATH, $CUSTOM_STYLES;

    if (empty($title)) {
        $title = $SITE_NAME;
    }

    $asset_base = getAssetBase();

 # Set security headers
    setSecurityHeaders();

 #Initialise the HTML output for the page.

    $htmlLang = htmlspecialchars(lum_current_locale(), ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="<?php echo $htmlLang; ?>">
<HEAD>
 <TITLE><?php print htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8'); ?></TITLE>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width, initial-scale=1">
 <link rel="stylesheet" href="<?php print htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>bootstrap/css/bootstrap.min.css">
 <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
 <style>
  .form-group{margin-bottom:1rem;}
  .navbar-user-name{
   display:inline-block;
   max-width:min(42vw, 280px);
   overflow:hidden;
   text-overflow:ellipsis;
   white-space:nowrap;
   vertical-align:bottom;
  }
  @media (max-width: 576px){
   .navbar-user-name{
    max-width:34vw;
   }
  }
 </style>
    <?php if ($CUSTOM_STYLES) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($CUSTOM_STYLES, ENT_QUOTES, 'UTF-8') . '">';
    } ?>
 <script src="<?php print htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>js/jquery-3.6.0.min.js"></script>
 <script src="<?php print htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>bootstrap/js/bootstrap.bundle.min.js"></script>
</HEAD>
<BODY>
    <?php

    if ($menu == true) {
        renderMenu();
    }

    if (function_exists('lumRenderManageLdapBindDebugBar')) {
        lumRenderManageLdapBindDebugBar();
    }

    if (isset($_GET['logged_in'])) {
        ?>
  <script>
    window.setTimeout(function() { $(".alert").fadeTo(500, 0).slideUp(500, function(){ $(this).remove(); }); }, 10000);
  </script>
  <div class="alert alert-success alert-dismissible fade show">
    <p class="text-center mb-0"><?php echo htmlspecialchars(t('alert.logged_in'), ENT_QUOTES, 'UTF-8'); ?></p>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo htmlspecialchars(t('alert.close'), ENT_QUOTES, 'UTF-8'); ?>"></button>
  </div>
        <?php
    }
    $SENT_HEADERS = true;
}

/**
 * Return the CSP nonce for this request, generating it once per request.
 * Use this in templates: <script nonce="<?= getCspNonce() ?>">
 */
function getCspNonce(): string
{
    if (!isset($_SESSION['csp_nonce'])) {
        $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $_SESSION['csp_nonce'];
}

/**
 * Set security headers to prevent common web attacks
 */
function setSecurityHeaders()
{
    global $SECURITY_CONFIG;

    // Set security headers from configuration
    $headers = $SECURITY_CONFIG['security_headers'];

    // Prevent clickjacking
    header('X-Frame-Options: ' . $headers['x_frame_options']);

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: ' . $headers['x_content_type_options']);

    // Enable XSS protection
    header('X-XSS-Protection: ' . $headers['x_xss_protection']);

    // Referrer policy
    header('Referrer-Policy: ' . $headers['referrer_policy']);

    // Content Security Policy – inject per-request nonce so unsafe-inline is
    // not required.  The nonce is refreshed each time getCspNonce() is first
    // called for a request cycle (stored in session only for templating
    // convenience; the header is the authoritative value).
    $nonce = base64_encode(random_bytes(16));
    $_SESSION['csp_nonce'] = $nonce;
    $csp = $headers['content_security_policy'];
    // Replace 'unsafe-inline' directives with the nonce.
    $nonce_token = "'nonce-{$nonce}'";
    $csp = preg_replace(
        "/script-src\s+'unsafe-inline'/",
        "script-src {$nonce_token}",
        $csp
    ) ?? $csp;
    $csp = preg_replace(
        "/style-src\s+'unsafe-inline'/",
        "style-src {$nonce_token} 'unsafe-inline'",
        $csp
    ) ?? $csp;
    header('Content-Security-Policy: ' . $csp);

    // HSTS (only if HTTPS and enabled)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' && $headers['strict_transport_security']) {
        header('Strict-Transport-Security: ' . $headers['strict_transport_security']);
    }
}

/**
 * Emit baseline security headers for API / export / data-download endpoints
 * that do not render HTML (so the CSP nonce / X-Frame-Options logic in
 * setSecurityHeaders() may not be appropriate, but we still want the minimum
 * hardenening set).
 */
function setApiResponseHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

######################################################

function renderMenu()
{

 #Render the navigation menu.
 #The menu is dynamically rendered the $MODULES hash

    global $SITE_NAME, $MODULES, $THIS_MODULE, $VALIDATED, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ID, $USER_DISPLAY_NAME, $SERVER_PATH, $CUSTOM_LOGO, $LDAP_DEBUG;

    if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
        error_log("renderMenu: User roles - Admin: " . ($IS_ADMIN ? 'YES' : 'NO') . ", Maintainer: " . ($IS_MAINTAINER ? 'YES' : 'NO') . ", Org Admin: " . ($IS_ORG_ADMIN ? 'YES' : 'NO') . ", Validated: " . ($VALIDATED ? 'YES' : 'NO'));
    }

    ?>
  <nav class="navbar navbar-expand-lg navbar-light bg-light">
   <div class="container-fluid">
    <?php if ($CUSTOM_LOGO) {
        echo '<a class="navbar-brand d-flex align-items-center" href="/"><img src="' . htmlspecialchars((string) $CUSTOM_LOGO, ENT_QUOTES, 'UTF-8') . '" class="logo me-2" alt="' . htmlspecialchars(t('nav.logo_alt'), ENT_QUOTES, 'UTF-8') . '"></a>';
    }
    ?><a class="navbar-brand" href="/"><?php print $SITE_NAME ?></a>
     <ul class="navbar-nav me-auto">
     <?php
        foreach (is_array($MODULES) ? $MODULES : [] as $module => $access) {
         // Customize module names for better display
            $this_module_name = '';
            if ($module === 'manage') {
                $this_module_name = t('nav.manage');
            } else {
                $navKey = 'nav.' . (string) $module;
                $translated = t($navKey);
                if ($translated !== $navKey) {
                    $this_module_name = $translated;
                } else {
                    $module_str = (string) preg_replace('/_/', ' ', (string) ($module ?? ''));
                    $this_module_name = stripslashes(ucwords($module_str));
                }
            }

            $show_this_module = true;
            if ($VALIDATED == true) {
                if ($access == 'hidden_on_login') {
                    $show_this_module = false;
                }
                if ($access == 'admin' && $IS_ADMIN == false) {
                    $show_this_module = false;
                }
                if ($access == 'admin_maintainer_org_admin' && !$IS_ADMIN && !$IS_MAINTAINER && !$IS_ORG_ADMIN) {
                    $show_this_module = false;
                }
            } else {
                if ($access != 'hidden_on_login') {
                    $show_this_module = false;
                }
            }

            if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
                error_log("renderMenu: Module '$module' (access: $access) - show: " . ($show_this_module ? 'YES' : 'NO'));
            }

         #print "<p>$module - access is $access & show is $show_this_module</p>";
            if ($show_this_module == true) {
                $active_class = ($module == $THIS_MODULE) ? " active" : "";
                // Canonical module hrefs (single-word routes).
                if ($module === 'manage') {
                    // Use /manage/index.php so the request hits the app with cookie (avoids /manage/ redirect issues)
                    $module_href = $SERVER_PATH . 'manage/index.php';
                } elseif ($module === 'log_in') {
                    $module_href = $SERVER_PATH . 'login/';
                } elseif ($module === 'logOut') {
                    $module_href = $SERVER_PATH . 'logout/';
                } elseif ($module === 'change_password') {
                    $module_href = $SERVER_PATH . 'password/change/';
                } elseif ($module === 'request_account') {
                    $module_href = $SERVER_PATH . 'account/request/';
                } else {
                    // Fallback: module directory name (should not be used by canonical routing).
                    $module_href = $SERVER_PATH . $module . '/';
                }
                print '<li class="nav-item"><a class="nav-link' . $active_class . '" href="' . $module_href . '">' . $this_module_name . '</a></li>' . "\n";
            }
        }
        ?>
     </ul>
     <ul class="navbar-nav ms-auto flex-row align-items-center flex-nowrap">
      <li class="nav-item me-3">
        <span class="navbar-text navbar-user-name"><?php if (isset($USER_ID)) {
            print htmlspecialchars($USER_DISPLAY_NAME !== null && $USER_DISPLAY_NAME !== '' ? $USER_DISPLAY_NAME : $USER_ID);
                                                   } ?></span>
      </li>
      <li class="nav-item dropdown">
        <?php
            $assetBase = getAssetBase();
            $currentLocale = lum_current_locale();
            $localeOptions = lum_i18n_locale_options();
            $currentFlag = $currentLocale . '.svg';
            $currentNative = strtoupper($currentLocale);
        foreach ($localeOptions as $option) {
            if ($option['code'] === $currentLocale) {
                $currentFlag = $option['flag'];
                $currentNative = $option['native'];
                break;
            }
        }
        ?>
        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="langChooser" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="<?php echo htmlspecialchars($assetBase . 'flags/' . $currentFlag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="" width="18" height="12" class="me-2 border rounded-1">
            <span><?php echo htmlspecialchars($currentNative, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="langChooser">
            <?php foreach ($localeOptions as $option) { ?>
                <li>
                    <a class="dropdown-item d-flex align-items-center" href="<?php echo htmlspecialchars(lumBuildLanguageSwitchUrl($option['code']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <img src="<?php echo htmlspecialchars($assetBase . 'flags/' . $option['flag'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="" width="18" height="12" class="me-2 border rounded-1">
                        <span><?php echo htmlspecialchars($option['native'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                    </a>
                </li>
            <?php } ?>
        </ul>
      </li>
     </ul>
   </div>
  </nav>
    <?php
}

/**
 * Build relative URL for switching language while preserving current query params.
 */
function lumBuildLanguageSwitchUrl(string $locale): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $parts = parse_url($uri);
    $path = (string) ($parts['path'] ?? '/');
    $query = (string) ($parts['query'] ?? '');
    $params = [];
    if ($query !== '') {
        parse_str($query, $params);
    }
    $params['lang'] = $locale;
    $newQuery = http_build_query($params);
    return $newQuery === '' ? $path : ($path . '?' . $newQuery);
}

/**
 * Render language chooser block for pages without navbar usage.
 */
function renderLanguageChooserInline(): void
{
    $assetBase = getAssetBase();
    $localeOptions = lum_i18n_locale_options();
    ?>
<div class="text-center mb-3">
    <?php foreach ($localeOptions as $option) { ?>
        <a class="btn btn-sm btn-outline-secondary me-1 mb-1 d-inline-flex align-items-center" href="<?php echo htmlspecialchars(lumBuildLanguageSwitchUrl($option['code']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <img src="<?php echo htmlspecialchars($assetBase . 'flags/' . $option['flag'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="" width="18" height="12" class="me-2 border rounded-1">
            <span><?php echo htmlspecialchars($option['native'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
        </a>
    <?php } ?>
</div>
    <?php
}

######################################################

function renderFooter()
{

#Finish rendering an HTML page.

    ?>
 <footer class="mt-4 mb-3">
  <p class="text-center mb-0">Made with &#10084;&#65039; and help by &#129302;.</p>
 </footer>
 </BODY>
</html>
    <?php
}

######################################################

function setPageAccess($allowed_levels)
{

    global $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $IS_SETUP_ADMIN, $VALIDATED, $log_prefix, $SESSION_DEBUG, $SESSION_TIMED_OUT, $SERVER_PATH, $LDAP_DEBUG;

 # Enhanced access control function that accepts multiple access levels
 # and implements path-based restrictions with automatic redirects
 #
 # Parameters:
 # $allowed_levels: string or array of allowed access levels
 #   - 'setup': Setup administrator (only allowed under /setup)
 #   - 'admin': Global administrator (allowed everywhere except /setup)
 #   - 'maintainer': Maintainer (restricted access with specific exclusions)
 #   - 'org_admin': Organization administrator (very restricted access)
 #   - 'user': Any authenticated user
 #   - 'hidden_on_login': Guest-only pages (e.g. password reset/set); unauthenticated users are allowed,
 #     authenticated users are redirected to their default destination (same idea as /login).
 #
 # Special paths:
 # - /password/change and below: Available to any authenticated user
 # - /logout and below: Available to any authenticated user
 # - /login and below: Available to any non-authenticated user

 // Convert single level to array for consistent handling
    if (!is_array($allowed_levels)) {
        $allowed_levels = [$allowed_levels];
    }

 // Get current request path for path-based restrictions (relative to app base when in subpath)
    $current_path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $basePath = rtrim((string) ($SERVER_PATH ?? ''), '/');
    if ($basePath !== '' && strpos($current_path, $basePath) === 0) {
        $current_path = substr($current_path, strlen($basePath)) ?: '/';
    }
    $current_path = rtrim($current_path, '/');

    if ($LDAP_DEBUG) {
        error_log("$log_prefix setPageAccess: Checking access for path: $current_path");
        error_log("$log_prefix setPageAccess: Allowed levels: " . implode(', ', $allowed_levels));
        error_log("$log_prefix setPageAccess: User roles - Admin: " . ($IS_ADMIN ? 'YES' : 'NO') . ", Maintainer: " . ($IS_MAINTAINER ? 'YES' : 'NO') . ", Org Admin: " . ($IS_ORG_ADMIN ? 'YES' : 'NO') . ", Setup: " . ($IS_SETUP_ADMIN ? 'YES' : 'NO'));
    }

 // Special path handling for password change and logout
    if (strpos($current_path, '/password/change') === 0 || strpos($current_path, '/logout') === 0) {
      // These paths are available to any authenticated user
        if ($VALIDATED == true) {
            if ($LDAP_DEBUG) {
                  error_log("$log_prefix setPageAccess: Allowing access to $current_path (authenticated user)");
            }
            return;
        } else {
       // Redirect to login
            $reason = ($SESSION_TIMED_OUT == true) ? "session_timeout" : "unauthorised";
            header("Location: " . getBaseUrl() . "login/?$reason&redirect_to=" . getLoginRedirectToQueryValue());
            if ($SESSION_DEBUG == true) {
                error_log("$log_prefix setPageAccess: Redirecting unauthenticated user to login from $current_path");
            }
            exit(0);
        }
    }

    if (strpos($current_path, '/login') === 0) {
      // Login pages are available to any non-authenticated user
        if ($VALIDATED == true) {
         // User is already logged in, redirect to appropriate default
            $redirect_url = getDefaultRedirectForUser();
            if ($LDAP_DEBUG) {
                  error_log("$log_prefix setPageAccess: Logged in user accessing login page, redirecting to: $redirect_url");
            }
            header("Location: $redirect_url");
            exit(0);
        } else {
       // Allow access to login page
            if ($LDAP_DEBUG) {
                error_log("$log_prefix setPageAccess: Allowing access to login page (non-authenticated user)");
            }
            return;
        }
    }

 // Check if user has any of the allowed access levels
    $has_access = false;
    $user_level = null;

    foreach ($allowed_levels as $level) {
        switch ($level) {
            case 'setup':
                if ($IS_SETUP_ADMIN == true) {
                    $has_access = true;
                    $user_level = 'setup';
                    break;
                }
                break;

            case 'hidden_on_login':
                if ($VALIDATED == true) {
                    $redirect_url = getDefaultRedirectForUser();
                    header('Location: ' . $redirect_url);
                    exit(0);
                }
                $has_access = true;
                $user_level = 'hidden_on_login';
                break;

            case 'admin':
                if ($IS_ADMIN == true && $VALIDATED == true) {
                    $has_access = true;
                    $user_level = 'admin';
                    break;
                }
                break;

            case 'maintainer':
                if ($IS_MAINTAINER == true && $VALIDATED == true) {
                    // Path-based restrictions for maintainers
                    if (strpos($current_path, '/setup') === 0) {
                        // Maintainers cannot access setup
                        break;
                    }
                    if (strpos($current_path, '/manage/roles') === 0) {
                        // Role assignment is admin-only
                        break;
                    }
                    $has_access = true;
                    $user_level = 'maintainer';
                    break;
                }
                break;

            case 'org_admin':
                if ($IS_ORG_ADMIN == true && $VALIDATED == true) {
                  // Check path-based restrictions for organization admins
                    if (strpos($current_path, '/setup') === 0) {
                     // Org admins cannot access setup
                        break;
                    }
                  // Org admins can access organization pages (but will only see their own org)
                    if (strpos($current_path, '/manage/organizations') === 0) {
                        $has_access = true;
                        $user_level = 'org_admin';
                        break;
                    }
                    break;
                }
                break;

            case 'user':
                if ($VALIDATED == true) {
                    $has_access = true;
                    $user_level = 'user';
                    break;
                }
                break;
        }

        if ($has_access) {
            break;
        }
    }

 // If user has access, allow them to proceed
    if ($has_access) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix setPageAccess: Access granted to $current_path for user level: $user_level");
        }
        return;
    }

 // User doesn't have access, redirect to appropriate default view
    if ($LDAP_DEBUG) {
        error_log("$log_prefix setPageAccess: Access denied to $current_path, redirecting user");
    }

    $redirect_url = getDefaultRedirectForUser();
    header("Location: $redirect_url");

    if ($SESSION_DEBUG == true) {
        error_log("$log_prefix setPageAccess: UNAUTHORISED: redirecting user from $current_path to $redirect_url");
    }
    exit(0);
}

/**
 * Base URL for redirects (protocol-relative). Ensures a slash between host and path.
 */
function getBaseUrl(): string
{
    global $SERVER_PATH, $SERVER_HOSTNAME, $SITE_PROTOCOL;

    $path = trim((string) $SERVER_PATH, '/');

    // Prefer APP_PUBLIC_BASE_URL env var (already used by lumPublicSiteBaseUrl).
    // Fall back to SERVER_HOSTNAME from config, which is set from the
    // APP_HTTP_HOST environment variable — never from a request header.
    $fromEnv = trim((string) (getenv('APP_PUBLIC_BASE_URL') ?: ''));
    if ($fromEnv !== '') {
        $base = rtrim($fromEnv, '/');
        return ($base === '') ? '/' : ($base . '/' . ($path !== '' ? $path . '/' : ''));
    }

    $hostname = (string) ($SERVER_HOSTNAME ?? '');
    if ($hostname === '') {
        // Last resort: use the Host header but only in a development context.
        // In production this should never be reached because APP_HTTP_HOST is configured as SERVER_HOSTNAME.
        if (getenv('APP_ENV') === 'production') {
            error_log('getBaseUrl() called without APP_HTTP_HOST-derived hostname in production — returning relative URL');
            return '/' . ($path !== '' ? $path . '/' : '');
        }
        $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    $protocol = (string) ($SITE_PROTOCOL ?? '//');
    return $protocol . $hostname . '/' . ($path !== '' ? $path . '/' : '');
}

/**
 * Value for the login "redirect_to" query field: base64 of the full request path, then percent-encoded
 * (so e.g. '+' in base64 is not treated as a space in the URL or in application/x-www-form-urlencoded body).
 */
function getLoginRedirectToQueryValue(?string $requestUri = null): string
{
    $uri = ($requestUri !== null && $requestUri !== '') ? $requestUri : (string) ($_SERVER['REQUEST_URI'] ?? '');

    return rawurlencode(base64_encode($uri));
}

/**
 * Turn validateRedirectUrl() output (absolute path) into a full URL for getBaseUrl() (avoids
 * duplicating SERVER_PATH when the path already contains the app mount prefix, e.g. /app/manage/...).
 */
function buildPostAuthRedirectFromValidatedPath(string $decodedPath): string
{
    global $SERVER_PATH;
    if ($decodedPath === '') {
        return getBaseUrl();
    }
    $sp = rtrim((string) $SERVER_PATH, '/');
    if ($sp === '' || $sp === '/') {
        $rel = ltrim($decodedPath, '/');

        return getBaseUrl() . $rel;
    }
    if (strpos($decodedPath, $sp . '/') === 0) {
        $rel = (string) substr($decodedPath, strlen($sp) + 1);
    } elseif ($decodedPath === $sp) {
        $rel = '';
    } else {
        $rel = ltrim($decodedPath, '/');
    }

    return getBaseUrl() . $rel;
}

/**
 * Path prefix for static assets (CSS, JS). Always starts and ends with / so href/src work from any page.
 */
function getAssetBase(): string
{
    global $SERVER_PATH;
    $path = rtrim((string) ($SERVER_PATH ?? ''), '/');
    $base = ($path === '') ? '/' : '/' . ltrim($path, '/') . '/';
    return $base . 'assets/';
}

/**
 * Get the appropriate default redirect URL based on user's access level
 */
function getDefaultRedirectForUser()
{
    global $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $IS_SETUP_ADMIN, $VALIDATED, $LDAP_DEBUG, $SESSION_TIMED_OUT;

 // If user is not validated, redirect to login
    if (!$VALIDATED) {
        $reason = (!empty($SESSION_TIMED_OUT)) ? "session_timeout" : "unauthorised";
        return getBaseUrl() . "login/?$reason&redirect_to=" . getLoginRedirectToQueryValue();
    }

 // Determine default redirect based on user's highest access level
    if ($IS_SETUP_ADMIN) {
        return getBaseUrl() . "setup/";
    } elseif ($IS_ADMIN) {
        return getBaseUrl() . "manage/users/";
    } elseif ($IS_MAINTAINER) {
        return getBaseUrl() . "manage/organizations/";
    } elseif ($IS_ORG_ADMIN) {
      // Get organization info for org admin redirect
        global $USER_ORG_NAME, $USER_ORG_UUID;

        if (isset($USER_ORG_UUID) && $USER_ORG_UUID) {
            return getBaseUrl() . "manage/organizations/" . urlencode($USER_ORG_UUID) . "/";
        }
        // Fallback to password change if org info not available
        return getBaseUrl() . "password/change/";
    } else {
      // Regular user, redirect to password change
        return getBaseUrl() . "password/change/";
    }
}

######################################################

function isValidEmail($email)
{

    return (!filter_var($email, FILTER_VALIDATE_EMAIL)) ? false : true;
}

######################################################

function renderJsUsernameCheck()
{

    global $USERNAME_REGEX, $ENFORCE_SAFE_SYSTEM_NAMES;

    if ($ENFORCE_SAFE_SYSTEM_NAMES == true) {
        print <<<EoCheckJS
<script>

 function checkEntityNameValidity(name,div_id) {

  var check_regex = /$USERNAME_REGEX/;

  if (! check_regex.test(name) ) {
   document.getElementById(div_id).classList.add("is-invalid");
  }
  else {
   document.getElementById(div_id).classList.remove("is-invalid");
  }

 }

</script>

EoCheckJS;
    } else {
        print "<script> function checkEntityNameValidity(name,div_id) {} </script>";
    }
}

######################################################

function generateUsername($fn, $ln)
{

    global $USERNAME_FORMAT;

  // Handle empty or null parameters
    if (empty($fn) || empty($ln)) {
        return '';
    }

    $username = $USERNAME_FORMAT;
    $username = str_replace('{first_name}', strtolower($fn), $username);
    $username = str_replace('{first_name_initial}', strtolower($fn[0]), $username);
    $username = str_replace('{last_name}', strtolower($ln), $username);
    $username = str_replace('{last_name_initial}', strtolower($ln[0]), $username);

    return $username;
}

######################################################

function renderJsUsernameGenerator($firstname_field_id, $lastname_field_id, $username_field_id, $username_div_id)
{

 #Parameters are the IDs of the input fields and username name div in the account creation form.
 #The div will be set to warning if the username is invalid.

    global $USERNAME_FORMAT, $ENFORCE_SAFE_SYSTEM_NAMES;

    $remove_accents = "";
    if ($ENFORCE_SAFE_SYSTEM_NAMES == true) {
        $remove_accents = ".normalize('NFD').replace(/[\u0300-\u036f]/g, '')";
    }

    print <<<EoRenderJS

<script>
 function updateUsername() {

  var first_name = document.getElementById('$firstname_field_id').value;
  var last_name  = document.getElementById('$lastname_field_id').value;
  var template = '$USERNAME_FORMAT';

  var actual_username = template;

  actual_username = actual_username.replace('{first_name}', first_name.toLowerCase()$remove_accents );
  actual_username = actual_username.replace('{first_name_initial}', first_name.charAt(0).toLowerCase()$remove_accents );
  actual_username = actual_username.replace('{last_name}', last_name.toLowerCase()$remove_accents );
  actual_username = actual_username.replace('{last_name_initial}', last_name.charAt(0).toLowerCase()$remove_accents );

  checkEntityNameValidity(actual_username,'$username_div_id');

  document.getElementById('$username_field_id').value = actual_username;

 }

</script>

EoRenderJS;
}

######################################################

function renderJsCnGenerator($firstname_field_id, $lastname_field_id, $cn_field_id, $cn_div_id)
{

    global $ENFORCE_SAFE_SYSTEM_NAMES;

    if ($ENFORCE_SAFE_SYSTEM_NAMES == true) {
        $gen_js = "first_name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '') + last_name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')";
    } else {
        $gen_js = "first_name + ' ' + last_name";
    }

    print <<<EoRenderCNJS
<script>

 var auto_cn_update = true;

 function updateCn() {

  if ( auto_cn_update == true ) {
    var first_name = document.getElementById('$firstname_field_id').value;
    var last_name  = document.getElementById('$lastname_field_id').value;
    this_cn = $gen_js;

    checkEntityNameValidity(this_cn,'$cn_div_id');

    document.getElementById('$cn_field_id').value = this_cn;
  }

 }
</script>

EoRenderCNJS;
}

######################################################

function renderJsEmailGenerator($username_field_id, $email_field_id)
{

    global $EMAIL_DOMAIN;

    print <<<EoRenderEmailJS
<script>

 var auto_email_update = true;

 function updateEmail() {

  if ( auto_email_update == true && "$EMAIL_DOMAIN" != ""  ) {
    var username = document.getElementById('$username_field_id').value;
    document.getElementById('$email_field_id').value = username + '@' + "$EMAIL_DOMAIN";
  }

 }
</script>

EoRenderEmailJS;
}

######################################################

function renderJsHomedirGenerator($username_field_id, $homedir_field_id)
{

    print <<<EoRenderHomedirJS
<script>

 var auto_homedir_update = true;

 function updateHomedir() {

  if ( auto_homedir_update == true ) {
    var username = document.getElementById('$username_field_id').value;
    document.getElementById('$homedir_field_id').value = "/home/" + username;
  }

 }
</script>

EoRenderHomedirJS;
}

######################################################

function renderDynamicFieldJs()
{

    ?>
<script>

  function addFieldTo(attribute_name,value=null) {

    var parent      = document.getElementById(attribute_name + '_input_div');
    var input_div   = document.createElement('div');

    window[attribute_name + '_count'] = (window[attribute_name + '_count'] === undefined) ? 1 : window[attribute_name + '_count'] + 1;
    var input_field_id = attribute_name + window[attribute_name + '_count'];
    var input_div_id = 'div' + '_' + input_field_id;

    input_div.className = 'input-group';
    input_div.id = input_div_id;

    parent.appendChild(input_div);

    var input_field = document.createElement('input');
        input_field.type = 'text';
        input_field.className = 'form-control';
        input_field.id = input_field_id;
        input_field.name = attribute_name + '[]';
        input_field.value = value;

    var button_span = document.createElement('span');
    var remove_button = document.createElement('button');
        remove_button.type = 'button';
        remove_button.className = 'btn btn-secondary';
        remove_button.onclick = function() { var div_to_remove = document.getElementById(input_div_id); div_to_remove.innerHTML = ""; }
        remove_button.innerHTML = '-';

    input_div.appendChild(input_field);
    input_div.appendChild(remove_button);

  }

</script>
    <?php
}

######################################################

function renderAttributeFields($attribute, $label, $values_r, $resource_identifier, $onkeyup = "", $inputtype = "", $tabindex = null)
{

    global $THIS_MODULE_PATH;

    ?>

     <div class="form-group" id="<?php print $attribute; ?>_div">

       <label for="<?php print $attribute; ?>" class="col-sm-3 form-label"><?php print $label; ?></label>
       <div class="col-sm-6" id="<?php print $attribute; ?>_input_div">
       <?php if ($inputtype == "multipleinput") {
            ?><div class="input-group">
                  <input type="text" class="form-control" id="<?php print $attribute; ?>" name="<?php print $attribute; ?>[]" value="<?php if (isset($values_r[0])) {
                        print $values_r[0];
                                                              } ?>">
                  <button type="button" class="btn btn-secondary" onclick="addFieldTo('<?php print $attribute; ?>')">+</button>
              </div>
            <?php
            if (isset($values_r['count']) and $values_r['count'] > 0) {
                unset($values_r['count']);
                $remaining_values = array_slice($values_r, 1);
                print "<script>";
                foreach ($remaining_values as $this_value) {
                    print "addFieldTo('$attribute','$this_value');";
                }
                print "</script>";
            }
       } elseif ($inputtype == "binary") {
           $button_text = t('manage.fields.file_browse');
           $file_button_action = "disabled";
           $description = t('manage.fields.file_upload_hint');
           $mimetype = "";

           if (isset($values_r[0])) {
                 $this_file_info = new finfo(FILEINFO_MIME_TYPE);
                 $mimetype = $this_file_info->buffer($values_r[0]);
               if (strlen($mimetype) > 23) {
                   $mimetype = substr($mimetype, 0, 19) . "...";
               }
                 $description = "Download $mimetype file (" . humanReadableFilesize(strlen($values_r[0])) . ")";
                 $button_text = t('manage.fields.file_replace');
               if ($resource_identifier != "") {
                   $this_url = "//{$_SERVER['HTTP_HOST']}{$THIS_MODULE_PATH}/download.php?resource_identifier={$resource_identifier}&attribute={$attribute}";
                   $file_button_action = "onclick=\"window.open('$this_url','_blank');\"";
               }
           }
           if ($mimetype == "image/jpeg") {
               $this_image = base64_encode($values_r[0]);
               print "<img class='img-thumbnail' src='data:image/jpeg;base64,$this_image'>";
               $description = "";
           } else {
                ?>
                 <button type="button" <?php print $file_button_action; ?> class="btn btn-secondary" id="<?php print $attribute; ?>-file-info"><?php print $description; ?></button>
           <?php } ?>
               <label class="btn btn-secondary">
           <?php print $button_text; ?><input <?php if (isset($tabindex)) {
                ?>tabindex="<?php print $tabindex; ?>" <?php
           } ?>type="file" style="display:none" onchange="$('#<?php print $attribute; ?>-file-info').text(this.files[0].name)" id="<?php print $attribute; ?>" name="<?php print $attribute; ?>">
               </label>
            <?php
       } else { ?>
              <input <?php if (isset($tabindex)) {
                    ?>tabindex="<?php print $tabindex; ?>" <?php
                     } ?>type="text" class="form-control" id="<?php print $attribute; ?>" name="<?php print $attribute; ?>" value="<?php if (isset($values_r[0])) {
                     print $values_r[0];
                     } ?>" <?php if ($onkeyup != "") {
                     print "onkeyup=\"$onkeyup\"";
                     } ?>>
           <?php
       }
        ?>
       </div>

     </div>

    <?php
}

######################################################

function humanReadableFilesize($bytes)
{
    for ($i = 0; ($bytes / 1024) > 0.9; $i++, $bytes /= 1024) {
    }
    return round($bytes, [0,0,1,2,2,3,3,4,4][$i]) . ['B','kB','MB','GB','TB','PB','EB','ZB','YB'][$i];
}

######################################################

function renderAlertBanner($message, $alert_class = "success", $timeout = 4000)
{
    $safe_class = preg_replace('/[^a-z0-9_-]/i', '', (string) $alert_class) ?: 'success';
    $safe_message = htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8');

    ?>
    <script>window.setTimeout(function() {$(".alert").fadeTo(500, 0).slideUp(500, function(){ $(this).remove(); }); }, <?php print (int) $timeout; ?>);</script>
    <div class="alert alert-<?php print $safe_class; ?> alert-dismissible fade show" role="alert">
     <p class="text-center mb-0"><?php print $safe_message; ?></p>
     <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo htmlspecialchars(t('alert.close'), ENT_QUOTES, 'UTF-8'); ?>"></button>
    </div>
    <?php
}

function setFlash(string $message, string $type = 'success', int $timeout = 8000): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['lum_flash'] = ['message' => $message, 'type' => $type, 'timeout' => $timeout];
}

function renderFlash(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['lum_flash'])) {
        return;
    }
    $flash = $_SESSION['lum_flash'];
    unset($_SESSION['lum_flash']);
    renderAlertBanner(
        (string) ($flash['message'] ?? ''),
        (string) ($flash['type'] ?? 'success'),
        (int) ($flash['timeout'] ?? 8000)
    );
}

/**
 * Render a Bootstrap 5 confirmation modal (e.g. disable/enable/delete).
 *
 * @param string $id Modal element ID
 * @param string $title Modal title
 * @param string $body_html Body HTML (may contain a <span id="..."> for dynamic label)
 * @param array $hidden_inputs Array of inputs: ['name' => 'x', 'id' => 'y'] or ['name' => 'x', 'value' => 'y']
 * @param string $submit_text Submit button label
 * @param string $submit_class Submit button class (e.g. 'btn-danger', 'btn-warning', 'btn-success')
 */
function renderConfirmModal(string $id, string $title, string $body_html, array $hidden_inputs, string $submit_text, string $submit_class = 'btn-primary'): void
{
    ?>
<div class="modal fade" id="<?php echo htmlspecialchars($id); ?>" tabindex="-1" role="dialog" aria-labelledby="<?php echo htmlspecialchars($id); ?>Label" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="<?php echo htmlspecialchars($id); ?>Label"><?php echo htmlspecialchars($title); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></button>
            </div>
            <div class="modal-body"><?php echo $body_html; ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo htmlspecialchars(t('modal.cancel'), ENT_QUOTES, 'UTF-8'); ?></button>
                <form method="post" action="" style="display: inline;">
                    <?php echo csrfTokenField(); ?>
                    <?php
                    foreach ($hidden_inputs as $input) {
                        $name = $input['name'] ?? '';
                        $attribs = ' name="' . htmlspecialchars($name) . '"';
                        if (!empty($input['id'])) {
                            $attribs .= ' id="' . htmlspecialchars($input['id']) . '"';
                        }
                        if (array_key_exists('value', $input)) {
                            $attribs .= ' value="' . htmlspecialchars((string) $input['value']) . '"';
                        }
                        echo '<input type="hidden"' . $attribs . '>';
                    }
                    ?>
                    <button type="submit" class="btn <?php echo htmlspecialchars($submit_class); ?>"><?php echo htmlspecialchars($submit_text); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
    <?php
}

// CSRF protection helpers
function getCsrfToken()
{
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Refresh session timeout to prevent premature expiration
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 300) {
        // Regenerate session ID every 5 minutes for security
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();

    // Generate new token if none exists or if it's too old (regenerate every hour for security)
    if (
        empty($_SESSION['csrf_token']) ||
        !isset($_SESSION['csrf_token_time']) ||
        (time() - $_SESSION['csrf_token_time']) > 3600
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }

    return $_SESSION['csrf_token'];
}
function csrfTokenField()
{
    $token = getCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}
function validateCsrfToken()
{
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Check if CSRF token exists in session
    if (empty($_SESSION['csrf_token'])) {
        error_log("CSRF validation failed: No token in session");
        return false;
    }

    // Check if CSRF token was posted
    if (!isset($_POST['csrf_token'])) {
        error_log("CSRF validation failed: No token posted");
        return false;
    }

    // Validate the token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        error_log("CSRF validation failed: Token mismatch. Session: " . substr($_SESSION['csrf_token'], 0, 8) . "... Posted: " . substr($_POST['csrf_token'], 0, 8) . "...");
        return false;
    }

    return true;
}

/**
 * Secure redirect validation to prevent HTTP response splitting attacks
 * @param string $redirect_url The base64 encoded redirect URL
 * @param string $base_path The base server path
 * @return string|false Validated redirect URL or false if invalid
 */
function validateRedirectUrl($redirect_url, $base_path = '/')
{
    $b64 = (string) $redirect_url;
    // Base64 of the return path. If a client double-encoded, retry after rawurldecode.
    $decoded = base64_decode($b64, true);
    if ($decoded === false) {
        $b64 = rawurldecode($b64);
        $decoded = base64_decode($b64, true);
    }
    if ($decoded === false) {
        return false;
    }

    // Ensure the decoded URL is a string
    if (!is_string($decoded)) {
        return false;
    }

    // Remove any null bytes or control characters
    $decoded = preg_replace('/[\x00-\x1F\x7F]/', '', $decoded);

    // Check if it's a relative path (starts with /)
    if (strpos($decoded, '/') === 0) {
        // Ensure it doesn't contain directory traversal attempts
        if (strpos($decoded, '..') !== false || strpos($decoded, '//') !== false) {
            return false;
        }

        // Ensure it doesn't contain any potentially dangerous characters
        if (preg_match('/[<>"\']/', $decoded)) {
            return false;
        }

        // Limit the length to prevent excessive redirects
        if (strlen($decoded) > 200) {
            return false;
        }

        return $decoded;
    }

    // Check if it's a relative path without leading slash
    if (strpos($decoded, 'http') !== 0 && strpos($decoded, '//') !== 0) {
        // Add leading slash if missing
        $decoded = '/' . ltrim($decoded, '/');

        // Apply the same validation as above
        if (strpos($decoded, '..') !== false || strpos($decoded, '//') !== false) {
            return false;
        }

        if (preg_match('/[<>"\']/', $decoded)) {
            return false;
        }

        if (strlen($decoded) > 200) {
            return false;
        }

        return $decoded;
    }

    // Reject absolute URLs for security
    return false;
}

/**
 * Safe name display functions to prevent PHP warnings
 */

/**
 * Safely display a user's full name with fallbacks
 * @param array $user User data array
 * @param string $cn_key Key for common name (default: 'cn')
 * @param string $givenname_key Key for given name (default: 'givenName')
 * @param string $sn_key Key for surname (default: 'sn')
 * @return string Safe display name
 */
function safeDisplayName($user, $cn_key = 'cn', $givenname_key = 'givenName', $sn_key = 'sn')
{
    // Try to get the common name first
    if (isset($user[$cn_key]) && !empty($user[$cn_key])) {
        if (is_array($user[$cn_key])) {
            return htmlspecialchars($user[$cn_key][0] ?? '');
        }
        return htmlspecialchars($user[$cn_key]);
    }

    // Fallback: construct from given name and surname
    $givenname = '';
    $sn = '';

    if (isset($user[$givenname_key]) && !empty($user[$givenname_key])) {
        if (is_array($user[$givenname_key])) {
            $givenname = $user[$givenname_key][0] ?? '';
        } else {
            $givenname = $user[$givenname_key];
        }
    }

    if (isset($user[$sn_key]) && !empty($user[$sn_key])) {
        if (is_array($user[$sn_key])) {
            $sn = $user[$sn_key][0] ?? '';
        } else {
            $sn = $user[$sn_key];
        }
    }

    // Return constructed name or fallback
    if ($givenname && $sn) {
        return htmlspecialchars($givenname . ' ' . $sn);
    } elseif ($givenname) {
        return htmlspecialchars($givenname);
    } elseif ($sn) {
        return htmlspecialchars($sn);
    } else {
        return '<em>' . htmlspecialchars(t('user.no_name_available'), ENT_QUOTES, 'UTF-8') . '</em>';
    }
}

/**
 * Safely get a single attribute value from user data
 * @param array $user User data array
 * @param string $key Attribute key
 * @param string $default Default value if attribute is missing
 * @return string Safe attribute value
 */
function safeUserAttribute($user, $key, $default = '')
{
    if (!isset($user[$key]) || empty($user[$key])) {
        return $default;
    }

    if (is_array($user[$key])) {
        return htmlspecialchars($user[$key][0] ?? $default);
    }

    return htmlspecialchars($user[$key]);
}

/**
 * Rate limiting functions to prevent brute force attacks
 */

/**
 * Check if user is rate limited for login attempts
 * @param string $identifier User identifier (email/username)
 * @param int $max_attempts Maximum attempts allowed
 * @param int $time_window Time window in seconds
 * @return bool True if rate limited, false otherwise
 */
function isRateLimited($identifier, $max_attempts = 5, $time_window = 300)
{
    $rate_limit_file = lumStateDir() . "/rate_limit_" . md5($identifier);

    if (file_exists($rate_limit_file)) {
        $data = file_get_contents($rate_limit_file);
        $attempts = json_decode($data, true);

        if ($attempts && is_array($attempts)) {
            // Remove attempts outside the time window
            $current_time = time();
            $attempts = array_filter($attempts, function ($timestamp) use ($current_time, $time_window) {
                return ($current_time - $timestamp) < $time_window;
            });

            // Check if too many attempts
            if (count($attempts) >= $max_attempts) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Record a login attempt for rate limiting
 * @param string $identifier User identifier
 * @param bool $success Whether the attempt was successful
 */
function recordLoginAttempt($identifier, $success = false)
{
    $rate_limit_file = lumStateDir() . "/rate_limit_" . md5($identifier);

    if ($success) {
        // Clear rate limiting on successful login
        if (file_exists($rate_limit_file)) {
            unlink($rate_limit_file);
        }
        return;
    }

    $attempts = [];
    if (file_exists($rate_limit_file)) {
        $data = file_get_contents($rate_limit_file);
        $decoded = json_decode($data, true);
        $attempts = is_array($decoded) ? $decoded : [];
    }

    $attempts[] = time();

    // Keep only last 10 attempts to prevent file bloat
    if (count($attempts) > 10) {
        $attempts = array_slice($attempts, -10);
    }

    file_put_contents($rate_limit_file, json_encode($attempts));
}

######################################################

/**
 * Generates JavaScript configuration object for password strength requirements
 * based on environment variables and current configuration.
 *
 * @return string JavaScript configuration object as JSON
 */
function getPasswordStrengthConfigJs()
{
    global $PASSWORD_STRENGTH_MIN_SCORE, $PASSWORD_STRENGTH_MIN_LENGTH;
    global $PASSWORD_STRENGTH_REQUIRE_UPPERCASE, $PASSWORD_STRENGTH_REQUIRE_LOWERCASE;
    global $PASSWORD_STRENGTH_REQUIRE_NUMBERS, $PASSWORD_STRENGTH_REQUIRE_SYMBOLS;
    global $ACCEPT_WEAK_PASSWORDS;

    // If weak passwords are accepted, allow score 0
    $minScore = $ACCEPT_WEAK_PASSWORDS ? 0 : $PASSWORD_STRENGTH_MIN_SCORE;

    $config = [
        'minScore' => $minScore,
        'minLength' => $PASSWORD_STRENGTH_MIN_LENGTH,
        'requireUppercase' => $PASSWORD_STRENGTH_REQUIRE_UPPERCASE,
        'requireLowercase' => $PASSWORD_STRENGTH_REQUIRE_LOWERCASE,
        'requireNumbers' => $PASSWORD_STRENGTH_REQUIRE_NUMBERS,
        'requireSymbols' => $PASSWORD_STRENGTH_REQUIRE_SYMBOLS,
        'showStrengthMeter' => true,
        'showScore' => true,
        'updateHiddenField' => true,
        'hiddenFieldId' => 'pass_score'
    ];

    return json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * Normalize a user-entered website URL for LDAP labeledURI storage.
 *
 * @return string|null Normalized URL, null when input is empty, false when invalid
 */
function normalizeWebsiteUrl(string $input, string $defaultScheme = 'https'): string|false|null
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (preg_match('/\s/u', $input)) {
        return false;
    }

    if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $input)) {
        $scheme = strtolower((string) parse_url($input, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
    } else {
        $input = rtrim($defaultScheme, ':/') . '://' . ltrim($input, '/');
    }

    if (!filter_var($input, FILTER_VALIDATE_URL)) {
        return false;
    }

    $host = parse_url($input, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }

    return $input;
}

/**
 * @param string|null $url Already-normalized or raw URL; empty/null is invalid
 */
function isValidWebsiteUrl(?string $url): bool
{
    if ($url === null || trim($url) === '') {
        return false;
    }

    $normalized = normalizeWebsiteUrl($url);

    return is_string($normalized);
}

/**
 * Split a stored website URL into scheme and host/path for the input-group widget.
 *
 * @return array{scheme: string, hostPath: string}
 */
function splitWebsiteUrl(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return ['scheme' => 'https', 'hostPath' => ''];
    }

    $normalized = normalizeWebsiteUrl($url);
    if (!is_string($normalized)) {
        return ['scheme' => 'https', 'hostPath' => $url];
    }

    $parsed = parse_url($normalized);
    $scheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = 'https';
    }

    $hostPath = (string) ($parsed['host'] ?? '');
    if (isset($parsed['port'])) {
        $hostPath .= ':' . $parsed['port'];
    }
    if (isset($parsed['path']) && $parsed['path'] !== '') {
        $hostPath .= $parsed['path'];
    }
    if (isset($parsed['query'])) {
        $hostPath .= '?' . $parsed['query'];
    }
    if (isset($parsed['fragment'])) {
        $hostPath .= '#' . $parsed['fragment'];
    }

    return ['scheme' => $scheme, 'hostPath' => $hostPath];
}

/**
 * Normalize labeledURI website value during org create/update.
 *
 * @return string|null Error message when invalid, null on success
 */
function applyWebsiteUrlNormalization(array &$orgData, string $ldapAttr = 'labeledURI'): ?string
{
    if (!array_key_exists($ldapAttr, $orgData)) {
        return null;
    }

    $raw = trim((string) $orgData[$ldapAttr]);
    if ($raw === '') {
        $orgData[$ldapAttr] = '';

        return null;
    }

    $normalized = normalizeWebsiteUrl($raw);
    if ($normalized === false) {
        return t('manage.orgs.website_invalid');
    }

    $orgData[$ldapAttr] = $normalized;

    return null;
}

/**
 * Render the organization website URL input group (scheme prefix + host/path).
 */
function renderWebsiteUrlField(string $fieldName, string $label, string $value = '', bool $required = false, string $labelClass = '', bool $includeLabel = true): void
{
    $parts = splitWebsiteUrl($value);
    $schemeId = $fieldName . '_scheme';
    $hostId = $fieldName . '_host';
    $requiredAttr = $required ? ' required' : '';
    $labelClassAttr = $labelClass !== '' ? ' class="' . htmlspecialchars($labelClass, ENT_QUOTES, 'UTF-8') . '"' : '';
    $requiredLabel = $required ? ' <sup>*</sup>' : '';
    $invalidMsg = t('manage.orgs.website_invalid');
    $previewTemplate = t('manage.orgs.website_preview');
    $hint = t('manage.orgs.website_hint');
    $placeholder = t('manage.orgs.website_placeholder');
    $schemeLabel = t('manage.orgs.website_scheme_aria');

    echo '<div class="website-url-field" data-website-field'
        . ' data-invalid-msg="' . htmlspecialchars($invalidMsg, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-preview-template="' . htmlspecialchars($previewTemplate, ENT_QUOTES, 'UTF-8') . '">';
    if ($includeLabel) {
        echo '<label for="' . htmlspecialchars($hostId, ENT_QUOTES, 'UTF-8') . '"' . $labelClassAttr . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $requiredLabel . '</label>';
    }
    echo '<input type="hidden" name="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '"'
        . ' id="' . htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') . '"'
        . ' value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="input-group">';
    echo '<select class="form-select website-url-scheme" id="' . htmlspecialchars($schemeId, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($schemeLabel, ENT_QUOTES, 'UTF-8') . '" style="max-width: 7.5rem;">';
    echo '<option value="https"' . ($parts['scheme'] === 'https' ? ' selected' : '') . '>https://</option>';
    echo '<option value="http"' . ($parts['scheme'] === 'http' ? ' selected' : '') . '>http://</option>';
    echo '</select>';
    echo '<input type="text" class="form-control website-url-host" id="' . htmlspecialchars($hostId, ENT_QUOTES, 'UTF-8') . '"'
        . ' inputmode="url" autocomplete="url"'
        . ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"'
        . ' value="' . htmlspecialchars($parts['hostPath'], ENT_QUOTES, 'UTF-8') . '"' . $requiredAttr . '>';
    echo '</div>';
    echo '<small class="form-text text-muted website-url-preview" aria-live="polite"></small>';
    echo '<small class="form-text text-muted website-url-hint">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</small>';
    echo '</div>';
}
