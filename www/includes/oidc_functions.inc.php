<?php

/**
 * OpenID Connect (OIDC) functions for LDAP User Manager
 *
 * Implements OIDC Authorization Code Flow with PKCE.
 * ID token validation uses firebase/php-jwt with JWKS signature verification.
 *
 * Design decisions:
 * - Auto-provisioning is intentionally removed: OIDC users must already exist in LDAP.
 * - Header-based SSO (REMOTE_HTTP_HEADERS_LOGIN) has been removed; OIDC is the sole
 *   external identity provider integration.
 */

declare(strict_types=1);

// Load Composer autoloader (Docker: vendor/ is a sibling of includes/;
// local dev: vendor/ is two levels up from includes/).
(static function (): void {
    $candidates = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
    ];
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
})();

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

// OIDC configuration (globals so it is available inside nested function calls)
$GLOBALS['OIDC_CONFIG'] = [
    'enabled'           => getenv('OIDC_ENABLED') === 'true',
    'issuer'            => getenv('OIDC_ISSUER') ?: 'https://id.example.org',
    'client_id'         => getenv('OIDC_CLIENT_ID') ?: 'ldap-user-manager',
    'client_secret'     => getenv('OIDC_CLIENT_SECRET') ?: '',
    'redirect_uri'      => getenv('OIDC_REDIRECT_URI') ?: 'https://app.example.org/oidc/callback',
    'scopes'            => getenv('OIDC_SCOPES') ?: 'openid profile email groups',
    'auth_endpoint'     => null,
    'token_endpoint'    => null,
    'userinfo_endpoint' => null,
    'jwks_uri'          => null,
];

/** Clock skew tolerance in seconds for JWT exp/nbf/iat checks. */
define('OIDC_CLOCK_SKEW', 30);

/** Allowed JWT signature algorithms. 'none' is deliberately absent. */
define('OIDC_ALLOWED_ALGS', ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512']);

// ---------------------------------------------------------------------------
// HTTP helpers (TLS peer verification always enforced)
// ---------------------------------------------------------------------------

/**
 * Perform an HTTPS GET and return the response body, or false on failure.
 */
function oidc_http_get(string $url): string|false
{
    $ctx = stream_context_create([
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        'http' => [
            'method'  => 'GET',
            'header'  => 'Accept: application/json',
            'timeout' => 10,
        ],
    ]);
    return file_get_contents($url, false, $ctx);
}

/**
 * Perform an HTTPS POST (application/x-www-form-urlencoded) and return the body.
 */
function oidc_http_post(string $url, array $data): string|false
{
    $ctx = stream_context_create([
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
            'content' => http_build_query($data),
            'timeout' => 10,
        ],
    ]);
    return file_get_contents($url, false, $ctx);
}

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------

/**
 * Fetch the OIDC discovery document and populate $OIDC_CONFIG endpoints.
 * Idempotent: if endpoints are already set, returns true immediately.
 */
function init_oidc_config(): bool
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled'])) {
        return false;
    }

    // Already initialized
    if (!empty($OIDC_CONFIG['auth_endpoint'])) {
        return true;
    }

    try {
        // RFC 8414 / OIDC Core: discovery path uses a hyphen (openid-configuration)
        $well_known_url = rtrim($OIDC_CONFIG['issuer'], '/') . '/.well-known/openid-configuration';
        $response = oidc_http_get($well_known_url);

        if ($response === false) {
            error_log("OIDC: Failed to fetch discovery document from: $well_known_url");
            return false;
        }

        $config = json_decode($response, true);
        if (!is_array($config)) {
            error_log('OIDC: Invalid discovery document response');
            return false;
        }

        $OIDC_CONFIG['auth_endpoint']     = $config['authorization_endpoint'] ?? null;
        $OIDC_CONFIG['token_endpoint']    = $config['token_endpoint'] ?? null;
        $OIDC_CONFIG['userinfo_endpoint'] = $config['userinfo_endpoint'] ?? null;
        // Spec field is jwks_uri (not jwks_endpoint)
        $OIDC_CONFIG['jwks_uri']          = $config['jwks_uri'] ?? null;

        if (empty($OIDC_CONFIG['auth_endpoint']) || empty($OIDC_CONFIG['token_endpoint'])) {
            error_log('OIDC: Discovery document missing required endpoints');
            return false;
        }

        return true;
    } catch (Exception $e) {
        error_log('OIDC: Error initializing config: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// Authorization URL (PKCE + nonce + state)
// ---------------------------------------------------------------------------

/**
 * Generate the OIDC authorization URL including PKCE, state, and nonce.
 * State and nonce are stored in $_SESSION for later verification.
 */
function generate_oidc_auth_url(?string $state = null): string|false
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled']) || empty($OIDC_CONFIG['auth_endpoint'])) {
        return false;
    }

    if ($state === null) {
        $state = bin2hex(random_bytes(32));
        $_SESSION['oidc_state'] = $state;
    }

    // Nonce prevents ID token replay attacks
    $nonce = bin2hex(random_bytes(32));
    $_SESSION['oidc_nonce'] = $nonce;

    $params = [
        'response_type'         => 'code',
        'client_id'             => $OIDC_CONFIG['client_id'],
        'redirect_uri'          => $OIDC_CONFIG['redirect_uri'],
        'scope'                 => $OIDC_CONFIG['scopes'],
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge_method' => 'S256',
        'code_challenge'        => oidc_generate_code_challenge(),
    ];

    return $OIDC_CONFIG['auth_endpoint'] . '?' . http_build_query($params);
}

/**
 * Generate PKCE code verifier + challenge. Stores verifier in session.
 */
function oidc_generate_code_challenge(): string
{
    $code_verifier = bin2hex(random_bytes(32));
    $_SESSION['oidc_code_verifier'] = $code_verifier;
    return rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
}

// ---------------------------------------------------------------------------
// Token exchange
// ---------------------------------------------------------------------------

/**
 * Exchange the authorization code for an access + ID token.
 *
 * @return array<string,mixed>|false
 */
function exchange_code_for_tokens(string $code): array|false
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled']) || empty($OIDC_CONFIG['token_endpoint'])) {
        return false;
    }

    $code_verifier = $_SESSION['oidc_code_verifier'] ?? null;
    if (!is_string($code_verifier) || $code_verifier === '') {
        error_log('OIDC: No code verifier in session');
        return false;
    }

    $post_data = [
        'grant_type'    => 'authorization_code',
        'client_id'     => $OIDC_CONFIG['client_id'],
        'client_secret' => $OIDC_CONFIG['client_secret'],
        'code'          => $code,
        'redirect_uri'  => $OIDC_CONFIG['redirect_uri'],
        'code_verifier' => $code_verifier,
    ];

    $response = oidc_http_post($OIDC_CONFIG['token_endpoint'], $post_data);
    if ($response === false) {
        error_log('OIDC: Failed to exchange code for tokens');
        return false;
    }

    $tokens = json_decode($response, true);
    if (!is_array($tokens) || !isset($tokens['access_token'])) {
        error_log('OIDC: Invalid or missing access_token in token response');
        return false;
    }

    return $tokens;
}

// ---------------------------------------------------------------------------
// JWKS fetch (request-level cache)
// ---------------------------------------------------------------------------

/**
 * Fetch and parse the JWKS from the IdP. Cached per request.
 *
 * @return array<string,mixed>|false Raw JWKS array on success
 */
function oidc_fetch_jwks(): array|false
{
    global $OIDC_CONFIG;
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $jwks_uri = $OIDC_CONFIG['jwks_uri'] ?? null;
    if (!is_string($jwks_uri) || $jwks_uri === '') {
        error_log('OIDC: jwks_uri not set — cannot verify ID token signature');
        return false;
    }

    $response = oidc_http_get($jwks_uri);
    if ($response === false) {
        error_log("OIDC: Failed to fetch JWKS from $jwks_uri");
        return false;
    }

    $jwks = json_decode($response, true);
    if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
        error_log('OIDC: Invalid JWKS response structure');
        return false;
    }

    $cached = $jwks;
    return $cached;
}

// ---------------------------------------------------------------------------
// ID token validation
// ---------------------------------------------------------------------------

/**
 * Validate an OIDC ID token using firebase/php-jwt with JWKS signature verification.
 *
 * Checks (in order):
 *   1. Algorithm allowlist (rejects 'none' and symmetric HS* algorithms)
 *   2. Cryptographic signature via JWKS
 *   3. exp / nbf / iat (handled by JWT::decode with clock skew)
 *   4. iss must match configured issuer
 *   5. aud must contain the configured client_id
 *   6. azp must match client_id when multiple audiences are present
 *   7. nonce must match session value (timing-safe comparison)
 *
 * @return array<string,mixed>|false Decoded payload on success, false on any failure
 */
function validate_id_token(string $id_token): array|false
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled'])) {
        return false;
    }

    if (!class_exists('Firebase\\JWT\\JWT') || !class_exists('Firebase\\JWT\\JWK')) {
        error_log('OIDC: firebase/php-jwt not loaded — cannot validate ID token');
        return false;
    }

    try {
        // 1. Pre-check: extract and validate alg from the JWT header BEFORE signature verification
        $parts = explode('.', $id_token);
        if (count($parts) !== 3) {
            error_log('OIDC: Malformed ID token (expected 3 parts)');
            return false;
        }
        $headerJson = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $header     = is_string($headerJson) ? json_decode($headerJson, true) : null;
        $alg        = is_array($header) ? (string) ($header['alg'] ?? '') : '';

        if (!in_array($alg, OIDC_ALLOWED_ALGS, true)) {
            error_log("OIDC: Rejected disallowed or missing alg '$alg' in ID token header");
            return false;
        }

        // 2. Fetch JWKS and parse into Key objects
        $jwks = oidc_fetch_jwks();
        if ($jwks === false) {
            return false;
        }
        $keySet = JWK::parseKeySet($jwks, 'RS256');

        // 3. Decode and verify signature + exp/nbf/iat (firebase/php-jwt handles these)
        JWT::$leeway = OIDC_CLOCK_SKEW;
        $decoded = JWT::decode($id_token, $keySet);
        $payload = (array) $decoded;

        // 4. Validate issuer (required; missing issuer is rejected)
        if (!isset($payload['iss']) || $payload['iss'] !== $OIDC_CONFIG['issuer']) {
            error_log('OIDC: Invalid or missing issuer in ID token');
            return false;
        }

        // 5. Validate audience
        $aud     = $payload['aud'] ?? null;
        $audList = is_array($aud) ? $aud : [$aud];
        if ($aud === null || !in_array($OIDC_CONFIG['client_id'], $audList, true)) {
            error_log('OIDC: client_id not present in ID token aud claim');
            return false;
        }

        // 6. When multiple audiences are present, azp must match client_id (RFC 7519 §4.1.3)
        if (count($audList) > 1) {
            $azp = (string) ($payload['azp'] ?? '');
            if ($azp !== $OIDC_CONFIG['client_id']) {
                error_log('OIDC: Multiple audiences present but azp is missing or mismatched');
                return false;
            }
        }

        // 7. Validate nonce (timing-safe comparison prevents oracle attacks)
        $sessionNonce = $_SESSION['oidc_nonce'] ?? null;
        if (!is_string($sessionNonce) || $sessionNonce === '') {
            error_log('OIDC: No nonce in session — cannot verify ID token');
            return false;
        }
        $tokenNonce = (string) ($payload['nonce'] ?? '');
        if (!hash_equals($sessionNonce, $tokenNonce)) {
            error_log('OIDC: Nonce mismatch in ID token');
            return false;
        }

        return $payload;
    } catch (Exception $e) {
        error_log('OIDC: ID token validation failed: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// UserInfo
// ---------------------------------------------------------------------------

/**
 * Fetch user info from the IdP's userinfo endpoint.
 *
 * @return array<string,mixed>|false
 */
function get_oidc_user_info(string $access_token): array|false
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled']) || empty($OIDC_CONFIG['userinfo_endpoint'])) {
        return false;
    }

    $ctx = stream_context_create([
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        'http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer $access_token\r\nAccept: application/json",
            'timeout' => 10,
        ],
    ]);

    $response = file_get_contents($OIDC_CONFIG['userinfo_endpoint'], false, $ctx);
    if ($response === false) {
        error_log('OIDC: Failed to get user info from userinfo endpoint');
        return false;
    }

    $user_info = json_decode($response, true);
    if (!is_array($user_info)) {
        error_log('OIDC: Invalid user info response');
        return false;
    }

    return $user_info;
}

// ---------------------------------------------------------------------------
// Attribute mapping
// ---------------------------------------------------------------------------

/**
 * Map OIDC user info claims to the LDAP attribute names used by this app.
 *
 * @param array<string,mixed> $oidc_user_info
 * @return array<string,mixed>
 */
function map_oidc_to_ldap_user(array $oidc_user_info): array
{
    $ldap_user = [
        'uid'       => $oidc_user_info['sub'] ?? $oidc_user_info['preferred_username'] ?? null,
        'mail'      => $oidc_user_info['email'] ?? null,
        'cn'        => $oidc_user_info['name'] ?? null,
        'givenname' => $oidc_user_info['given_name'] ?? null,
        'sn'        => $oidc_user_info['family_name'] ?? null,
        'groups'    => $oidc_user_info['groups'] ?? [],
    ];

    return array_filter($ldap_user, static fn ($v) => $v !== null && $v !== '');
}

// ---------------------------------------------------------------------------
// LDAP user lookup (auto-provisioning removed)
// ---------------------------------------------------------------------------

/**
 * Find an existing LDAP user matching the OIDC identity.
 *
 * Auto-provisioning has been intentionally removed. If the user does not
 * already exist in LDAP, this function returns false and the caller must
 * return an HTTP 403 response.
 *
 * The uid claim is validated against a strict allowlist regex and escaped
 * with ldap_escape() before being used in any LDAP filter.
 *
 * @param array<string,mixed> $ldap_user Result of map_oidc_to_ldap_user()
 * @return string|false LDAP DN on success, false if not found or invalid
 */
function find_oidc_user(array $ldap_user): string|false
{
    global $LDAP;

    $uid = $ldap_user['uid'] ?? '';
    if (!is_string($uid) || $uid === '') {
        error_log('OIDC: Missing uid claim in OIDC user info');
        return false;
    }

    // Strictly validate uid format before using in LDAP filter
    if (!preg_match('/^[a-zA-Z0-9._@\-]+$/', $uid)) {
        error_log("OIDC: uid claim '$uid' contains disallowed characters");
        return false;
    }

    $ldap = open_ldap_connection();
    if (!$ldap) {
        error_log('OIDC: Cannot open LDAP connection for user lookup');
        return false;
    }

    $account_attr   = $LDAP['account_attribute'] ?? 'uid';
    $escaped_uid    = ldap_escape($uid, '', LDAP_ESCAPE_FILTER);
    $user_filter    = "(&(objectclass=inetOrgPerson)({$account_attr}={$escaped_uid}))";
    $ldap_search    = @ldap_search($ldap, $LDAP['base_dn'], $user_filter, ['dn']);

    if ($ldap_search) {
        $result = @ldap_get_entries($ldap, $ldap_search);
        if (is_array($result) && (int) $result['count'] > 0) {
            $user_dn = (string) $result[0]['dn'];
            ldap_close($ldap);
            return $user_dn;
        }
    }

    ldap_close($ldap);
    error_log("OIDC: User not found in LDAP for uid='$uid'. OIDC auto-provisioning is disabled.");
    return false;
}

// ---------------------------------------------------------------------------
// Callback handler
// ---------------------------------------------------------------------------

/**
 * Handle the OIDC authorization code callback.
 *
 * On success, calls setPasskeyCookie() to establish the application session
 * and returns true. Returns false (and may set HTTP 403) on any error.
 */
function handle_oidc_callback(): bool
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled'])) {
        return false;
    }

    // Verify state (CSRF protection for the OAuth flow)
    $state         = $_GET['state'] ?? null;
    $session_state = $_SESSION['oidc_state'] ?? null;
    if (!is_string($state) || !is_string($session_state) || !hash_equals($session_state, $state)) {
        error_log('OIDC: Invalid or missing state parameter in callback');
        return false;
    }

    $code = $_GET['code'] ?? null;
    if (!is_string($code) || $code === '') {
        error_log('OIDC: No authorization code in callback');
        return false;
    }

    $tokens = exchange_code_for_tokens($code);
    if (!is_array($tokens)) {
        return false;
    }

    $id_token = $tokens['id_token'] ?? null;
    if (!is_string($id_token) || $id_token === '') {
        error_log('OIDC: No ID token in token response');
        return false;
    }

    $token_payload = validate_id_token($id_token);
    if (!is_array($token_payload)) {
        return false;
    }

    // Consume session nonce/state/verifier after successful validation
    unset($_SESSION['oidc_state'], $_SESSION['oidc_code_verifier'], $_SESSION['oidc_nonce']);

    // Prefer userinfo endpoint over ID token claims for richer profile data
    $access_token = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : null;
    $user_info    = ($access_token !== null) ? get_oidc_user_info($access_token) : null;
    if (!is_array($user_info)) {
        $user_info = $token_payload;
    }

    $ldap_user = map_oidc_to_ldap_user($user_info);

    // Auto-provisioning removed: user must already exist in LDAP
    $user_dn = find_oidc_user($ldap_user);
    if ($user_dn === false) {
        error_log('OIDC: User not found in LDAP — access denied');
        http_response_code(403);
        return false;
    }

    $user_id      = (string) ($ldap_user['uid'] ?? '');
    $groups       = is_array($ldap_user['groups'] ?? null) ? (array) $ldap_user['groups'] : [];
    $is_admin     = check_oidc_user_admin_status($user_dn, $groups);
    $is_maintainer = check_oidc_user_maintainer_status($user_dn, $groups);

    setPasskeyCookie($user_id, $is_admin, $is_maintainer);

    return true;
}

// ---------------------------------------------------------------------------
// Role checks for OIDC users
// ---------------------------------------------------------------------------

/**
 * @param string[] $groups Groups claim from the OIDC token
 */
function check_oidc_user_admin_status(string $user_dn, array $groups): bool
{
    global $LDAP;

    $admin_role = $LDAP['admin_role'] ?? 'administrators';
    if (in_array($admin_role, $groups, true)) {
        return true;
    }

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }
    $result = ldap_is_group_member($ldap, $LDAP['roles_dn'], $admin_role, $user_dn);
    ldap_close($ldap);
    return $result;
}

/**
 * @param string[] $groups Groups claim from the OIDC token
 */
function check_oidc_user_maintainer_status(string $user_dn, array $groups): bool
{
    global $LDAP;

    $maintainer_role = $LDAP['maintainer_role'] ?? 'maintainers';
    if (in_array($maintainer_role, $groups, true)) {
        return true;
    }

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }
    $result = ldap_is_group_member($ldap, $LDAP['roles_dn'], $maintainer_role, $user_dn);
    ldap_close($ldap);
    return $result;
}

// ---------------------------------------------------------------------------
// Integration helpers used by web_functions.inc.php
// ---------------------------------------------------------------------------

/**
 * Returns true when OIDC is enabled and the user has no active session/cookie.
 */
function require_oidc_auth(): bool
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled'])) {
        return false;
    }

    return !isset($_SESSION['user_id']) && !isset($_COOKIE['orf_cookie']);
}

/**
 * Redirect to the OIDC authorization endpoint when authentication is required.
 */
function redirect_to_oidc_if_required(): void
{
    if (require_oidc_auth()) {
        $auth_url = generate_oidc_auth_url();
        if (is_string($auth_url)) {
            header('Location: ' . $auth_url);
            exit;
        }
    }
}
