# Nextcloud OCC Commands for OIDC Configuration

This file contains all the OCC commands needed to configure Nextcloud for OIDC authentication.

## Prerequisites

Ensure you have access to the Nextcloud OCC command line tool:

```bash
# Navigate to Nextcloud directory
cd /var/www/html

# Check OCC availability
sudo -u www-data php occ --version
```

## OIDC Configuration Commands

### Basic OIDC Settings

```bash
# Set OIDC provider URL
sudo -u www-data php occ config:app:set oidc_login provider-url --value="https://id.example.org"

# Set client ID
sudo -u www-data php occ config:app:set oidc_login client-id --value="nextcloud"

# Set client secret
sudo -u www-data php occ config:app:set oidc_login client-secret --value="your-nextcloud-client-secret-here"

# Set redirect URL
sudo -u www-data php occ config:app:set oidc_login redirect-url --value="https://nextcloud.example.org/index.php/apps/oidc_login/oidc"
```

### Scopes and Claims

```bash
# Set OIDC scopes
sudo -u www-data php occ config:app:set oidc_login scope --value="openid profile email groups"

# Set claim mapping for username
sudo -u www-data php occ config:app:set oidc_login claim-name --value="sub"

# Set claim mapping for email
sudo -u www-data php occ config:app:set oidc_login claim-email --value="email"

# Set claim mapping for display name
sudo -u www-data php occ config:app:set oidc_login claim-display-name --value="name"
```

### User Management

```bash
# Enable auto-provisioning
sudo -u www-data php occ config:app:set oidc_login auto-provision --value="1"

# Use email as UID
sudo -u www-data php occ config:app:set oidc_login use-email-as-uid --value="1"

# Disable registration (recommended for OIDC)
sudo -u www-data php occ config:app:set oidc_login disable-registration --value="1"

# Set default groups for new users
sudo -u www-data php occ config:app:set oidc_login default-groups --value="users"
```

### Group Mapping

```bash
# Enable group mapping
sudo -u www-data php occ config:app:set oidc_login group-mapping --value="1"

# Set group claim name
sudo -u www-data php occ config:app:set oidc_login claim-groups --value="groups"

# Map specific groups (optional)
sudo -u www-data php occ config:app:set oidc_login group-mapping-administrators --value="admins"
sudo -u www-data php occ config:app:set oidc_login group-mapping-maintainers --value="maintainers"
```

### Security Settings

```bash
# Require HTTPS for OIDC
sudo -u www-data php occ config:app:set oidc_login require-https --value="1"

# Set token validation
sudo -u www-data php occ config:app:set oidc_login validate-token --value="1"

# Set clock skew tolerance (seconds)
sudo -u www-data php occ config:app:set oidc_login clock-skew --value="30"
```

## Verification Commands

### Check Current Configuration

```bash
# List all OIDC Login app settings
sudo -u www-data php occ config:app:get oidc_login

# Check specific setting
sudo -u www-data php occ config:app:get oidc_login provider-url
sudo -u www-data php occ config:app:get oidc_login client-id
sudo -u www-data php occ config:app:get oidc_login auto-provision
```

### Test OIDC Connectivity

```bash
# Test OIDC discovery endpoint
curl -k https://id.example.org/.well-known/openid_configuration

# Test Nextcloud OIDC endpoint
curl -k https://nextcloud.example.org/index.php/apps/oidc_login/oidc
```

## Troubleshooting Commands

### Reset Configuration

```bash
# Reset specific setting to default
sudo -u www-data php occ config:app:delete oidc_login provider-url

# Reset all OIDC Login app settings
sudo -u www-data php occ config:app:delete oidc_login
```

### Check Logs

```bash
# View Nextcloud logs
sudo -u www-data php occ log:tail

# Check specific log level
sudo -u www-data php occ log:manage --level=debug
```

### User Management

```bash
# List all users
sudo -u www-data php occ user:list

# Check specific user
sudo -u www-data php occ user:info username

# Delete user (if needed)
sudo -u www-data php occ user:delete username
```

## Complete Configuration Script

Here's a complete script to configure OIDC:

```bash
#!/bin/bash
# Complete OIDC configuration for Nextcloud

cd /var/www/html

# Basic OIDC settings
sudo -u www-data php occ config:app:set oidc_login provider-url --value="https://id.example.org"
sudo -u www-data php occ config:app:set oidc_login client-id --value="nextcloud"
sudo -u www-data php occ config:app:set oidc_login client-secret --value="your-nextcloud-client-secret-here"
sudo -u www-data php occ config:app:set oidc_login redirect-url --value="https://nextcloud.example.org/index.php/apps/oidc_login/oidc"

# Scopes and claims
sudo -u www-data php occ config:app:set oidc_login scope --value="openid profile email groups"
sudo -u www-data php occ config:app:set oidc_login claim-name --value="sub"
sudo -u www-data php occ config:app:set oidc_login claim-email --value="email"
sudo -u www-data php occ config:app:set oidc_login claim-display-name --value="name"

# User management
sudo -u www-data php occ config:app:set oidc_login auto-provision --value="1"
sudo -u www-data php occ config:app:set oidc_login use-email-as-uid --value="1"
sudo -u www-data php occ config:app:set oidc_login disable-registration --value="1"
sudo -u www-data php occ config:app:set oidc_login default-groups --value="users"

# Group mapping
sudo -u www-data php occ config:app:set oidc_login group-mapping --value="1"
sudo -u www-data php occ config:app:set oidc_login claim-groups --value="groups"

# Security
sudo -u www-data php occ config:app:set oidc_login require-https --value="1"
sudo -u www-data php occ config:app:set oidc_login validate-token --value="1"

echo "OIDC configuration complete!"
```

## Notes

- Run all commands as `www-data` user or with `sudo -u www-data`
- Ensure Nextcloud is accessible before running commands
- Test OIDC flow after configuration
- Monitor logs for any configuration errors
- Backup configuration before making changes
