# Custom Applications OIDC Configuration

This guide explains how to configure custom applications to authenticate against your local Dex OIDC provider.

## Prerequisites

- Custom application with OIDC/LDAP client capabilities
- Access to application configuration files
- Dex OIDC provider running at `https://id.example.org`

## Configuration Examples

### PHP Application

#### OIDC Client Configuration
```php
<?php
// config/oidc.php

return [
    'issuer' => 'https://id.example.org',
    'client_id' => 'custom-app',
    'client_secret' => 'your-custom-app-client-secret-here',
    'redirect_uri' => 'https://custom-app.example.org/callback',
    'scopes' => 'openid profile email groups',
    
    'user_mapping' => [
        'username' => 'preferred_username',
        'email' => 'email',
        'first_name' => 'given_name',
        'last_name' => 'family_name',
        'display_name' => 'name',
        'groups' => 'groups'
    ]
];
```

#### LDAP Client Configuration
```php
<?php
// config/ldap.php

return [
    'host' => 'ldap.example.com',
    'port' => 636,
    'encryption' => 'ssl',
    'base_dn' => 'dc=example,dc=com',
    'bind_dn' => 'cn=admin,dc=example,dc=com',
    'bind_password' => 'admin123',
    
    'user_filter' => '(objectClass=inetOrgPerson)',
    'group_filter' => '(objectClass=groupOfNames)',
    
    'attributes' => [
        'username' => 'uid',
        'email' => 'mail',
        'name' => 'cn',
        'first_name' => 'givenName',
        'last_name' => 'sn'
    ]
];
```

### Python Application

#### OIDC Client Configuration
```python
# config/oidc.py

OIDC_CONFIG = {
    'issuer': 'https://id.example.org',
    'client_id': 'custom-app',
    'client_secret': 'your-custom-app-client-secret-here',
    'redirect_uri': 'https://custom-app.example.org/callback',
    'scopes': 'openid profile email groups',
    
    'user_mapping': {
        'username': 'preferred_username',
        'email': 'email',
        'first_name': 'given_name',
        'last_name': 'family_name',
        'display_name': 'name',
        'groups': 'groups'
    }
}
```

#### LDAP Client Configuration
```python
# config/ldap.py

LDAP_CONFIG = {
    'host': 'ldap.example.com',
    'port': 636,
    'encryption': 'ssl',
    'base_dn': 'dc=example,dc=com',
    'bind_dn': 'cn=admin,dc=example,dc=com',
    'bind_password': 'admin123',
    
    'user_filter': '(objectClass=inetOrgPerson)',
    'group_filter': '(objectClass=groupOfNames)',
    
    'attributes': {
        'username': 'uid',
        'email': 'mail',
        'name': 'cn',
        'first_name': 'givenName',
        'last_name': 'sn'
    }
}
```

### Node.js Application

#### OIDC Client Configuration
```javascript
// config/oidc.js

module.exports = {
    issuer: 'https://id.example.org',
    clientId: 'custom-app',
    clientSecret: 'your-custom-app-client-secret-here',
    redirectUri: 'https://custom-app.example.org/callback',
    scopes: 'openid profile email groups',
    
    userMapping: {
        username: 'preferred_username',
        email: 'email',
        firstName: 'given_name',
        lastName: 'family_name',
        displayName: 'name',
        groups: 'groups'
    }
};
```

#### LDAP Client Configuration
```javascript
// config/ldap.js

module.exports = {
    host: 'ldap.example.com',
    port: 636,
    encryption: 'ssl',
    baseDn: 'dc=example,dc=com',
    bindDn: 'cn=admin,dc=example,dc=com',
    bindPassword: 'admin123',
    
    userFilter: '(objectClass=inetOrgPerson)',
    groupFilter: '(objectClass=groupOfNames)',
    
    attributes: {
        username: 'uid',
        email: 'mail',
        name: 'cn',
        firstName: 'givenName',
        lastName: 'sn'
    }
};
```

## Testing

### 1. Verify Configuration
- Check application logs for OIDC/LDAP connection errors
- Verify OIDC provider URL is accessible
- Ensure client secret matches Dex configuration

### 2. Test OIDC Flow
1. Visit application login page
2. Click OIDC login button
3. Should redirect to Dex at `https://id.example.org/auth`
4. Login with LDAP credentials
5. Should redirect back to application
6. User should be logged in with proper attributes

### 3. Test LDAP Flow
1. Use application login form
2. Enter LDAP credentials
3. Should authenticate against LDAP server
4. User should be logged in with proper attributes

## Troubleshooting

### Common Issues

**Error**: "OIDC client not configured"
- **Solution**: Verify OIDC configuration in application
- **Check**: Client ID and secret match Dex configuration

**Error**: "LDAP connection failed"
- **Solution**: Verify LDAP server connectivity
- **Check**: LDAP host, port, and credentials

**Error**: "User not found" after authentication
- **Solution**: Check user mapping configuration
- **Check**: User exists in LDAP directory

### Debug Steps

1. Check application logs for authentication errors
2. Test OIDC discovery: `curl -k https://id.example.org/.well-known/openid_configuration`
3. Test LDAP connectivity: `ldapsearch -H ldaps://ldap.example.com:636 -D "cn=admin,dc=example,dc=com" -w admin123 -b "dc=example,dc=com" -s base`
4. Verify user attributes in LDAP directory

## Security Considerations

- **Client Secret**: Store securely, never commit to version control
- **HTTPS Only**: Ensure application runs over HTTPS
- **User Permissions**: Configure appropriate access levels
- **Token Validation**: Implement proper token validation
- **Session Management**: Use secure session handling

## Support

- **OIDC Documentation**: https://openid.net/connect/
- **LDAP Documentation**: https://tools.ietf.org/html/rfc4511
- **Main Documentation**: [../../docs/identity.md](../../docs/identity.md)
- **OIDC Quick Reference**: [../../docs/oidc-quick-reference.md](../../docs/oidc-quick-reference.md)
