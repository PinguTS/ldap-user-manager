# TYPO3 Integration

This guide explains how to configure TYPO3 CMS to authenticate against the Dex OIDC provider bundled with LDAP User Manager.

## Overview

Two integration methods are available:

- **Causal OIDC Extension** (`causal/oidc`) — recommended; modern OIDC-based single sign-on
- **Legacy LDAP SSO** (`ig_ldap_sso_auth`) — direct LDAP authentication without OIDC; see [typo3-legacy.md](typo3-legacy.md)

---

## OIDC Integration via Causal Extension

### Prerequisites

- TYPO3 CMS installed and running
- Composer available for extension management
- Dex OIDC provider accessible at `https://id.example.org`
- Client secret configured in Dex for the `typo3` client

### 1. Install the Extension

```bash
composer require causal/oidc
```

Then activate it in the TYPO3 backend under **Admin Tools → Extensions**.

An example `composer.json` with the required dependencies is available at [`services/typo3/composer.json`](../../services/typo3/composer.json).

### 2. Configure the Extension

#### Option A: TYPO3 Backend

Go to **Admin Tools → Extensions → OIDC** and enter:

| Setting | Value |
|---------|-------|
| Issuer URL | `https://id.example.org` |
| Client ID | `typo3` |
| Client Secret | your client secret from Dex |
| Redirect URI | `https://typo3.example.org/index.php?eID=oidc` |
| Scopes | `openid profile email groups` |

#### Option B: LocalConfiguration.php

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oidc'] = [
    'enabled'       => true,
    'issuer'        => 'https://id.example.org',
    'clientId'      => 'typo3',
    'clientSecret'  => 'your-typo3-client-secret-here',
    'redirectUri'   => 'https://typo3.example.org/index.php?eID=oidc',
    'scopes'        => 'openid profile email groups',
    'autoLogin'     => true,
    'autoLogout'    => true,
    'userMapping'   => [
        'username'  => 'preferred_username',
        'email'     => 'email',
        'firstName' => 'given_name',
        'lastName'  => 'family_name',
        'groups'    => 'groups',
    ],
];
```

#### TypoScript (frontend login)

```typoscript
plugin.tx_oidc {
    settings {
        issuer      = https://id.example.org
        clientId    = typo3
        redirectUri = https://typo3.example.org/index.php?eID=oidc
        scopes      = openid profile email groups
    }
}
```

### 3. Group Mapping

LDAP groups from the `groups` claim are mapped to TYPO3 user groups by UID. Configure in **Admin Tools → Extensions → OIDC → Group mapping**.

Example mapping:
```php
'groupMapping' => [
    'administrators' => 1,  // TYPO3 admin group UID
    'maintainers'    => 2,
],
'defaultGroups' => [3],     // fallback group UID for new users
```

### 4. Test the Integration

1. Visit the TYPO3 frontend login page
2. Click the OIDC login button
3. Verify you are redirected to `https://id.example.org/auth`
4. Log in with LDAP credentials
5. Verify you are redirected back to TYPO3 and logged in with the correct attributes

```bash
# Verify the OIDC discovery endpoint is reachable from the TYPO3 server
curl -s https://id.example.org/.well-known/openid-configuration | jq .issuer

# Verify the TYPO3 OIDC endpoint responds
curl -I "https://typo3.example.org/index.php?eID=oidc&action=login"
```

### 5. Troubleshooting

**"Invalid redirect URI"**
Ensure the `redirectUri` in TYPO3 exactly matches the `redirectURIs` entry in Dex `config.yaml`.

**"User not found" after OIDC login**
Check the `userMapping` configuration and verify the user exists in LDAP with the expected group membership.

**"OIDC extension not found"**
Verify the extension is installed (`composer show causal/oidc`) and activated in the TYPO3 backend.

**Check TYPO3 logs:**
```bash
tail -f var/log/typo3*.log
```

**Verify Dex client configuration:**
```yaml
# dex/config.yaml
staticClients:
  - id: typo3
    secret: your-typo3-client-secret-here
    redirectURIs:
      - https://typo3.example.org/index.php?eID=oidc
```

---

## Security Considerations

- Store the client secret securely; never commit it to version control
- Use HTTPS for both TYPO3 and the Dex provider
- Review TYPO3 session security settings after enabling OIDC
- Configure appropriate TYPO3 user group permissions for auto-provisioned users

## Support

- [Causal OIDC Extension](https://extensions.typo3.org/extension/oidc/)
- [TYPO3 Documentation](https://docs.typo3.org/)
- [Dex Documentation](https://dexidp.io/docs/)
- [OIDC Quick Reference](oidc-quick-reference.md)
