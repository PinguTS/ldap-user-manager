<?php
declare(strict_types=1);

// Include security configuration
include_once __DIR__ . '/security_config.inc.php';

$log_prefix = "";

# User account defaults

$DEFAULT_USER_GROUP = getenv('DEFAULT_USER_GROUP') ?: 'everybody';
$DEFAULT_USER_SHELL = getenv('DEFAULT_USER_SHELL') ?: '/bin/bash';
$ENFORCE_SAFE_SYSTEM_NAMES = strcasecmp(getenv('ENFORCE_SAFE_SYSTEM_NAMES'), 'FALSE') !== 0;
$USERNAME_FORMAT = getenv('USERNAME_FORMAT') ?: '{first_name}-{last_name}';
$USERNAME_REGEX = getenv('USERNAME_REGEX') ?: '^[a-z][a-zA-Z0-9\._-]{3,32}$'; // We use the username regex for groups too.

if (getenv('PASSWORD_HASH')) {
    $PASSWORD_HASH = strtoupper(getenv('PASSWORD_HASH'));
}
$ACCEPT_WEAK_PASSWORDS = strcasecmp(getenv('ACCEPT_WEAK_PASSWORDS'), 'TRUE') === 0;

$min_uid = 2000;
$min_gid = 2000;

# User field configuration
# Required fields for user creation (must be present)
# Note: The 'uid' field is always required as it's used as the RDN
$LDAP['user_required_fields'] = getenv('LDAP_USER_REQUIRED_FIELDS') ? 
    explode(',', getenv('LDAP_USER_REQUIRED_FIELDS')) : 
    ['uid', 'givenname', 'sn', 'mail'];

# Ensure 'uid' field is always included in required fields
if (!in_array('uid', $LDAP['user_required_fields'])) {
    $LDAP['user_required_fields'][] = 'uid';
}

# Optional fields for user creation (can be present but not required)
# For system users, we only need basic fields - address fields are not needed
$LDAP['user_optional_fields'] = getenv('LDAP_USER_OPTIONAL_FIELDS') ? 
    explode(',', getenv('LDAP_USER_OPTIONAL_FIELDS')) : 
    ['cn', 'organization', 'description', 'telephoneNumber', 'labeledURI'];

# Field mappings from form names to LDAP attributes
# Simplified for system users - removed address-related fields
$LDAP['user_field_mappings'] = [
    'first_name' => 'givenname',
    'last_name' => 'sn',
    'email' => 'mail',
    'common_name' => 'cn',
    'uid' => 'uid',
    'organization' => 'organization',
    'user_role' => 'description',
    'phone' => 'telephoneNumber',
    'website' => 'labeledURI'
];

# Field labels for the UI (human-readable names)
$LDAP['user_field_labels'] = [
    'first_name' => 'First Name',
    'last_name' => 'Last Name',
    'email' => 'Email',
    'common_name' => 'Common Name',
    'uid' => 'Account ID',
    'organization' => 'Organization',
    'user_role' => 'User Role',
    'phone' => 'Phone Number',
    'website' => 'Website'
];

# Field types for form rendering
$LDAP['user_field_types'] = [
    'first_name' => 'text',
    'last_name' => 'text',
    'email' => 'email',
    'common_name' => 'text',
    'uid' => 'text',
    'organization' => 'text',
    'user_role' => 'text',
    'phone' => 'tel',
    'website' => 'url'
];

#Default attributes and objectclasses

$LDAP['account_attribute'] = getenv('LDAP_ACCOUNT_ATTRIBUTE') ?: 'mail';
$LDAP['account_objectclasses'] = ['person', 'inetOrgPerson'];
$LDAP['default_attribute_map'] = [
    "givenname" => [
        "label" => "First name",
        "onkeyup" => "update_username(); update_email(); update_cn(); update_homedir(); check_email_validity(document.getElementById('mail').value);",
        "required" => TRUE,
    ],
    "sn" => [
        "label" => "Last name",
        "onkeyup" => "update_username(); update_email(); update_cn(); update_homedir(); check_email_validity(document.getElementById('mail').value);",
        "required" => TRUE,
    ],
    "mail" => [
        "label" => "Email",
        "onkeyup" => "auto_email_update = false; check_email_validity(document.getElementById('mail').value);",
        "required" => TRUE,
    ],
    "cn" => [
        "label" => "Common name",
        "onkeyup" => "auto_cn_update = false;",
    ],
    "organization" => [
        "label" => "Organization",
        "required" => TRUE,
    ],
    "description" => [
        "label" => "User Role",
        "default" => "user",
    ],
    "userPassword" => [
        "label" => "Password/Passcode",
    ]
];

$LDAP['group_attribute'] = getenv('LDAP_GROUP_ATTRIBUTE') ?: 'cn';
$LDAP['group_objectclasses'] = ['top', 'posixGroup']; // groupOfUniqueNames is added automatically if rfc2307bis is available.

$LDAP['default_group_attribute_map'] = ["description" => ["label" => "Description"]];

$SHOW_POSIX_ATTRIBUTES = strcasecmp(getenv('SHOW_POSIX_ATTRIBUTES'), 'TRUE') === 0;

if ($SHOW_POSIX_ATTRIBUTES !== TRUE) {
    # Remove POSIX-specific attributes when not needed
    unset($LDAP['default_attribute_map']['uidnumber']);
    unset($LDAP['default_attribute_map']['gidnumber']);
    unset($LDAP['default_attribute_map']['homedirectory']);
    unset($LDAP['default_attribute_map']['loginshell']);
} else {
    $LDAP['default_attribute_map']["uidnumber"] = ["label" => "UID"];
    $LDAP['default_attribute_map']["gidnumber"] = ["label" => "GID"];
    $LDAP['default_attribute_map']["homedirectory"] = ["label" => "Home directory", "onkeyup" => "auto_homedir_update = false;"];
    $LDAP['default_attribute_map']["loginshell"] = ["label" => "Shell", "default" => $DEFAULT_USER_SHELL];
    $LDAP['default_group_attribute_map']["gidnumber"] = ["label" => "Group ID number"];
}

## LDAP server

$LDAP['uri'] = getenv('LDAP_URI');
$LDAP['base_dn'] = getenv('LDAP_BASE_DN');
$LDAP['admin_bind_dn'] = getenv('LDAP_ADMIN_BIND_DN');
$LDAP['admin_bind_pwd'] = getenv('LDAP_ADMIN_BIND_PWD');
$LDAP['connection_type'] = "plain";
$LDAP['require_starttls'] = strcasecmp(getenv('LDAP_REQUIRE_STARTTLS'), 'TRUE') === 0;
$LDAP['ignore_cert_errors'] = strcasecmp(getenv('LDAP_IGNORE_CERT_ERRORS'), 'TRUE') === 0;
$LDAP['rfc2307bis_check_run'] = FALSE;

# Various advanced LDAP settings

# Role names used throughout the system (groups, user descriptions, etc.)
$LDAP['admin_role'] = getenv('LDAP_ADMIN_ROLE') ?: 'administrator';
$LDAP['maintainer_role'] = getenv('LDAP_MAINTAINER_ROLE') ?: 'maintainer';
$LDAP['org_admin_role'] = getenv('LDAP_ORG_ADMIN_ROLE') ?: 'org_admin';

# Organization field configuration
# Required fields for organization creation (must be present)
# Note: The 'o' (organization name) field is always required as it's used as the RDN
$LDAP['org_required_fields'] = getenv('LDAP_ORG_REQUIRED_FIELDS') ? 
    explode(',', getenv('LDAP_ORG_REQUIRED_FIELDS')) : 
    ['o', 'mail'];

# Ensure 'o' field is always included in required fields
if (!in_array('o', $LDAP['org_required_fields'])) {
    $LDAP['org_required_fields'][] = 'o';
}

# Optional fields for organization creation
$LDAP['org_optional_fields'] = getenv('LDAP_ORG_OPTIONAL_FIELDS') ? 
    explode(',', getenv('LDAP_ORG_OPTIONAL_FIELDS')) : 
    ['description', 'telephoneNumber', 'labeledURI'];

# Field mappings from form names to LDAP attributes for organizations
$LDAP['org_field_mappings'] = [
    'org_name' => 'o',
    'email' => 'mail',
    'description' => 'description',
    'phone' => 'telephoneNumber',
    'website' => 'labeledURI'
];

# Field labels for the UI (human-readable names) for organizations
$LDAP['org_field_labels'] = [
    'org_name' => 'Organization Name',
    'email' => 'Email',
    'description' => 'Description',
    'phone' => 'Phone Number',
    'website' => 'Website'
];

# Field types for form rendering for organizations
$LDAP['org_field_types'] = [
    'org_name' => 'text',
    'email' => 'email',
    'description' => 'text',
    'phone' => 'tel',
    'website' => 'url'
];

# Default attributes and objectclasses for organizations
$LDAP['org_objectclasses'] = ['top', 'organization'];

# Directory structure configuration
$LDAP['org_dn'] = "ou=organizations," . $LDAP['base_dn'];
$LDAP['people_dn'] = "ou=people," . $LDAP['base_dn'];
$LDAP['roles_dn'] = "ou=roles," . $LDAP['base_dn'];
$LDAP['system_users_dn'] = "ou=system_users," . $LDAP['base_dn'];

# Session configuration
$SESSION_TIMEOUT = getenv('SESSION_TIMEOUT') ? (int)getenv('SESSION_TIMEOUT') : 3600;
$SESSION_DEBUG = getenv('SESSION_DEBUG') === 'TRUE';
$LDAP_DEBUG = getenv('LDAP_DEBUG') === 'TRUE';
$LDAP_VERBOSE_CONNECTION_LOGS = getenv('LDAP_VERBOSE_CONNECTION_LOGS') === 'TRUE';

# Security configuration
$NO_HTTPS = getenv('NO_HTTPS') === 'TRUE';
$REMOTE_HTTP_HEADERS_LOGIN = getenv('REMOTE_HTTP_HEADERS_LOGIN') === 'TRUE';

# Server path configuration
$SERVER_PATH = getenv('SERVER_PATH') ?: '/';

# Mail configuration
$MAIL['smtp_host'] = getenv('MAIL_SMTP_HOST') ?: 'localhost';
$MAIL['smtp_port'] = getenv('MAIL_SMTP_PORT') ?: 25;
$MAIL['smtp_username'] = getenv('MAIL_SMTP_USERNAME') ?: '';
$MAIL['smtp_password'] = getenv('MAIL_SMTP_PASSWORD') ?: '';
$MAIL['from_address'] = getenv('MAIL_FROM_ADDRESS') ?: 'noreply@example.com';
$MAIL['from_name'] = getenv('MAIL_FROM_NAME') ?: 'LDAP User Manager';

# Application configuration
$APP_NAME = getenv('APP_NAME') ?: 'LDAP User Manager';
$APP_VERSION = getenv('APP_VERSION') ?: '1.0.0';
$APP_DEBUG = getenv('APP_DEBUG') === 'TRUE';

# Logging configuration
$LOG_LEVEL = getenv('LOG_LEVEL') ?: 'INFO';
$LOG_FILE = getenv('LOG_FILE') ?: '/var/log/ldap-user-manager.log';

# Feature flags
$ENABLE_USER_REGISTRATION = getenv('ENABLE_USER_REGISTRATION') !== 'FALSE';
$ENABLE_ORGANIZATION_CREATION = getenv('ENABLE_ORGANIZATION_CREATION') !== 'FALSE';
$ENABLE_ROLE_MANAGEMENT = getenv('ENABLE_ROLE_MANAGEMENT') !== 'FALSE';
$ENABLE_PASSWORD_RESET = getenv('ENABLE_PASSWORD_RESET') !== 'FALSE';

# UI configuration
$UI_THEME = getenv('UI_THEME') ?: 'default';
$UI_LANGUAGE = getenv('UI_LANGUAGE') ?: 'en';
$UI_TIMEZONE = getenv('UI_TIMEZONE') ?: 'UTC';

# Performance configuration
$CACHE_ENABLED = getenv('CACHE_ENABLED') === 'TRUE';
$CACHE_TTL = getenv('CACHE_TTL') ? (int)getenv('CACHE_TTL') : 300;
$CACHE_DRIVER = getenv('CACHE_DRIVER') ?: 'file';

# Backup configuration
$BACKUP_ENABLED = getenv('BACKUP_ENABLED') === 'TRUE';
$BACKUP_RETENTION_DAYS = getenv('BACKUP_RETENTION_DAYS') ? (int)getenv('BACKUP_RETENTION_DAYS') : 30;
$BACKUP_PATH = getenv('BACKUP_PATH') ?: '/var/backups/ldap-user-manager';

# Monitoring configuration
$MONITORING_ENABLED = getenv('MONITORING_ENABLED') === 'TRUE';
$MONITORING_INTERVAL = getenv('MONITORING_INTERVAL') ? (int)getenv('MONITORING_INTERVAL') : 300;
$MONITORING_ALERT_EMAIL = getenv('MONITORING_ALERT_EMAIL') ?: '';

# Development configuration
$ENVIRONMENT = getenv('ENVIRONMENT') ?: 'production';
$DISPLAY_ERRORS = getenv('DISPLAY_ERRORS') === 'TRUE' && $ENVIRONMENT === 'development';
$LOG_ERRORS = getenv('LOG_ERRORS') !== 'FALSE';

# Set error reporting based on environment
if ($ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

# Set timezone
if ($UI_TIMEZONE) {
    date_default_timezone_set($UI_TIMEZONE);
}

# Initialize logging
if ($LOG_FILE && is_writable(dirname($LOG_FILE))) {
    ini_set('log_errors', '1');
    ini_set('error_log', $LOG_FILE);
}

# Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    if (!$NO_HTTPS) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

