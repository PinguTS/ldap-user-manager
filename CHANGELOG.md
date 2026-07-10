# Changelog

## [2.2.0] - 2026-07-11

### Added

- Organization-based LDAP user management with UUID-based identification and role-based access control (System Administrator, Maintainer, Organization Administrator, User)
- Restructured `/manage` administration UI with flash messaging, structured alert banners, and role-based post-login redirects
- Internationalization with bundled locale files, Accept-Language detection, in-app language chooser, and translated LDAP field labels and error messages
- Localized transactional email (welcome, password reset, invitations) with recipient locale resolution
- Self-service and admin-initiated password set/reset links
- Organization website URL validation with live preview
- Accessible country picker with ISO 3166-1 alpha-2 country codes (requires PHP `intl`; included in Docker image)
- Organization export API, canonical routes, and organization count/filter display in the management UI
- CSRF protection on management and setup forms
- OpenLDAP user-bind ACL support with bind debugging
- Per-organization disabled accounts group and standard LDAP account locking via `pwdAccountLockedTime`
- Bootstrap 5 UI upgrade, centralized web assets, and PHPUnit/PHPStan quality tooling in CI

### Changed

- Migrated from custom LDAP schema attributes to standard LDAP attributes where possible
- URL paths aligned with filesystem layout; environment variable names standardized
- Organization address countries stored as ISO alpha-2 codes instead of localized names
- Improved state/session directory handling and logout flow
- Removed passcode feature; consolidated password strength configuration via environment variables
- Removed obsolete groups management functionality

### Fixed

- Organization and system user lock/unlock reliability, including fallback locking when `pwdAccountLockedTime` is unavailable
- Organization user management modals, search, and JavaScript dependencies
- Setup wizard flow, organization admin login/redirect, and UUID lookup issues
- LDAP connection scope and error handling across management pages
- User profile updates, display-name autofill, and account creation feedback

### Security

- Hardened LDAP, OIDC, session handling, and user management flows
- CSRF token validation on state-changing requests
- Safer post-login redirect validation

### Documentation

- Reorganized documentation for administrators, integrators, and developers
- Updated integration guides and added organizations API documentation

## [2.1.1] - 2025-12-12

### Fixed

- **[#246](https://github.com/wheelybird/luminary/issues/246)** - Nested OU support: LDAP functions now search for user DNs instead of constructing them
- Environment variable `_FILE` suffix handling for Docker secrets now processed early in entrypoint
- Group creation UX: button text, attributes tab visibility during creation, MFA tab appearing after creation
- Form structure bugs causing multiple handlers to trigger on single form submission

## [2.1.0] - 2025-12-05

### Added

- Self-service password reset via email with token validation and rate limiting
- Optional LDAP storage for application data (sessions, tokens, rate limits) with filesystem fallback
- Automated daily cleanup of expired LDAP data via cron (randomised 3-5am)
- New password reset email events and templates

### Removed

- GROUP_BULK_OPERATIONS_ENABLED, GROUP_TEMPLATES_ENABLED, GROUP_NESTING_ENABLED config placeholders (unimplemented features that were accidentally included)

### Fixed

- **[#242](https://github.com/wheelybird/luminary/issues/242)** - Fatal error when creating initial admin account (undefined STDERR constant)
- **[#241](https://github.com/wheelybird/luminary/issues/241)** - Inconsistent password change email behaviour (checkbox now works correctly)
- **[#240](https://github.com/wheelybird/luminary/issues/240)** - SMTP_HOST_PORT variable maintained for backwards compatibility
- Object class preservation when updating users with MFA enabled (prevents `totpSecret` attribute errors)
- Duplicate group membership update banners (removed duplicate handler include)

## [2.0.0] - 2025-11-29

### Added

#### Multi-Factor Authentication (MFA/TOTP) System
**Complete MFA implementation integrated throughout Luminary**

- **Self-service MFA enrollment** (`/manage_mfa`)
  - QR code generation for authenticator apps (Google Authenticator, Authy, etc.)
  - Two-code verification during setup to ensure correct configuration
  - 10 emergency backup/scratch codes generated per user
  - Pure PHP TOTP implementation (RFC 6238 compliant)
  - No external dependencies

- **LDAP-backed secret storage**
  - TOTP secrets stored securely in LDAP user objects
  - Uses `totpSecret` attribute from ldap-totp-schema
  - Centralised management across multiple services
  - Supports custom TOTP attribute names via `MFA_TOTP_ATTRIBUTE`

- **Group-based MFA enforcement**
  - Configure MFA requirements per LDAP group
  - Groups can have `mfaRequired=TRUE` attribute
  - Per-group grace periods (`mfaGracePeriodDays` attribute)
  - Automatic `totpStatus=pending` when user added to MFA-required group
  - Shortest grace period wins when user in multiple groups
  - Configurable group MFA attributes for custom schemas

- **MFA-protected login for Luminary**
  - Two-step authentication: password validation, then TOTP code
  - Enforces MFA for users in MFA-required groups
  - Grace period support for new users
  - Automatic redirect to MFA setup if grace period expired

- **Admin MFA management** (`/account_manager/mfa_status.php`)
  - Dashboard showing MFA enrollment status for all users
  - Filter by enrollment status, group membership
  - Visual status indicators (active, pending, none, disabled)
  - Quick view of users without MFA in required groups
  - Grace period expiration tracking

- **MFA environment variables**
  - `MFA_FEATURE_ENABLED` - Enable/disable MFA features (default: FALSE)
  - `MFA_REQUIRED_GROUPS` - Comma-separated groups requiring MFA (config-based fallback)
  - `MFA_GRACE_PERIOD_DAYS` - Default grace period (default: 7)
  - `MFA_TOTP_ISSUER` - Name in authenticator app (default: "LDAP")
  - `MFA_TOTP_ATTRIBUTE` - LDAP attribute for TOTP secret (default: "totpSecret")
  - `MFA_GROUP_REQUIRED_ATTRIBUTE` - Group MFA requirement attribute (default: "mfaRequired")
  - `MFA_GROUP_GRACE_ATTRIBUTE` - Group grace period attribute (default: "mfaGracePeriodDays")

#### Configuration Registry System

  - Self-documenting configuration architecture
  - Auto-generated System Config page** (`/system_config`)
  - Auto-generated documentation

#### User Interface Enhancements

  - Bootstrap 5 migration
  - Unicode and internationalisation support
  - Navigation improvements
  - System information page

#### User Profile Module Refinements

  - Default attributes reduced to 7 essential fields
  - User-friendly field labels
  - JPEG photo validation (format + 500KB limit)
  - Security enhancements:
    - Blacklist enforcement for restricted attributes
    - Proper validation for all editable fields
    - Safe defaults for new attributes

#### Password Policy and Complexity Enforcement 🔒
**Comprehensive password policy support with ppolicy overlay integration**

- **ppolicy overlay detection**
  - Automatic detection of OpenLDAP ppolicy overlay availability
  - Graceful fallback when ppolicy not available
  - Checks for ppolicy control OID (1.3.6.1.4.1.42.2.27.8.5.1)

- **Password complexity validation**
  - Minimum length enforcement (configurable via `PASSWORD_MIN_LENGTH`)
  - Uppercase letter requirement (configurable via `PASSWORD_REQUIRE_UPPERCASE`)
  - Lowercase letter requirement (configurable via `PASSWORD_REQUIRE_LOWERCASE`)
  - Number requirement (configurable via `PASSWORD_REQUIRE_NUMBERS`)
  - Special character requirement (configurable via `PASSWORD_REQUIRE_SPECIAL`)
  - Minimum strength score (configurable via `PASSWORD_MIN_SCORE`)

- **Current password verification**
  - Requires current password when changing password (when ppolicy enabled)
  - Prevents unauthorised password changes from open sessions
  - Uses ppolicy-aware password change operations

- **Password history and expiry tracking** (requires ppolicy overlay)
  - Displays password changed date (`pwdChangedTime`)
  - Shows password age in days
  - Displays password expiry date when `PASSWORD_EXPIRY_DAYS` configured
  - Prevents password reuse based on ppolicy history settings
  - Visual indicators for password expiry warnings

- **Password policy environment variables**
  - `PASSWORD_POLICY_ENABLED` - Enable password policy features (default: FALSE)
  - `PASSWORD_MIN_LENGTH` - Minimum password length (default: 8)
  - `PASSWORD_REQUIRE_UPPERCASE` - Require uppercase letters (default: FALSE)
  - `PASSWORD_REQUIRE_LOWERCASE` - Require lowercase letters (default: FALSE)
  - `PASSWORD_REQUIRE_NUMBERS` - Require numbers (default: FALSE)
  - `PASSWORD_REQUIRE_SPECIAL` - Require special characters (default: FALSE)
  - `PASSWORD_MIN_SCORE` - Minimum password strength score (default: 3)
  - `PASSWORD_EXPIRY_DAYS` - Password expiry in days (default: 0 = never)

### Changed

  - Base container updated 
  - Module and submodule improvements
  - Added `totp_functions.inc.php` - MFA/TOTP functionality
  - Errors handling is improved - errors are sent to the logs
  - jQuery has been replaced with vanilla javascript

### Fixed

- **[#234](https://github.com/wheelybird/luminary/issues/234)** - Umlauts and Unicode characters converted to HTML entities (implemented proper UTF-8 support and LDAP escaping)
- **[#230](https://github.com/wheelybird/luminary/issues/230)** - Group creation flow issues with lastGID prefilling and empty groups (auto-generate gidNumber, allow empty groups for RFC2307)
- **[#213](https://github.com/wheelybird/luminary/issues/213), [#171](https://github.com/wheelybird/luminary/issues/171)** - PHP warnings for undefined array key 'givenname' when users have mononyms (implemented safe array access with null coalescing)
- **[#206](https://github.com/wheelybird/luminary/issues/206)** - Password changed date indicator (displays pwdChangedTime and expiry date when ppolicy overlay is enabled)
- **[#164](https://github.com/wheelybird/luminary/issues/164)** - Require current password when changing password (enforced when ppolicy is enabled for enhanced security)
- **[#225](https://github.com/wheelybird/luminary/issues/225)** - Reply-to address support for emails (EMAIL_REPLY_TO_ADDRESS environment variable)
- **[#200](https://github.com/wheelybird/luminary/issues/200)** - Account identifier field visual distinction (key icon, bold label, highlighted background)
- **[#203](https://github.com/wheelybird/luminary/issues/203)** - Checkbox input support documented (colon-separated format with special suffixes)
- **[#224](https://github.com/wheelybird/luminary/issues/224)** - Reset password email templates now used for admin and self-service password changes
- **[#219](https://github.com/wheelybird/luminary/issues/219)** - Show both email and UID fields in forms (removed conditional field hiding)
- **[#218](https://github.com/wheelybird/luminary/issues/218)** - SERVER_PATH properly applied behind reverse proxy (url() helper function)
- **[#227](https://github.com/wheelybird/luminary/issues/227)** - Tilde escape sequences in custom attribute configuration (split_escaped() function, uses ~ instead of \ to avoid shell escaping issues)
- Character encoding issues with international characters in LDAP attributes
- Form validation error messages and client/server-side validation
- Photo upload validation and error handling for invalid formats

### Backwards Compatibility

**Version 2.0.0 maintains full backwards compatibility with 1.x releases (ldap-user-manager).**

- All existing environment variables unchanged
- New MFA features disabled by default (`MFA_FEATURE_ENABLED=FALSE`)
- No breaking changes to LDAP schema requirements
- Existing user/group management unchanged
- Optional features clearly marked and disabled by default

**Upgrading from v1.11:**

1. **No configuration changes required** - Upgrade works as drop-in replacement
2. **Optional: Enable MFA** - Set `MFA_FEATURE_ENABLED=TRUE` to activate MFA features
3. **Optional: Configure MFA** - Set MFA-related environment variables as needed
4. **LDAP Schema** - If using MFA, add TOTP schema attributes (see ldap-totp-schema project)

#### LDAP Schema Requirements for MFA

Add TOTP attributes to LDAP schema.  See [ldap-totp-schema](https://github.com/wheelybird/ldap-totp-schema) for complete schema definitions and installation instructions.

### Known Issues

- **LDAP ACL verification** - Manual verification required for `totpSecret` access restrictions

### Contributors

- wheelybird - Project maintainer
- Original ldap-user-manager contributors
- Community testers and feedback

### Links

- **GitHub**: https://github.com/wheelybird/luminary
- **Docker Hub**: https://hub.docker.com/r/wheelybird/luminary
- **Issues**: https://github.com/wheelybird/luminary/issues
- **Related Projects**:
  - [openvpn-server-ldap-otp](https://github.com/wheelybird/openvpn-server-ldap-otp) - OpenVPN with LDAP+TOTP
  - [ldap-totp-schema](https://github.com/wheelybird/ldap-totp-schema) - LDAP schema for TOTP
  - [pam-ldap-totp-auth](https://github.com/wheelybird/pam-ldap-totp-auth) - PAM module for LDAP+TOTP

---

## [1.11] - 2024-07-20

### Added
- Selectable LDAP login attribute

See Git history for older releases.

## [1.10] - Earlier Release
## [1.9] - Earlier Release
## [1.8] - Earlier Release
## [1.7] - Earlier Release
## [1.6] - Earlier Release
## [1.5] - Earlier Release
## [1.4] - Earlier Release
## [1.3] - Earlier Release
## [1.2] - Earlier Release
## [1.1] - Earlier Release
## [1.0] - Initial Release

See Git history for details on earlier releases.
