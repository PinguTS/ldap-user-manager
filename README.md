# LDAP User Manager

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Docker](https://img.shields.io/badge/Docker-ready-blue.svg)](DOCKER-SETUP.md)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net/)
[![Release](https://img.shields.io/github/v/tag/PinguTS/ldap-user-manager?label=release)](CHANGELOG.md)

A PHP web interface for managing LDAP users, organizations, and role-based access control. Built for small to medium organizations that need centralized user management, with Docker as the primary deployment method.

## What It Does

- **User Management** — Create, edit, disable, and delete user accounts across organizations
- **Organization Management** — Manage multiple organizations with separate user pools and membership status
- **Role-based Access Control** — Four role levels: System Administrator, Maintainer, Organization Administrator, and User
- **Self-service** — Users can change their own passwords; optional account request workflow
- **OIDC Integration** — Acts as a user source for Dex, enabling SSO for external services (TYPO3, GitLab, Nextcloud)
- **Email** — Transactional email for account invitations and password reset links via SMTP

## Quick Start

**Requires**: Docker and Docker Compose.

```bash
git clone https://github.com/pinguts/ldap-user-manager.git
cd ldap-user-manager
cp env.example .env          # edit .env with your LDAP settings
docker-compose up -d
```

Open `http://localhost:8080/setup/` to complete the initial configuration via the web wizard.

For production (with TLS and OIDC):

```bash
./setup-oidc.sh              # generates TLS certificates and OIDC client secrets
docker-compose up -d
```

See [Quick Start Guide](docs/getting-started/quick-start.md) for a step-by-step walkthrough.

## Configuration

The application is configured entirely through environment variables. Copy `env.example` to `.env` and set at minimum:

| Variable | Description | Example |
|---|---|---|
| `LDAP_URI` | LDAP server address | `ldaps://ldap-server:636` |
| `LDAP_BASE_DN` | Base DN of your directory | `dc=example,dc=com` |
| `LDAP_ADMIN_BIND_DN` | Admin bind DN | `cn=admin,dc=example,dc=com` |
| `LDAP_ADMIN_BIND_PWD` | Admin bind password | — |
| `APP_HTTP_HOST` | Public hostname of this app | `app.example.org` |
| `APP_ORGANIZATION_NAME` | Your organization name | `Acme Corp` |

See [env.example](env.example) for all available settings and [Environment Variables](docs/configuration/environment-variables.md) for the full reference.

## Documentation

### For Administrators

| Document | Description |
|---|---|
| [Quick Start](docs/getting-started/quick-start.md) | Get running in under 10 minutes |
| [Prerequisites](docs/getting-started/prerequisites.md) | System requirements |
| [Docker Deployment](DOCKER-SETUP.md) | Full Docker / Portainer setup with OIDC |
| [Web Server Deployment](web-servers/README.md) | Apache and Nginx setup without Docker |
| [Environment Variables](docs/configuration/environment-variables.md) | Complete configuration reference |
| [Password Policy](docs/configuration/password-policy.md) | Password strength settings |
| [Role Configuration](docs/configuration/roles.md) | Role-based access control setup |
| [Troubleshooting](docs/deployment/troubleshooting.md) | Common issues and solutions |
| [Security Best Practices](docs/security/best-practices.md) | Hardening recommendations |
| [Monitoring](docs/deployment/monitoring.md) | Health checks and log monitoring |

### For End Users

| Document | Description |
|---|---|
| [User Guide](docs/user-guide/getting-started.md) | Logging in, changing your password, self-service |

### For Integrators

| Document | Description |
|---|---|
| [OIDC Integration](docs/identity.md) | Dex OIDC setup and architecture |
| [TYPO3](docs/integrations/typo3.md) | TYPO3 OIDC integration |
| [GitLab](docs/integrations/gitlab.md) | GitLab OIDC integration |
| [Nextcloud](docs/integrations/nextcloud.md) | Nextcloud OIDC integration |
| [Export Endpoint](docs/deployment/export-endpoint.md) | Member organization export API |

### For Contributors

| Document | Description |
|---|---|
| [Development Setup](docs/contributing/development.md) | Local development environment |
| [Code Quality](docs/contributing/code-quality.md) | Coding standards and tooling |
| [Internationalization](docs/contributing/i18n.md) | Adding or updating translations |

Full documentation index: [docs/README.md](docs/README.md)

## Support

- **Issues**: [GitHub issue tracker](https://github.com/pinguts/ldap-user-manager/issues)
- **Troubleshooting**: [Troubleshooting guide](docs/deployment/troubleshooting.md)

## License

MIT License — see [LICENSE](LICENSE) for details.
This project is a fork of [wheelybird/ldap-user-manager](https://github.com/wheelybird/ldap-user-manager).
