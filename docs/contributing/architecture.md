# Architecture Overview

This document describes the internal structure of LDAP User Manager for contributors. It covers the directory layout, key PHP files, and the LDAP directory structure the application expects.

## Technology Stack

| Component | Technology |
|---|---|
| Language | PHP 8.2 (plain PHP, no framework) |
| Web server | Apache 2.4 (built into Docker image) |
| LDAP server | OpenLDAP via `osixia/openldap` (Docker) |
| OIDC provider | Dex |
| Reverse proxy | Caddy (HTTPS termination) |
| Dependency management | Composer |
| Coding standard | PSR-12 (enforced by PHP CS Fixer + PHPCS) |
| Static analysis | PHPStan level 8 |
| Refactoring | Rector |
| Build | GNU Make (`Makefile`) |

## Directory Layout

```
www/                    Application root (served by Apache)
├── includes/           Core PHP includes (config, LDAP functions, auth, email, etc.)
├── manage/             Admin management pages (users, organizations, roles)
│   ├── users/          System user management
│   ├── organizations/  Organization management
│   │   └── users/      Organization user management
│   └── roles/          Role management
├── setup/              Setup wizard pages
├── login/              Login / logout
├── password/           Password change / reset flows
├── oidc/               OIDC callback handler
├── account/            Account request flow
├── export/             Export endpoint (organizations.php)
├── assets/             Static assets (CSS, JS, fonts)
├── templates/          Email templates
└── locales/            i18n JSON files

src/                    PSR-4 autoloaded classes (namespace: LdapUserManager\)
apache/                 Apache vhost configuration (loaded in Docker image)
docker/                 Docker support files (OpenLDAP bootstrap, etc.)
ldif/                   Example LDIF files
dex/                    Dex OIDC provider configuration
caddy/                  Caddy reverse proxy configuration
certs/                  TLS certificate directory (gitignored)
tests/                  PHPUnit test suite
```

## Key PHP Files

| File | Role |
|---|---|
| `www/includes/config.inc.php` | Loads all environment variables, sets LDAP config arrays, detects role conflicts |
| `www/includes/ldap_functions.inc.php` | All LDAP read/write operations (domain layer, `snake_case` naming) |
| `www/includes/access_functions.inc.php` | Role/permission checks for the current session user |
| `www/includes/web_functions.inc.php` | HTTP helpers, session management, URL builders, HTML renderers |
| `www/includes/security_config.inc.php` | Security headers, CSRF tokens, rate limiting, audit log |
| `www/includes/mail_functions.inc.php` | Email sending via PHPMailer |
| `www/includes/email_locale.inc.php` | Recipient locale resolution chain |
| `www/includes/oidc_functions.inc.php` | OIDC discovery, token exchange, user provisioning |
| `www/includes/password_reset_functions.inc.php` | Signed token generation and validation |
| `www/includes/setup_lock.inc.php` | Setup wizard lock state management |
| `www/setup/ldap.php` | Creates LDAP OUs, system users, and role groups |
| `www/setup/verify.php` | Verifies existing LDAP structure |

## Naming Conventions

The codebase uses two naming layers:

| Layer | Style | Used for |
|---|---|---|
| Domain / LDAP | `snake_case` | Functions that read/write the directory, DN/attribute helpers, data-tier primitives |
| Web application / UI | `camelCase` | Session/auth glue, HTTP helpers, HTML renderers, permission facades, URL builders |

PSR-12 applies to **class** names (StudlyCaps) and method names (camelCase). See [Code Quality](code-quality.md) for the full standard.

## LDAP Directory Structure

```
dc=example,dc=com
├── ou=people                               System users (admins, maintainers)
│   └── uid=admin@example.com
├── ou=organizations
│   └── o=Example Organization
│       └── ou=people                       Organization users
│           └── uid=user@example.com
└── ou=roles
    ├── cn=administrators                   Global admin role group
    ├── cn=maintainers                      Global maintainer role group
    ├── cn=org_admin                        Org admin role group
    ├── cn=user                             User role group
    ├── cn=memberOrganizations              Status: organizations with active membership
    ├── cn=disabledOrganizations            Status: deactivated organizations
    └── o=Example Organization
        └── cn=disabledAccounts             Status: disabled users within this org
```

OUs and group CNs are configurable via environment variables (see [Environment Variables](../configuration/environment-variables.md)).

## UUID-Based Identification

The application uses OpenLDAP's `entryUUID` operational attribute as the primary stable identifier for users and organizations. URL parameters use `uuid=` as the preferred form; legacy `account_identifier=` and `org=` parameters are supported for backward compatibility.

Key functions: `ldap_get_organization_by_uuid()`, `ldap_get_user_by_uuid()`.

Set `LDAP_USE_UUID_IDENTIFICATION=true` to enable UUID mode (default when unset).

## Account Locking

User account locking uses `pwdAccountLockedTime` from the OpenLDAP `ppolicy` overlay. This attribute must be available in the LDAP schema:

- **osixia/openldap**: set `LDAP_BACKEND_OVERLAY_PPOLICY=true`
- **Bitnami OpenLDAP**: set `LDAP_CONFIGURE_PPOLICY=yes`
- **Manual**: load the ppolicy schema via LDIF (see `docker/openldap/`)

## Docker Image Build

The `Dockerfile` uses a two-stage build:

1. Composer stage: installs production PHP dependencies.
2. `php:8.2-apache` stage: installs PHP extensions (`ldap`, `gd`, `intl`), Apache modules (`rewrite`, `ssl`, `headers`, etc.), copies application and vendor files, sets the session path.

The `entrypoint` script: loads `*_FILE` Docker secret variables, sets defaults for `APP_HTTP_HOST`/`APP_HTTP_PATH`/`SESSION_SAVE_PATH`, generates Apache vhosts, handles `APP_SERVE_HTTP_ONLY` and TLS certificate configuration, and when `APP_ENV` is not `development` runs the setup verification check to auto-lock the setup wizard.
