# Configuration Variables Reference

This document explains all the configurable settings available in the LDAP User Manager system. These settings allow you to customize the system for your organization's needs without modifying any code.

## Upgrade from previous releases (breaking)

If you upgraded from an older deployment, rename these variables in your environment—the application no longer reads the old names:

| Previous | Current |
|----------|---------|
| `ENVIRONMENT` | `APP_ENV` |
| `ORGANISATION_NAME` | `APP_ORGANIZATION_NAME` |
| `SITE_NAME` | `APP_SITE_NAME` |
| `SERVER_HOSTNAME` | `APP_HTTP_HOST` |
| `SERVER_PATH` | `APP_HTTP_PATH` |
| `SITE_PUBLIC_URL` | `APP_PUBLIC_BASE_URL` |
| `SITE_LOGIN_LDAP_ATTRIBUTE` | `APP_LOGIN_LDAP_ATTRIBUTE` |
| `SITE_LOGIN_FIELD_LABEL` | `APP_LOGIN_FIELD_LABEL` |
| `NO_HTTPS` | `APP_SERVE_HTTP_ONLY` |
| `SERVER_PORT` | `APP_HTTP_PORT` |
| `SERVER_CERT_FILENAME` | `APP_TLS_CERT_FILE` |
| `SERVER_KEY_FILENAME` | `APP_TLS_KEY_FILE` |
| `CA_CERT_FILENAME` | `APP_TLS_CA_CHAIN_FILE` |
| `LUM_STATE_DIR` | `APP_STATE_DIR` |
| `LDAP_SETUP_LOCK_FILE` | `APP_SETUP_LOCK_FILE` |
| `LDAP_SETUP_LOCKED` | `APP_SETUP_LOCKED` |
| `LUM_WRITE_BIND_FALLBACK` | `LDAP_FALLBACK_ADMIN_ON_FAILED_USER_BIND` |
| `LUM_DEBUG_MANAGE_BIND` | `APP_DEBUG_MANAGE_LDAP_BIND` |
| `FORCE_RFC2307BIS` | `LDAP_FORCE_RFC2307BIS` |
| `PHPMailer_PATH` | `PHPMAILER_CUSTOM_PATH` |
| `TYPO3_EXPORT_PID` | `EXPORT_TYPO3_PAGE_ID` |

See also the migration block at the bottom of [`env.example`](../../env.example).

## Environment Variables

### LDAP Server Configuration
These settings connect the system to your LDAP directory server:
- `LDAP_URI` - LDAP server address (required)
  - **Default**: `ldaps://ldap-server:636` (LDAPS for Docker setup)
  - **Development**: `ldap://localhost:389` (plain LDAP)
  - **Production**: `ldaps://your-ldap-server.com:636` (LDAPS)
- `LDAP_BASE_DN` - Base directory path in your LDAP tree (required)
- `LDAP_ADMIN_BIND_DN` - Administrator account for LDAP operations (required)
- `LDAP_ADMIN_BIND_PWD` - Administrator password (required)
- `LDAP_REQUIRE_STARTTLS` - Whether to require encrypted connections (default: TRUE). In production (`APP_ENV=production`), STARTTLS failure is always fatal even when this is FALSE.
- `LDAP_IGNORE_CERT_ERRORS` - Whether to ignore SSL certificate errors (default: FALSE). Refused in production.

### Password Security Configuration
These settings control how strong passwords must be in your system:

#### **Password Strength Requirements**
- `PASSWORD_STRENGTH_MIN_SCORE` - Minimum password strength level (default: 2)
  - **0**: Very Weak (any password accepted)
  - **1**: Weak (basic passwords allowed)
  - **2**: Fair (moderate security required) ← **Recommended for most organizations**
  - **3**: Good (strong security required)
  - **4**: Strong (excellent security required)

- `PASSWORD_STRENGTH_MIN_LENGTH` - Minimum password length (default: 8 characters)
- `PASSWORD_STRENGTH_REQUIRE_UPPERCASE` - Require capital letters (default: TRUE)
- `PASSWORD_STRENGTH_REQUIRE_LOWERCASE` - Require small letters (default: TRUE)
- `PASSWORD_STRENGTH_REQUIRE_NUMBERS` - Require numbers (default: TRUE)
- `PASSWORD_STRENGTH_REQUIRE_SYMBOLS` - Require special characters (default: FALSE)

#### **Legacy Password Settings**
- `PASSWORD_HASH` - Password encryption method (default: 'SSHA'). Supported values include `SSHA`, `SHA512CRYPT`, `SHA256CRYPT`, and `ARGON2` (requires OpenLDAP/bind support for `{ARGON2}` storage).
- `ACCEPT_WEAK_PASSWORDS` - Allow very weak passwords (default: FALSE)
  - **Note**: If set to TRUE, this overrides the minimum score requirement

#### **Password set/reset link tokens**
These settings control the secure email links used to set or reset passwords (no passwords are emailed):
- `PASSWORD_RESET_TOKEN_SECRET` - Secret used to sign password action tokens (required to enable link-based flows)
  - **Recommendation**: generate with `openssl rand -hex 32` (or longer)
  - **Security note**: rotate this secret to invalidate all outstanding links immediately
- `PASSWORD_RESET_TOKEN_TTL_SECONDS` - Link expiry in seconds (default: 3600)

#### **Password Configuration Examples**

**Development/Testing Environment (Lenient):**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=0      # Allow any password
export PASSWORD_STRENGTH_MIN_LENGTH=4     # Minimum 4 characters
export ACCEPT_WEAK_PASSWORDS=TRUE        # Allow very weak passwords
```

**Production Environment (Strict):**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=3      # Require Good or higher
export PASSWORD_STRENGTH_MIN_LENGTH=12    # Minimum 12 characters
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=TRUE  # Require special characters
```

**Balanced Environment (Recommended):**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=2      # Require Fair or higher
export PASSWORD_STRENGTH_MIN_LENGTH=8     # Minimum 8 characters
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
```

### Role Configuration
These settings define the names of different user roles in your system:
- `LDAP_ADMIN_ROLE` - Role name for system administrators (default: 'administrators')
- `LDAP_MAINTAINER_ROLE` - Role name for system maintainers (default: 'maintainers')
- `LDAP_ORG_ADMIN_ROLE` - Role name for organization administrators (default: 'org_admin')
- `LDAP_USER_ROLE` - Role name for regular users (default: 'user')

### Display Labels (Localization)
These settings control how role names appear in the user interface:
- `LDAP_ADMIN_DISPLAY_LABEL` - Display label for admin role (default: 'System Administrator')
- `LDAP_MAINTAINER_DISPLAY_LABEL` - Display label for maintainer role (default: 'System Maintainer')
- `LDAP_ORG_ADMIN_DISPLAY_LABEL` - Display label for org admin role (default: 'Organization Administrator')
- `LDAP_USER_DISPLAY_LABEL` - Display label for user role (default: 'User')

### Error Messages (Localization)
These settings control the text of error messages shown to users:
- `LDAP_ERROR_MAINTAINER_CANNOT_DELETE_ADMIN` - Error message for maintainer deletion restriction (default: 'Maintainers cannot delete administrators')
- `LDAP_ERROR_MAINTAINER_CANNOT_CREATE_ADMIN` - Error message for maintainer creation restriction (default: 'Maintainers cannot create users with administrator roles')
- `LDAP_ERROR_CANNOT_DELETE_SELF` - Error message for self-deletion prevention (default: 'You cannot delete your own account')

### Role Hierarchy and Access Control
The system automatically manages user permissions based on these built-in role levels:
- **Global Administrator** (Level 100) - Can do everything in the system
- **System Maintainer** (Level 80) - Can manage users and organizations
- **Organization Administrator** (Level 60) - Can manage their own organization only
- **Regular User** (Level 10) - Basic user with minimal privileges

**Important**: The system automatically prevents role conflicts that could break access control.

### LDAP Structure
These settings define the organization of your LDAP directory:
- `LDAP_GROUP_OU` - Groups/roles organizational unit (default in code: 'groups'). **This project's canonical DIT uses `ou=roles`**; set `LDAP_GROUP_OU=roles` to match.
- `LDAP_USER_OU` - Users organizational unit (default: 'people')
- `LDAP_ORG_OU` - Organizations organizational unit (default: 'organizations')
- `LDAP_ORG_ALLOWED_COUNTRIES` - Optional comma-separated ISO 3166-1 alpha-2 codes that restrict the organization **country** dropdown (e.g. `DE,AT,CH,TW`). Unset or empty = full built-in catalog (sovereign states and common territories). Unknown codes are ignored. Existing organizations with a stored country outside the allowlist remain visible and editable (grandfathered); only new selections are limited. Stored values are always ISO codes in `postalAddress`, not localized names. See also `EMAIL_COUNTRY_LOCALE_MAP` for email locale mapping from the same country segment.
- `LDAP_ACCOUNT_ATTRIBUTE` - Primary account identifier attribute (default: 'mail')
- `LDAP_GROUP_ATTRIBUTE` - Group identifier attribute (default: 'cn')
- `LDAP_FORCE_RFC2307BIS` - When `TRUE`, skip RFC2307bis autodetection and assume the extended `posixGroup` schema (optional; default is autodetect)

### Status Group Names (Membership and Disabled Flags)
These settings define the CNs of status groups used for organization membership and disabled states. Group membership is the authoritative flag (not boolean attributes on entries):
- `LDAP_GROUP_MEMBER_ORGS` - CN for organizations with active membership status (default: 'memberOrganizations') — global group under `ou=roles`
- `LDAP_GROUP_DISABLED_ORGS` - CN for deactivated organizations (default: 'disabledOrganizations') — global group under `ou=roles`
- `LDAP_GROUP_DISABLED_ACCOUNTS` - CN for individually disabled user accounts within an organization (default: 'disabledAccounts') — per-org group under `ou=roles,o=<OrgName>,…`

### Member Organizations Export (TYPO3)
These settings control the export endpoint used by external systems (e.g. TYPO3) to fetch member organization data:
- `EXPORT_SHARED_SECRET` - Shared secret for machine-to-machine auth. Send via **`Authorization: Bearer <secret>` header only** (never as a query parameter). Generate with `openssl rand -hex 32` (min 32 characters). Empty = export endpoint returns 503 (disabled).
- `EXPORT_TYPO3_PAGE_ID` - Page ID for tt_address export (default: 0)

### Security Configuration
These settings control security features and access control:

#### **Session Security**
- `SESSION_TIMEOUT` - Session timeout in **minutes** (default: 60 - 1 hour)
- `SESSION_SAVE_PATH` - Directory for app session files (default: `/tmp`). When running **multiple app instances** (e.g. Docker replicas or load-balanced containers), set this to a **shared writable path** (e.g. a mounted volume) so all instances see the same sessions; otherwise login may succeed but the next request can hit another instance and fail with "session file wasn't found", causing redirects or "corrupted content" errors.

**Note:** Only `SESSION_TIMEOUT` and `SESSION_SAVE_PATH` are currently configurable via environment variables. Other session/cookie settings are controlled by the application code (with `APP_SERVE_HTTP_ONLY` affecting whether cookies are marked `Secure`).

#### **Rate Limiting**

Login rate limiting is currently fixed at **5 attempts per 5 minutes** (not configurable via environment variables in the current release).

#### **File Upload Security**
- `FILE_UPLOAD_MAX_SIZE` - Maximum file upload size in bytes (default: 2097152 - 2MB)
- `FILE_UPLOAD_ALLOWED_MIME_TYPES` - Comma-separated list of allowed MIME types (default: 'image/jpeg,image/png,image/gif,application/pdf,text/plain')

#### **Security Headers**
The application sets security headers in PHP (see `www/includes/security_config.inc.php`), and your web server / reverse proxy configuration may also set headers.

**Avoid setting the same header in multiple places with different values**, as this can lead to duplicate/conflicting policies.

The system sets security headers (effective values depend on your deployment):
- `X-Frame-Options` - Prevent clickjacking (app default: `DENY`; some provided Apache configs also add `SAMEORIGIN` — choose one policy and apply it consistently)
- `X-Content-Type-Options: nosniff` - Prevent MIME type sniffing
- `X-XSS-Protection: 1; mode=block` - XSS protection
- `Referrer-Policy: strict-origin-when-cross-origin` - Control referrer information
- `Content-Security-Policy` - Restrict resource loading
- `Strict-Transport-Security` - Enforce HTTPS (when enabled)

#### **Audit Logging**
- `AUDIT_LOG_ENABLED` - Enable audit logging (default: TRUE)
- `AUDIT_LOG_FILE` - Audit log file path (default: '/var/log/ldap_user_manager/audit.log')

#### **Organization Change History (LDAP accesslog)**
- `LDAP_ACCESSLOG_ENABLED` - Enable reading organization change history from the OpenLDAP accesslog overlay (default: `false`).
  When set to `true`, the organization list shows a "last modified" badge per entry and the detail view shows a full change timeline with actor and timestamp.
  **Prerequisites:** The OpenLDAP accesslog overlay must be active on the LDAP server and the bind DN (`LDAP_ADMIN_BIND_DN`) must have read access to `cn=accesslog`.
  See `docker/openldap/README.md` for detailed setup instructions, including how to enable on an existing database via `ldapmodify`.

**User-bound `/manage` (LDAP as the signed-in user):** After a successful **form-based** login, the app encrypts the submitted LDAP password, stores the ciphertext in the PHP session, and holds the decryption key only in the signed `orf_cookie` (libsodium). Subsequent `/manage` requests open a per-request LDAP connection bound as that user so ACLs apply to data changes. Flows with **no** LDAP password (OIDC, reverse-proxy header login, one-time `auth_tok` handoff) continue to use `LDAP_ADMIN_BIND_DN` for directory operations, so the accesslog actor for writes may be the service account for those sessions.

**Role-based OpenLDAP ACLs:** For user-bind writes to work correctly (so each role — administrator, maintainer, org admin — can write only what it is permitted to change), OpenLDAP must have `olcAccess` rules that grant access based on `groupOfNames` membership. The setup pages at `/setup/run_checks.php` and `/setup/apply_user_bind_acls.php` verify and apply two layers:

- **Step 1 — Baseline:** Self password write + authenticated read on the tree (minimum for login and role resolution).
- **Step 2 — Role-based write:** Group-scoped write for system administrators (`manage` on the whole tree), system maintainers (`write` on the organisations subtree), and per-org admins (`write` on their org subtree via `dn.regex` + `group.expand`).

Both steps provide copy/paste `ldapmodify` LDIF blocks pre-filled from your environment. See [`docs/ldap/userbind-acls.md`](../ldap/userbind-acls.md) for the complete rule set, explanation, and `ldapsearch` verification commands.

- `LDAP_OLC_MDB_DN` - (Optional) DN of the main `mdb` database entry in `cn=config` (default: `olcDatabase={1}mdb,cn=config`). Used by setup to locate `olcAccess` for ACL verification and the corrective LDIF apply. Override if your primary database is not `olcDatabase={1}mdb`.
- `LDAP_CONFIG_BIND_DN` - (Optional) Bind DN for the OpenLDAP `cn=config` database (default: `cn=admin,cn=config`). `cn=config` is a separate database; `LDAP_ADMIN_BIND_DN` (the directory rootDN) has no access to it by design.
- `LDAP_CONFIG_BIND_PWD` - (Optional) Password for `LDAP_CONFIG_BIND_DN` (falls back to `LDAP_CONFIG_PASSWORD` if set, matching osixia/openldap's own config-password variable). When set, `/setup/run_checks.php` and `/setup/apply_user_bind_acls.php` can read and apply `olcAccess` directly. When unset, both pages fall back to the app admin bind (which cannot read `cn=config`) and surface the manual `ldapmodify`/`ldapi://` copy/paste commands instead.
- `LDAP_FALLBACK_ADMIN_ON_FAILED_USER_BIND` - Controls what happens when the app holds a user's LDAP credentials in the session but the LDAP bind with those credentials fails (e.g. the user's password was changed externally while their app session is still valid).
  - `true` (default) — fall back to the admin service account so the session continues. Writes succeed but the LDAP accesslog records the service account DN instead of the user's DN.
  - `false` — strict mode. Deny write operations for that request, clear the stale credentials, and log the failure. The user's next request uses admin bind for reads only; they must re-login to restore per-user write accountability. In strict mode the debug bar (see `APP_DEBUG_MANAGE_LDAP_BIND`) shows a prominent **bind_failed** state.

  Note: this flag only governs the *bind-level* fallback (the LDAP bind itself failing). If the bind succeeds but a write is later denied by an OpenLDAP ACL, that failure is returned as a visible error by the application regardless of this setting. See [`docs/ldap/userbind-acls.md`](../ldap/userbind-acls.md) for ACL troubleshooting.

- `APP_DEBUG_MANAGE_LDAP_BIND` - When set to `true` (string, case-insensitive), **global administrators and maintainers** see a small debug bar on manage UI pages (after the main nav) that states whether the current request uses a **per-user** LDAP bind, the **admin** bind (no per-user password, e.g. OIDC/SSO), **admin fallback** (user password was in session but the bind failed and the app fell back to the service account), or **bind_failed** (strict mode: bind failed and no fallback was permitted). Unset in production unless troubleshooting bind mode.

### User Account Configuration
These settings control how new user accounts are created:
- `DEFAULT_USER_GROUP` - Default group for new users (default: 'everybody')
- `DEFAULT_USER_SHELL` - Default shell for new users (default: '/bin/bash')
- `ENFORCE_SAFE_SYSTEM_NAMES` - Whether to enforce safe system names (default: TRUE)
- `USERNAME_FORMAT` - Username format template (default: '{first_name}-{last_name}')
- `USERNAME_REGEX` - Username validation rules (default: '^[a-z][a-zA-Z0-9\._-]{3,32}$')
- `SHOW_POSIX_ATTRIBUTES` - Whether to show POSIX attributes (default: FALSE)

### Application shell (`APP_`)
These settings control runtime mode, branding, HTTP service identity, setup lock paths, and link generation:
- `APP_ENV` - Process environment: `development`, `test`, or `production` (default: `production`)
- `APP_ORGANIZATION_NAME` - Organization name (default: `LDAP`)
- `APP_SITE_NAME` - Site name (default: `{APP_ORGANIZATION_NAME} user manager`)
- `APP_LOGIN_LDAP_ATTRIBUTE` - LDAP attribute for login (default: `mail`)
- `APP_LOGIN_FIELD_LABEL` - Login field label (default: `Email`)
- `APP_HTTP_HOST` - **Required.** Hostname used in generated links and redirects when `APP_PUBLIC_BASE_URL` is not set. Include a non-standard port if needed (e.g. `idm.example.org:8443`). If neither this nor `APP_PUBLIC_BASE_URL` is set, the app will display a startup error.
- `APP_HTTP_PATH` - URL path prefix for the app (default: `/`)
- `APP_PUBLIC_BASE_URL` - Optional full public site base for **email links** and similar (scheme + host + optional port + optional path). When unset, links use inferred protocol plus `APP_HTTP_HOST` and `APP_HTTP_PATH`.
- `APP_SERVE_HTTP_ONLY` - When `TRUE`, Apache serves HTTP only (`VirtualHost :80`) and PHP session cookies omit `Secure` (default: `FALSE`). Use behind a TLS-terminating reverse proxy only as appropriate for your deployment.
- `APP_STATE_DIR` - Writable directory used by the application to persist state files: rate-limit counters (one file per IP hash), the setup-complete lock file, and password-reset tokens (default: `/var/lib/ldap_user_manager`). **The directory and all files written to it must be writable by the `www-data` user.** In the official Docker image this is handled automatically; if you mount an external volume for this path, ensure the ownership is set to `www-data:www-data` (e.g. `chown -R 33:33 /your/volume`). If `SESSION_SAVE_PATH` is not set it defaults to `$APP_STATE_DIR/sessions`.
- `APP_SETUP_LOCK_FILE` - Absolute path override for the setup-complete lock file.
- `APP_SETUP_LOCKED` - Set to `false` to force-unlock `/setup/` (e.g. development), even when the lock file exists.
- `APP_HTTP_PORT` / `APP_TLS_CERT_FILE` / `APP_TLS_KEY_FILE` / `APP_TLS_CA_CHAIN_FILE` - Used by the container `entrypoint` for HTTPS listen port and certificate filenames under `/opt/ssl`.

Session timeout (`SESSION_TIMEOUT`, `SESSION_SAVE_PATH`) appears under Session Security above.

### Email Configuration
These settings control how the system sends emails:
- `SMTP_HOSTNAME` - SMTP server hostname
- `SMTP_USERNAME` - SMTP username
- `SMTP_PASSWORD` - SMTP password
- `SMTP_HOST_PORT` - SMTP port (default: 25)
- `SMTP_HELO_HOST` - SMTP HELO hostname
- `SMTP_USE_SSL` - Whether to use SSL (default: FALSE)
- `SMTP_USE_TLS` - Whether to use STARTTLS on the SMTP connection (default: FALSE). **Enable this in production** so credentials and message content are not sent in plaintext when using port 25.
- `EMAIL_DOMAIN` - Default email domain
- `EMAIL_FROM_ADDRESS` - From email address
- `EMAIL_FROM_NAME` - From email name
- `PHPMAILER_CUSTOM_PATH` - Override path to PHPMailer when not using Composer autoload (default: Composer `vendor/phpmailer/phpmailer/src`)
- `EMAIL_TEMPLATES_DIR` - Optional. Directory containing email template files; when set, overrides the default `www/templates/emails`. Use an absolute path. Each file: first line = subject, blank line, then HTML body. Basenames include: **`new_account.html`** (invite / set-password link for new users), **`account_welcome.html`** (admin set password; no link), **`reset_password.html`** (self-service forgot-password), **`reset_password_admin.html`** (admin sent reset link). For translations, add `basename.<locale>.html` (e.g. `new_account.de.html`) using the same locale codes as `www/locales`. Missing localized files fall back to the default `*.html` (English).
- `EMAIL_DEFAULT_LOCALE` - Optional installation default locale (e.g. `de`, `fr`) used in the **recipient** email locale chain (see below) and as the sole language for **system/service** accounts (administrator / maintainer invites). Invalid values are ignored and logged. When unset, that step is skipped.
- `EMAIL_USER_LOCALE_LDAP_ATTR` - Optional. LDAP attribute on **users** for preferred language (default: `preferredLanguage`). Set to an empty string to disable reading user locale from LDAP.
- `EMAIL_ORG_LOCALE_LDAP_ATTR` - Optional. LDAP attribute on **organization** entries for the organization’s default email locale. When unset or empty, this step is skipped (no extra schema required).
- `EMAIL_COUNTRY_LOCALE_MAP` - Optional map from ISO 3166-1 alpha-2 country (as stored in the organization `postalAddress` country segment) to a locale code. Use JSON, e.g. `{"DE":"de","FR":"fr","AT":"de"}`, or comma-separated `DE=de,FR=fr,CH=de`. Only entries whose locale matches a `www/locales/*.json` file are used.
- `EMAIL_SYSTEM_ACCOUNT_ROLES` - Optional comma-separated list of **user** `description` values (role names stored on the account) that should always receive mail in the installation default only (`EMAIL_DEFAULT_LOCALE` if valid, else `en`). When unset, defaults to the configured global admin role name (`LDAP_ADMIN_ROLE`) and `maintainer` (same rule as system-user creation in the UI).

**Recipient locale for transactional HTML mail** (`new_account`, `account_welcome`, password-reset templates): the language is **not** taken from the administrator’s UI. Resolution order: user attribute (`EMAIL_USER_LOCALE_LDAP_ATTR`) → organization attribute (`EMAIL_ORG_LOCALE_LDAP_ATTR`) → `EMAIL_COUNTRY_LOCALE_MAP` using the org’s postal country → `EMAIL_DEFAULT_LOCALE` → for **self-service** password reset only, the visitor’s UI locale → `en`. System/service roles use `EMAIL_DEFAULT_LOCALE` → `en` only.

### Account Requests
These settings control whether users can request new accounts:
- `ACCOUNT_REQUESTS_ENABLED` - Whether account requests are enabled (default: FALSE)
- `ACCOUNT_REQUESTS_EMAIL` - Email for account requests

### Debugging and Troubleshooting
These settings help diagnose problems:
- `LDAP_DEBUG` - Enable LDAP debugging (default: FALSE)
- `LDAP_VERBOSE_CONNECTION_LOGS` - Enable verbose LDAP connection logging (default: FALSE)
- `SESSION_DEBUG` - Enable session debugging (default: FALSE)
- `SETUP_DEBUG` - Enable setup debugging (default: FALSE)
- `SMTP_LOG_LEVEL` - SMTP logging level (default: 0)

### File Upload
These settings control file upload limits:
- `FILE_UPLOAD_MAX_SIZE` - Maximum file upload size in bytes (default: 2MB)
- `FILE_UPLOAD_ALLOWED_MIME_TYPES` - Comma-separated list of allowed file types

## Configuration Examples

### Docker Environment
```yaml
# docker-compose.yml
version: '3.8'
services:
  ldap-user-manager:
    environment:
      # LDAP Configuration
      - LDAP_URI=ldap://ldap.example.com:389
      - LDAP_BASE_DN=dc=example,dc=com
      - LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com
      - LDAP_ADMIN_BIND_PWD=admin_password
      
      # Password Security
      - PASSWORD_STRENGTH_MIN_SCORE=2
      - PASSWORD_STRENGTH_MIN_LENGTH=8
      - PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
      - PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
      - PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
      - PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
      - ACCEPT_WEAK_PASSWORDS=FALSE
      
      # Role Configuration
      - LDAP_ADMIN_ROLE=superuser
      - LDAP_MAINTAINER_ROLE=tech_support
      - LDAP_ORG_ADMIN_ROLE=org_manager
      - LDAP_USER_ROLE=member
      
      # Application shell
      - APP_ORGANIZATION_NAME=Example Corp
      - APP_SITE_NAME=Example Corp User Manager
      - SESSION_TIMEOUT=120

      # Optional: restrict organization country picker (ISO alpha-2 codes)
      # - LDAP_ORG_ALLOWED_COUNTRIES=DE,AT,CH,TW
      # - EMAIL_COUNTRY_LOCALE_MAP={"DE":"de","AT":"de","CH":"de","TW":"zh"}
```

### Environment File (.env)
```bash
# LDAP Server
LDAP_URI=ldap://ldap.example.com:389
LDAP_BASE_DN=dc=example,dc=com
LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com
LDAP_ADMIN_BIND_PWD=admin_password

# Password Security
PASSWORD_STRENGTH_MIN_SCORE=2
PASSWORD_STRENGTH_MIN_LENGTH=8
PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
ACCEPT_WEAK_PASSWORDS=FALSE

# Roles
LDAP_ADMIN_ROLE=superuser
LDAP_MAINTAINER_ROLE=tech_support
LDAP_ORG_ADMIN_ROLE=org_manager
LDAP_USER_ROLE=member

# Application shell
APP_ORGANIZATION_NAME=Example Corp
APP_SITE_NAME=Example Corp User Manager
SESSION_TIMEOUT=120

# Optional: restrict organization country picker to ISO alpha-2 codes (unset = full catalog)
# LDAP_ORG_ALLOWED_COUNTRIES=DE,AT,CH,TW
# EMAIL_COUNTRY_LOCALE_MAP={"DE":"de","AT":"de","CH":"de","TW":"zh"}
```

## Best Practices

### Password Security
1. **Production Environments**: Use minimum score 2 or higher
2. **Development/Testing**: Use score 0 or 1 for easier testing
3. **Length Requirements**: Minimum 8 characters for production, 4-6 for testing
4. **Character Requirements**: Require mixed case and numbers for production

### Role Configuration
1. **Unique Names**: Each role should have a different name
2. **Descriptive Labels**: Use clear, understandable role names
3. **Consistent Naming**: Follow a consistent pattern across your organization
4. **Security First**: Don't compromise security for convenience

### Environment Management
1. **Separate Configurations**: Use different settings for development, testing, and production
2. **Version Control**: Keep configuration files in version control
3. **Documentation**: Document your configuration choices
4. **Testing**: Test configuration changes in a safe environment first

## Troubleshooting

### Common Issues
1. **Password Rejection**: Check password strength requirements
2. **Login Problems**: Verify LDAP connection settings
3. **Role Access Issues**: Check role configuration for conflicts
4. **Email Problems**: Verify SMTP settings

### Getting Help
1. Check the error logs for specific error messages
2. Verify all required environment variables are set
3. Test LDAP connectivity separately
4. Review the configuration examples above

## Security Considerations

### Production Security
- Use strong password requirements (score 2+)
- Require minimum 8-12 characters
- Enable HTTPS in the container (set `APP_SERVE_HTTP_ONLY=FALSE` and supply TLS certs; or terminate TLS upstream)
- Use secure LDAP connections
- Regular security updates

### Development Security
- Relaxed password requirements for testing
- Debug logging enabled
- Development-specific role names
- Test environment isolation

This configuration system allows you to customize the LDAP User Manager for your organization's specific needs while maintaining security and functionality.
