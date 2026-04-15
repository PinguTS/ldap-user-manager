# Troubleshooting Integration Issues

This guide provides comprehensive troubleshooting procedures for LDAP User Manager integration issues.

## Overview

Common integration issues include:
- **Authentication failures**
- **User synchronization problems**
- **Group membership issues**
- **Network connectivity problems**
- **Configuration errors**
- **Performance issues**

## Quick Diagnosis

### Health Check Script
```bash
#!/bin/bash
# integration-health-check.sh

echo "=== Integration Health Check ==="

# Configuration
OIDC_ISSUER="https://id.example.org"
LDAP_HOST="ldap.example.com"
LDAP_PORT="636"
LDAP_BASE_DN="dc=example,dc=com"
LDAP_BIND_DN="cn=admin,dc=example,dc=com"
LDAP_BIND_PWD="admin123"

# Check 1: OIDC Provider Status
echo "1. Checking OIDC provider status..."
if curl -f -s "$OIDC_ISSUER/.well-known/openid_configuration" > /dev/null; then
    echo "✅ OIDC provider is accessible"
else
    echo "❌ OIDC provider is not accessible"
    echo "   - Check if OIDC service is running"
    echo "   - Verify network connectivity"
    echo "   - Check SSL certificate validity"
fi

# Check 2: LDAP Server Status
echo "2. Checking LDAP server status..."
if ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
    -D "$LDAP_BIND_DN" \
    -w "$LDAP_BIND_PWD" \
    -b "$LDAP_BASE_DN" \
    -s base > /dev/null 2>&1; then
    echo "✅ LDAP server is accessible"
else
    echo "❌ LDAP server is not accessible"
    echo "   - Check if LDAP service is running"
    echo "   - Verify network connectivity"
    echo "   - Check SSL certificate validity"
fi

# Check 3: User Count
echo "3. Checking user count..."
USER_COUNT=$(ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
    -D "$LDAP_BIND_DN" \
    -w "$LDAP_BIND_PWD" \
    -b "$LDAP_BASE_DN" \
    -s sub "(objectClass=inetOrgPerson)" | grep -c "^dn:")

if [ "$USER_COUNT" -gt 0 ]; then
    echo "✅ Found $USER_COUNT users in LDAP"
else
    echo "❌ No users found in LDAP"
    echo "   - Check LDAP data import"
    echo "   - Verify LDAP schema"
fi

# Check 4: Group Count
echo "4. Checking group count..."
GROUP_COUNT=$(ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
    -D "$LDAP_BIND_DN" \
    -w "$LDAP_BIND_PWD" \
    -b "$LDAP_BASE_DN" \
    -s sub "(objectClass=groupOfNames)" | grep -c "^dn:")

if [ "$GROUP_COUNT" -gt 0 ]; then
    echo "✅ Found $GROUP_COUNT groups in LDAP"
else
    echo "❌ No groups found in LDAP"
    echo "   - Check LDAP data import"
    echo "   - Verify LDAP schema"
fi

echo "=== Health Check Completed ==="
```

## OIDC Integration Issues

### Common OIDC Problems

#### 1. OIDC Provider Not Accessible
**Symptoms:**
- Cannot reach OIDC provider
- Connection timeout errors
- SSL certificate errors

**Diagnosis:**
```bash
# Test basic connectivity
curl -v https://id.example.org/.well-known/openid_configuration

# Check SSL certificate
openssl s_client -connect id.example.org:443 -servername id.example.org

# Test DNS resolution
nslookup id.example.org
```

**Solutions:**
1. **Check OIDC service status:**
   ```bash
   docker-compose ps dex
   docker-compose logs dex
   ```

2. **Verify network connectivity:**
   ```bash
   ping id.example.org
   telnet id.example.org 443
   ```

3. **Check SSL certificate:**
   ```bash
   openssl x509 -in certs/id.example.org.crt -text -noout
   ```

#### 2. Client Configuration Errors
**Symptoms:**
- "Invalid client" errors
- "Unauthorized client" messages
- Client secret validation failures

**Diagnosis:**
```bash
# Test client credentials
curl -X POST https://id.example.org/token \
  -d "grant_type=client_credentials&client_id=your-client-id&client_secret=your-client-secret"
```

**Solutions:**
1. **Verify client configuration in Dex:**
   ```yaml
   # dex/config.yaml
   staticClients:
   - id: your-client-id
     secret: your-client-secret
     redirectURIs:
     - https://your-app.example.org/callback
   ```

2. **Check client registration:**
   ```bash
   # List registered clients
   curl -s https://id.example.org/.well-known/openid_configuration | jq '.'
   ```

3. **Regenerate client secret:**
   ```bash
   # Generate new secret
   openssl rand -base64 32
   ```

#### 3. Authorization Code Flow Issues
**Symptoms:**
- Authorization code exchange fails
- "Invalid authorization code" errors
- Redirect URI mismatch

**Diagnosis:**
```bash
# Test authorization URL generation
echo "https://id.example.org/auth?response_type=code&client_id=your-client-id&redirect_uri=https://your-app.example.org/callback&scope=openid%20profile%20email%20groups&state=test"
```

**Solutions:**
1. **Verify redirect URI configuration:**
   ```yaml
   # dex/config.yaml
   staticClients:
   - id: your-client-id
     redirectURIs:
     - https://your-app.example.org/callback  # Must match exactly
   ```

2. **Check state parameter handling:**
   ```javascript
   // Ensure state parameter is properly generated and validated
   const state = crypto.randomBytes(16).toString('hex');
   ```

3. **Verify scope configuration:**
   ```yaml
   # dex/config.yaml
   staticClients:
   - id: your-client-id
     scopes:
     - openid
     - profile
     - email
     - groups
   ```

#### 4. Token Validation Issues
**Symptoms:**
- Token validation failures
- "Invalid token" errors
- Token expiration issues

**Diagnosis:**
```bash
# Decode JWT token (without signature verification)
echo "YOUR_JWT_TOKEN" | cut -d'.' -f2 | base64 -d | jq '.'

# Check token expiration
echo "YOUR_JWT_TOKEN" | cut -d'.' -f2 | base64 -d | jq '.exp'
```

**Solutions:**
1. **Check token expiry configuration:**
   ```yaml
   # dex/config.yaml
   oauth2:
     skipApproval: true
   expiry:
     idTokens: 24h
     accessTokens: 1h
   ```

2. **Verify token signing:**
   ```bash
   # Check signing key
   openssl x509 -in certs/dex.key -text -noout
   ```

3. **Implement token refresh:**
   ```javascript
   // Implement refresh token logic
   if (token.exp < Date.now() / 1000) {
     // Refresh token
     const refreshResponse = await fetch('/token', {
       method: 'POST',
       body: new URLSearchParams({
         grant_type: 'refresh_token',
         refresh_token: token.refresh_token
       })
     });
   }
   ```

## LDAP Integration Issues

### Common LDAP Problems

#### 1. LDAP Connection Failures
**Symptoms:**
- Cannot connect to LDAP server
- Connection timeout errors
- SSL/TLS handshake failures

**Diagnosis:**
```bash
# Test LDAP connectivity
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s base

# Check SSL certificate
openssl s_client -connect ldap.example.com:636 -servername ldap.example.com
```

**Solutions:**
1. **Check LDAP service status:**
   ```bash
   docker-compose ps ldap
   docker-compose logs ldap
   ```

2. **Verify LDAP configuration:**
   ```bash
   # Check LDAP server configuration
   docker exec ldap-user-manager_ldap_1 slapcat -n 0 | grep -E "(olcSuffix|olcRootDN)"
   ```

3. **Test SSL configuration:**
   ```bash
   # Check SSL certificate
   openssl x509 -in certs/ldap.example.com.crt -text -noout
   ```

#### 2. Authentication Failures
**Symptoms:**
- User login failures
- "Invalid credentials" errors
- Bind DN issues

**Diagnosis:**
```bash
# Test admin bind
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s base

# Test user bind
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "uid=testuser,ou=people,dc=example,dc=com" \
  -w testpassword \
  -b "dc=example,dc=com" \
  -s base
```

**Solutions:**
1. **Verify user DN format:**
   ```bash
   # Check user DN format
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(uid=testuser)"
   ```

2. **Check password policy:**
   ```bash
   # Check password policy
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "cn=config" \
     -s sub "(olcPasswordPolicy=*)"
   ```

3. **Reset user password:**
   ```bash
   # Reset user password
   ldappasswd -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -s newpassword \
     "uid=testuser,ou=people,dc=example,dc=com"
   ```

#### 3. User Search Issues
**Symptoms:**
- User not found errors
- Search filter problems
- Attribute mapping issues

**Diagnosis:**
```bash
# Test user search
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s sub "(uid=testuser)"

# Test attribute search
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s sub "(mail=testuser@example.com)"
```

**Solutions:**
1. **Verify search base configuration:**
   ```bash
   # Check LDAP base DN
   echo $LDAP_BASE_DN
   ```

2. **Check search filter:**
   ```bash
   # Test different search filters
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(objectClass=inetOrgPerson)"
   ```

3. **Verify attribute mapping:**
   ```bash
   # Check user attributes
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(uid=testuser)" \
     uid cn mail sn givenName
   ```

#### 4. Group Membership Issues
**Symptoms:**
- Group membership not found
- Group search failures
- Member attribute problems

**Diagnosis:**
```bash
# Test group search
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s sub "(objectClass=groupOfNames)"

# Test user group membership
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s sub "(uid=testuser)" \
  memberOf
```

**Solutions:**
1. **Verify group structure:**
   ```bash
   # Check group structure
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(objectClass=groupOfNames)" \
     cn member
   ```

2. **Check member attribute:**
   ```bash
   # Verify member attribute format
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "cn=admins,ou=roles,dc=example,dc=com" \
     -s base \
     member
   ```

3. **Add user to group:**
   ```bash
   # Add user to group
   ldapmodify -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 << EOF
   dn: cn=admins,ou=roles,dc=example,dc=com
   changetype: modify
   add: member
   member: uid=testuser,ou=people,dc=example,dc=com
   EOF
   ```

## Platform-Specific Issues

### TYPO3 Integration Issues

#### Common TYPO3 Problems

1. **Extension Installation Issues**
   ```bash
   # Check extension status
   cd /path/to/typo3
   ./vendor/bin/typo3 extension:list | grep oidc
   ```

2. **Configuration Errors**
   ```php
   // Check TYPO3 configuration
   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oidc']
   ```

3. **User Synchronization Problems**
   ```bash
   # Check TYPO3 user database
   ./vendor/bin/typo3 backend:user:list
   ```

### Nextcloud Integration Issues

#### Common Nextcloud Problems

1. **App Installation Issues**
   ```bash
   # Check app status
   cd /path/to/nextcloud
   ./occ app:list | grep oidc
   ```

2. **Configuration Errors**
   ```bash
   # Check Nextcloud configuration
   ./occ config:list system
   ```

3. **User Backend Issues**
   ```bash
   # Test LDAP user backend
   ./occ user:list
   ```

### GitLab Integration Issues

#### Common GitLab Problems

1. **OmniAuth Configuration Issues**
   ```bash
   # Check GitLab configuration
   sudo gitlab-rake gitlab:check
   ```

2. **OIDC Provider Issues**
   ```bash
   # Test OIDC configuration
   curl -X POST https://gitlab.example.org/users/auth/openid_connect
   ```

3. **User Provisioning Problems**
   ```bash
   # Check GitLab users
   sudo gitlab-rake gitlab:user:list
   ```

## Performance Issues

### Common Performance Problems

#### 1. Slow Authentication
**Symptoms:**
- Long login times
- Timeout errors
- High response times

**Diagnosis:**
```bash
# Test authentication performance
time ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s sub "(uid=testuser)"
```

**Solutions:**
1. **Optimize LDAP queries:**
   ```bash
   # Use indexed attributes
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(uid=testuser)" \
     uid cn mail
   ```

2. **Implement caching:**
   ```php
   // Implement user cache
   $cache = new Cache();
   $user = $cache->get("user:$uid") ?: fetchUserFromLDAP($uid);
   ```

3. **Connection pooling:**
   ```php
   // Use connection pooling
   $ldap = new LdapConnectionPool([
       'host' => 'ldap.example.com',
       'port' => 636,
       'pool_size' => 10
   ]);
   ```

#### 2. High Memory Usage
**Symptoms:**
- Memory exhaustion
- Slow response times
- Service crashes

**Diagnosis:**
```bash
# Check memory usage
docker stats ldap-user-manager_ldap_1
docker stats ldap-user-manager_dex_1
```

**Solutions:**
1. **Optimize LDAP queries:**
   ```bash
   # Limit search results
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(objectClass=inetOrgPerson)" \
     -z 100
   ```

2. **Implement pagination:**
   ```php
   // Implement pagination
   $users = $ldap->search($baseDn, $filter, [
       'limit' => 100,
       'offset' => $offset
   ]);
   ```

3. **Memory limits:**
   ```yaml
   # docker-compose.yml
   services:
     ldap:
       deploy:
         resources:
           limits:
             memory: 512M
   ```

## Debugging Tools

### Log Analysis

#### Enable Debug Logging
```bash
# Enable debug mode
export DEBUG=true
export LDAP_DEBUG=1

# Check application logs
docker-compose logs ldap-user-manager
docker-compose logs dex
docker-compose logs ldap
```

#### Log Analysis Script
```bash
#!/bin/bash
# analyze-logs.sh

echo "=== Log Analysis ==="

# Analyze LDAP User Manager logs
echo "1. Analyzing LDAP User Manager logs..."
docker-compose logs ldap-user-manager | grep -E "(ERROR|WARN|Exception)" | tail -20

# Analyze Dex logs
echo "2. Analyzing Dex logs..."
docker-compose logs dex | grep -E "(ERROR|WARN|Exception)" | tail -20

# Analyze LDAP logs
echo "3. Analyzing LDAP logs..."
docker-compose logs ldap | grep -E "(ERROR|WARN|Exception)" | tail -20

echo "=== Log Analysis Completed ==="
```

### Network Diagnostics

#### Network Test Script
```bash
#!/bin/bash
# network-test.sh

echo "=== Network Diagnostics ==="

# Test DNS resolution
echo "1. Testing DNS resolution..."
nslookup id.example.org
nslookup ldap.example.com

# Test port connectivity
echo "2. Testing port connectivity..."
telnet id.example.org 443
telnet ldap.example.com 636

# Test SSL certificates
echo "3. Testing SSL certificates..."
openssl s_client -connect id.example.org:443 -servername id.example.org < /dev/null
openssl s_client -connect ldap.example.com:636 -servername ldap.example.com < /dev/null

echo "=== Network Diagnostics Completed ==="
```

## Recovery Procedures

### Service Recovery

#### Restart Services
```bash
# Restart all services
docker-compose restart

# Restart specific service
docker-compose restart ldap-user-manager
docker-compose restart dex
docker-compose restart ldap
```

#### Data Recovery
```bash
# Backup LDAP data
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s sub > backup.ldif

# Restore LDAP data
ldapadd -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -f backup.ldif
```

### Configuration Recovery

#### Reset Configuration
```bash
# Reset to default configuration
cp env.example .env
docker-compose down
docker-compose up -d
```

#### Restore Configuration
```bash
# Restore from backup
cp config.backup .env
docker-compose down
docker-compose up -d
```

## Prevention

### Best Practices

1. **Regular Monitoring**
   ```bash
   # Set up monitoring
   crontab -e
   # Add: */5 * * * * /path/to/health-check.sh
   ```

2. **Backup Strategy**
   ```bash
   # Automated backup
   crontab -e
   # Add: 0 2 * * * /path/to/backup.sh
   ```

3. **Documentation**
   - Keep configuration documentation up to date
   - Document troubleshooting procedures
   - Maintain change logs

4. **Testing**
   - Regular integration testing
   - Performance testing
   - Security testing

## Support Resources

### Documentation
- [LDAP User Manager Documentation](../README.md)
- [OIDC Integration Guide](oidc-quick-reference.md)
- [LDAP Configuration Guide](../ldap/setup.md)

### Community Support
- [GitHub Issues](https://github.com/pinguts/ldap-user-manager/issues)
