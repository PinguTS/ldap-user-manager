# Verification Guide

This guide helps you verify that your LDAP User Manager installation is working correctly.

## Service Status Check

### Check Docker Services
```bash
docker-compose ps
```

All services should show `Up` status:
- `ldap-user-manager` - Web application
- `ldap` - LDAP server
- `dex` - OIDC provider (if enabled)
- `caddy` - Reverse proxy

### Check Service Logs
```bash
# Check web application logs
docker-compose logs ldap-user-manager

# Check LDAP server logs
docker-compose logs ldap

# Check all services for errors
docker-compose logs --tail=50
```

## Web Interface Verification

### Access the Web Interface
1. **Open your browser** and go to `http://localhost:8080`
2. **You should see** the login page or setup wizard
3. **If you see an error**, check the service logs

### Test the Setup Wizard
1. **Navigate to** `http://localhost:8080/setup/`
2. **Complete the setup** if not already done
3. **Verify all steps complete** without errors

## LDAP Connection Test

### Test LDAP Connectivity
```bash
# Test LDAP connection from host
ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base

# Test from within the container
docker-compose exec ldap-user-manager ldapsearch -H ldap://ldap:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base
```

### Expected Results
- Connection should succeed
- You should see LDAP directory information
- No authentication errors

## User Management Test

### Create a Test User
1. **Log in** to the web interface
2. **Navigate to** User Management
3. **Create a new user** with test data
4. **Verify the user appears** in the user list

### Test User Login
1. **Log out** of the admin account
2. **Log in** with the test user credentials
3. **Verify the user can access** their account

## Organization Management Test

### Create a Test Organization
1. **Log in** as an administrator
2. **Navigate to** Organization Management
3. **Create a new organization** with test data
4. **Verify the organization appears** in the list

### Test Organization Users
1. **Create a user** in the test organization
2. **Verify the user is assigned** to the organization
3. **Test organization-specific** permissions

## Role Management Test

### Test Role Assignment
1. **Navigate to** Role Management
2. **Create a test role** or use existing role
3. **Assign the role** to a test user
4. **Verify the assignment** is saved

### Test Role Permissions
1. **Log in** as the user with the assigned role
2. **Verify the user has** the expected permissions
3. **Test role-based** access restrictions

## OIDC Integration Test (if enabled)

### Test OIDC Discovery
```bash
# Test OIDC discovery endpoint
curl -k https://id.example.org/.well-known/openid_configuration
```

### Test OIDC Authentication
1. **Configure an external service** (TYPO3, GitLab, Nextcloud)
2. **Test login flow** through the external service
3. **Verify user creation** in LDAP

## Security Verification

### Check SSL/TLS (if configured)
```bash
# Test SSL certificate
openssl s_client -connect localhost:443 -servername yourdomain.com

# Test LDAPS connection
ldapsearch -H ldaps://localhost:636 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base
```

### Check Access Logs
```bash
# Check web access logs
docker-compose logs ldap-user-manager | grep "access"

# Check LDAP access logs
docker-compose logs ldap | grep "bind"
```

## Common Issues and Solutions

### Service Won't Start
- **Check port conflicts**: `netstat -tulpn | grep :8080`
- **Check Docker resources**: Ensure enough memory/CPU
- **Check configuration**: Verify environment variables

### Can't Access Web Interface
- **Check service status**: `docker-compose ps`
- **Check port mapping**: Verify port 8080 is mapped
- **Check firewall**: Ensure port 8080 is open

### LDAP Connection Fails
- **Check LDAP service**: `docker-compose logs ldap`
- **Check credentials**: Verify admin DN and password
- **Check network**: Ensure containers can communicate

### Users Can't Log In
- **Check user creation**: Verify user exists in LDAP
- **Check password policy**: Verify password meets requirements
- **Check role assignment**: Ensure user has appropriate roles

## Next Steps

Once verification is complete:

1. **Configure production settings**: See [Configuration](../configuration/environment-variables.md)
2. **Set up backups**: See [Backup Guide](../ldap/backup.md)
3. **Configure monitoring**: See [Monitoring](../deployment/monitoring.md)
4. **Train users**: See [User Guide](../user-guide/user-management.md)
