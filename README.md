# LDAP User Manager

A PHP-based web interface for managing LDAP user accounts, organizations, and role-based access control. Perfect for small to medium organizations that need centralized user management with Docker deployment.

## What It Does

- **User Management**: Create, edit, and delete user accounts
- **Organization Management**: Manage multiple organizations with separate user pools
- **Role-based Access**: Assign users to roles with different permission levels
- **Self-service**: Users can change their own passwords
- **OIDC Integration**: Works with external services like TYPO3, GitLab, and Nextcloud

## Quick Start

### Option 1: Docker (Recommended)
```bash
git clone https://github.com/pinguts/ldap-user-manager.git
cd ldap-user-manager
docker-compose up -d
```
Visit `http://localhost:8080/setup/` to complete configuration.

### Option 2: Web Server Deployment
```bash
git clone https://github.com/pinguts/ldap-user-manager.git
cd ldap-user-manager
./web-servers/setup.sh
```

## Configuration

Key environment variables (see [env.example](env.example) and [Environment Variables](docs/configuration/environment-variables.md)):

- **LDAP**: `LDAP_URI`, `LDAP_BASE_DN`, `LDAP_ADMIN_BIND_DN`, `LDAP_ADMIN_BIND_PWD`; optional `LDAP_USER_OU`, `LDAP_ORG_OU`, `LDAP_GROUP_OU`, `LDAP_ACCOUNT_ATTRIBUTE`, `LDAP_USE_UUID_IDENTIFICATION`.
- **Roles**: `LDAP_ADMIN_ROLE`, `LDAP_MAINTAINER_ROLE`, `LDAP_ORG_ADMIN_ROLE`, `LDAP_USER_ROLE` (must be unique).
- **Status groups** (membership/disabled flags): `LDAP_GROUP_MEMBER_ORGS`, `LDAP_GROUP_DISABLED_ORGS`, `LDAP_GROUP_DISABLED_USERS`.
- **Session**: `SESSION_TIMEOUT`, `SESSION_SAVE_PATH`.
- **Password policy**: `PASSWORD_STRENGTH_MIN_SCORE`, `PASSWORD_STRENGTH_MIN_LENGTH`, `PASSWORD_STRENGTH_REQUIRE_*`, `ACCEPT_WEAK_PASSWORDS`.
- **Password set/reset links**: `PASSWORD_RESET_TOKEN_SECRET` (signing secret; generate with `openssl rand -hex 32`), `PASSWORD_RESET_TOKEN_TTL_SECONDS`.
- **Audit**: `AUDIT_LOG_ENABLED`, `AUDIT_LOG_FILE`.
- **Export** (member organizations): `EXPORT_SHARED_SECRET` (required for `/export/organizations.php`; generate with `openssl rand -hex 32`; empty disables endpoint), `TYPO3_EXPORT_PID`.

## Success Checklist

After setup, verify these items:
- [ ] Web interface accessible at `http://localhost:8080`
- [ ] Setup wizard completes without errors
- [ ] Can create and manage users
- [ ] Can create and manage organizations
- [ ] Role-based access control works

## Screenshots

UI screenshots and placeholders are documented in [docs/images/ui-screenshots/README.md](docs/images/ui-screenshots/README.md).

## Documentation

### Getting Started
- [Quick Start](docs/getting-started/quick-start.md) - Get up and running in under 10 minutes
- [Prerequisites](docs/getting-started/prerequisites.md) - What you need before starting
- [Verification](docs/getting-started/verification.md) - How to verify your installation

### Configuration
- [Quick Reference](docs/configuration/quick-reference.md) - Essential configuration settings
- [Environment Variables](docs/configuration/environment-variables.md) - Complete configuration reference
- [Password Policy](docs/configuration/password-policy.md) - Password security settings
- [Role Configuration](docs/configuration/roles.md) - Role-based access control setup

### Deployment
- [Docker Setup](DOCKER-SETUP.md) - Container deployment guide
- [Web Server Deployment](web-servers/README.md) - Apache and Nginx setup
- [Troubleshooting](docs/deployment/troubleshooting.md) - Common issues and solutions
- [Monitoring](docs/deployment/monitoring.md) - System monitoring and alerting

### Advanced Topics
- [OIDC Integration](docs/identity.md) - OpenID Connect setup with Dex
- [Service Integrations](services/) - TYPO3, GitLab, Nextcloud setup
- [LDAP Structure](docs/ldap-structure.md) - Directory structure and examples
- [Security Best Practices](docs/security/best-practices.md) - Security recommendations

### User Guides
- [User Management](docs/user-guide/user-management.md) - How to manage users
- [Organization Management](docs/user-guide/organization-management.md) - How to manage organizations
- [Role Management](docs/user-guide/role-management.md) - How to manage roles and permissions

### Development
- [Development Setup](docs/contributing/development.md) - Local development environment
- [Code Quality](docs/contributing/code-quality.md) - Coding standards and practices

## Support

- **Documentation**: See the documentation files above
- **Issues**: Report problems in the [GitHub issue tracker](https://github.com/pinguts/ldap-user-manager/issues)
- **Setup Help**: Start with [Docker Setup](DOCKER-SETUP.md) for Docker deployments
