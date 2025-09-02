# Nextcloud Integration

This guide provides detailed integration instructions for connecting Nextcloud with LDAP User Manager.

## Overview

Nextcloud can integrate with LDAP User Manager using two methods:
- **OIDC Login App**: Modern OIDC-based integration
- **LDAP User and Group Backend**: Traditional LDAP-based integration

## Nextcloud OIDC Login App

### Installation

#### Manual Installation
```bash
# Install the OIDC Login app
cd /var/www/nextcloud/apps
git clone https://github.com/pulsejet/nextcloud-oidc-login.git oidc_login
chown -R www-data:www-data oidc_login
```

#### Via Nextcloud App Store
1. Go to **Apps** in Nextcloud
2. Search for "OIDC Login"
3. Click **Download and enable**

### Configuration

#### Configuration Files

##### config.php
```php
<?php
/**
 * Nextcloud Configuration with OIDC Integration
 * This file shows the configuration structure for Nextcloud with OIDC Login app
 */

$CONFIG = array(
  // Basic Nextcloud Configuration
  'instanceid' => 'nextcloud',
  'passwordsalt' => 'your-password-salt-here',
  'secret' => 'your-secret-here',
  'trusted_domains' => array(
    0 => 'nextcloud.example.org',
  ),
  'datadirectory' => '/var/www/html/data',
  'dbtype' => 'mysql',
  'version' => '25.0.0.0',
  'overwrite.cli.url' => 'https://nextcloud.example.org',
  
  // Database Configuration (adjust for your environment)
  'dbhost' => 'localhost:3306',
  'dbname' => 'nextcloud',
  'dbuser' => 'nextcloud',
  'dbpassword' => 'your-db-password',
  
  // Redis Configuration (optional, for caching)
  'redis' => array(
    'host' => 'localhost',
    'port' => 6379,
  ),
  
  // OIDC Configuration
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
  
  // LDAP Configuration (optional fallback)
  'ldap_host' => 'ldap-server',
  'ldap_port' => 636,
  'ldap_base' => 'dc=example,dc=com',
  'ldap_dn' => 'cn=admin,dc=example,dc=com',
  'ldap_password' => 'admin123',
  'ldap_login_filter' => '(&(objectClass=inetOrgPerson)(uid=%uid))',
  'ldap_userlist_filter' => '(objectClass=inetOrgPerson)',
  'ldap_attributes' => array(
    'uid' => 'uid',
    'mail' => 'mail',
    'cn' => 'cn',
    'givenName' => 'givenName',
    'sn' => 'sn',
  ),
  
  // Security Settings
  'updatechecker' => true,
  'updater.release.channel' => 'stable',
  'htaccess.RewriteBase' => '/',
  'memcache.local' => '\\OC\\Memcache\\APCu',
  'memcache.distributed' => '\\OC\\Memcache\\Redis',
  'memcache.locking' => '\\OC\\Memcache\\Redis',
  
  // Logging
  'log_type' => 'file',
  'log_level' => 2,
  'logfile' => '/var/log/nextcloud/nextcloud.log',
  
  // Maintenance Mode
  'maintenance' => false,
  'maintenance_window_start' => 1,
);
```

#### OCC Commands

Use the Nextcloud OCC command line tool for configuration:

```bash
# Basic OIDC Settings
sudo -u www-data php occ config:app:set oidc_login provider-url --value="https://id.example.org"
sudo -u www-data php occ config:app:set oidc_login client-id --value="nextcloud"
sudo -u www-data php occ config:app:set oidc_login client-secret --value="your-nextcloud-client-secret-here"
sudo -u www-data php occ config:app:set oidc_login redirect-url --value="https://nextcloud.example.org/index.php/apps/oidc_login/oidc"

# Scopes and Claims
sudo -u www-data php occ config:app:set oidc_login scope --value="openid profile email groups"
sudo -u www-data php occ config:app:set oidc_login claim-name --value="sub"
sudo -u www-data php occ config:app:set oidc_login claim-email --value="email"
sudo -u www-data php occ config:app:set oidc_login claim-display-name --value="name"

# User Management
sudo -u www-data php occ config:app:set oidc_login auto-provision --value="1"
sudo -u www-data php occ config:app:set oidc_login use-email-as-uid --value="1"
sudo -u www-data php occ config:app:set oidc_login disable-registration --value="1"
sudo -u www-data php occ config:app:set oidc_login default-groups --value="users"

# Group Mapping
sudo -u www-data php occ config:app:set oidc_login group-mapping --value="1"
sudo -u www-data php occ config:app:set oidc_login claim-groups --value="groups"

# Security Settings
sudo -u www-data php occ config:app:set oidc_login require-https --value="1"
sudo -u www-data php occ config:app:set oidc_login validate-token --value="1"
sudo -u www-data php occ config:app:set oidc_login clock-skew --value="30"
```

#### App Configuration (`config/config.php`)
```php
<?php
$CONFIG = [
    'apps_paths' => [
        [
            'path' => '/var/www/nextcloud/apps',
            'url' => '/apps',
            'writable' => true,
        ],
    ],
    
    'oidc_login_provider_url' => 'https://id.example.org',
    'oidc_login_client_id' => 'nextcloud',
    'oidc_login_client_secret' => 'your-nextcloud-client-secret-here',
    'oidc_login_auto_provision' => true,
    'oidc_login_use_id_token' => true,
    'oidc_login_proxy_ldap' => false,
    
    'oidc_login_mapping' => [
        'uid' => 'preferred_username',
        'email' => 'email',
        'displayName' => 'name',
        'quota' => 'quota',
        'groups' => 'groups'
    ],
    
    'oidc_login_attributes' => [
        'mappingDisplayName' => 'name',
        'mappingEmail' => 'email',
        'mappingQuota' => 'quota',
        'mappingGroups' => 'groups'
    ],
    
    'oidc_login_auto_redirect' => true,
    'oidc_login_redir_fallback' => false,
    'oidc_login_button_text' => 'Sign in with SSO',
    'oidc_login_hide_password_form' => true,
    'oidc_login_use_existing_users' => true,
    'oidc_login_update_on_login' => true,
    'oidc_login_protect_api' => false,
    'oidc_login_webdav_enabled' => true,
    'oidc_login_public_links' => false,
];
```

#### User Provisioning Script
```php
<?php
// apps/oidc_login/lib/UserProvisioning.php

namespace OCA\OidcLogin;

class UserProvisioning
{
    public function provisionUser($userData)
    {
        $uid = $userData['preferred_username'];
        $email = $userData['email'];
        $displayName = $userData['name'];
        
        // Check if user exists
        if (!$this->userManager->userExists($uid)) {
            // Create user
            $user = $this->userManager->createUser($uid, $this->generatePassword());
            
            // Set display name
            $user->setDisplayName($displayName);
            
            // Set email
            $user->setEMailAddress($email);
            
            // Set quota if provided
            if (isset($userData['quota'])) {
                $user->setQuota($userData['quota']);
            }
            
            // Add to groups
            if (isset($userData['groups'])) {
                foreach ($userData['groups'] as $group) {
                    $this->groupManager->createGroup($group);
                    $this->groupManager->addUserToGroup($user, $group);
                }
            }
            
            return $user;
        }
        
        return $this->userManager->get($uid);
    }
    
    private function generatePassword()
    {
        return bin2hex(random_bytes(32));
    }
}
```

### Testing the Integration

#### Test OIDC Connection
```bash
# Test OIDC provider accessibility
curl -f -s https://id.example.org/.well-known/openid_configuration

# Test client configuration
curl -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=nextcloud&client_secret=your-nextcloud-client-secret-here"
```

#### Test User Authentication
1. Go to your Nextcloud instance
2. Click the OIDC login button
3. Complete authentication on the OIDC provider
4. Verify user is logged into Nextcloud

## Nextcloud LDAP Integration

### Installation

#### Enable LDAP App
1. Go to **Apps** in Nextcloud
2. Search for "LDAP user and group backend"
3. Click **Download and enable**

### Configuration

#### LDAP Configuration
```php
<?php
$CONFIG = [
    'ldap' => [
        'host' => 'ldap.example.com',
        'port' => 636,
        'encryption' => 'ssl',
        'base' => 'dc=example,dc=com',
        'bindDN' => 'cn=admin,dc=example,dc=com',
        'bindPassword' => 'admin123',
        
        'userFilter' => '(&(objectClass=inetOrgPerson)(uid=%uid))',
        'userDisplayName' => 'cn',
        'userEmail' => 'mail',
        'userQuota' => 'quota',
        
        'groupFilter' => '(objectClass=groupOfNames)',
        'groupDisplayName' => 'cn',
        'groupMemberAssoc' => 'member',
        
        'autoCreateUsers' => true,
        'autoCreateGroups' => true,
        'syncInterval' => 3600,
    ]
];
```

#### Advanced LDAP Configuration
```php
// Additional LDAP settings
$CONFIG['ldap']['additional'] = [
    'userSearch' => [
        'baseDN' => 'ou=people,dc=example,dc=com',
        'filter' => '(objectClass=inetOrgPerson)',
        'attributes' => ['uid', 'mail', 'cn', 'givenName', 'sn']
    ],
    'groupSearch' => [
        'baseDN' => 'ou=roles,dc=example,dc=com',
        'filter' => '(objectClass=groupOfNames)',
        'attributes' => ['cn', 'member', 'description']
    ],
    'userMapping' => [
        'username' => 'uid',
        'email' => 'mail',
        'displayName' => 'cn',
        'firstName' => 'givenName',
        'lastName' => 'sn',
        'quota' => 'quota'
    ],
    'groupMapping' => [
        'groupName' => 'cn',
        'groupMember' => 'member',
        'groupDescription' => 'description'
    ]
];
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
1. Go to your Nextcloud instance
2. Use LDAP credentials to log in
3. Verify user is authenticated
4. Check group membership

## User Provisioning

### Automatic User Creation
```php
// config/config.php
$CONFIG['ldap']['autoCreateUsers'] = true;
$CONFIG['ldap']['autoCreateGroups'] = true;

// Custom user creation hook
$CONFIG['ldap']['userCreationHook'] = \OCA\CustomApp\Hooks\UserCreationHook::class;
```

### User Creation Hook
```php
<?php
namespace OCA\CustomApp\Hooks;

class UserCreationHook
{
    public function createUser($userData)
    {
        // Create Nextcloud user
        $user = new \OC\User\User($userData['uid']);
        
        // Set user attributes
        $user->setDisplayName($userData['cn']);
        $user->setEMailAddress($userData['mail']);
        
        // Set quota if provided
        if (isset($userData['quota'])) {
            $user->setQuota($userData['quota']);
        }
        
        // Add to groups
        if (isset($userData['memberOf'])) {
            foreach ($userData['memberOf'] as $group) {
                $this->groupManager->createGroup($group);
                $this->groupManager->addUserToGroup($user, $group);
            }
        }
        
        return $user;
    }
}
```

## Group Management

### LDAP Group Synchronization
```php
// config/config.php
$CONFIG['ldap']['groupSync'] = [
    'enabled' => true,
    'syncInterval' => 3600,
    'groupFilter' => '(objectClass=groupOfNames)',
    'groupBaseDN' => 'ou=roles,dc=example,dc=com',
    'groupMapping' => [
        'groupName' => 'cn',
        'groupMember' => 'member',
        'groupDescription' => 'description'
    ]
];
```

### Group Provisioning Script
```php
<?php
// apps/custom_app/lib/GroupProvisioning.php

namespace OCA\CustomApp;

class GroupProvisioning
{
    public function provisionGroups($ldapGroups)
    {
        foreach ($ldapGroups as $group) {
            $groupName = $group['cn'];
            
            // Create group if it doesn't exist
            if (!$this->groupManager->groupExists($groupName)) {
                $this->groupManager->createGroup($groupName);
            }
            
            // Add members to group
            if (isset($group['member'])) {
                foreach ($group['member'] as $member) {
                    $uid = $this->extractUidFromDN($member);
                    if ($this->userManager->userExists($uid)) {
                        $this->groupManager->addUserToGroup($uid, $groupName);
                    }
                }
            }
        }
    }
    
    private function extractUidFromDN($dn)
    {
        // Extract UID from DN like "uid=user1,ou=people,dc=example,dc=com"
        preg_match('/uid=([^,]+)/', $dn, $matches);
        return $matches[1] ?? null;
    }
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
    -d "grant_type=client_credentials&client_id=nextcloud&client_secret=your-nextcloud-client-secret-here"
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

#### Nextcloud Configuration Issues
```bash
# Check Nextcloud logs
tail -f /var/log/nextcloud/nextcloud.log

# Check Nextcloud status
sudo -u www-data php /var/www/nextcloud/occ status

# Clear Nextcloud cache
sudo -u www-data php /var/www/nextcloud/occ files:scan --all
```

### Debug Configuration

#### Enable Debug Logging
```php
// config/config.php
$CONFIG['loglevel'] = 0; // Debug level
$CONFIG['ldap']['debug'] = true;
```

#### Debug Log Location
```bash
# Nextcloud debug logs
tail -f /var/log/nextcloud/nextcloud.log

# LDAP specific logs
tail -f /var/log/nextcloud/ldap.log

# OIDC specific logs
tail -f /var/log/nextcloud/oidc.log
```

## Best Practices

### Security Considerations
1. **Use HTTPS**: Always use HTTPS for OIDC and LDAP connections
2. **Strong Passwords**: Use strong passwords for LDAP bind DN
3. **Certificate Validation**: Enable certificate validation for LDAPS
4. **Access Control**: Implement proper access controls in Nextcloud

### Performance Optimization
1. **Connection Pooling**: Configure LDAP connection pooling
2. **Caching**: Enable Nextcloud caching for better performance
3. **Group Mapping**: Cache group mappings to reduce LDAP queries
4. **User Sessions**: Configure appropriate session timeouts

### Maintenance
1. **Regular Updates**: Keep Nextcloud and apps updated
2. **Log Monitoring**: Monitor logs for authentication issues
3. **User Management**: Regular review of user accounts and groups
4. **Backup**: Regular backup of Nextcloud configuration and data

## Support

For Nextcloud integration support:
- **Nextcloud Documentation**: [Nextcloud Documentation](https://docs.nextcloud.com/)
- **App Documentation**: [OIDC Login App](https://github.com/pulsejet/nextcloud-oidc-login)
- **Community Support**: [Nextcloud Community](https://help.nextcloud.com/)
- **Professional Support**: [Nextcloud GmbH](https://nextcloud.com/support/)
