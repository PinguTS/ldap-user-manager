# Contributing to LDAP User Manager

Thank you for your interest in contributing.

## Getting Started

- Read the [Development Setup](docs/contributing/development.md) guide to set up a local environment.
- Read the [Code Quality](docs/contributing/code-quality.md) guide for coding standards and tooling (PHP CS Fixer, PHPStan, Rector).
- Read the [Internationalization](docs/contributing/i18n.md) guide if you are adding or updating translations.

## How to Contribute

1. Fork the repository and create a branch from `main`.
2. Make your changes, following the coding standards described in the docs above.
3. Install hooks (`make install-hooks`) and ensure `make quality` passes (enforced on push; same as CI).
4. Open a pull request with a clear description of what changed and why.

## Reporting Bugs

Use the [GitHub issue tracker](https://github.com/pinguts/ldap-user-manager/issues) to report bugs. Please include:

- The release or branch you are running.
- How you are running it (Docker, direct deployment, etc.).
- Steps to reproduce the issue.
- Relevant log output (enable `LDAP_DEBUG=TRUE` and `LDAP_VERBOSE_CONNECTION_LOGS=TRUE` for LDAP issues).

## Security Issues

Please do **not** open a public GitHub issue for security vulnerabilities. See [SECURITY.md](SECURITY.md) for the responsible disclosure process.
