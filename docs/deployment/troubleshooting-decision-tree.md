# Troubleshooting Decision Tree

This guide provides a visual decision tree to help you quickly diagnose and resolve common issues with LDAP User Manager.

## Quick Start Decision Tree

```
Can't Access Web Interface?
├── Service Running? 
│   ├── NO → Check `docker-compose ps`
│   │   ├── Services stopped → Run `docker-compose up -d`
│   │   └── Services failed → Check logs with `docker-compose logs`
│   └── YES → Continue to next check
├── Port Available?
│   ├── NO → Check `netstat -tulpn | grep :8080`
│   │   ├── Port in use → Stop conflicting service or change port
│   │   └── Port blocked → Check firewall rules
│   └── YES → Continue to next check
├── Firewall Blocking?
│   ├── YES → Open port 8080 in firewall
│   └── NO → Continue to next check
└── DNS Resolution?
    ├── NO → Check hostname resolution
    └── YES → Check browser console for errors
```

## Detailed Decision Trees

### 1. Web Interface Issues

```
Web Interface Not Loading?
├── Browser shows "Connection refused"
│   ├── Check if Docker is running
│   │   ├── NO → Start Docker service
│   │   └── YES → Check container status
│   ├── Check container status
│   │   ├── Stopped → Start with `docker-compose up -d`
│   │   └── Running → Check port mapping
│   └── Check port mapping
│       ├── Port not mapped → Check docker-compose.yml
│       └── Port mapped → Check if port is available
├── Browser shows "Page not found"
│   ├── Check URL path
│   │   ├── Wrong path → Use correct URL
│   │   └── Correct path → Check Apache configuration
│   ├── Check Apache configuration
│   │   ├── Config error → Fix apache/ldap-user-manager.conf
│   │   └── Config OK → Check file permissions
│   └── Check file permissions
│       ├── Wrong permissions → Fix with `chown -R www-data:www-data /opt/ldap_user_manager`
│       └── Correct permissions → Check Apache logs
└── Browser shows "Internal Server Error"
    ├── Check PHP error logs
    │   ├── Syntax error → Fix PHP code
    │   ├── Permission error → Fix file permissions
    │   └── Configuration error → Check .env file
    └── Check Apache error logs
        ├── Module missing → Install required Apache modules
        └── Configuration error → Fix Apache configuration
```

### 2. LDAP Connection Issues

```
LDAP Connection Fails?
├── "Connection refused" error
│   ├── Check LDAP service status
│   │   ├── Service stopped → Start with `docker-compose up -d ldap`
│   │   └── Service running → Check LDAP port
│   ├── Check LDAP port
│   │   ├── Port 389/636 not listening → Check LDAP configuration
│   │   └── Port listening → Check network connectivity
│   └── Check network connectivity
│       ├── Network issue → Check Docker networks
│       └── Network OK → Check LDAP credentials
├── "Authentication failed" error
│   ├── Check LDAP credentials
│   │   ├── Wrong credentials → Update .env file
│   │   └── Correct credentials → Check LDAP structure
│   ├── Check LDAP structure
│   │   ├── Missing base DN → Create with ldif/base.ldif
│   │   └── Base DN exists → Check user DN
│   └── Check user DN
│       ├── Wrong DN format → Fix DN format
│       └── Correct DN → Check LDAP ACLs
└── "SSL/TLS error" error
    ├── Check SSL configuration
    │   ├── SSL disabled → Set LDAP_IGNORE_CERT_ERRORS=true
    │   └── SSL enabled → Check certificates
    ├── Check certificates
    │   ├── Missing certificates → Generate certificates
    │   └── Certificates exist → Check certificate validity
    └── Check certificate validity
        ├── Expired certificates → Renew certificates
        └── Valid certificates → Check LDAP SSL configuration
```

### 3. OIDC Integration Issues

```
OIDC Integration Not Working?
├── "OIDC provider not accessible"
│   ├── Check Dex service status
│   │   ├── Service stopped → Start with `docker-compose up -d dex`
│   │   └── Service running → Check Dex port
│   ├── Check Dex port
│   │   ├── Port 5556 not listening → Check Dex configuration
│   │   └── Port listening → Check Dex configuration
│   └── Check Dex configuration
│       ├── Config error → Fix dex/config.yaml
│       └── Config OK → Check LDAP connector
├── "OIDC client not found" error
│   ├── Check client configuration
│   │   ├── Client not configured → Add client to dex/config.yaml
│   │   └── Client configured → Check client secret
│   ├── Check client secret
│   │   ├── Wrong secret → Update client secret
│   │   └── Correct secret → Check redirect URI
│   └── Check redirect URI
│       ├── Wrong URI → Fix redirect URI
│       └── Correct URI → Check OIDC scopes
└── "OIDC token invalid" error
    ├── Check token configuration
    │   ├── Token expired → Check token lifetime settings
    │   └── Token valid → Check token signature
    ├── Check token signature
    │   ├── Wrong signature → Check signing keys
    │   └── Correct signature → Check audience claim
    └── Check audience claim
        ├── Wrong audience → Fix audience configuration
        └── Correct audience → Check issuer claim
```

### 4. User Management Issues

```
User Management Problems?
├── "Can't create user" error
│   ├── Check user permissions
│   │   ├── Insufficient permissions → Check user role
│   │   └── Sufficient permissions → Check required fields
│   ├── Check required fields
│   │   ├── Missing fields → Fill all required fields
│   │   └── All fields present → Check field validation
│   └── Check field validation
│       ├── Validation error → Fix field format
│       └── Validation OK → Check LDAP connection
├── "Can't edit user" error
│   ├── Check user permissions
│   │   ├── Insufficient permissions → Check user role
│   │   └── Sufficient permissions → Check user ownership
│   ├── Check user ownership
│   │   ├── Not owner → Check organization permissions
│   │   └── Owner → Check LDAP write permissions
│   └── Check LDAP write permissions
│       ├── No write permission → Check LDAP ACLs
│       └── Write permission → Check LDAP connection
└── "Can't delete user" error
    ├── Check user permissions
    │   ├── Insufficient permissions → Check user role
    │   └── Sufficient permissions → Check user dependencies
    ├── Check user dependencies
    │   ├── User has dependencies → Remove dependencies first
    │   └── No dependencies → Check LDAP delete permissions
    └── Check LDAP delete permissions
        ├── No delete permission → Check LDAP ACLs
        └── Delete permission → Check LDAP connection
```

### 5. Organization Management Issues

```
Organization Management Problems?
├── "Can't create organization" error
│   ├── Check user permissions
│   │   ├── Insufficient permissions → Check user role
│   │   └── Sufficient permissions → Check organization name
│   ├── Check organization name
│   │   ├── Name already exists → Use unique name
│   │   └── Unique name → Check LDAP write permissions
│   └── Check LDAP write permissions
│       ├── No write permission → Check LDAP ACLs
│       └── Write permission → Check LDAP connection
├── "Can't edit organization" error
│   ├── Check user permissions
│   │   ├── Insufficient permissions → Check user role
│   │   └── Sufficient permissions → Check organization ownership
│   ├── Check organization ownership
│   │   ├── Not owner → Check global admin permissions
│   │   └── Owner → Check LDAP write permissions
│   └── Check LDAP write permissions
│       ├── No write permission → Check LDAP ACLs
│       └── Write permission → Check LDAP connection
└── "Can't delete organization" error
    ├── Check user permissions
    │   ├── Insufficient permissions → Check user role
    │   └── Sufficient permissions → Check organization dependencies
    ├── Check organization dependencies
    │   ├── Organization has users → Remove users first
    │   └── No users → Check LDAP delete permissions
    └── Check LDAP delete permissions
        ├── No delete permission → Check LDAP ACLs
        └── Delete permission → Check LDAP connection
```

### 6. Authentication Issues

```
Authentication Problems?
├── "Login failed" error
│   ├── Check username/password
│   │   ├── Wrong credentials → Use correct credentials
│   │   └── Correct credentials → Check user account status
│   ├── Check user account status
│   │   ├── Account deactivated → Activate account
│   │   └── Account active → Check LDAP connection
│   └── Check LDAP connection
│       ├── Connection failed → Fix LDAP connection
│       └── Connection OK → Check user DN
├── "Session expired" error
│   ├── Check session timeout
│   │   ├── Timeout too short → Increase SESSION_TIMEOUT
│   │   └── Timeout OK → Check session configuration
│   ├── Check session configuration
│   │   ├── Config error → Fix session configuration
│   │   └── Config OK → Check browser settings
│   └── Check browser settings
│       ├── Cookies disabled → Enable cookies
│       └── Cookies enabled → Check session storage
└── "OIDC authentication failed" error
    ├── Check OIDC configuration
    │   ├── Config error → Fix OIDC configuration
    │   └── Config OK → Check OIDC provider
    ├── Check OIDC provider
    │   ├── Provider not accessible → Fix provider connection
    │   └── Provider accessible → Check client configuration
    └── Check client configuration
        ├── Wrong client config → Fix client configuration
        └── Correct client config → Check redirect URI
```

### 7. Performance Issues

```
Performance Problems?
├── "Slow response times"
│   ├── Check system resources
│   │   ├── High CPU usage → Check for resource-intensive processes
│   │   └── Normal CPU usage → Check memory usage
│   ├── Check memory usage
│   │   ├── High memory usage → Increase memory allocation
│   │   └── Normal memory usage → Check disk I/O
│   └── Check disk I/O
│       ├── High disk I/O → Check for disk-intensive operations
│       └── Normal disk I/O → Check network latency
├── "High memory usage"
│   ├── Check PHP memory limit
│   │   ├── Limit too low → Increase memory_limit
│   │   └── Limit OK → Check for memory leaks
│   ├── Check for memory leaks
│       ├── Memory leak detected → Fix memory leak
│       └── No memory leak → Check LDAP connection pooling
│   └── Check LDAP connection pooling
│       ├── Pooling not configured → Configure connection pooling
│       └── Pooling configured → Check connection limits
└── "Database connection issues"
    ├── Check LDAP connection limits
    │   ├── Too many connections → Reduce connection limit
    │   └── Normal connections → Check connection timeout
    ├── Check connection timeout
    │   ├── Timeout too short → Increase timeout
    │   └── Timeout OK → Check LDAP server performance
    └── Check LDAP server performance
        ├── Server overloaded → Optimize LDAP server
        └── Server OK → Check network connectivity
```

## Quick Diagnostic Commands

### Service Status Check
```bash
# Check all services
docker-compose ps

# Check specific service
docker-compose ps ldap-user-manager

# Check service logs
docker-compose logs --tail=50 ldap-user-manager
```

### Network Connectivity Check
```bash
# Check if web interface is accessible
curl -I http://localhost:8080

# Check if LDAP is accessible
ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base

# Check if OIDC provider is accessible
curl -I http://localhost:5556/.well-known/openid_configuration
```

### Configuration Check
```bash
# Check environment variables
docker-compose exec ldap-user-manager env | grep LDAP

# Check Apache configuration
docker-compose exec ldap-user-manager apache2ctl -t

# Check PHP configuration
docker-compose exec ldap-user-manager php -m | grep ldap
```

### Permission Check
```bash
# Check file permissions
docker-compose exec ldap-user-manager ls -la /opt/ldap_user_manager

# Check Apache user
docker-compose exec ldap-user-manager ps aux | grep apache

# Check LDAP permissions
ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s sub
```

## Common Solutions

### 1. Service Won't Start
```bash
# Check Docker daemon
sudo systemctl status docker

# Check disk space
df -h

# Check Docker logs
docker-compose logs

# Restart Docker
sudo systemctl restart docker
```

### 2. Port Already in Use
```bash
# Find process using port
sudo netstat -tulpn | grep :8080

# Kill process
sudo kill -9 <PID>

# Or change port in docker-compose.yml
ports:
  - "8081:80"  # Change from 8080 to 8081
```

### 3. LDAP Connection Issues
```bash
# Test LDAP connection
ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base

# Check LDAP configuration
docker-compose exec ldap ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s sub

# Restart LDAP service
docker-compose restart ldap
```

### 4. Permission Issues
```bash
# Fix file permissions
docker-compose exec ldap-user-manager chown -R www-data:www-data /opt/ldap_user_manager

# Fix directory permissions
docker-compose exec ldap-user-manager find /opt/ldap_user_manager -type d -exec chmod 755 {} \;

# Fix file permissions
docker-compose exec ldap-user-manager find /opt/ldap_user_manager -type f -exec chmod 644 {} \;
```

### 5. Configuration Issues
```bash
# Check environment file
cat .env

# Validate docker-compose.yml
docker-compose config

# Restart services with new configuration
docker-compose down
docker-compose up -d
```

## Getting Help

If you can't resolve the issue using this decision tree:

1. **Check the logs**: `docker-compose logs --tail=100`
2. **Search existing issues**: Check GitHub issues
3. **Create detailed report**: Include logs, configuration, and steps to reproduce
4. **Contact support**: Use the support channels listed in the documentation

## Prevention Tips

1. **Regular monitoring**: Set up monitoring for services
2. **Backup configuration**: Keep backups of configuration files
3. **Test changes**: Test changes in a development environment
4. **Document changes**: Keep track of configuration changes
5. **Update regularly**: Keep the system updated with security patches
