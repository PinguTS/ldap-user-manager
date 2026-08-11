# Changelog

## [1.1.5] - 2026-08-11

### Fixed

- Organization name validation with clearer user feedback and localized error messages
- User management error handling, session display name, and LDAP user/RDN operations
- Organization list link display and duplicate text on the view button
- Guard preventing organization managers from removing their own manager role

### Changed

- Organization website links styled for clearer presentation in the management UI
- Expanded test coverage for org validation, special characters, session names, and user RDN changes

## [1.1.4] - 2026-07-30

### Fixed

- Password change form: clearer failure alerts, visibility toggles, and strength meter guidance
- Organization user edit DN resolution when the account attribute is not UUID/uid
- User add form account-identifier handling and input sanitization
- Password-management and role/status localization strings across locales

## [1.1.3] - 2026-07-24

### Added

- Delete user action on the organization detail page (CSRF-protected confirm modal and alert feedback)

### Fixed

- Password change form layout and generation UX (removed debug strength score, improved generate control accessibility)

### Changed

- Added `www/assets/js/package-lock.json` for the JS build toolchain
- Ignore local `docs/videos/` content in `.gitignore`

## [1.1.2] - 2026-07-24

### Added

- Manual password-setting hint on user forms when the password set-link option is enabled
- Localized organization role display names and descriptions in the organization management UI
- Contact-admin note in account welcome, new account, and admin password-reset email templates across locales

### Changed

- LDAP user roles resolved from group membership instead of the `description` attribute
- Password fields on user create/add and reset-password flows toggle based on how the password is set
- Clarified email locale resolution for system/service accounts (`EMAIL_SYSTEM_ACCOUNT_ROLES`)

### Fixed

- Localization corrections across supported languages (notably Norwegian account-request and login strings)

## [1.1.1] - 2026-07-13

### Fixed

- Organization website URLs no longer fail to save: the old `minify.sh` sed step corrupted minified JavaScript (e.g. stripping `://` inside strings)
- Firefox source-map console errors from bundled `org.min.js`

### Changed

- Replaced `minify.sh` with an UglifyJS build pipeline (`npm run build:js`) producing `org.min.js`, `password.min.js`, `sync.min.js`, `lists.min.js`, and `modals.min.js`
- Removed legacy unused JavaScript (`user_management`, `generate_passphrase`, `wordlist`, `zxcvbn-bootstrap-strength-meter`)
- Re-enabled zxcvbn-based password strength scoring in `password.min.js`

## [1.1.0] - 2026-07-11

### Added

- CSRF protection on management and setup forms
- Structured alert banners and role-based post-login redirects
- Organization website URL validation with live preview
- Accessible country picker with ISO 3166-1 alpha-2 country codes (PHP `intl` included in Docker image)
- Organization count and filter display in the management UI
- Expanded internationalization for LDAP field labels, error messages, and account requests across all supported locales
- Locale placeholder validation in the test suite
- `ldap_map_user_record` helper for consistent LDAP entry mapping

### Changed

- Organization address countries stored as ISO alpha-2 codes instead of localized names
- Improved type checking and data handling in user and LDAP functions

### Fixed

- User account creation feedback and LDAP error handling when creation or group assignment fails
- Streamlined logout session handling

## [1.0.1] - 2026-07-10

### Fixed

- Setup pretty-URL wrappers now resolve navigation links to `/setup/*` correctly
- Optional `LDAP_CONFIG_BIND_PWD` enables in-app olcAccess read/apply via `cn=admin,cn=config`
- System users list no longer empty when `LDAP_ACCOUNT_ATTRIBUTE` defaults to `mail`
- Maintainer role creation uses `LDAP_MAINTAINER_ROLE` consistently; role group auto-created on first maintainer
- PHPStan: narrow LDAP connection type in setup ACL checks
