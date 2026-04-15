# Joomla Integration

This guide explains how to configure Joomla to authenticate users via the Dex OIDC provider bundled with LDAP User Manager.

## Overview

Joomla supports OIDC authentication through third-party authentication plugins. No official first-party OIDC plugin exists in Joomla core as of Joomla 5.

---

## Plugin Options

Search the [Joomla Extensions Directory](https://extensions.joomla.org/) for `openid connect` or `oidc` to find a suitable authentication plugin for your Joomla version. Evaluate plugins based on:

- Compatibility with your Joomla version (4.x or 5.x)
- Active maintenance and community support
- Support for PKCE and the Authorization Code flow
- Group/role mapping from OIDC claims

---

## OIDC Connection Parameters

Regardless of which plugin you choose, you will need the following values from your Dex deployment:

| Parameter | Value |
|-----------|-------|
| Provider URL / Issuer | `https://id.example.org` |
| Client ID | `joomla` |
| Client Secret | your configured Dex client secret |
| Authorization Endpoint | `https://id.example.org/auth` |
| Token Endpoint | `https://id.example.org/token` |
| Userinfo Endpoint | `https://id.example.org/userinfo` |
| JWKS URI | `https://id.example.org/keys` |
| Scopes | `openid profile email groups` |
| Redirect URI | `https://joomla.example.org/<plugin-callback-path>` |

The Discovery document at `https://id.example.org/.well-known/openid-configuration` provides all endpoints automatically for plugins that support OIDC Discovery.

---

## Dex Client Configuration

Register a Joomla client in Dex `config.yaml`:

```yaml
staticClients:
  - id: joomla
    secret: your-joomla-client-secret-here
    redirectURIs:
      - https://joomla.example.org/<plugin-specific-callback-path>
    name: Joomla
```

The redirect URI must exactly match the path expected by the plugin. Consult the plugin's documentation.

---

## configuration.php (Optional)

Some plugins read OIDC settings from `configuration.php`. An example snippet is available at [`services/joomla/configuration.php`](../../services/joomla/configuration.php).

Typical properties:

```php
public $oidc_provider_url     = 'https://id.example.org';
public $oidc_client_id        = 'joomla';
public $oidc_client_secret    = 'your-joomla-client-secret-here';
public $oidc_scope            = 'openid profile email groups';
public $oidc_redirect_uri     = 'https://joomla.example.org/index.php?option=com_users&task=user.oidccallback';
public $oidc_enable_auto_user = true;
public $oidc_default_group    = 2;
public $oidc_group_claim      = 'groups';
```

---

## Test the Integration

1. Navigate to `https://joomla.example.org`
2. Click the OIDC login button provided by your plugin
3. Verify you are redirected to `https://id.example.org/auth`
4. Log in with LDAP credentials
5. Confirm you are redirected back to Joomla and signed in

```bash
# Verify the Dex discovery endpoint is reachable
curl -s https://id.example.org/.well-known/openid-configuration | jq .issuer
```

---

## Troubleshooting

**"Redirect URI mismatch"**
Check the exact redirect URI the plugin sends and ensure it matches the value in Dex `config.yaml`.

**"Invalid client credentials"**
Verify the client ID and secret match the values in `dex/config.yaml` exactly.

**"User not provisioned"**
Check the plugin settings for auto-registration options and the required claim mappings (`sub`, `email`, `preferred_username`).

---

## Security Considerations

- Store the client secret securely; do not commit it to version control
- Use HTTPS for both Joomla and the Dex provider
- Review Joomla user group permissions for auto-provisioned accounts

## Support

- [Joomla Extensions Directory](https://extensions.joomla.org/)
- [Joomla Documentation](https://docs.joomla.org/)
- [Dex Documentation](https://dexidp.io/docs/)
- [OIDC Quick Reference](oidc-quick-reference.md)
