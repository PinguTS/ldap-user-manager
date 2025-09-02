# WordPress Integration

This guide provides detailed integration instructions for connecting WordPress with LDAP User Manager.

## Overview

WordPress can integrate with LDAP User Manager using:
- **OpenID Connect Plugin**: Modern OIDC-based integration
- **LDAP Authentication**: Traditional LDAP-based integration

## WordPress OIDC Plugin

### Installation

#### Via WordPress Admin
1. Go to **Plugins** → **Add New**
2. Search for "OpenID Connect"
3. Click **Install Now**
4. Click **Activate**

#### Manual Installation
```bash
# Download and install plugin
cd /var/www/wordpress/wp-content/plugins
wget https://wordpress.org/plugins/openid-connect/trunk/openid-connect.zip
unzip openid-connect.zip
chown -R www-data:www-data openid-connect
```

### Configuration

#### Plugin Configuration
```php
<?php
// wp-config.php or plugin configuration

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

#### User Role Mapping
```php
<?php
// functions.php

add_filter('oidc_user_roles', function($roles, $user_data) {
    $role_mapping = [
        'administrators' => 'administrator',
        'maintainers' => 'editor',
        'org_admin' => 'author',
        'user' => 'subscriber'
    ];
    
    if (isset($user_data['groups'])) {
        foreach ($user_data['groups'] as $group) {
            if (isset($role_mapping[$group])) {
                $roles[] = $role_mapping[$group];
            }
        }
    }
    
    return array_unique($roles);
}, 10, 2);
```

### Testing the Integration

#### Test OIDC Connection
```bash
# Test OIDC provider accessibility
curl -f -s https://id.example.org/.well-known/openid_configuration

# Test client configuration
curl -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=wordpress&client_secret=your-wordpress-client-secret-here"
```

#### Test User Authentication
1. Go to your WordPress site
2. Click the OIDC login button
3. Complete authentication on the OIDC provider
4. Verify user is logged into WordPress

## WordPress LDAP Integration

### Installation

#### Via WordPress Admin
1. Go to **Plugins** → **Add New**
2. Search for "LDAP Authentication"
3. Click **Install Now**
4. Click **Activate**

#### Manual Installation
```bash
# Download and install plugin
cd /var/www/wordpress/wp-content/plugins
wget https://wordpress.org/plugins/ldap-authentication/trunk/ldap-authentication.zip
unzip ldap-authentication.zip
chown -R www-data:www-data ldap-authentication
```

### Configuration

#### LDAP Configuration
```php
<?php
// wp-config.php

// LDAP configuration
define('LDAP_HOST', 'ldap.example.com');
define('LDAP_PORT', 636);
define('LDAP_ENCRYPTION', 'ssl');
define('LDAP_BASE_DN', 'dc=example,dc=com');
define('LDAP_BIND_DN', 'cn=admin,dc=example,dc=com');
define('LDAP_BIND_PASSWORD', 'admin123');

// LDAP user mapping
define('LDAP_USER_FILTER', '(objectClass=inetOrgPerson)');
define('LDAP_USER_BASE', 'ou=people,dc=example,dc=com');
define('LDAP_USER_ATTRIBUTES', [
    'username' => 'uid',
    'email' => 'mail',
    'first_name' => 'givenName',
    'last_name' => 'sn',
    'display_name' => 'cn'
]);

// LDAP group mapping
define('LDAP_GROUP_FILTER', '(objectClass=groupOfNames)');
define('LDAP_GROUP_BASE', 'ou=roles,dc=example,dc=com');
define('LDAP_GROUP_ATTRIBUTES', [
    'group_name' => 'cn',
    'group_member' => 'member',
    'group_description' => 'description'
]);
```

#### Plugin Configuration
```php
<?php
// functions.php

// LDAP authentication settings
add_filter('ldap_auth_settings', function($settings) {
    $settings['enabled'] = true;
    $settings['host'] = LDAP_HOST;
    $settings['port'] = LDAP_PORT;
    $settings['encryption'] = LDAP_ENCRYPTION;
    $settings['base_dn'] = LDAP_BASE_DN;
    $settings['bind_dn'] = LDAP_BIND_DN;
    $settings['bind_password'] = LDAP_BIND_PASSWORD;
    
    $settings['user_filter'] = LDAP_USER_FILTER;
    $settings['user_base'] = LDAP_USER_BASE;
    $settings['user_attributes'] = LDAP_USER_ATTRIBUTES;
    
    $settings['group_filter'] = LDAP_GROUP_FILTER;
    $settings['group_base'] = LDAP_GROUP_BASE;
    $settings['group_attributes'] = LDAP_GROUP_ATTRIBUTES;
    
    $settings['auto_create_users'] = true;
    $settings['auto_create_groups'] = true;
    
    return $settings;
});
```

### Testing the Integration

#### Test LDAP Connection
```bash
# Test LDAP connectivity
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s base

# Test user search
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "ou=people,dc=example,dc=com" \
    -s sub "(objectClass=inetOrgPerson)"
```

#### Test User Authentication
1. Go to your WordPress site
2. Use LDAP credentials to log in
3. Verify user is authenticated
4. Check role assignment

## User Provisioning

### Automatic User Creation
```php
<?php
// functions.php

// Enable automatic user creation
add_filter('ldap_auto_create_users', '__return_true');

// Custom user creation hook
add_action('ldap_user_created', function($user_id, $user_data) {
    // Set user meta
    update_user_meta($user_id, 'ldap_uid', $user_data['uid']);
    update_user_meta($user_id, 'ldap_dn', $user_data['dn']);
    
    // Set user role based on groups
    $roles = map_ldap_groups_to_roles($user_data['groups']);
    foreach ($roles as $role) {
        $user = get_user_by('id', $user_id);
        $user->add_role($role);
    }
}, 10, 2);

function map_ldap_groups_to_roles($groups) {
    $role_mapping = [
        'administrators' => 'administrator',
        'maintainers' => 'editor',
        'org_admin' => 'author',
        'user' => 'subscriber'
    ];
    
    $roles = [];
    foreach ($groups as $group) {
        if (isset($role_mapping[$group])) {
            $roles[] = $role_mapping[$group];
        }
    }
    
    return array_unique($roles);
}
```

### User Synchronization
```php
<?php
// functions.php

// Sync user data on login
add_action('wp_login', function($user_login, $user) {
    if (is_ldap_user($user)) {
        sync_ldap_user_data($user);
    }
}, 10, 2);

function is_ldap_user($user) {
    return get_user_meta($user->ID, 'ldap_uid', true);
}

function sync_ldap_user_data($user) {
    $ldap_uid = get_user_meta($user->ID, 'ldap_uid', true);
    $ldap_data = get_ldap_user_data($ldap_uid);
    
    if ($ldap_data) {
        // Update user data
        wp_update_user([
            'ID' => $user->ID,
            'first_name' => $ldap_data['givenName'],
            'last_name' => $ldap_data['sn'],
            'display_name' => $ldap_data['cn'],
            'user_email' => $ldap_data['mail']
        ]);
        
        // Update user meta
        update_user_meta($user->ID, 'ldap_last_sync', current_time('mysql'));
    }
}

function get_ldap_user_data($uid) {
    // Connect to LDAP and get user data
    $ldap_conn = ldap_connect(LDAP_HOST, LDAP_PORT);
    ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
    
    if (ldap_bind($ldap_conn, LDAP_BIND_DN, LDAP_BIND_PASSWORD)) {
        $filter = "(uid=$uid)";
        $result = ldap_search($ldap_conn, LDAP_USER_BASE, $filter);
        $entries = ldap_get_entries($ldap_conn, $result);
        
        if ($entries['count'] > 0) {
            return $entries[0];
        }
    }
    
    return false;
}
```

## Group Management

### LDAP Group Synchronization
```php
<?php
// functions.php

// Sync LDAP groups to WordPress roles
add_action('wp_ajax_sync_ldap_groups', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $ldap_groups = get_ldap_groups();
    foreach ($ldap_groups as $group) {
        sync_ldap_group($group);
    }
    
    wp_send_json_success('Groups synchronized successfully');
});

function get_ldap_groups() {
    $ldap_conn = ldap_connect(LDAP_HOST, LDAP_PORT);
    ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
    
    if (ldap_bind($ldap_conn, LDAP_BIND_DN, LDAP_BIND_PASSWORD)) {
        $result = ldap_search($ldap_conn, LDAP_GROUP_BASE, LDAP_GROUP_FILTER);
        $entries = ldap_get_entries($ldap_conn, $result);
        
        return $entries;
    }
    
    return [];
}

function sync_ldap_group($group) {
    $group_name = $group['cn'][0];
    $members = $group['member'];
    
    // Create WordPress role if it doesn't exist
    $role_name = 'ldap_' . strtolower($group_name);
    if (!get_role($role_name)) {
        add_role($role_name, $group_name, [
            'read' => true,
            'edit_posts' => true,
            'delete_posts' => true
        ]);
    }
    
    // Assign users to role
    foreach ($members as $member) {
        $uid = extract_uid_from_dn($member);
        $user = get_user_by('login', $uid);
        
        if ($user) {
            $user->add_role($role_name);
        }
    }
}

function extract_uid_from_dn($dn) {
    preg_match('/uid=([^,]+)/', $dn, $matches);
    return $matches[1] ?? null;
}
```

## Troubleshooting

### Common Issues

#### OIDC Configuration Issues
```bash
# Check OIDC provider configuration
curl -v https://id.example.org/.well-known/openid_configuration

# Verify client registration
curl -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=wordpress&client_secret=your-wordpress-client-secret-here"
```

#### LDAP Configuration Issues
```bash
# Test LDAP connection
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s base

# Check user search
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "ou=people,dc=example,dc=com" \
    -s sub "(uid=testuser)"
```

#### WordPress Configuration Issues
```bash
# Check WordPress logs
tail -f /var/log/wordpress/error.log

# Check WordPress debug log
tail -f /var/log/wordpress/debug.log

# Check plugin status
wp plugin list --status=active
```

### Debug Configuration

#### Enable Debug Logging
```php
// wp-config.php

// Enable WordPress debug
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Enable LDAP debug
define('LDAP_DEBUG', true);
```

#### Debug Log Location
```bash
# WordPress debug logs
tail -f /var/log/wordpress/debug.log

# Plugin specific logs
tail -f /var/log/wordpress/ldap.log
tail -f /var/log/wordpress/oidc.log
```

## Best Practices

### Security Considerations
1. **Use HTTPS**: Always use HTTPS for OIDC and LDAP connections
2. **Strong Passwords**: Use strong passwords for LDAP bind DN
3. **Certificate Validation**: Enable certificate validation for LDAPS
4. **Access Control**: Implement proper access controls in WordPress

### Performance Optimization
1. **Connection Pooling**: Configure LDAP connection pooling
2. **Caching**: Enable WordPress caching for better performance
3. **Group Mapping**: Cache group mappings to reduce LDAP queries
4. **User Sessions**: Configure appropriate session timeouts

### Maintenance
1. **Regular Updates**: Keep WordPress and plugins updated
2. **Log Monitoring**: Monitor logs for authentication issues
3. **User Management**: Regular review of user accounts and roles
4. **Backup**: Regular backup of WordPress configuration and data

## Support

For WordPress integration support:
- **WordPress Documentation**: [WordPress Documentation](https://wordpress.org/support/)
- **Plugin Documentation**: [OpenID Connect Plugin](https://wordpress.org/plugins/openid-connect/)
- **Community Support**: [WordPress Support Forums](https://wordpress.org/support/)
- **Professional Support**: [WordPress.com Support](https://wordpress.com/support/)
