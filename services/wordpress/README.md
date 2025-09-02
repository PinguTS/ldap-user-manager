# WordPress OIDC Configuration

This guide explains how to configure WordPress on an external server to authenticate against your local Dex OIDC provider.

## Prerequisites

- WordPress installed and running
- Access to WordPress admin panel and file system
- Plugin installation capabilities
- Dex OIDC provider running at `https://id.example.org`

## Installation

### 1. Install Required Plugins

#### Option A: Via WordPress Admin
1. Go to **Plugins** → **Add New**
2. Search for "OpenID Connect"
3. Install and activate the plugin

#### Option B: Manual Installation
```bash
# Download plugin
wget https://github.com/wordpress-plugins/openid-connect/archive/main.zip
unzip main.zip -d /var/www/html/wp-content/plugins/
chown -R www-data:www-data /var/www/html/wp-content/plugins/openid-connect/
```

### 2. Install LDAP Plugin (Optional)
```bash
# Install LDAP authentication plugin
wget https://github.com/wordpress-plugins/ldap-auth/archive/main.zip
unzip main.zip -d /var/www/html/wp-content/plugins/
chown -R www-data:www-data /var/www/html/wp-content/plugins/ldap-auth/
```

## Configuration

### OIDC Plugin Configuration

#### wp-config.php Configuration
```php
<?php
// WordPress OIDC Configuration
define('OIDC_CLIENT_ID', 'wordpress');
define('OIDC_CLIENT_SECRET', 'your-wordpress-client-secret-here');
define('OIDC_ISSUER_URL', 'https://id.example.org');
define('OIDC_REDIRECT_URI', 'https://wordpress.example.org/wp-admin/admin-ajax.php?action=oidc_callback');
define('OIDC_SCOPES', 'openid profile email groups');

// OIDC plugin settings
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
```

#### Plugin Settings Configuration
```php
<?php
// functions.php or plugin file
add_action('init', function() {
    // OIDC Configuration
    update_option('oidc_client_id', 'wordpress');
    update_option('oidc_client_secret', 'your-wordpress-client-secret-here');
    update_option('oidc_issuer_url', 'https://id.example.org');
    update_option('oidc_redirect_uri', 'https://wordpress.example.org/wp-admin/admin-ajax.php?action=oidc_callback');
    update_option('oidc_scopes', 'openid profile email groups');
    
    // User Mapping
    update_option('oidc_username_field', 'preferred_username');
    update_option('oidc_email_field', 'email');
    update_option('oidc_first_name_field', 'given_name');
    update_option('oidc_last_name_field', 'family_name');
    update_option('oidc_display_name_field', 'name');
    
    // Auto-provisioning
    update_option('oidc_auto_provision', true);
    update_option('oidc_update_on_login', true);
    update_option('oidc_default_role', 'subscriber');
});
```

### LDAP Plugin Configuration (Optional)

#### LDAP Settings
```php
<?php
// LDAP Configuration
define('LDAP_HOST', 'ldap.example.com');
define('LDAP_PORT', 636);
define('LDAP_BASE_DN', 'dc=example,dc=com');
define('LDAP_BIND_DN', 'cn=admin,dc=example,dc=com');
define('LDAP_BIND_PASSWORD', 'admin123');

// LDAP plugin settings
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
```

## Testing

### 1. Verify Configuration
- Check WordPress admin panel for OIDC plugin settings
- Verify OIDC provider URL is accessible
- Ensure client secret matches Dex configuration

### 2. Test OIDC Flow
1. Visit WordPress login page
2. Click "Login with OpenID Connect" button
3. Should redirect to Dex at `https://id.example.org/auth`
4. Login with LDAP credentials
5. Should redirect back to WordPress
6. User should be logged in with proper attributes

## Troubleshooting

### Common Issues

**Error**: "OIDC plugin not found"
- **Solution**: Verify plugin is installed and activated
- **Check**: Plugin appears in WordPress admin panel

**Error**: "Invalid OIDC configuration"
- **Solution**: Verify OIDC provider URL and client credentials
- **Check**: Network connectivity to Dex provider

**Error**: "User not created" after OIDC login
- **Solution**: Check auto-provisioning settings
- **Check**: User attribute mapping configuration

### Debug Steps

1. Check WordPress logs for OIDC-related errors
2. Verify OIDC plugin configuration in admin panel
3. Test OIDC discovery: `curl -k https://id.example.org/.well-known/openid_configuration`
4. Check user creation in WordPress admin panel

## Security Considerations

- **Client Secret**: Store securely, never commit to version control
- **HTTPS Only**: Ensure WordPress runs over HTTPS
- **User Permissions**: Configure appropriate user role assignments
- **Plugin Updates**: Keep OIDC plugin updated

## Support

- **WordPress OIDC Plugin**: https://wordpress.org/plugins/openid-connect/
- **WordPress Documentation**: https://wordpress.org/support/
- **Main Documentation**: [../../docs/identity.md](../../docs/identity.md)
- **OIDC Quick Reference**: [../../docs/oidc-quick-reference.md](../../docs/oidc-quick-reference.md)
