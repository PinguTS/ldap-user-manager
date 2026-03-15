<?php

/**
 * OpenID Connect (OIDC) functions for LDAP User Manager
 *
 * This file contains functions for OIDC authentication, token validation,
 * and user session management.
 */

declare(strict_types=1);

// OIDC configuration (use GLOBALS so it is available when this file is included from inside a function, e.g. bootstrap_manage)
$GLOBALS['OIDC_CONFIG'] = [
    'enabled' => getenv('OIDC_ENABLED') === 'true',
    'issuer' => getenv('OIDC_ISSUER') ?: 'https://id.example.org',
    'client_id' => getenv('OIDC_CLIENT_ID') ?: 'ldap-user-manager',
    'client_secret' => getenv('OIDC_CLIENT_SECRET') ?: '',
    'redirect_uri' => getenv('OIDC_REDIRECT_URI') ?: 'https://app.example.org/oidc/callback',
    'scopes' => getenv('OIDC_SCOPES') ?: 'openid profile email groups',
    'auth_endpoint' => null,
    'token_endpoint' => null,
    'userinfo_endpoint' => null,
    'jwks_uri' => null
];

/**
 * Initialize OIDC configuration by discovering endpoints
 */
function init_oidc_config()
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled'])) {
        return false;
    }

    try {
        $well_known_url = rtrim($OIDC_CONFIG['issuer'], '/') . '/.well-known/openid_configuration';
        $response = file_get_contents($well_known_url);

        if ($response === false) {
            error_log("Failed to fetch OIDC configuration from: $well_known_url");
            return false;
        }

        $config = json_decode($response, true);
        if (!$config) {
            error_log("Invalid OIDC configuration response");
            return false;
        }

        $OIDC_CONFIG['auth_endpoint'] = $config['authorization_endpoint'] ?? null;
        $OIDC_CONFIG['token_endpoint'] = $config['token_endpoint'] ?? null;
        $OIDC_CONFIG['userinfo_endpoint'] = $config['userinfo_endpoint'] ?? null;
        $OIDC_CONFIG['jwks_uri'] = $config['jwks_endpoint'] ?? null;

        return true;
    } catch (Exception $e) {
        error_log("Error initializing OIDC config: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate OIDC authorization URL
 */
function generate_oidc_auth_url($state = null)
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled']) || empty($OIDC_CONFIG['auth_endpoint'])) {
        return false;
    }

    if (!$state) {
        $state = bin2hex(random_bytes(32));
        $_SESSION['oidc_state'] = $state;
    }

    $params = [
        'response_type' => 'code',
        'client_id' => $OIDC_CONFIG['client_id'],
        'redirect_uri' => $OIDC_CONFIG['redirect_uri'],
        'scope' => $OIDC_CONFIG['scopes'],
        'state' => $state,
        'code_challenge_method' => 'S256',
        'code_challenge' => generate_code_challenge()
    ];

    return $OIDC_CONFIG['auth_endpoint'] . '?' . http_build_query($params);
}

/**
 * Generate PKCE code challenge
 */
function generate_code_challenge()
{
    $code_verifier = bin2hex(random_bytes(32));
    $_SESSION['oidc_code_verifier'] = $code_verifier;
    return rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
}

/**
 * Exchange authorization code for tokens
 */
function exchange_code_for_tokens($code)
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled']) || empty($OIDC_CONFIG['token_endpoint'])) {
        return false;
    }

    $code_verifier = $_SESSION['oidc_code_verifier'] ?? null;
    if (!$code_verifier) {
        error_log("No code verifier found in session");
        return false;
    }

    $post_data = [
        'grant_type' => 'authorization_code',
        'client_id' => $OIDC_CONFIG['client_id'],
        'client_secret' => $OIDC_CONFIG['client_secret'],
        'code' => $code,
        'redirect_uri' => $OIDC_CONFIG['redirect_uri'],
        'code_verifier' => $code_verifier
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($post_data)
        ]
    ]);

    $response = file_get_contents($OIDC_CONFIG['token_endpoint'], false, $context);
    if ($response === false) {
        error_log("Failed to exchange code for tokens");
        return false;
    }

    $tokens = json_decode($response, true);
    if (!$tokens || !isset($tokens['access_token'])) {
        error_log("Invalid token response");
        return false;
    }

    return $tokens;
}

/**
 * Validate and decode ID token
 */
function validate_id_token($id_token)
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled'])) {
        return false;
    }

    try {
        // For now, we'll do basic validation
        // In production, you should validate the JWT signature using JWKS
        $token_parts = explode('.', $id_token);
        if (count($token_parts) !== 3) {
            error_log("Invalid ID token format");
            return false;
        }

        $payload = json_decode(base64_decode(strtr($token_parts[1], '-_', '+/')), true);
        if (!$payload) {
            error_log("Failed to decode ID token payload");
            return false;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            error_log("ID token expired");
            return false;
        }

        // Check issuer
        if (isset($payload['iss']) && $payload['iss'] !== $OIDC_CONFIG['issuer']) {
            error_log("Invalid issuer in ID token");
            return false;
        }

        return $payload;
    } catch (Exception $e) {
        error_log("Error validating ID token: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user info from OIDC provider
 */
function get_oidc_user_info($access_token)
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled']) || empty($OIDC_CONFIG['userinfo_endpoint'])) {
        return false;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $access_token"
        ]
    ]);

    $response = file_get_contents($OIDC_CONFIG['userinfo_endpoint'], false, $context);
    if ($response === false) {
        error_log("Failed to get user info");
        return false;
    }

    $user_info = json_decode($response, true);
    if (!$user_info) {
        error_log("Invalid user info response");
        return false;
    }

    return $user_info;
}

/**
 * Map OIDC user info to LDAP user attributes
 */
function map_oidc_to_ldap_user($oidc_user_info)
{
    $ldap_user = [
        'uid' => $oidc_user_info['sub'] ?? $oidc_user_info['preferred_username'] ?? null,
        'mail' => $oidc_user_info['email'] ?? null,
        'cn' => $oidc_user_info['name'] ?? null,
        'givenname' => $oidc_user_info['given_name'] ?? null,
        'sn' => $oidc_user_info['family_name'] ?? null,
        'groups' => $oidc_user_info['groups'] ?? []
    ];

    // Clean up empty values
    return array_filter($ldap_user, function ($value) {
        return $value !== null && $value !== '';
    });
}

/**
 * Handle OIDC callback and create user session
 */
function handle_oidc_callback()
{
    global $OIDC_CONFIG, $LDAP;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled'])) {
        return false;
    }

    // Verify state parameter
    $state = $_GET['state'] ?? null;
    $session_state = $_SESSION['oidc_state'] ?? null;

    if (!$state || !$session_state || $state !== $session_state) {
        error_log("Invalid state parameter in OIDC callback");
        return false;
    }

    // Exchange code for tokens
    $code = $_GET['code'] ?? null;
    if (!$code) {
        error_log("No authorization code in OIDC callback");
        return false;
    }

    $tokens = exchange_code_for_tokens($code);
    if (!$tokens) {
        return false;
    }

    // Validate ID token
    $id_token = $tokens['id_token'] ?? null;
    if (!$id_token) {
        error_log("No ID token in response");
        return false;
    }

    $token_payload = validate_id_token($id_token);
    if (!$token_payload) {
        return false;
    }

    // Get user info
    $access_token = $tokens['access_token'] ?? null;
    $user_info = get_oidc_user_info($access_token);
    if (!$user_info) {
        // Fall back to ID token claims
        $user_info = $token_payload;
    }

    // Map to LDAP user attributes
    $ldap_user = map_oidc_to_ldap_user($user_info);

    // Find or create user in LDAP
    $user_dn = find_or_create_oidc_user($ldap_user);
    if (!$user_dn) {
        error_log("Failed to find or create OIDC user");
        return false;
    }

    // Create user session
    $user_id = $ldap_user['uid'];
    $is_admin = check_oidc_user_admin_status($user_dn, $ldap_user['groups']);
    $is_maintainer = check_oidc_user_maintainer_status($user_dn, $ldap_user['groups']);

    set_passkey_cookie($user_id, $is_admin, $is_maintainer);

    // Clean up OIDC session data
    unset($_SESSION['oidc_state']);
    unset($_SESSION['oidc_code_verifier']);

    return true;
}

/**
 * Find or create OIDC user in LDAP
 */
function find_or_create_oidc_user($ldap_user)
{
    global $LDAP;

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }

    // Search for existing user
    $user_filter = "(&(objectclass=inetOrgPerson)(uid={$ldap_user['uid']}))";
    $ldap_search = @ldap_search($ldap, $LDAP['base_dn'], $user_filter, ['dn']);

    if ($ldap_search) {
        $result = @ldap_get_entries($ldap, $ldap_search);
        if ($result['count'] > 0) {
            // User exists, update if needed
            $user_dn = $result[0]['dn'];
            update_oidc_user_attributes($ldap, $user_dn, $ldap_user);
            ldap_close($ldap);
            return $user_dn;
        }
    }

    // Create new user
    $user_dn = create_oidc_user($ldap, $ldap_user);
    ldap_close($ldap);

    return $user_dn;
}

/**
 * Create new OIDC user in LDAP
 */
function create_oidc_user($ldap, $ldap_user)
{
    global $LDAP;

    $user_dn = "uid={$ldap_user['uid']},ou=people,dc=example,dc=com";

    $user_entry = [
        'objectclass' => ['top', 'person', 'inetOrgPerson'],
        'uid' => $ldap_user['uid'],
        'cn' => $ldap_user['cn'] ?? $ldap_user['uid'],
        'sn' => $ldap_user['sn'] ?? 'Unknown',
        'givenname' => $ldap_user['givenname'] ?? 'Unknown',
        'mail' => $ldap_user['mail'] ?? $ldap_user['uid'] . '@example.com'
    ];

    if (!@ldap_add($ldap, $user_dn, $user_entry)) {
        error_log("Failed to create OIDC user: " . ldap_error($ldap));
        return false;
    }

    return $user_dn;
}

/**
 * Update existing OIDC user attributes
 */
function update_oidc_user_attributes($ldap, $user_dn, $ldap_user)
{
    $update_attrs = [];

    if (isset($ldap_user['cn'])) {
        $update_attrs['cn'] = $ldap_user['cn'];
    }
    if (isset($ldap_user['mail'])) {
        $update_attrs['mail'] = $ldap_user['mail'];
    }
    if (isset($ldap_user['givenname'])) {
        $update_attrs['givenname'] = $ldap_user['givenname'];
    }
    if (isset($ldap_user['sn'])) {
        $update_attrs['sn'] = $ldap_user['sn'];
    }

    if (!empty($update_attrs)) {
        @ldap_modify($ldap, $user_dn, $update_attrs);
    }
}

/**
 * Check if OIDC user has admin status
 */
function check_oidc_user_admin_status($user_dn, $groups)
{
    global $LDAP;

    // Check if user is in admin group via OIDC groups claim
    if (in_array('administrators', $groups)) {
        return true;
    }

    // Fall back to LDAP group membership check
    return ldap_is_group_member(open_ldap_connection(), $LDAP['roles_dn'], $LDAP['admin_role'], $user_dn);
}

/**
 * Check if OIDC user has maintainer status
 */
function check_oidc_user_maintainer_status($user_dn, $groups)
{
    global $LDAP;

    // Check if user is in maintainer group via OIDC groups claim
    if (in_array('maintainers', $groups)) {
        return true;
    }

    // Fall back to LDAP group membership check
    return ldap_is_group_member(open_ldap_connection(), $LDAP['roles_dn'], $LDAP['maintainer_role'], $user_dn);
}

/**
 * Check if OIDC authentication is required
 */
function require_oidc_auth()
{
    global $OIDC_CONFIG;

    if (!is_array($OIDC_CONFIG) || empty($OIDC_CONFIG['enabled'])) {
        return false;
    }

    // Check if user is already authenticated (cookie name matches web_functions.inc.php)
    if (isset($_SESSION['user_id']) || isset($_COOKIE['orf_cookie'])) {
        return false;
    }

    return true;
}

/**
 * Redirect to OIDC login if required
 */
function redirect_to_oidc_if_required()
{
    if (require_oidc_auth()) {
        $auth_url = generate_oidc_auth_url();
        if ($auth_url) {
            header("Location: $auth_url");
            exit;
        }
    }
}
