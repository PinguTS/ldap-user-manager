# LDAP User Manager — Documentation

This directory contains the full documentation for LDAP User Manager. For a project overview, see the [repository README](../README.md).

---

## End Users

People who log into the system to manage their own account.

| Document | Description |
|---|---|
| [Getting Started](user-guide/getting-started.md) | Logging in, changing your password, self-service |

---

## Administrators — Quick Deploy

Get the system running as fast as possible.

| Document | Description |
|---|---|
| [Prerequisites](getting-started/prerequisites.md) | System requirements before you start |
| [Quick Start](getting-started/quick-start.md) | Running in under 10 minutes with Docker |
| [Verification](getting-started/verification.md) | Confirming the installation is working |

---

## Administrators — Detailed Deployment

All deployment options, full configuration reference, and operations.

### Deployment Options

| Document | Description |
|---|---|
| [Docker / Portainer](../DOCKER-SETUP.md) | Full Docker Compose setup with OIDC and Caddy |
| [Web Server (Apache / Nginx)](../web-servers/README.md) | Bare-metal deployment without Docker |
| [Apache Configuration in Docker](deployment/apache-setup.md) | How the Docker image's Apache config works |
| [URL Routing](deployment/url-routing.md) | Clean URL system and routing details |
| [AJAX Handler](deployment/ajax-handler.md) | Dynamic user data fetching endpoint |
| [Export Endpoint](deployment/export-endpoint.md) | Member organizations export API (Bearer auth) |

### Configuration Reference

| Document | Description |
|---|---|
| [Environment Variables](configuration/environment-variables.md) | Complete reference for all environment variables |
| [Quick Reference](configuration/quick-reference.md) | Essential settings cheat sheet |
| [Password Policy](configuration/password-policy.md) | Password strength and hashing configuration |
| [Role Configuration](configuration/roles.md) | Role-based access control setup |

### OIDC Integration

| Document | Description |
|---|---|
| [OIDC / Dex Setup](../docs/identity.md) | Full guide: architecture, configuration, external services |
| [OIDC Quick Reference](integrations/oidc-quick-reference.md) | Key OIDC variables and endpoints at a glance |

### LDAP Server

| Document | Description |
|---|---|
| [LDAP Structure](ldap-structure.md) | Directory layout, OUs, roles, and status groups |
| [LDAP Setup](ldap/setup.md) | Configuring the OpenLDAP server |
| [LDAP Examples](ldap/examples.md) | Sample LDIF files |
| [LDAP Backup](ldap/backup.md) | Backup and restore procedures |

### Operations

| Document | Description |
|---|---|
| [Troubleshooting](deployment/troubleshooting.md) | Common issues and step-by-step solutions |
| [Monitoring](deployment/monitoring.md) | Health checks, alerting, and log monitoring |
| [Disaster Recovery](deployment/disaster-recovery.md) | Recovery procedures |
| [Security Best Practices](security/best-practices.md) | Hardening recommendations |
| [Production Security Checklist](security/checklist.md) | Pre-deployment security checklist |
| [Compliance Considerations](security/compliance.md) | GDPR and data protection guidance |

---

## Administrators — Managing Users and Organizations

Day-to-day administration through the web interface.

| Document | Description |
|---|---|
| [User Management](user-guide/user-management.md) | Creating, editing, and deleting user accounts |
| [Organization Management](user-guide/organization-management.md) | Managing organizations and membership |
| [Role Management](user-guide/role-management.md) | Assigning and managing roles |

---

## Integrators

Connecting external services via OIDC or the export API.

| Document | Description |
|---|---|
| [TYPO3](integrations/typo3.md) | TYPO3 OIDC integration |
| [GitLab](integrations/gitlab.md) | GitLab OIDC integration |
| [Nextcloud](integrations/nextcloud.md) | Nextcloud OIDC integration |
| [WordPress](integrations/wordpress.md) | WordPress OIDC integration |
| [Joomla](integrations/joomla.md) | Joomla OIDC integration |
| [Custom Applications](integrations/custom-applications.md) | Integrating your own application |
| [TYPO3 Legacy (SSO)](integrations/typo3-legacy.md) | Legacy TYPO3 ig_ldap_sso_auth integration |

---

## API Reference

Machine-readable endpoints for server-to-server integration.

| Document | Description |
|---|---|
| [Organizations Export](api/organizations.md) | GET member organizations as JSON or CSV (Bearer auth) |
| [Password Reset Request](api/password-reset-request.md) | Trigger a password reset email from an external system |

---

## Contributors and Developers

| Document | Description |
|---|---|
| [Architecture Overview](contributing/architecture.md) | Codebase structure, key files, LDAP layout |
| [Development Setup](contributing/development.md) | Local development environment |
| [Code Quality](contributing/code-quality.md) | Coding standards, PHP CS Fixer, PHPStan |
| [Internationalization](contributing/i18n.md) | Adding or updating translations |
| [Features Overview](features.md) | Full feature list from a user perspective |
