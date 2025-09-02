# Prerequisites

This document outlines what you need to have in place before installing LDAP User Manager.

## Software Requirements

### Required Software
- **Docker**: Version 20.10 or later
- **Docker Compose**: Version 2.0 or later
- **Git**: For downloading the repository

### Optional Software
- **Portainer**: For Docker management (recommended)
- **SSL certificates**: For production deployments
- **Domain name**: For external access

## Knowledge Requirements

### Basic Understanding
- **Docker concepts**: Containers, images, volumes
- **LDAP basics**: Directory structure, users, groups
- **Web interfaces**: Browser navigation, form filling

### Helpful Knowledge
- **Linux command line**: Basic file operations, permissions
- **Network concepts**: Ports, DNS, SSL certificates
- **User management**: Creating and managing user accounts

## Network Requirements

### Ports Used
- **8080**: Web interface (HTTP)
- **389**: LDAP server (LDAP)
- **636**: LDAP server (LDAPS, if enabled)
- **443**: HTTPS (if SSL configured)

### Firewall Considerations
- Ensure ports 8080 and 389 are accessible
- For external access, configure port forwarding
- Consider using a reverse proxy for production

## Domain and SSL (Production)

### Domain Name
- A domain name is recommended for production use
- Subdomain setup: `ldap.yourdomain.com`
- DNS records must point to your server

### SSL Certificates
- Self-signed certificates work for testing
- Let's Encrypt certificates recommended for production
- Wildcard certificates useful for multiple subdomains

## Storage Considerations

### Docker Volumes
- LDAP data is stored in Docker volumes
- Backup your volumes regularly
- Consider using external storage for production

### Backup Strategy
- LDAP data backup (see [Backup Guide](../ldap/backup.md))
- Configuration backup
- SSL certificate backup

## Security Considerations

### Initial Setup
- Change default passwords immediately
- Use strong passwords for admin accounts
- Restrict network access to necessary ports only

### Production Hardening
- Enable SSL/TLS encryption
- Use firewall rules to restrict access
- Regular security updates
- Monitor access logs

## Testing Environment

### Local Testing
- Use `localhost` for initial testing
- Docker Desktop works well on Windows/Mac
- Linux containers recommended for production

### Network Testing
- Test from different network locations
- Verify firewall rules work correctly
- Test SSL certificate validity

## Next Steps

Once you have these prerequisites in place:

1. **Download the software**: See [Quick Start](quick-start.md)
2. **Configure your environment**: See [Configuration](../configuration/environment-variables.md)
3. **Set up your first users**: See [User Guide](../user-guide/user-management.md)
