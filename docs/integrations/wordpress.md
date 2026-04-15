# WordPress Integration

This guide explains how to configure WordPress to authenticate users via the Dex OIDC provider bundled with LDAP User Manager.

## Overview

WordPress supports OIDC through the **OpenID Connect Generic** plugin (`daggerhart/openid-connect-generic`). Users sign in via Dex, which authenticates them against the LDAP directory.

---

## Prerequisites

- WordPress installed and running
- Dex OIDC provider accessible at `https://id.example.org`
- A client secret configured in Dex for the `wordpress` client

---

## 1. Install the Plugin

Install **OpenID Connect Generic** through the WordPress admin UI:

1. Go to **Plugins → Add New**
2. Search for "OpenID Connect Generic"
3. Install and activate the plugin

Alternatively, use WP-CLI:

```bash
wp plugin install daggerhart-openid-connect-generic --activate
```

---

## 2. Configure wp-config.php

Add the OIDC settings to `wp-config.php` before the `/* That's all, stop editing! */` line. An example snippet is available at [`services/wordpress/wp-config.php`](../../services/wordpress/wp-config.php).

```php
define('OIDC_LOGIN_TYPE',          'button');
define('OIDC_CLIENT_ID',           'wordpress');
define('OIDC_CLIENT_SECRET',       'your-wordpress-client-secret-here');
define('OIDC_ENDPOINT_LOGIN_URL',  'https://id.example.org/auth');
define('OIDC_ENDPOINT_USERINFO_URL', 'https://id.example.org/userinfo');
define('OIDC_ENDPOINT_TOKEN_URL',  'https://id.example.org/token');
define('OIDC_ENDPOINT_LOGOUT_URL', 'https://id.example.org/end_session');
define('OIDC_SCOPE',               'openid profile email groups');
define('OIDC_IDENTITY_KEY',        'preferred_username');
define('OIDC_NO_SSLVERIFY',        0);
define('OIDC_HTTP_REQUEST_TIMEOUT', 5);
define('OIDC_ENFORCE_PRIVACY',     0);
define('OIDC_ALTERNATE_REDIRECT_URI', 0);
define('OIDC_TOKEN_REFRESH_ENABLE', 1);
define('OIDC_LINK_EXISTING_USERS', 1);
define('OIDC_CREATE_IF_DOES_NOT_EXIST', 1);
define('OIDC_REDIRECT_USER_BACK',  1);
define('OIDC_REDIRECT_ON_LOGOUT',  1);
define('OIDC_ENABLE_LOGGING',      0);
define('OIDC_LOG_LIMIT',           1000);
```

---

## 3. Plugin Settings

After activation, the plugin can also be configured in the WordPress admin under **Settings → OpenID Connect Client**. Settings defined in `wp-config.php` take precedence.

---

## 4. User Role Mapping

The plugin creates WordPress users on first login. Default role assignment:

| LDAP Group | WordPress Role |
|------------|---------------|
| `admins` | `administrator` |
| `maintainers` | `editor` |
| (all others) | `subscriber` |

Role mapping requires a custom `mu-plugin` or hook. The plugin provides the `openid-connect-generic-user-created` action for post-provisioning customization.

---

## 5. Test the Integration

1. Navigate to `https://wordpress.example.org/wp-login.php`
2. Click **Login with SSO**
3. Verify you are redirected to `https://id.example.org/auth`
4. Log in with LDAP credentials
5. Confirm you are redirected back to WordPress and signed in

```bash
# Verify the Dex discovery endpoint is reachable
curl -s https://id.example.org/.well-known/openid-configuration | jq .issuer
```

---

## 6. Troubleshooting

**"Invalid redirect URI"**
The WordPress redirect URI is `https://wordpress.example.org/wp-admin/admin-ajax.php?action=openid-connect-authorize`. Ensure this matches the entry in Dex `config.yaml`.

**"User not created on login"**
Set `OIDC_CREATE_IF_DOES_NOT_EXIST` to `1` in `wp-config.php`.

**"SSL verification failed"**
If using a self-signed certificate for Dex, set `OIDC_NO_SSLVERIFY` to `1` for testing only. Use a valid certificate in production.

**Verify Dex client configuration:**
```yaml
# dex/config.yaml
staticClients:
  - id: wordpress
    secret: your-wordpress-client-secret-here
    redirectURIs:
      - https://wordpress.example.org/wp-admin/admin-ajax.php?action=openid-connect-authorize
```

---

## Security Considerations

- Store the client secret securely; do not commit it to version control
- Use HTTPS for both WordPress and the Dex provider
- Set `OIDC_NO_SSLVERIFY` to `0` in production
- Restrict the WordPress admin login page if OIDC is the primary authentication method

## Support

- [OpenID Connect Generic Plugin](https://wordpress.org/plugins/daggerhart-openid-connect-generic/)
- [Plugin GitHub Repository](https://github.com/daggerhart/openid-connect-generic)
- [Dex Documentation](https://dexidp.io/docs/)
- [OIDC Quick Reference](oidc-quick-reference.md)
