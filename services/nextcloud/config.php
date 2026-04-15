<?php
/**
 * Nextcloud config/config.php — OIDC configuration snippet
 * Add these keys to your existing config.php array.
 * See docs/integrations/nextcloud.md for the full integration guide.
 */

$CONFIG = [
    'oidc_login_provider_url'         => 'https://id.example.org',
    'oidc_login_client_id'            => 'nextcloud',
    'oidc_login_client_secret'        => 'your-nextcloud-client-secret-here',
    'oidc_login_auto_redirect'        => true,
    'oidc_login_redir_fallback'       => true,
    'oidc_login_scope'                => 'openid profile email groups',
    'oidc_login_button_text'          => 'Log in with LDAP SSO',
    'oidc_login_disable_registration' => true,

    'oidc_login_attributes' => [
        'id'       => 'preferred_username',
        'name'     => 'name',
        'mail'     => 'email',
        'groups'   => 'groups',
        'ldap_uid' => 'uid',
        'is_admin' => 'is_admin',
    ],
];
