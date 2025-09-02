# Nextcloud Integration Troubleshooting

This guide provides comprehensive troubleshooting procedures for Nextcloud integration issues.

## Quick Diagnosis

### Health Check Script
```bash
#!/bin/bash
# nextcloud_health_check.sh

echo "=== Nextcloud Health Check ==="

# Configuration
NEXTCLOUD_URL="https://nextcloud.example.org"
OIDC_ISSUER="https://id.example.org"
LDAP_HOST="ldap.example.com"
LDAP_PORT="636"

# Check 1: Nextcloud accessibility
echo "1. Checking Nextcloud accessibility..."
if curl -f -s "$NEXTCLOUD_URL" > /dev/null; then
    echo "✅ Nextcloud is accessible"
else
    echo "❌ Nextcloud is not accessible"
    echo "   - Check if Nextcloud service is running"
    echo "   - Verify web server configuration"
    echo "   - Check network connectivity"
fi

# Check 2: OIDC app status
echo "2. Checking OIDC app status..."
OIDC_RESPONSE=$(curl -s "$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc")

if echo "$OIDC_RESPONSE" | grep -q "redirect\|location"; then
    echo "✅ OIDC app is working"
else
    echo "❌ OIDC app is not working"
    echo "   - Check if OIDC Login app is installed"
    echo "   - Verify app configuration"
    echo "   - Check Nextcloud logs for errors"
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

### 1. Nextcloud Not Accessible

**Symptoms:**
- Cannot reach Nextcloud website
- Connection timeout errors
- 404 or 500 errors

**Diagnosis:**
```bash
# Test Nextcloud accessibility
curl -v https://nextcloud.example.org

# Check web server status
sudo systemctl status apache2
sudo systemctl status nginx

# Check Nextcloud logs
tail -20 /var/log/apache2/nextcloud_error.log
tail -20 /var/log/nextcloud/nextcloud.log
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
   sudo chown -R www-data:www-data /var/www/html/nextcloud
   sudo chmod -R 755 /var/www/html/nextcloud
   ```

### 2. OIDC App Not Working

**Symptoms:**
- OIDC login button not appearing
- "App not found" errors
- Configuration errors

**Diagnosis:**
```bash
# Check if app is installed
cd /var/www/html/nextcloud
sudo -u www-data php occ app:list | grep oidc_login

# Check app status
sudo -u www-data php occ app:enable oidc_login

# Check app configuration
sudo -u www-data php occ config:app:get oidc_login
```

**Solutions:**
1. **Install OIDC Login app:**
   ```bash
   cd /var/www/html/nextcloud
   sudo -u www-data php occ app:install oidc_login
   sudo -u www-data php occ app:enable oidc_login
   ```

2. **Verify configuration:**
   ```bash
   sudo -u www-data php occ config:app:set oidc_login provider-url --value="https://id.example.org"
   sudo -u www-data php occ config:app:set oidc_login client-id --value="nextcloud"
   ```

3. **Clear Nextcloud cache:**
   ```bash
   sudo -u www-data php occ files:scan --all
   sudo -u www-data php occ files:cleanup
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
  -d "grant_type=client_credentials&client_id=nextcloud&client_secret=your-secret"

# Test OIDC discovery
curl -v https://id.example.org/.well-known/openid_configuration

# Check Nextcloud OIDC logs
tail -20 /var/log/nextcloud/oidc_login.log
```

**Solutions:**
1. **Verify client configuration in Dex:**
   ```yaml
   # dex/config.yaml
   staticClients:
   - id: nextcloud
     secret: your-nextcloud-client-secret-here
     redirectURIs:
     - https://nextcloud.example.org/index.php/apps/oidc_login/oidc
   ```

2. **Check redirect URI configuration:**
   ```bash
   sudo -u www-data php occ config:app:set oidc_login redirect-url --value="https://nextcloud.example.org/index.php/apps/oidc_login/oidc"
   ```

3. **Regenerate client secret:**
   ```bash
   # Generate new secret
   openssl rand -base64 32
   
   # Update in Nextcloud
   sudo -u www-data php occ config:app:set oidc_login client-secret --value="new-secret"
   
   # Update in Dex
   # Edit dex/config.yaml with new secret
   ```

### 4. User Not Created After OIDC Login

**Symptoms:**
- OIDC login successful but user not created in Nextcloud
- "User not found" errors
- Missing user attributes

**Diagnosis:**
```bash
# Check Nextcloud user database
mysql -u nextcloud -p nextcloud -e "SELECT * FROM oc_users WHERE uid = 'test@example.com';"

# Check user mapping configuration
sudo -u www-data php occ config:app:get oidc_login

# Check OIDC user info
curl -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  https://id.example.org/userinfo
```

**Solutions:**
1. **Verify user mapping configuration:**
   ```bash
   sudo -u www-data php occ config:app:set oidc_login claim-name --value="sub"
   sudo -u www-data php occ config:app:set oidc_login claim-email --value="email"
   ```

2. **Enable auto-provisioning:**
   ```bash
   sudo -u www-data php occ config:app:set oidc_login auto-provision --value="1"
   sudo -u www-data php occ config:app:set oidc_login use-email-as-uid --value="1"
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
  -d "grant_type=password&username=test@example.com&password=test123&client_id=nextcloud&client_secret=your-secret"

# Check Nextcloud performance
sudo -u www-data php occ files:scan --all
sudo -u www-data php occ files:cleanup
```

**Solutions:**
1. **Optimize Nextcloud cache:**
   ```bash
   sudo -u www-data php occ files:scan --all
   sudo -u www-data php occ files:cleanup
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
   mysql -u nextcloud -p nextcloud -e "OPTIMIZE TABLE oc_users;"
   mysql -u nextcloud -p nextcloud -e "ANALYZE TABLE oc_users;"
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

# Check Nextcloud memory usage
sudo -u www-data php occ system:report
```

**Solutions:**
1. **Increase PHP memory limit:**
   ```bash
   # In php.ini
   memory_limit = 512M
   ```

2. **Optimize Nextcloud configuration:**
   ```bash
   sudo -u www-data php occ config:system:set filesystem_check_changes --value=0
   sudo -u www-data php occ config:system:set preview_max_memory --value=2048
   ```

3. **Enable garbage collection:**
   ```bash
   sudo -u www-data php occ config:system:set gc_maxlifetime --value=3600
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
openssl s_client -connect nextcloud.example.org:443 -servername nextcloud.example.org

# Check certificate validity
openssl x509 -in /path/to/certificate.crt -text -noout
```

**Solutions:**
1. **Install valid SSL certificate:**
   ```bash
   # Install Let's Encrypt certificate
   sudo certbot --apache -d nextcloud.example.org
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
# Check Nextcloud access control
sudo -u www-data php occ user:list
sudo -u www-data php occ group:list

# Check file permissions
ls -la /var/www/html/nextcloud/
```

**Solutions:**
1. **Set proper file permissions:**
   ```bash
   sudo chown -R www-data:www-data /var/www/html/nextcloud
   sudo chmod -R 755 /var/www/html/nextcloud
   sudo chmod -R 777 /var/www/html/nextcloud/data
   ```

2. **Configure access control:**
   ```bash
   sudo -u www-data php occ config:system:set trusted_domains 1 --value=nextcloud.example.org
   sudo -u www-data php occ config:system:set allow_local_remote_storage --value=false
   ```

3. **Review user permissions:**
   ```bash
   sudo -u www-data php occ user:add
   sudo -u www-data php occ group:add
   ```

## Debugging Tools

### 1. Enable Debug Mode
```bash
# Enable Nextcloud debug mode
sudo -u www-data php occ config:system:set debug --value=true
sudo -u www-data php occ config:system:set loglevel --value=2
```

### 2. Log Analysis
```bash
# Check Nextcloud logs
tail -f /var/log/nextcloud/nextcloud.log
tail -f /var/log/nextcloud/oidc_login.log

# Check web server logs
tail -f /var/log/apache2/nextcloud_error.log
tail -f /var/log/apache2/nextcloud_access.log
```

### 3. Database Debugging
```bash
# Check Nextcloud database
mysql -u nextcloud -p nextcloud -e "SHOW TABLES;"
mysql -u nextcloud -p nextcloud -e "SELECT * FROM oc_users LIMIT 10;"
mysql -u nextcloud -p nextcloud -e "SELECT * FROM oc_groups LIMIT 10;"
```

## Recovery Procedures

### 1. Backup and Restore
```bash
# Backup Nextcloud
tar -czf nextcloud_backup_$(date +%Y%m%d_%H%M%S).tar.gz /var/www/html/nextcloud/

# Backup database
mysqldump -u nextcloud -p nextcloud > nextcloud_db_backup_$(date +%Y%m%d_%H%M%S).sql

# Restore Nextcloud
tar -xzf nextcloud_backup_YYYYMMDD_HHMMSS.tar.gz -C /

# Restore database
mysql -u nextcloud -p nextcloud < nextcloud_db_backup_YYYYMMDD_HHMMSS.sql
```

### 2. Reset Configuration
```bash
# Reset Nextcloud configuration
sudo -u www-data php occ config:system:delete all

# Clear all caches
sudo -u www-data php occ files:scan --all
sudo -u www-data php occ files:cleanup

# Reinstall apps
sudo -u www-data php occ app:disable oidc_login
sudo -u www-data php occ app:enable oidc_login
```

### 3. Emergency Mode
```bash
# Enable Nextcloud maintenance mode
sudo -u www-data php occ maintenance:mode --on

# Disable OIDC temporarily
sudo -u www-data php occ app:disable oidc_login
```

## Prevention

### 1. Regular Monitoring
```bash
# Set up monitoring script
crontab -e
# Add: */5 * * * * /path/to/nextcloud_health_check.sh
```

### 2. Automated Backups
```bash
# Set up automated backups
crontab -e
# Add: 0 2 * * * /path/to/nextcloud_backup.sh
```

### 3. Security Updates
```bash
# Update Nextcloud regularly
cd /var/www/html/nextcloud
sudo -u www-data php occ upgrade

# Update apps
sudo -u www-data php occ app:update oidc_login
```

## Support Resources

### Documentation
- [Nextcloud Documentation](https://docs.nextcloud.com/)
- [OIDC Login App Documentation](https://apps.nextcloud.com/apps/oidc_login)
- [Main Documentation](../../docs/identity.md)

### Community Support
- [Nextcloud Forum](https://help.nextcloud.com/)
- [Stack Overflow](https://stackoverflow.com/questions/tagged/nextcloud)
- [Nextcloud GitHub](https://github.com/nextcloud)

### Professional Support
- [Nextcloud GmbH](https://nextcloud.com/enterprise/)
- [Nextcloud Partners](https://nextcloud.com/partners/)
