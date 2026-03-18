# TYPO3 OIDC Configuration

This guide explains how to configure TYPO3 CMS on an external server to authenticate against your local Dex OIDC provider.

## Prerequisites

- TYPO3 CMS installed and running
- Access to TYPO3 backend and file system
- Composer installed for extension management
- Dex OIDC provider running at `https://id.example.org`

## Installation

### 1. Install Required Extensions

```bash
# Install the causal OIDC extension
composer require causal/oidc

# Install the LDAP SSO extension (optional fallback)
composer require ichhabrecht/ig-ldap-sso-auth
```

### 2. Enable Extensions

In TYPO3 backend, go to **Admin Tools > Extensions** and enable:
- `causal_oidc`
- `ig_ldap_sso_auth` (optional)

## Configuration

### OIDC Extension Settings

Go to **Admin Tools > Extensions > OIDC** and configure:

```yaml
# OIDC Configuration
issuer: https://id.example.org
client_id: typo3
client_secret: your-typo3-client-secret-here
redirect_uri: https://typo3.example.org/index.php?eID=oidc
scopes: openid profile email groups

# User Mapping
user_mapping:
  username: sub
  email: email
  firstName: given_name
  lastName: family_name
  displayName: name
  groups: groups

# Group Mapping
group_mapping:
  administrators: 1  # TYPO3 admin group UID
  maintainers: 2     # TYPO3 maintainer group UID

# Auto-provisioning
create_user_if_not_exists: true
update_user_on_login: true
default_groups: [3]  # TYPO3 default user group UID
```

### TypoScript Configuration

Add this to your site's TypoScript setup:

```typoscript
# OIDC Login Configuration
plugin.tx_oidc {
    settings {
        issuer = https://id.example.org
        clientId = typo3
        redirectUri = https://typo3.example.org/index.php?eID=oidc
        scopes = openid profile email groups
    }
}

# Default redirect after OIDC login
[globalVar = GP:logintype = login]
page.10 = TEXT
page.10.value = Welcome back!
page.10.wrap = <h1>|</h1>
[global]
```

## Testing

### 1. Verify Configuration
- Check TYPO3 backend for OIDC extension settings
- Verify redirect URI matches Dex configuration exactly
- Ensure client secret is correct

### 2. Test OIDC Flow
1. Visit TYPO3 frontend
2. Click OIDC login button
3. Should redirect to Dex at `https://id.example.org/auth`
4. Login with LDAP credentials
5. Should redirect back to TYPO3
6. User should be logged in with proper attributes

## Troubleshooting

### Common Issues

**Error**: "Invalid redirect URI"
- **Solution**: Ensure redirect URI in TYPO3 exactly matches Dex configuration

**Error**: "User not found" after OIDC login
- **Solution**: Check user mapping configuration and LDAP group membership

**Error**: "OIDC extension not found"
- **Solution**: Verify extension is installed and enabled

### Debug Steps

1. Check TYPO3 logs for OIDC-related errors
2. Verify OIDC extension configuration in backend
3. Test OIDC discovery endpoint: `https://id.example.org/.well-known/openid_configuration`
4. Check user group assignments in LDAP

## Security Considerations

- **Client Secret**: Store securely, never commit to version control
- **HTTPS Only**: Ensure TYPO3 runs over HTTPS
- **User Permissions**: Configure appropriate user group permissions
- **Session Management**: Review TYPO3 session security settings

## Support

- **TYPO3 OIDC Extension**: https://extensions.typo3.org/extension/oidc/
- **Main Documentation**: [../../docs/identity.md](../../docs/identity.md)
- **OIDC Quick Reference**: [../../docs/integrations/oidc-quick-reference.md](../../docs/integrations/oidc-quick-reference.md)
