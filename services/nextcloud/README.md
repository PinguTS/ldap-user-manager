# Nextcloud OIDC Configuration

This guide explains how to configure Nextcloud on an external server to authenticate against your local Dex OIDC provider using the OIDC Login app.

## Prerequisites

- Nextcloud installed and running
- Access to Nextcloud admin panel
- OCC command line tool available
- Dex OIDC provider running at `https://id.example.org`

## Installation

### 1. Install OIDC Login App

#### Option A: Via Nextcloud App Store
1. Go to **Apps** in Nextcloud admin panel
2. Search for "OIDC Login"
3. Install and enable the app

#### Option B: Manual Installation
```bash
# Download from GitHub
wget https://github.com/pulsejet/nextcloud-oidc-login/releases/latest/download/oidc_login.tar.gz

# Extract to Nextcloud apps directory
tar -xzf oidc_login.tar.gz -C /var/www/html/custom_apps/

# Set permissions
chown -R www-data:www-data /var/www/html/custom_apps/oidc_login/
chmod -R 755 /var/www/html/custom_apps/oidc_login/
```

### 2. Enable the App

In Nextcloud admin panel, go to **Apps > OIDC Login** and enable it.

## Configuration

### OCC Commands (Recommended)

Use the Nextcloud OCC command line tool for configuration:

```bash
# Set OIDC provider URL
occ config:app:set oidc_login provider-url --value="https://id.example.org"

# Set client ID
occ config:app:set oidc_login client-id --value="nextcloud"

# Set client secret
occ config:app:set oidc_login client-secret --value="your-nextcloud-client-secret-here"

# Set redirect URL
occ config:app:set oidc_login redirect-url --value="https://nextcloud.example.org/index.php/apps/oidc_login/oidc"

# Set scopes
occ config:app:set oidc_login scope --value="openid profile email groups"

# Enable auto-provisioning
occ config:app:set oidc_login auto-provision --value="1"

# Use email as UID
occ config:app:set oidc_login use-email-as-uid --value="1"

# Disable registration
occ config:app:set oidc_login disable-registration --value="1"

# Enable group mapping
occ config:app:set oidc_login group-mapping --value="1"

# Set default groups
occ config:app:set oidc_login default-groups --value="users"
```

### Direct config.php Configuration

Alternatively, add to your `config.php`:

```php
$CONFIG = array(
  'oidc_login_provider_url' => 'https://id.example.org',
  'oidc_login_client_id' => 'nextcloud',
  'oidc_login_client_secret' => 'your-nextcloud-client-secret-here',
  'oidc_login_redirect_url' => 'https://nextcloud.example.org/index.php/apps/oidc_login/oidc',
  'oidc_login_scope' => 'openid profile email groups',
  'oidc_login_auto_provision' => true,
  'oidc_login_use_email_as_uid' => true,
  'oidc_login_disable_registration' => true,
  'oidc_login_group_mapping' => true,
  'oidc_login_default_groups' => 'users',
);
```

## User Mapping

Nextcloud will map OIDC claims to user attributes:

- **Internal UID**: `sub` (OIDC subject identifier)
- **Email**: `mail` attribute
- **Display Name**: `name` claim
- **Groups**: `groups` claim for Nextcloud group membership
- **Auto-provisioning**: Enabled for new users

## Testing

### 1. Verify Configuration
- Check Nextcloud admin panel for OIDC Login app settings
- Verify OIDC provider URL is accessible
- Ensure client secret matches Dex configuration

### 2. Test OIDC Flow
1. Visit Nextcloud login page
2. Click "Login with OpenID Connect" button
3. Should redirect to Dex at `https://id.example.org/auth`
4. Login with LDAP credentials
5. Should redirect back to Nextcloud
6. User should be logged in with proper attributes

## Troubleshooting

### Common Issues

**Error**: "OIDC Login app not found"
- **Solution**: Verify app is installed and enabled
- **Check**: App appears in Nextcloud admin panel

**Error**: "Invalid OIDC configuration"
- **Solution**: Verify OIDC provider URL and client credentials
- **Check**: Network connectivity to Dex provider

**Error**: "User not created" after OIDC login
- **Solution**: Check auto-provisioning settings
- **Check**: User attribute mapping configuration

### Debug Steps

1. Check Nextcloud logs for OIDC-related errors
2. Verify OIDC Login app configuration in admin panel
3. Test OIDC discovery: `curl -k https://id.example.org/.well-known/openid_configuration`
4. Check user creation in Nextcloud admin panel

## Security Considerations

- **Client Secret**: Store securely, never commit to version control
- **HTTPS Only**: Ensure Nextcloud runs over HTTPS
- **User Permissions**: Configure appropriate user access levels
- **Group Mapping**: Review group membership assignments

## Support

- **OIDC Login App**: https://github.com/pulsejet/nextcloud-oidc-login
- **Nextcloud Documentation**: https://docs.nextcloud.com/
- **Main Documentation**: [../../docs/identity.md](../../docs/identity.md)
- **OIDC Quick Reference**: [../../docs/integrations/oidc-quick-reference.md](../../docs/integrations/oidc-quick-reference.md)
