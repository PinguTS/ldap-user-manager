<?php
/**
 * wp-config.php — OIDC configuration snippet
 * Add these defines to your existing wp-config.php, before the
 * "That's all, stop editing!" line.
 * See docs/integrations/wordpress.md for the full integration guide.
 */

define('OIDC_LOGIN_TYPE',             'button');
define('OIDC_CLIENT_ID',              'wordpress');
define('OIDC_CLIENT_SECRET',          'your-wordpress-client-secret-here');
define('OIDC_ENDPOINT_LOGIN_URL',     'https://id.example.org/auth');
define('OIDC_ENDPOINT_USERINFO_URL',  'https://id.example.org/userinfo');
define('OIDC_ENDPOINT_TOKEN_URL',     'https://id.example.org/token');
define('OIDC_ENDPOINT_LOGOUT_URL',    'https://id.example.org/end_session');
define('OIDC_SCOPE',                  'openid profile email groups');
define('OIDC_IDENTITY_KEY',           'preferred_username');
define('OIDC_NO_SSLVERIFY',           0);
define('OIDC_HTTP_REQUEST_TIMEOUT',   5);
define('OIDC_ENFORCE_PRIVACY',        0);
define('OIDC_ALTERNATE_REDIRECT_URI', 0);
define('OIDC_TOKEN_REFRESH_ENABLE',   1);
define('OIDC_LINK_EXISTING_USERS',    1);
define('OIDC_CREATE_IF_DOES_NOT_EXIST', 1);
define('OIDC_REDIRECT_USER_BACK',     1);
define('OIDC_REDIRECT_ON_LOGOUT',     1);
define('OIDC_ENABLE_LOGGING',         0);
define('OIDC_LOG_LIMIT',              1000);
