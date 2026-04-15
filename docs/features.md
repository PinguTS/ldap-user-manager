# Features and Capabilities

## User and Organization Management

- **User accounts** — Create, edit, disable, and delete user accounts. Two types: system-level users (administrators and maintainers) and organization users (regular members).
- **Organizations** — Group users into organizations with their own user pools. Each organization can have a name, address, and membership status.
- **Organization membership** — Grant or revoke an organization's active membership status. Disabled organizations and their users are excluded from exports and OIDC group claims.
- **Account disabling** — Individually disable user accounts without deleting them. Requires OpenLDAP with the `ppolicy` overlay enabled.
- **Account requests** — Optional workflow allowing visitors to request a new account (enabled via `ACCOUNT_REQUESTS_ENABLED`).

## Role-Based Access Control

Four role levels with configurable names:

| Role | Default CN | What they can do |
|---|---|---|
| System Administrator | `administrators` | Full access to everything |
| System Maintainer | `maintainers` | Manage organizations and their users |
| Organization Administrator | `org_admin` | Manage their own organization only |
| User | `user` | Change own password, view own profile |

Role names are stored as LDAP group CNs under `ou=roles` and are configurable via environment variables (`LDAP_ADMIN_ROLE`, `LDAP_MAINTAINER_ROLE`, `LDAP_ORG_ADMIN_ROLE`, `LDAP_USER_ROLE`). All four must be unique.

The system automatically detects and prevents role configuration conflicts that would break access control.

## Self-Service

- Users can change their own password at any time.
- If email is configured, administrators can send password set/reset links to users (no password is sent in plain text — only a signed, time-limited link).
- Users can reset their own forgotten password via the "Forgot password" flow (requires SMTP and `PASSWORD_RESET_TOKEN_SECRET`).

## OIDC Integration

When OIDC is enabled, the application authenticates users via Dex (an OIDC provider) instead of a local login form. Dex queries the LDAP directory for user credentials and group membership. External services (TYPO3, GitLab, Nextcloud) can use Dex as their SSO provider, making the LDAP directory the single source of truth for user identities.

See [OIDC Integration](identity.md) for setup details.

## Export API

An authenticated HTTP endpoint (`/export/organizations.php`) provides a machine-readable list of member organizations and their users, intended for integration with external systems such as TYPO3 (`tt_address`). Secured with a shared Bearer token (`EXPORT_SHARED_SECRET`).

See [Export Endpoint](deployment/export-endpoint.md) for details.

## Email

When SMTP is configured, the system can send:

- Account invitation emails with a password-set link (new users)
- Password reset emails (self-service)
- Admin-triggered password reset emails
- Welcome emails (when admin sets a password directly)

Email templates support multiple locales. See [Environment Variables](configuration/environment-variables.md) for locale resolution configuration.

## Audit Logging

All administrative actions (user creation, modification, deletion, login events) are written to an audit log file when `AUDIT_LOG_ENABLED=TRUE`.

## Setup Wizard

A web-based setup wizard at `/setup/` guides initial configuration: it verifies the LDAP connection, creates the required organizational unit structure, and sets up system users and role groups. Once setup is confirmed, the wizard is locked to prevent unintended re-runs.

## Internationalization

The UI is available in multiple languages. Email templates also support per-user and per-organization locale selection. See [Internationalization](contributing/i18n.md) for adding translations.
