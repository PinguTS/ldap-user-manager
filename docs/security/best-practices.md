# Security Best Practices

This guide outlines security best practices for LDAP User Manager deployments.

## Overview

Security is critical for any user management system. This guide covers:
- **Authentication security**
- **Network security**
- **Data protection**
- **Access control**
- **Monitoring and auditing**

## Authentication Security

### Password Policies
- **Strong passwords**: Enforce minimum password strength
- **Password rotation**: Regular password changes
- **Account lockout**: Prevent brute force attacks
- **Multi-factor authentication**: Consider OIDC integration

### Rate Limiting Configuration
The system includes built-in rate limiting to prevent brute force attacks. In the current release this is **not configurable via environment variables** and is fixed to **5 attempts per 5 minutes**.

```bash
# Rate limiting settings (configured in Caddy)
Rate limit: 100 requests per minute
Burst: 200 requests
Lockout: 5 failed attempts in 5 minutes
Lockout duration: 15 minutes
```

### Session Security
```bash
# Session configuration
Session timeout: 60 minutes (default)
Session regeneration: Enabled on login
Secure cookies: Enabled
HTTP-only cookies: Enabled
SameSite policy: Strict
```

### LDAP Security
```bash
# Use LDAPS (LDAP over SSL)
export LDAP_URI=ldaps://ldap.example.com:636

# Require STARTTLS
export LDAP_REQUIRE_STARTTLS=TRUE

# Use strong LDAP admin credentials
export LDAP_ADMIN_BIND_PWD=complex_password_here
```

### OIDC Security
- **Short-lived tokens**: 15-minute ID tokens
- **Secure client secrets**: Rotate regularly
- **HTTPS only**: No HTTP endpoints
- **Valid certificates**: Use CA-signed certificates

## Network Security

### Firewall Configuration
```bash
# Allow only necessary ports
# 8080: Web interface (HTTP)
# 443: Web interface (HTTPS)
# 389: LDAP (internal only)
# 636: LDAPS (internal only)

# Block unnecessary ports
iptables -A INPUT -p tcp --dport 22 -j ACCEPT  # SSH
iptables -A INPUT -p tcp --dport 443 -j ACCEPT # HTTPS
iptables -A INPUT -p tcp --dport 8080 -j ACCEPT # HTTP (if needed)
iptables -A INPUT -j DROP
```

### Docker Network Security
```yaml
# docker-compose.yml
version: '3.8'
services:
  ldap-user-manager:
    networks:
      - internal
    # Don't expose LDAP ports to host
    # ports:
    #   - "389:389"
    #   - "636:636"

networks:
  internal:
    driver: bridge
    internal: true  # No external access
```

### Reverse Proxy Security
```nginx
# nginx.conf
server {
    listen 443 ssl http2;
    server_name ldap.example.com;
    
    # Security headers
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains";
    
    # SSL configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    ssl_prefer_server_ciphers off;
}
```

### Security Headers
The system automatically sets security headers to protect against common attacks:

```bash
# Security headers (effective values depend on where you set them)
# Prefer setting them in ONE place (reverse proxy OR web server OR PHP) to avoid duplicates/conflicts.
X-Frame-Options: DENY                    # Prevent clickjacking (or SAMEORIGIN if you intentionally allow same-site framing)
X-Content-Type-Options: nosniff         # Prevent MIME type sniffing
X-XSS-Protection: 1; mode=block         # XSS protection
Referrer-Policy: strict-origin-when-cross-origin  # Control referrer info
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none';  # Restrict resource loading
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload  # Enforce HTTPS
```

## Data Protection

### LDAP Data Security
- **Encrypted storage**: Use encrypted volumes
- **Regular backups**: Secure backup procedures

### File Upload Security
The system includes file upload security to prevent malicious file uploads:

```bash
# File upload configuration
Maximum file size: 2MB (2097152 bytes)
Allowed file types: image/jpeg, image/png, image/gif, application/pdf, text/plain
Virus scanning: Disabled by default (enable if antivirus available)
Content validation: Enabled
```

**Best Practices:**
- **Restrict file types**: Only allow necessary file formats
- **Limit file size**: Prevent large file uploads
- **Scan for viruses**: Enable virus scanning in production
- **Validate content**: Check file content matches declared type
- **Store securely**: Use encrypted storage for uploaded files
- **Access logging**: Monitor all LDAP operations
- **Data classification**: Identify sensitive data

### Backup Security
```bash
# Encrypt backup files
gpg --encrypt --recipient admin@example.com backup.ldif

# Secure backup storage
chmod 600 backup.ldif.gpg
chown root:root backup.ldif.gpg

# Off-site backup
rsync -avz --delete /backups/ remote-backup-server:/backups/
```

### Configuration Security
```bash
# Secure environment files
chmod 600 .env
chown root:root .env

# Secure configuration files
chmod 644 docker-compose.yml
chown root:root docker-compose.yml
```

## Access Control

### Role-Based Access Control
- **Principle of least privilege**: Minimum necessary permissions
- **Role hierarchy**: Clear permission levels
- **Regular audits**: Review role assignments
- **Separation of duties**: Different roles for different functions

### LDAP Access Control Lists
```ldif
# Example ACLs for security
# 1. Administrators: Full access
access to *
  by group.exact="cn=administrators,ou=roles,dc=example,dc=com" manage

# 2. Maintainers: Limited access
access to dn.regex="^uid=.+,ou=people,o=.*,ou=organizations,dc=example,dc=com$"
  by group.exact="cn=maintainers,ou=roles,dc=example,dc=com" write

# 3. Users: Self-management only
access to dn.regex="^uid=([^,]+),ou=people,o=.*,ou=organizations,dc=example,dc=com$"
  by self write

# 4. Anonymous: No access
access to *
  by anonymous none
```

### Web Interface Security
- **Session management**: Secure session handling
- **CSRF protection**: Cross-site request forgery prevention
- **Input validation**: Sanitize all user inputs
- **Output encoding**: Prevent XSS attacks

## Monitoring and Auditing

### Security Monitoring
```bash
#!/bin/bash
# security-monitor.sh

LOG_FILE="/var/log/ldap-user-manager/security.log"

# Monitor failed login attempts
FAILED_LOGINS=$(docker-compose logs ldap-user-manager | grep "login failed" | wc -l)
echo "$(date): Failed logins: $FAILED_LOGINS" >> $LOG_FILE

# Monitor LDAP bind failures
FAILED_BINDS=$(docker-compose logs ldap | grep "bind failed" | wc -l)
echo "$(date): Failed LDAP binds: $FAILED_BINDS" >> $LOG_FILE

# Alert on suspicious activity
if [ $FAILED_LOGINS -gt 10 ] || [ $FAILED_BINDS -gt 10 ]; then
    echo "$(date): Security alert - High number of failed attempts" >> $LOG_FILE
    # Send alert email
    echo "Security alert at $(date)" | mail -s "Security Alert" admin@example.com
fi
```

### Audit Logging
```bash
# Enable LDAP audit logging
docker-compose exec ldap ldapmodify -D "cn=admin,dc=example,dc=com" -w admin << EOF
dn: cn=config
changetype: modify
add: olcLogLevel
olcLogLevel: audit
EOF
```

### Log Analysis
```bash
# Analyze access patterns
docker-compose logs ldap-user-manager | grep -oE "\b([0-9]{1,3}\.){3}[0-9]{1,3}\b" | sort | uniq -c | sort -nr

# Check for unusual access times
docker-compose logs ldap-user-manager | grep "$(date +%Y-%m-%d)" | grep -E "(02|03|04):[0-5][0-9]"

# Monitor configuration changes
docker-compose logs ldap-user-manager | grep "configuration"
```

## Incident Response

### Security Incident Procedures
1. **Immediate response**: Isolate affected systems
2. **Assessment**: Determine scope and impact
3. **Containment**: Prevent further damage
4. **Eradication**: Remove threat
5. **Recovery**: Restore normal operations
6. **Lessons learned**: Document and improve

### Emergency Contacts
```bash
# Emergency response script
#!/bin/bash
# emergency-response.sh

# Stop all services
docker-compose down

# Backup current state
docker-compose exec ldap slapcat -n 0 > emergency_backup_$(date +%Y%m%d_%H%M%S).ldif

# Notify administrators
echo "Emergency: LDAP User Manager services stopped at $(date)" | mail -s "EMERGENCY" admin@example.com

# Log incident
echo "$(date): Emergency response activated" >> /var/log/ldap-user-manager/incidents.log
```

## Compliance and Standards

### Data Protection Regulations
- **GDPR compliance**: User data protection
- **Data retention**: Appropriate retention periods
- **User consent**: Clear consent mechanisms
- **Data portability**: Export user data

### Security Standards
- **OWASP Top 10**: Web application security
- **NIST Cybersecurity Framework**: Security best practices
- **ISO 27001**: Information security management
- **SOC 2**: Security and availability controls

## Security Checklist

### Pre-Deployment
- [ ] **SSL certificates**: Valid, CA-signed certificates
- [ ] **Firewall rules**: Proper port restrictions
- [ ] **Strong passwords**: Complex admin credentials
- [ ] **Backup procedures**: Secure backup setup
- [ ] **Monitoring**: Security monitoring configured

### Ongoing Security
- [ ] **Regular updates**: Keep systems updated
- [ ] **Access reviews**: Regular permission audits
- [ ] **Log monitoring**: Monitor security logs
- [ ] **Vulnerability scans**: Regular security assessments
- [ ] **Incident response**: Test response procedures

### Post-Incident
- [ ] **Root cause analysis**: Identify incident cause
- [ ] **Remediation**: Fix security gaps
- [ ] **Documentation**: Update procedures
- [ ] **Training**: Improve team awareness
- [ ] **Testing**: Verify security improvements

## Security Tools

### Recommended Security Tools
- **Fail2ban**: Intrusion prevention
- **ClamAV**: Malware scanning
- **Lynis**: Security auditing
- **OpenSCAP**: Security compliance
- **Snort**: Intrusion detection

### Security Testing
```bash
# Run security scan
lynis audit system

# Check SSL configuration
nmap --script ssl-enum-ciphers -p 443 ldap.example.com

# Test LDAP security
ldapsearch -H ldaps://ldap.example.com:636 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base
```

## Next Steps

- **Implement security measures**: Apply recommended practices
- **Regular security reviews**: Schedule periodic assessments
- **Security training**: Educate team members
- **Incident response planning**: Prepare for security incidents
- **Compliance monitoring**: Ensure regulatory compliance
