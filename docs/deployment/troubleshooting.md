# Troubleshooting Guide

This guide helps you resolve common issues with LDAP User Manager.

## Quick Diagnosis

### Check Service Status
```bash
# Check if all services are running
docker-compose ps

# Check service logs for errors
docker-compose logs --tail=50
```

### Check Network Connectivity
```bash
# Test web interface
curl -I http://localhost:8080

# Test LDAP connection
ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base
```

### URL Structure Reference

The system uses the following URL patterns for different features:

#### **Organization Management**
- **List Organizations**: `/manage/organizations/`
- **Add Organization**: `/manage/organizations/add.php`
- **View/Edit Organization**: `/manage/organizations/show/index.php?uuid={uuid}` or `?org={name}`
- **Organization Users**: `/manage/organizations/users/index.php?uuid={uuid}` or `?org={name}`
- **Add User to Organization**: `/manage/organizations/users/add.php?uuid={uuid}` or `?org={name}`

#### **User Management**
- **System Users**: `/manage/users/`
- **New System User**: `/manage/users/new.php`
- **View/Edit User**: `/manage/users/show.php?uuid={uuid}` or `?account_identifier={id}`
- **System Roles**: `/manage/roles/`

#### **Authentication & Setup**
- **Login**: `/login/`
- **Logout**: `/logout/`
- **Setup Wizard**: `/setup/`
- **OIDC Callback**: `/oidc/callback.php` (or `/oidc/callback` if you add a rewrite rule)
- **Change Password**: `/password/change/`
- **Request Account**: `/account/request/`

#### **File Downloads**
- **Download Resource**: `/manage/download.php?resource_identifier={dn}&attribute={attr}`

#### **AJAX Endpoints**
- **User Data Fetching**: `/manage/organizations/users/ajax_handler.php?action=fetch_user_data&fetch_user_data={user_id}&uuid={org_uuid}&csrf_token={token}`

**Note**: The system supports both UUID-based and legacy account_identifier-based URLs for backward compatibility.

## Common Issues and Solutions

### Web Interface Issues

#### Can't Access Web Interface
**Symptoms:**
- Browser shows "Connection refused" or "Page not found"
- Port 8080 not responding

**Solutions:**
1. **Check service status:**
   ```bash
   docker-compose ps ldap-user-manager
   ```

2. **Check port conflicts:**
   ```bash
   netstat -tulpn | grep :8080
   ```

3. **Restart the service:**
   ```bash
   docker-compose restart ldap-user-manager
   ```

4. **Check firewall settings:**
   - Ensure port 8080 is open
   - Check local firewall rules

#### Login Succeeds but Next Page Shows "Corrupted Content" or Redirects Back to Login
**Symptoms:**
- Logging in works, but the browser then shows "Beschädigter Inhalt" / "network protocol violated" or you are sent back to the login page with "unauthorised"
- Server log shows: `Session: orf_cookie was sent by the client but the session file wasn't found at /tmp/session_...`

**Cause:** The app stores session data in files. If you run **multiple app instances** (e.g. several Docker containers behind a load balancer), each instance has its own filesystem. The instance that handled the login wrote the session file to its local `/tmp`; the next request may hit a different instance, which does not have that file, so the user appears logged out.

**Solutions:**
1. **Use a shared session directory** (recommended when scaling):
   - Set `SESSION_SAVE_PATH` to a path that is **the same on all instances** and writable (e.g. `/sessions`).
   - In Docker: mount a **shared volume** at that path on every app container. Example:
     ```yaml
     environment:
       SESSION_SAVE_PATH: /sessions
     volumes:
       - app-sessions:/sessions
     ```
   - Ensure the directory exists and is writable by the web server user (e.g. `www-data`).
2. **Single instance:** If you only run one app container/process:
   - Check server logs right after login for: **`Session: failed to write session file to ...`**. If present, the session directory is missing or not writable by the web server (e.g. `SESSION_SAVE_PATH` or `/tmp`). Fix permissions or set `SESSION_SAVE_PATH` to a writable path and ensure the directory exists.
   - Verify the session file is created: after a login, list session files (e.g. `docker exec <container> ls -la /tmp/session_*` or `ls -la $SESSION_SAVE_PATH/session_*`). If no file appears, the write is failing (permissions, read-only filesystem, or `open_basedir`).
   - Ensure requests are not load-balanced to different hosts; otherwise use option 1.

#### Setup Wizard Not Working
**Symptoms:**
- Setup wizard shows errors
- Can't complete initial configuration

**Solutions:**
1. **Check LDAP connection:**
   ```bash
   docker-compose logs ldap
   ```

2. **Verify environment variables:**
   ```bash
   docker-compose exec ldap-user-manager env | grep LDAP
   ```

3. **Check file permissions:**
   ```bash
   docker-compose exec ldap-user-manager ls -la /var/www/html
   ```

### LDAP Issues

#### LDAP Connection Fails
**Symptoms:**
- "Connection refused" errors
- Authentication failures
- Setup wizard can't connect to LDAP

**Solutions:**
1. **Check LDAP service:**
   ```bash
   docker-compose logs ldap
   ```

2. **Test LDAP connectivity:**
   ```bash
   ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base
   ```

3. **Verify credentials:**
   - Check `LDAP_ADMIN_BIND_DN` and `LDAP_ADMIN_BIND_PWD`
   - Ensure credentials match LDAP server

4. **Check LDAP structure:**
   ```bash
   ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s sub
   ```

#### User Authentication Fails
**Symptoms:**
- Users can't log in
- "Invalid credentials" errors
- Users not found in LDAP

**Solutions:**
1. **Check user exists:**
   ```bash
   ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" "uid=username"
   ```

2. **Verify password:**
   - Check if password meets strength requirements
   - Try resetting user password

3. **Check user location:**
   - Verify user is in correct organization
   - Check user DN structure

### Docker Issues

#### Container Won't Start
**Symptoms:**
- Container exits immediately
- "Exit code 1" errors
- Service not available

**Solutions:**
1. **Check container logs:**
   ```bash
   docker-compose logs [service-name]
   ```

2. **Check resource limits:**
   ```bash
   docker stats
   ```

3. **Verify Docker configuration:**
   ```bash
   docker-compose config
   ```

4. **Check disk space:**
   ```bash
   docker system df
   ```

#### Port Conflicts
**Symptoms:**
- "Port already in use" errors
- Services can't bind to ports

**Solutions:**
1. **Find conflicting processes:**
   ```bash
   netstat -tulpn | grep :8080
   ```

2. **Stop conflicting services:**
   ```bash
   sudo systemctl stop apache2  # if Apache is using port 8080
   ```

3. **Change port mapping:**
   ```yaml
   # In docker-compose.yml
   ports:
     - "8081:80"  # Change from 8080 to 8081
   ```

### Configuration Issues

#### Environment Variables Not Working
**Symptoms:**
- Configuration changes not applied
- Default values still used

**Solutions:**
1. **Check environment file:**
   ```bash
   cat .env
   ```

2. **Restart services:**
   ```bash
   docker-compose down
   docker-compose up -d
   ```

3. **Verify variable names:**
   - Check spelling and case
   - Ensure no extra spaces

#### Password Policy Issues
**Symptoms:**
- Users can't set passwords
- "Password too weak" errors

**Solutions:**
1. **Check password policy settings:**
   ```bash
   docker-compose exec ldap-user-manager env | grep PASSWORD
   ```

2. **Adjust policy for testing:**
   ```bash
   export PASSWORD_STRENGTH_MIN_SCORE=0
   export PASSWORD_STRENGTH_MIN_LENGTH=4
   ```

3. **Test password requirements:**
   - Try different password combinations
   - Check password strength meter

### OIDC Issues

#### OIDC Authentication Fails
**Symptoms:**
- External services can't authenticate
- OIDC discovery fails
- Token exchange errors

**Solutions:**
1. **Check Dex service:**
   ```bash
   docker-compose logs dex
   ```

2. **Test OIDC discovery:**
   ```bash
   curl -k https://id.example.org/.well-known/openid_configuration
   ```

3. **Verify client configuration:**
   - Check client ID and secret
   - Verify redirect URIs
   - Ensure scopes are correct

#### SSL Certificate Issues
**Symptoms:**
- Browser security warnings
- OIDC calls fail due to SSL
- Certificate errors

**Solutions:**
1. **Check certificate validity:**
   ```bash
   openssl s_client -connect localhost:443 -servername yourdomain.com
   ```

2. **Regenerate certificates:**
   ```bash
   ./setup-oidc.sh
   ```

3. **Check Caddy configuration:**
   ```bash
   docker-compose logs caddy
   ```

### AJAX Handler Issues

#### AJAX Requests Fail
**Symptoms:**
- User data not loading in organization management
- JavaScript errors in browser console
- AJAX calls return 403 or 401 errors

**Solutions:**
1. **Check session authentication:**
   - Ensure you are logged in and the session cookie is sent with requests.
   - For detailed AJAX debug logs (session, CSRF, roles), set `APP_ENV=development` and check application logs (e.g. `docker-compose logs ldap-user-manager | grep "AJAX Handler"`). Do not use development mode in production.

2. **Verify CSRF token:**
   - Check if CSRF token is included in AJAX requests
   - Ensure token matches session token
   - Refresh page to get new token

3. **Check access permissions:**
   - Verify user has admin/maintainer role
   - Check organization membership for org admins
   - Ensure user is accessing correct organization

4. **Debug session issues:**
   ```bash
   # Check application logs
   docker-compose logs ldap-user-manager | grep "AJAX Handler"
   ```

#### User Data Not Found
**Symptoms:**
- AJAX returns "User not found" error
- User exists but data not returned

**Solutions:**
1. **Check user identifier format:**
   - Verify UUID format for UUID-based lookups
   - Check uid format for legacy lookups
   - Ensure case sensitivity matches

2. **Verify organization scope:**
   - Check if user belongs to the specified organization
   - Verify organization UUID/name is correct
   - Check LDAP search base

3. **Test LDAP connectivity:**
   ```bash
   # Test direct LDAP search
   ldapsearch -H ldaps://ldap-server:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin \
     -b "ou=people,o=OrganizationName,dc=example,dc=com" \
     "(uid=user@example.com)"
   ```

## Performance Issues

### Slow Response Times
**Symptoms:**
- Web interface loads slowly
- LDAP queries take time
- High resource usage

**Solutions:**
1. **Check resource usage:**
   ```bash
   docker stats
   ```

2. **Optimize LDAP queries:**
   - Add indexes to LDAP
   - Reduce query scope
   - Use connection pooling

3. **Scale resources:**
   - Increase memory allocation
   - Add CPU cores
   - Use SSD storage

### High Memory Usage
**Symptoms:**
- Containers using excessive memory
- System becomes unresponsive

**Solutions:**
1. **Check memory limits:**
   ```bash
   docker stats --no-stream
   ```

2. **Restart services:**
   ```bash
   docker-compose restart
   ```

3. **Optimize configuration:**
   - Reduce PHP memory limits
   - Optimize LDAP cache settings

## Log Analysis

### Understanding Logs
```bash
# Follow logs in real-time
docker-compose logs -f

# Filter logs by service
docker-compose logs ldap-user-manager | grep ERROR

# Check recent logs
docker-compose logs --tail=100
```

### Common Log Messages
- **"Connection refused"**: Network connectivity issue
- **"Authentication failed"**: Credential problem
- **"Permission denied"**: File permission issue
- **"Port already in use"**: Port conflict

## Recovery Procedures

### Complete Reset
```bash
# Stop all services
docker-compose down

# Remove volumes (WARNING: deletes all data)
docker-compose down -v

# Restart fresh
docker-compose up -d
```

### Backup and Restore
```bash
# Backup LDAP data
docker-compose exec ldap slapcat -n 0 > backup.ldif

# Restore from backup
docker-compose exec ldap slapadd -n 0 < backup.ldif
```

### Configuration Reset
```bash
# Remove configuration files
rm -f .env
rm -f docker-compose.override.yml

# Restart with defaults
docker-compose up -d
```

## Getting Help

### Information to Collect
When reporting issues, include:
- **Docker version**: `docker --version`
- **Docker Compose version**: `docker-compose --version`
- **Service logs**: `docker-compose logs`
- **Configuration**: Relevant environment variables
- **Steps to reproduce**: Exact steps that cause the issue

### Support Resources
- **Documentation**: Check other guides in `/docs`
- **GitHub Issues**: Report bugs at [GitHub Issues](https://github.com/pinguts/ldap-user-manager/issues)
- **Community**: Check for similar issues in the community

## Prevention

### Regular Maintenance
- **Monitor logs** regularly
- **Update containers** periodically
- **Backup data** regularly
- **Test functionality** after changes

### Best Practices
- **Use strong passwords** for all accounts
- **Limit access** to necessary ports only
- **Monitor resource usage**
- **Keep documentation** updated
