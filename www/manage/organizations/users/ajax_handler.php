<?php

declare(strict_types=1);

// Set include path for required functions first
set_include_path(".:" . __DIR__ . "/../../../includes/");

// Include config to get session settings
include_once "config.inc.php";

// Start session for authentication and CSRF validation
if (session_status() === PHP_SESSION_NONE) {
    // Use the same session configuration as the main application
    if (isset($SERVER_PATH) && !empty($SERVER_PATH)) {
        session_set_cookie_params([
            'path' => $SERVER_PATH,
            'httponly' => true,
            'samesite' => 'strict'
        ]);
    }
    session_start();
}

$ajax_debug = (getenv('ENVIRONMENT') === 'development');
if ($ajax_debug) {
    error_log("AJAX Handler - Session ID: " . session_id());
    error_log("AJAX Handler - Session path: " . session_save_path());
    error_log("AJAX Handler - Session cookie path: " . ini_get('session.cookie_path'));
    error_log("AJAX Handler - Session data keys: " . implode(', ', array_keys($_SESSION)));
    error_log("AJAX Handler - VALIDATED: " . (isset($_SESSION['VALIDATED']) ? ($_SESSION['VALIDATED'] ? 'TRUE' : 'FALSE') : 'NOT SET'));
    error_log("AJAX Handler - CSRF token: " . (isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 8) . '...' : 'NOT SET'));
}

require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap', 'organization', 'user']);

// Security: Only allow AJAX requests
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access not allowed']);
    exit;
}

// Security: Only allow GET requests for this endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Security: Check if user is authenticated
// The session uses different keys than expected - check for actual authentication data
$is_authenticated = false;
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $is_authenticated = true;
}

if (!$is_authenticated) {
    if ($ajax_debug) {
        error_log("AJAX Handler - Authentication failed. User ID not found in session");
    }
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Security: Validate CSRF token
if (!isset($_GET['csrf_token']) || empty($_GET['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing security token']);
    exit;
}

// Check if CSRF token exists in session and matches
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
    if ($ajax_debug) {
        error_log("AJAX Handler - CSRF validation failed. Session token: " . (isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 8) . '...' : 'NOT SET'));
    }
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}

// Check if this is a user data fetch request
if (!isset($_GET['action']) || $_GET['action'] !== 'fetch_user_data') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// Validate required parameters
if (!isset($_GET['fetch_user_data']) || empty($_GET['fetch_user_data'])) {
    http_response_code(400);
    echo json_encode(['error' => 'User identifier required']);
    exit;
}

$res = resolve_organization_from_request();
if ($res['error'] !== null) {
    http_response_code(strpos($res['error'], 'not found') !== false ? 404 : 400);
    echo json_encode(['error' => $res['error']]);
    exit;
}
$orgName = $res['org_name'] ?? '';
$org_uuid = $res['org_uuid'] ?? '';

// Access control: Check if user has permission to access this organization
$user_roles = [
    'is_admin' => isset($_SESSION['is_admin']) && $_SESSION['is_admin'],
    'is_maintainer' => isset($_SESSION['is_maintainer']) && $_SESSION['is_maintainer'],
    'is_org_admin' => isset($_SESSION['is_org_admin']) && $_SESSION['is_org_admin'],
    'org_uuid' => isset($_SESSION['org_uuid']) ? $_SESSION['org_uuid'] : null
];

if ($ajax_debug) {
    error_log("AJAX Handler - User roles: " . print_r($user_roles, true));
}

// Only allow access if user is global admin, maintainer, or org admin for this specific organization
$has_access = false;
if ($user_roles['is_admin'] || $user_roles['is_maintainer']) {
    $has_access = true;
    if ($ajax_debug) {
        error_log("AJAX Handler - Access granted via global admin/maintainer role");
    }
} elseif ($user_roles['is_org_admin'] && $user_roles['org_uuid'] === $org_uuid) {
    $has_access = true;
    if ($ajax_debug) {
        error_log("AJAX Handler - Access granted via org admin role for org: " . $org_uuid);
    }
} else {
    if ($ajax_debug) {
        error_log("AJAX Handler - Access denied. User roles: " . print_r($user_roles, true) . ", Requested org: " . $org_uuid);
    }
}

if (!$has_access) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get user data
$fetchUserParam = $_GET['fetch_user_data'];
$user_data = null;

// Check if this is a UUID or uid
$is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $fetchUserParam);

if ($is_uuid) {
    // UUID-based lookup
    $ldap_connection = open_ldap_connection();
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $fetchUserParam, $usersDn);
    ldap_close($ldap_connection);

    if ($user_by_uuid) {
        $user_data = [
            'givenName' => get_ldap_attribute($user_by_uuid, 'givenName'),
            'sn' => get_ldap_attribute($user_by_uuid, 'sn'),
            'mail' => get_ldap_attribute($user_by_uuid, 'mail'),
            'uid' => get_ldap_attribute($user_by_uuid, 'uid')
        ];
    }
} else {
    // Legacy uid-based lookup
    $existingUsers = getUsersInOrg($orgName);
    if (is_array($existingUsers)) {
        foreach ($existingUsers as $user) {
            if (strtolower(get_ldap_attribute($user, 'uid')) === strtolower($fetchUserParam)) {
                $user_data = [
                    'givenName' => get_ldap_attribute($user, 'givenName'),
                    'sn' => get_ldap_attribute($user, 'sn'),
                    'mail' => get_ldap_attribute($user, 'mail'),
                    'uid' => get_ldap_attribute($user, 'uid')
                ];
                break;
            }
        }
    }
}

// Return response
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($user_data) {
    echo json_encode([
        'success' => true,
        'user_data' => $user_data
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'User not found',
        'user_data' => null
    ]);
}
exit;
