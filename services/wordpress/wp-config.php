<?php
/**
 * WordPress Configuration with OIDC Integration
 * This file shows the configuration structure for WordPress with OIDC plugin
 */

// WordPress Database Configuration
define('DB_NAME', 'wordpress');
define('DB_USER', 'wordpress');
define('DB_PASSWORD', 'your-db-password');
define('DB_HOST', 'localhost:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// WordPress Security Keys
define('AUTH_KEY',         'your-auth-key-here');
define('SECURE_AUTH_KEY',  'your-secure-auth-key-here');
define('LOGGED_IN_KEY',    'your-logged-in-key-here');
define('NONCE_KEY',        'your-nonce-key-here');
define('AUTH_SALT',        'your-auth-salt-here');
define('SECURE_AUTH_SALT', 'your-secure-auth-salt-here');
define('LOGGED_IN_SALT',   'your-logged-in-salt-here');
define('NONCE_SALT',       'your-nonce-salt-here');

// WordPress URLs
define('WP_HOME', 'https://wordpress.example.org');
define('WP_SITEURL', 'https://wordpress.example.org');

// WordPress Directory
define('WP_CONTENT_DIR', '/var/www/html/wp-content');
define('WP_CONTENT_URL', 'https://wordpress.example.org/wp-content');

// OIDC Configuration
define('OIDC_CLIENT_ID', 'wordpress');
define('OIDC_CLIENT_SECRET', 'your-wordpress-client-secret-here');
define('OIDC_ISSUER_URL', 'https://id.example.org');
define('OIDC_REDIRECT_URI', 'https://wordpress.example.org/wp-admin/admin-ajax.php?action=oidc_callback');
define('OIDC_SCOPES', 'openid profile email groups');

// LDAP Configuration (optional fallback)
define('LDAP_HOST', 'ldap.example.com');
define('LDAP_PORT', 636);
define('LDAP_BASE_DN', 'dc=example,dc=com');
define('LDAP_BIND_DN', 'cn=admin,dc=example,dc=com');
define('LDAP_BIND_PASSWORD', 'admin123');

// WordPress Settings
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');

// File Upload Settings
define('WP_ALLOW_REPAIR', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);

// Security Settings
define('FORCE_SSL_ADMIN', true);
define('FORCE_SSL_LOGIN', true);
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', false);

// Performance Settings
define('WP_CACHE', true);
define('WP_POST_REVISIONS', 5);
define('EMPTY_TRASH_DAYS', 7);

// Table Prefix
$table_prefix = 'wp_';

// Absolute path to the WordPress directory
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// OIDC Plugin Configuration
add_filter('oidc_settings', function($settings) {
    $settings['client_id'] = OIDC_CLIENT_ID;
    $settings['client_secret'] = OIDC_CLIENT_SECRET;
    $settings['issuer_url'] = OIDC_ISSUER_URL;
    $settings['redirect_uri'] = OIDC_REDIRECT_URI;
    $settings['scopes'] = OIDC_SCOPES;
    $settings['auto_login'] = true;
    $settings['auto_logout'] = true;
    $settings['user_mapping'] = [
        'username' => 'preferred_username',
        'email' => 'email',
        'first_name' => 'given_name',
        'last_name' => 'family_name',
        'display_name' => 'name'
    ];
    return $settings;
});

// LDAP Plugin Configuration (optional)
add_filter('ldap_auth_settings', function($settings) {
    $settings['ldap_host'] = LDAP_HOST;
    $settings['ldap_port'] = LDAP_PORT;
    $settings['ldap_base_dn'] = LDAP_BASE_DN;
    $settings['ldap_bind_dn'] = LDAP_BIND_DN;
    $settings['ldap_bind_password'] = LDAP_BIND_PASSWORD;
    $settings['ldap_user_filter'] = '(objectClass=inetOrgPerson)';
    $settings['ldap_username_attribute'] = 'uid';
    $settings['ldap_email_attribute'] = 'mail';
    $settings['ldap_name_attribute'] = 'cn';
    return $settings;
});

// Load WordPress
require_once ABSPATH . 'wp-settings.php';
