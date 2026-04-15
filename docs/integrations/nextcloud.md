# Nextcloud Integration

This guide explains how to configure Nextcloud to authenticate users via the Dex OIDC provider bundled with LDAP User Manager.

## Overview

Nextcloud supports OIDC through the **Social Login** or **OpenID Connect Login** app. This guide uses the `oidc_login` app (`nextcloud/user_oidc`), which maps OIDC claims to Nextcloud user attributes and handles automatic provisioning.

---

## Prerequisites

- Nextcloud installed and running
- Dex OIDC provider accessible at `https://id.example.org`
- A client secret configured in Dex for the `nextcloud` client
- `occ` command-line tool available

---

## 1. Install the OIDC Login App

Install via the Nextcloud App Store in the admin UI under **Apps → Integration → OpenID Connect Login**, or from the command line:

```bash
php occ app:install oidc_login
php occ app:enable oidc_login
```

---

## 2. Configure via OCC Commands

The recommended approach is to configure OIDC using `occ config:system:set` commands. A complete set of commands is available at [`services/nextcloud/occ-commands.md`](../../services/nextcloud/occ-commands.md).

Key commands:

```bash
php occ config:system:set oidc_login_provider_url --value="https://id.example.org"
php occ config:system:set oidc_login_client_id --value="nextcloud"
php occ config:system:set oidc_login_client_secret --value="your-nextcloud-client-secret-here"
php occ config:system:set oidc_login_auto_redirect --value=true --type=bool
php occ config:system:set oidc_login_redir_fallback --value=true --type=bool
php occ config:system:set oidc_login_scope --value="openid profile email groups"
```

---

## 3. Configure config.php (Alternative)

You can also add configuration directly to `config/config.php`. An example snippet is available at [`services/nextcloud/config.php`](../../services/nextcloud/config.php).

Minimum required keys:

```php
$CONFIG = [
    'oidc_login_provider_url'    => 'https://id.example.org',
    'oidc_login_client_id'       => 'nextcloud',
    'oidc_login_client_secret'   => 'your-nextcloud-client-secret-here',
    'oidc_login_auto_redirect'   => true,
    'oidc_login_redir_fallback'  => true,
    'oidc_login_scope'           => 'openid profile email groups',
    'oidc_login_button_text'     => 'Log in with LDAP SSO',

    'oidc_login_attributes'      => [
        'id'            => 'preferred_username',
        'name'          => 'name',
        'mail'          => 'email',
        'groups'        => 'groups',
        'quota'         => 'ownCloudQuota',
        'home'          => 'homeDirectory',
        'ldap_uid'      => 'uid',
        'is_admin'      => 'is_admin',
    ],
];
```

---

## 4. Group Synchronization

When `groups` is included in the OIDC scope, Nextcloud will create groups matching the LDAP group names and assign users automatically on login.

```bash
php occ config:system:set oidc_login_scope --value="openid profile email groups"
```

---

## 5. Test the Integration

1. Navigate to `https://nextcloud.example.org`
2. Click **Log in with LDAP SSO**
3. Verify you are redirected to `https://id.example.org/auth`
4. Log in with LDAP credentials
5. Confirm you are redirected back to Nextcloud and signed in with the correct display name and group memberships

```bash
# Verify the Dex discovery endpoint is reachable
curl -s https://id.example.org/.well-known/openid-configuration | jq .issuer

# Check Nextcloud logs
tail -f /var/www/nextcloud/data/nextcloud.log
```

---

## 6. Troubleshooting

**"Redirect URI mismatch"**
The redirect URI Nextcloud sends must match the entry in Dex `config.yaml`. It is typically:
`https://nextcloud.example.org/apps/oidc_login/oidc`

**"User not provisioned"**
- Ensure `oidc_login_attributes.id` matches the claim key returned by Dex (use `preferred_username`)
- Check Nextcloud logs for attribute mapping errors

**"Auto redirect loops"**
Set `oidc_login_redir_fallback` to `true` so a fallback login page is available.

**Verify Dex client configuration:**
```yaml
# dex/config.yaml
staticClients:
  - id: nextcloud
    secret: your-nextcloud-client-secret-here
    redirectURIs:
      - https://nextcloud.example.org/apps/oidc_login/oidc
```

---

## Security Considerations

- Store the client secret securely; do not commit it to version control
- Use HTTPS for both Nextcloud and the Dex provider
- Review Nextcloud's session configuration after enabling OIDC

## Support

- [Nextcloud OIDC Login App](https://github.com/pulsejet/nextcloud-oidc-login)
- [Nextcloud Documentation](https://docs.nextcloud.com/)
- [Dex Documentation](https://dexidp.io/docs/)
- [OIDC Quick Reference](oidc-quick-reference.md)
