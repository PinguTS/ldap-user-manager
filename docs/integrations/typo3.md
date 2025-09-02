# TYPO3 Integration

This guide provides detailed integration instructions for connecting TYPO3 with LDAP User Manager.

## Overview

TYPO3 can integrate with LDAP User Manager using two methods:
- **Causal OIDC Extension**: Modern OIDC-based integration
- **Legacy SSO Extension**: Traditional LDAP-based integration

## TYPO3 with Causal OIDC Extension

### Installation

#### Via Composer
```bash
# Install the Causal OIDC extension
composer require causal/oidc

# Or install via TYPO3 Extension Manager
# Search for "Causal OIDC" and install
```

#### Via Extension Manager
1. Go to **Admin Tools** → **Extensions**
2. Search for "Causal OIDC"
3. Click **Install**

### Configuration

#### Configuration Files

##### composer.json
```json
{
    "require": {
        "causal/oidc": "^1.0",
        "ichhabrecht/ig-ldap-sso-auth": "^1.0"
    }
}
```

##### oidc-config.yaml
```yaml
# TYPO3 OIDC Configuration Example
# This file shows the configuration structure for the causal OIDC extension

# OIDC Provider Configuration
oidc:
  issuer: "https://id.example.org"
  client_id: "typo3"
  client_secret: "your-typo3-client-secret-here"
  redirect_uri: "https://typo3.example.org/index.php?eID=oidc"
  scopes: "openid profile email groups"
  
  # User Attribute Mapping
  user_mapping:
    username: "sub"           # OIDC subject identifier
    email: "email"            # User email address
    firstName: "given_name"   # First name
    lastName: "family_name"   # Last name
    displayName: "name"       # Display name
    groups: "groups"          # Group membership
    
  # Group Mapping (TYPO3 group UIDs)
  group_mapping:
    administrators: 1         # TYPO3 admin group
    maintainers: 2            # TYPO3 maintainer group
    editors: 3                # TYPO3 editor group
    
  # Auto-provisioning Settings
  auto_provisioning:
    create_user_if_not_exists: true
    update_user_on_login: true
    default_groups: [3]       # Default group UIDs
    
  # Security Settings
  security:
    require_https: true
    validate_issuer: true
    validate_audience: true
    clock_skew_tolerance: 30  # seconds
```

#### Extension Configuration (`ext_conf_template.txt`)
```php
<?php
defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oidc'] = [
    'enabled' => true,
    'issuer' => 'https://id.example.org',
    'clientId' => 'typo3',
    'clientSecret' => 'your-typo3-client-secret-here',
    'redirectUri' => 'https://typo3.example.org/index.php?eID=oidc',
    'scopes' => 'openid profile email groups',
    'autoLogin' => true,
    'autoLogout' => true,
    'userMapping' => [
        'username' => 'preferred_username',
        'email' => 'email',
        'firstName' => 'given_name',
        'lastName' => 'family_name',
        'groups' => 'groups'
    ]
];
```

#### Frontend Plugin Configuration
```yaml
# TypoScript configuration
plugin.tx_oidc {
    settings {
        issuer = https://id.example.org
        clientId = typo3
        clientSecret = your-typo3-client-secret-here
        redirectUri = https://typo3.example.org/index.php?eID=oidc
        scopes = openid profile email groups
        autoLogin = 1
        autoLogout = 1
    }
    
    persistence {
        storagePid = 1
    }
    
    features {
        skipDefaultArguments = 1
    }
}
```

#### User Group Mapping
```php
// ext_localconf.php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\Causal\Oidc\Service\AuthenticationService::class] = [
    'className' => \YourVendor\YourExtension\Service\CustomAuthenticationService::class
];

// Custom authentication service
class CustomAuthenticationService extends \Causal\Oidc\Service\AuthenticationService
{
    protected function mapGroups($userData)
    {
        $groups = [];
        
        // Map LDAP groups to TYPO3 groups
        $groupMapping = [
            'administrators' => 1,  // TYPO3 admin group
            'maintainers' => 2,      // TYPO3 editor group
            'org_admin' => 3,        // TYPO3 content editor group
            'user' => 4              // TYPO3 user group
        ];
        
        if (isset($userData['groups'])) {
            foreach ($userData['groups'] as $group) {
                if (isset($groupMapping[$group])) {
                    $groups[] = $groupMapping[$group];
                }
            }
        }
        
        return $groups;
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
    -d "grant_type=client_credentials&client_id=typo3&client_secret=your-typo3-client-secret-here"
```

#### Test User Authentication
1. Go to your TYPO3 frontend
2. Click the OIDC login button
3. Complete authentication on the OIDC provider
4. Verify user is logged into TYPO3

## TYPO3 with Legacy SSO Extension

### Installation

#### Via Composer
```bash
# Install the ig_ldap_sso_auth extension
composer require typo3-ter/ig_ldap_sso_auth

# Or install via TYPO3 Extension Manager
# Search for "ig_ldap_sso_auth" and install
```

#### Via Extension Manager
1. Go to **Admin Tools** → **Extensions**
2. Search for "ig_ldap_sso_auth"
3. Click **Install**

### Configuration

#### Extension Configuration
```php
<?php
defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ig_ldap_sso_auth']['auth'] = [
    'LDAP' => [
        'host' => 'ldap.example.com',
        'port' => 636,
        'encryption' => 'ssl',
        'base' => 'dc=example,dc=com',
        'filter' => '(uid=%s)',
        'bindDN' => 'cn=admin,dc=example,dc=com',
        'bindPassword' => 'admin123',
        'userMapping' => [
            'username' => 'uid',
            'email' => 'mail',
            'firstName' => 'givenName',
            'lastName' => 'sn',
            'groups' => 'memberOf'
        ]
    ]
];

$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ig_ldap_sso_auth']['auth']['OIDC'] = [
    'enabled' => true,
    'issuer' => 'https://id.example.org',
    'clientId' => 'typo3',
    'clientSecret' => 'your-typo3-client-secret-here',
    'redirectUri' => 'https://typo3.example.org/index.php?eID=oidc',
    'scopes' => 'openid profile email groups'
];
```

#### LDAP Configuration
```php
// Additional LDAP settings
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ig_ldap_sso_auth']['auth']['LDAP']['additional'] = [
    'userSearch' => [
        'baseDN' => 'ou=people,dc=example,dc=com',
        'filter' => '(objectClass=inetOrgPerson)',
        'attributes' => ['uid', 'mail', 'givenName', 'sn', 'cn']
    ],
    'groupSearch' => [
        'baseDN' => 'ou=roles,dc=example,dc=com',
        'filter' => '(objectClass=groupOfNames)',
        'attributes' => ['cn', 'member']
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
1. Go to your TYPO3 frontend
2. Use LDAP credentials to log in
3. Verify user is authenticated
4. Check group membership

## User Provisioning

### Automatic User Creation
```php
// ext_localconf.php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ig_ldap_sso_auth']['auth']['LDAP']['autoCreateUsers'] = true;

// Custom user creation hook
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ig_ldap_sso_auth']['auth']['LDAP']['userCreationHook'] = 
    \YourVendor\YourExtension\Hooks\UserCreationHook::class;
```

### User Creation Hook
```php
<?php
namespace YourVendor\YourExtension\Hooks;

class UserCreationHook
{
    public function createUser($userData)
    {
        // Create TYPO3 user
        $user = new \TYPO3\CMS\Core\Authentication\AbstractUserAuthentication();
        
        $user->user = [
            'username' => $userData['uid'],
            'email' => $userData['mail'],
            'first_name' => $userData['givenName'],
            'last_name' => $userData['sn'],
            'usergroup' => $this->mapGroups($userData['memberOf'])
        ];
        
        return $user;
    }
    
    private function mapGroups($ldapGroups)
    {
        $typo3Groups = [];
        
        $groupMapping = [
            'administrators' => 1,
            'maintainers' => 2,
            'org_admin' => 3,
            'user' => 4
        ];
        
        foreach ($ldapGroups as $group) {
            if (isset($groupMapping[$group])) {
                $typo3Groups[] = $groupMapping[$group];
            }
        }
        
        return $typo3Groups;
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
    -d "grant_type=client_credentials&client_id=typo3&client_secret=your-typo3-client-secret-here"
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

#### TYPO3 Configuration Issues
```bash
# Check TYPO3 logs
tail -f /var/log/typo3/typo3.log

# Clear TYPO3 cache
./vendor/bin/typo3 cache:flush

# Check extension status
./vendor/bin/typo3 extension:list
```

### Debug Configuration

#### Enable Debug Logging
```php
// ext_localconf.php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ig_ldap_sso_auth']['auth']['LDAP']['debug'] = true;
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oidc']['debug'] = true;
```

#### Debug Log Location
```bash
# TYPO3 debug logs
tail -f /var/log/typo3/debug.log

# Extension specific logs
tail -f /var/log/typo3/ig_ldap_sso_auth.log
tail -f /var/log/typo3/oidc.log
```

## Best Practices

### Security Considerations
1. **Use HTTPS**: Always use HTTPS for OIDC and LDAP connections
2. **Strong Passwords**: Use strong passwords for LDAP bind DN
3. **Certificate Validation**: Enable certificate validation for LDAPS
4. **Access Control**: Implement proper access controls in TYPO3

### Performance Optimization
1. **Connection Pooling**: Configure LDAP connection pooling
2. **Caching**: Enable TYPO3 caching for better performance
3. **Group Mapping**: Cache group mappings to reduce LDAP queries
4. **User Sessions**: Configure appropriate session timeouts

### Maintenance
1. **Regular Updates**: Keep TYPO3 and extensions updated
2. **Log Monitoring**: Monitor logs for authentication issues
3. **User Management**: Regular review of user accounts and groups
4. **Backup**: Regular backup of TYPO3 configuration and data

## Support

For TYPO3 integration support:
- **TYPO3 Documentation**: [TYPO3 Documentation](https://docs.typo3.org/)
- **Extension Documentation**: [Causal OIDC](https://docs.typo3.org/p/causal/oidc/main/en-us/)
- **Community Support**: [TYPO3 Community](https://typo3.org/community/)
- **Professional Support**: [TYPO3 Association](https://typo3.org/association/)
