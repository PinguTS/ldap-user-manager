# TYPO3 Integration Troubleshooting

This guide provides comprehensive troubleshooting procedures for TYPO3 integration issues.

## Quick Diagnosis

### Health Check Script
```bash
#!/bin/bash
# typo3_health_check.sh

echo "=== TYPO3 Health Check ==="

# Configuration
TYPO3_URL="https://typo3.example.org"
OIDC_ISSUER="https://id.example.org"
LDAP_HOST="ldap.example.com"
LDAP_PORT="636"

# Check 1: TYPO3 accessibility
echo "1. Checking TYPO3 accessibility..."
if curl -f -s "$TYPO3_URL" > /dev/null; then
    echo "✅ TYPO3 is accessible"
else
    echo "❌ TYPO3 is not accessible"
    echo "   - Check if TYPO3 service is running"
    echo "   - Verify web server configuration"
    echo "   - Check network connectivity"
fi

# Check 2: OIDC extension status
echo "2. Checking OIDC extension status..."
OIDC_RESPONSE=$(curl -s "$TYPO3_URL/index.php?eID=oidc&action=login")

if echo "$OIDC_RESPONSE" | grep -q "redirect\|location"; then
    echo "✅ OIDC extension is working"
else
    echo "❌ OIDC extension is not working"
    echo "   - Check if OIDC extension is installed"
    echo "   - Verify extension configuration"
    echo "   - Check TYPO3 logs for errors"
fi

# Check 3: OIDC provider connectivity
echo "3. Checking OIDC provider connectivity..."
if curl -f -s "$OIDC_ISSUER/.well-known/openid_configuration" > /dev/null; then
    echo "✅ OIDC provider is accessible"
else
    echo "❌ OIDC provider is not accessible"
    echo "   - Check if Dex service is running"
    echo "   - Verify network connectivity"
    echo "   - Check SSL certificate validity"
fi

# Check 4: LDAP connectivity
echo "4. Checking LDAP connectivity..."
if ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s base > /dev/null 2>&1; then
    echo "✅ LDAP server is accessible"
else
    echo "❌ LDAP server is not accessible"
    echo "   - Check if LDAP service is running"
    echo "   - Verify network connectivity"
    echo "   - Check SSL certificate validity"
fi

echo "=== Health Check Completed ==="
```

## Common Issues and Solutions

### 1. TYPO3 Not Accessible

**Symptoms:**
- Cannot reach TYPO3 website
- Connection timeout errors
- 404 or 500 errors

**Diagnosis:**
```bash
# Test TYPO3 accessibility
curl -v https://typo3.example.org

# Check web server status
sudo systemctl status apache2
sudo systemctl status nginx

# Check TYPO3 logs
tail -20 /var/log/apache2/typo3_error.log
tail -20 /var/log/typo3/typo3.log
```

**Solutions:**
1. **Check web server status:**
   ```bash
   sudo systemctl start apache2
   sudo systemctl enable apache2
   ```

2. **Verify virtual host configuration:**
   ```bash
   sudo apache2ctl -S
   sudo apache2ctl configtest
   ```

3. **Check file permissions:**
   ```bash
   sudo chown -R www-data:www-data /var/www/html/typo3
   sudo chmod -R 755 /var/www/html/typo3
   ```

### 2. OIDC Extension Not Working

**Symptoms:**
- OIDC login button not appearing
- "Extension not found" errors
- Configuration errors

**Diagnosis:**
```bash
# Check if extension is installed
cd /var/www/html/typo3
composer show causal/oidc

# Check extension status in TYPO3
php bin/typo3 extension:list | grep oidc

# Check extension configuration
php bin/typo3 configuration:get EXTENSIONS.oidc
```

**Solutions:**
1. **Install OIDC extension:**
   ```bash
   cd /var/www/html/typo3
   composer require causal/oidc
   php bin/typo3 extension:activate oidc
   ```

2. **Verify configuration:**
   ```bash
   php bin/typo3 configuration:set EXTENSIONS.oidc.enabled true
   php bin/typo3 configuration:set EXTENSIONS.oidc.issuer "https://id.example.org"
   ```

3. **Clear TYPO3 cache:**
   ```bash
   php bin/typo3 cache:flush
   ```

### 3. OIDC Authentication Failures

**Symptoms:**
- "Invalid client" errors
- "Redirect URI mismatch" errors
- Token validation failures

**Diagnosis:**
```bash
# Test OIDC client configuration
curl -X POST https://id.example.org/token \
  -d "grant_type=client_credentials&client_id=typo3&client_secret=your-secret"

# Test OIDC discovery
curl -v https://id.example.org/.well-known/openid_configuration

# Check TYPO3 OIDC logs
tail -20 /var/log/typo3/oidc.log
```

**Solutions:**
1. **Verify client configuration in Dex:**
   ```yaml
   # dex/config.yaml
   staticClients:
   - id: typo3
     secret: your-typo3-client-secret-here
     redirectURIs:
     - https://typo3.example.org/index.php?eID=oidc
   ```

2. **Check redirect URI configuration:**
   ```bash
   php bin/typo3 configuration:set EXTENSIONS.oidc.redirectUri "https://typo3.example.org/index.php?eID=oidc"
   ```

3. **Regenerate client secret:**
   ```bash
   # Generate new secret
   openssl rand -base64 32
   
   # Update in TYPO3
   php bin/typo3 configuration:set EXTENSIONS.oidc.clientSecret "new-secret"
   
   # Update in Dex
   # Edit dex/config.yaml with new secret
   ```

### 4. User Not Created After OIDC Login

**Symptoms:**
- OIDC login successful but user not created in TYPO3
- "User not found" errors
- Missing user attributes

**Diagnosis:**
```bash
# Check TYPO3 user database
mysql -u typo3 -p typo3 -e "SELECT * FROM fe_users WHERE username = 'test@example.com';"

# Check user mapping configuration
php bin/typo3 configuration:get EXTENSIONS.oidc.userMapping

# Check OIDC user info
curl -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  https://id.example.org/userinfo
```

**Solutions:**
1. **Verify user mapping configuration:**
   ```bash
   php bin/typo3 configuration:set EXTENSIONS.oidc.userMapping.username "preferred_username"
   php bin/typo3 configuration:set EXTENSIONS.oidc.userMapping.email "email"
   ```

2. **Enable auto-provisioning:**
   ```bash
   php bin/typo3 configuration:set EXTENSIONS.oidc.autoLogin true
   php bin/typo3 configuration:set EXTENSIONS.oidc.createUserIfNotExists true
   ```

3. **Check user attributes in LDAP:**
   ```bash
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(uid=testuser)" \
     uid mail cn givenName sn
   ```

### 5. LDAP Integration Issues

**Symptoms:**
- LDAP authentication failures
- "Invalid credentials" errors
- User search problems

**Diagnosis:**
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
  -b "dc=example,dc=com" \
  -s sub "(objectClass=inetOrgPerson)"

# Test user authentication
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "uid=testuser,ou=people,dc=example,dc=com" \
  -w testpassword \
  -b "dc=example,dc=com" \
  -s base
```

**Solutions:**
1. **Verify LDAP configuration:**
   ```bash
   php bin/typo3 configuration:set EXTENSIONS.ig_ldap_sso_auth.ldapHost "ldap.example.com"
   php bin/typo3 configuration:set EXTENSIONS.ig_ldap_sso_auth.ldapPort 636
   php bin/typo3 configuration:set EXTENSIONS.ig_ldap_sso_auth.ldapBaseDn "dc=example,dc=com"
   ```

2. **Check LDAP user filter:**
   ```bash
   php bin/typo3 configuration:set EXTENSIONS.ig_ldap_sso_auth.ldapUserFilter "(objectClass=inetOrgPerson)"
   ```

3. **Verify user attributes:**
   ```bash
   php bin/typo3 configuration:set EXTENSIONS.ig_ldap_sso_auth.ldapUsernameAttribute "uid"
   php bin/typo3 configuration:set EXTENSIONS.ig_ldap_sso_auth.ldapEmailAttribute "mail"
   ```

## Performance Issues

### 1. Slow Authentication

**Symptoms:**
- Long login times
- Timeout errors
- High response times

**Diagnosis:**
```bash
# Test authentication performance
time curl -X POST https://id.example.org/token \
  -d "grant_type=password&username=test@example.com&password=test123&client_id=typo3&client_secret=your-secret"

# Check TYPO3 performance
php bin/typo3 cache:flush
php bin/typo3 cache:warmup
```

**Solutions:**
1. **Optimize TYPO3 cache:**
   ```bash
   php bin/typo3 cache:flush
   php bin/typo3 cache:warmup
   ```

2. **Enable OPcache:**
   ```bash
   # In php.ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=4000
   ```

3. **Optimize database:**
   ```bash
   mysql -u typo3 -p typo3 -e "OPTIMIZE TABLE fe_users;"
   mysql -u typo3 -p typo3 -e "ANALYZE TABLE fe_users;"
   ```

### 2. High Memory Usage

**Symptoms:**
- Memory exhaustion
- Slow response times
- Service crashes

**Diagnosis:**
```bash
# Check memory usage
free -h
ps aux | grep php

# Check TYPO3 memory usage
php bin/typo3 system:report
```

**Solutions:**
1. **Increase PHP memory limit:**
   ```bash
   # In php.ini
   memory_limit = 512M
   ```

2. **Optimize TYPO3 configuration:**
   ```bash
   php bin/typo3 configuration:set SYS.maxFileSize 2097152
   php bin/typo3 configuration:set SYS.maxImageFileSize 2097152
   ```

3. **Enable garbage collection:**
   ```bash
   php bin/typo3 configuration:set SYS.garbageCollection true
   ```

## Security Issues

### 1. SSL/TLS Configuration

**Symptoms:**
- SSL certificate errors
- Mixed content warnings
- Security warnings

**Diagnosis:**
```bash
# Test SSL configuration
openssl s_client -connect typo3.example.org:443 -servername typo3.example.org

# Check certificate validity
openssl x509 -in /path/to/certificate.crt -text -noout
```

**Solutions:**
1. **Install valid SSL certificate:**
   ```bash
   # Install Let's Encrypt certificate
   sudo certbot --apache -d typo3.example.org
   ```

2. **Configure HTTPS redirect:**
   ```apache
   # In Apache virtual host
   RewriteEngine On
   RewriteCond %{HTTPS} off
   RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
   ```

3. **Set security headers:**
   ```apache
   # In Apache virtual host
   Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
   Header always set X-Frame-Options DENY
   Header always set X-Content-Type-Options nosniff
   ```

### 2. Access Control Issues

**Symptoms:**
- Unauthorized access
- Missing permissions
- Security vulnerabilities

**Diagnosis:**
```bash
# Check TYPO3 access control
php bin/typo3 backend:user:list
php bin/typo3 backend:group:list

# Check file permissions
ls -la /var/www/html/typo3/
```

**Solutions:**
1. **Set proper file permissions:**
   ```bash
   sudo chown -R www-data:www-data /var/www/html/typo3
   sudo chmod -R 755 /var/www/html/typo3
   sudo chmod -R 777 /var/www/html/typo3/public/fileadmin
   sudo chmod -R 777 /var/www/html/typo3/public/typo3temp
   ```

2. **Configure access control:**
   ```bash
   php bin/typo3 configuration:set BE.adminOnlyMode true
   php bin/typo3 configuration:set BE.lockSSL 3
   ```

3. **Review user permissions:**
   ```bash
   php bin/typo3 backend:user:create
   php bin/typo3 backend:group:create
   ```

## Debugging Tools

### 1. Enable Debug Mode
```bash
# Enable TYPO3 debug mode
php bin/typo3 configuration:set SYS.devIPmask "*"
php bin/typo3 configuration:set SYS.displayErrors 1
php bin/typo3 configuration:set SYS.debugExceptionHandler "TYPO3\CMS\Core\Error\DebugExceptionHandler"
```

### 2. Log Analysis
```bash
# Check TYPO3 logs
tail -f /var/log/typo3/typo3.log
tail -f /var/log/typo3/oidc.log

# Check web server logs
tail -f /var/log/apache2/typo3_error.log
tail -f /var/log/apache2/typo3_access.log
```

### 3. Database Debugging
```bash
# Check TYPO3 database
mysql -u typo3 -p typo3 -e "SHOW TABLES;"
mysql -u typo3 -p typo3 -e "SELECT * FROM fe_users LIMIT 10;"
mysql -u typo3 -p typo3 -e "SELECT * FROM fe_groups LIMIT 10;"
```

## Recovery Procedures

### 1. Backup and Restore
```bash
# Backup TYPO3
tar -czf typo3_backup_$(date +%Y%m%d_%H%M%S).tar.gz /var/www/html/typo3/

# Backup database
mysqldump -u typo3 -p typo3 > typo3_db_backup_$(date +%Y%m%d_%H%M%S).sql

# Restore TYPO3
tar -xzf typo3_backup_YYYYMMDD_HHMMSS.tar.gz -C /

# Restore database
mysql -u typo3 -p typo3 < typo3_db_backup_YYYYMMDD_HHMMSS.sql
```

### 2. Reset Configuration
```bash
# Reset TYPO3 configuration
php bin/typo3 configuration:reset

# Clear all caches
php bin/typo3 cache:flush
php bin/typo3 cache:clear

# Reinstall extensions
php bin/typo3 extension:deactivate oidc
php bin/typo3 extension:activate oidc
```

### 3. Emergency Mode
```bash
# Enable TYPO3 emergency mode
php bin/typo3 configuration:set SYS.maintenanceMode true

# Disable OIDC temporarily
php bin/typo3 configuration:set EXTENSIONS.oidc.enabled false
```

## Prevention

### 1. Regular Monitoring
```bash
# Set up monitoring script
crontab -e
# Add: */5 * * * * /path/to/typo3_health_check.sh
```

### 2. Automated Backups
```bash
# Set up automated backups
crontab -e
# Add: 0 2 * * * /path/to/typo3_backup.sh
```

### 3. Security Updates
```bash
# Update TYPO3 regularly
cd /var/www/html/typo3
composer update

# Update extensions
php bin/typo3 extension:update oidc
```

## Support Resources

### Documentation
- [TYPO3 Documentation](https://docs.typo3.org/)
- [OIDC Extension Documentation](https://extensions.typo3.org/extension/oidc/)
- [Main Documentation](../../docs/identity.md)

### Community Support
- [TYPO3 Forge](https://forge.typo3.org/)
- [Stack Overflow](https://stackoverflow.com/questions/tagged/typo3)
- [TYPO3 Slack](https://typo3.slack.com/)

### Professional Support
- [TYPO3 Association](https://typo3.org/association/)
- [TYPO3 Partners](https://typo3.org/partners/)
