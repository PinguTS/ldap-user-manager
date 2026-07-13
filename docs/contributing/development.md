# Development Setup Guide

This guide helps developers set up a local development environment for LDAP User Manager.

## Overview

This guide covers:
- **Local development setup**
- **Development tools**
- **Testing procedures**
- **Code contribution workflow**
- **Development best practices**

## Frontend conventions

- **CSS framework**: Bootstrap 5.3 (served from `www/assets/bootstrap/`).
- **Icons**: Bootstrap Icons (loaded from CDN).
- **Global asset loading**: Pages should use `renderHeader()` in `www/includes/web_functions.inc.php` so CSS/JS is loaded consistently.
- **JavaScript**: Minified bundles under `www/assets/js/` (`org.min.js`, `password.min.js`, etc.). Edit source `*.js` files and run `npm run build:js` from the repo root (see [`www/assets/js/README.md`](../../www/assets/js/README.md)).
- **jQuery**: Loaded globally via `renderHeader()` for legacy UI helpers (e.g. alert fade-out on login).
- **Markup**: Use Bootstrap 5 patterns (`data-bs-*`, `card` instead of `panel`, `offset-*-*` instead of `col-*-offset-*`).
- **i18n**: JSON locales under `www/locales/`, `Accept-Language` resolution, and `t()` — see [Internationalization (i18n)](i18n.md).

## Prerequisites

### Required Software
- **Docker**: Version 20.10 or later
- **Docker Compose**: Version 2.0 or later
- **Git**: For version control
- **PHP**: Version 8.0 or later (for local development)
- **Composer**: For PHP dependencies

### Optional Software
- **IDE**: VS Code, PHPStorm, or similar
- **Database client**: For LDAP browsing
- **API testing tool**: Postman, curl, or similar

## Local Development Setup

### 1. Clone Repository
```bash
git clone https://github.com/pinguts/ldap-user-manager.git
cd ldap-user-manager
```

### 2. Development Environment
```bash
# Create development environment file
cp env.example .env.dev

# Edit development settings
nano .env.dev
```

### 3. Start Development Services
```bash
# Start with development configuration
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

# Or use development script
./scripts/dev-start.sh
```

### 4. Install Dependencies
```bash
# Install PHP dependencies
make install

# Install JavaScript dependencies (for asset builds)
npm install

# Install git hooks (quality check before push; matches CI)
make install-hooks
```

### Git hooks

Hooks live in `scripts/git-hooks/` and are **not** active until you install them:

```bash
make install-hooks
```

This sets `core.hooksPath` for your clone only (not committed to git config globally).

| Hook | When | What |
|------|------|------|
| **pre-commit** | `git commit` | Scans staged files for likely secrets |
| **pre-push** | `git push` | Runs `make quality` (PHPCS, PHPStan, naming — same as GitHub Actions). On branch `dev`, also runs `make dev` (private registry image) |

A failed `make quality` blocks the push locally, so you see the same errors as CI before they reach GitHub.

Bypass only when necessary: `git push --no-verify`.

See [`scripts/git-hooks/README.md`](../../scripts/git-hooks/README.md) for details.

### PHP and Composer only via Docker

If **PHP is not installed** on your machine, use the Makefile targets (they call Docker when `php` / `composer` are missing from your PATH):

| Goal | Command |
|------|---------|
| Install dev dependencies (PHPUnit, PHPStan, …) | `make install` |
| Run PHPUnit | `make test` |
| Run PHPStan | `make stan` |
| PHPCS + PHPStan | `make quality` |

Raw equivalents (from the repo root):

```bash
docker run --rm -v "$(pwd)":/app -w /app composer:2 install
docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli ./vendor/bin/phpunit
docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli ./vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=512M
```

The app container (`ldap-user-manager` image) is built with **production** Composer deps only; tests and static analysis use **`php:8.2-cli`** against your mounted project (including `vendor/` from `make install`).

## Development Configuration

### Environment Variables for Development
```bash
# Development environment
export APP_ENV=development
export DEBUG=true
export LOG_LEVEL=debug

# LDAP settings
export LDAP_URI=ldap://localhost:389
export LDAP_BASE_DN=dc=example,dc=com
export LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com
export LDAP_ADMIN_BIND_PWD=admin

# Web settings
export APP_HTTP_HOST=localhost:8080
export APP_ORGANIZATION_NAME="Development Organization"
export APP_SITE_NAME="LDAP User Manager (Dev)"

# Password policy (lenient for development)
export PASSWORD_STRENGTH_MIN_SCORE=0
export PASSWORD_STRENGTH_MIN_LENGTH=4
export ACCEPT_WEAK_PASSWORDS=TRUE
```

### Development Docker Compose
```yaml
# docker-compose.dev.yml
version: '3.8'
services:
  ldap-user-manager:
    build: .
    environment:
      - APP_ENV=development
      - DEBUG=true
    volumes:
      - ./www:/var/www/html
      - ./logs:/var/log/apache2
    ports:
      - "8080:80"
      - "443:443"
    depends_on:
      - ldap

  ldap:
    image: osixia/openldap:latest
    command: ["--copy-service"]
    environment:
      - LDAP_ORGANISATION=Development
      - LDAP_DOMAIN=example.com
      - LDAP_ADMIN_PASSWORD=admin
      - LDAP_BACKEND=mdb
      - LDAP_BACKEND_OVERLAY_PPOLICY=true
    ports:
      - "389:389"
      - "636:636"
    volumes:
      - ldap_dev_data:/var/lib/ldap
      - ldap_dev_config:/etc/ldap/slapd.d

volumes:
  ldap_dev_data:
  ldap_dev_config:
```

## Development Tools

### Code Quality Tools
```bash
# Install development tools
composer require --dev phpstan/phpstan
composer require --dev friendsofphp/php-cs-fixer
composer require --dev phpunit/phpunit

# Run code analysis
./vendor/bin/phpstan analyse www/

# Run code formatting
./vendor/bin/php-cs-fixer fix www/

# Run tests
./vendor/bin/phpunit
```

### Development Scripts
```bash
#!/bin/bash
# scripts/dev-setup.sh

echo "Setting up development environment..."

# Create development directories
mkdir -p logs
mkdir -p tmp
mkdir -p cache

# Set permissions
chmod 755 logs tmp cache
chmod 644 .env.dev

# Install dependencies
composer install

# Start services
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

echo "Development environment ready!"
```

### IDE Configuration
```json
// .vscode/settings.json
{
    "php.validate.enable": true,
    "php.suggest.basic": false,
    "php.executablePath": "/usr/bin/php",
    "phpcs.standard": ".phpcs.xml",
    "phpstan.enabled": true,
    "phpstan.configFile": "phpstan.neon"
}
```

## Testing

### Unit Tests
```bash
# Run unit tests
./vendor/bin/phpunit tests/Unit/

# Run specific test
./vendor/bin/phpunit tests/Unit/UserTest.php

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/
```

### Integration Tests
```bash
# Run integration tests
./vendor/bin/phpunit tests/Integration/

# Test LDAP integration
./vendor/bin/phpunit tests/Integration/LdapTest.php

# Test web interface
./vendor/bin/phpunit tests/Integration/WebTest.php
```

### Manual Testing
```bash
# Test web interface
curl -I http://localhost:8080

# Test LDAP connection
ldapsearch -H ldap://localhost:389 -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base

# Test OIDC (if enabled)
curl -k https://id.example.org/.well-known/openid_configuration
```

## Code Contribution Workflow

### 1. Fork Repository
```bash
# Fork on GitHub, then clone your fork
git clone https://github.com/your-username/ldap-user-manager.git
cd ldap-user-manager

# Add upstream remote
git remote add upstream https://github.com/pinguts/ldap-user-manager.git
```

### 2. Create Feature Branch
```bash
# Update main branch
git checkout main
git pull upstream main

# Create feature branch
git checkout -b feature/your-feature-name
```

### 3. Make Changes
```bash
# Make your changes (follow coding standards)

# Fix style issues if needed
make fix-all

# Commit (pre-commit hook scans for secrets if hooks are installed)
git add .
git commit -m "Add feature: description of changes"
```

`make quality` runs automatically on `git push` when hooks are installed (`make install-hooks`). You can also run it manually before committing.

### 4. Submit Pull Request
```bash
# Push to your fork
git push origin feature/your-feature-name

# Create pull request on GitHub
# Include description of changes
# Reference any related issues
```

## Development Best Practices

### Code Standards
- **Follow PSR-12**: PHP coding standards
- **Use meaningful names**: Variables, functions, classes
- **Add comments**: Document complex logic
- **Keep functions small**: Single responsibility principle
- **Write tests**: Test new features

### Git Workflow
- **Small commits**: One logical change per commit
- **Clear messages**: Descriptive commit messages
- **Branch naming**: Use descriptive branch names
- **Pull request reviews**: Request reviews from maintainers

### Testing Strategy
- **Unit tests**: Test individual functions
- **Integration tests**: Test component interactions
- **End-to-end tests**: Test complete workflows
- **Manual testing**: Test user interface

## Debugging

### Enable Debug Mode
```bash
# Set debug environment
export DEBUG=true
export LOG_LEVEL=debug

# View debug logs
docker-compose logs ldap-user-manager | grep DEBUG
```

### Debug Tools
```bash
# Install Xdebug for debugging
docker-compose exec ldap-user-manager pecl install xdebug

# Configure Xdebug
echo "zend_extension=xdebug.so" >> /usr/local/etc/php/conf.d/xdebug.ini
echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/xdebug.ini
echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/xdebug.ini
```

### Log Analysis
```bash
# View application logs
docker-compose logs ldap-user-manager

# View LDAP logs
docker-compose logs ldap

# View Apache logs
docker-compose exec ldap-user-manager tail -f /var/log/apache2/error.log
```

## Performance Development

### Profiling
```bash
# Install Xhprof for profiling
docker-compose exec ldap-user-manager pecl install xhprof

# Enable profiling
echo "extension=xhprof.so" >> /usr/local/etc/php/conf.d/xhprof.ini
echo "xhprof.output_dir=/tmp/xhprof" >> /usr/local/etc/php/conf.d/xhprof.ini
```

### Performance Testing
```bash
# Load testing with Apache Bench
ab -n 1000 -c 10 http://localhost:8080/

# Memory usage monitoring
docker stats ldap-user-manager_ldap-user-manager_1

# Database query analysis
docker-compose exec ldap slapcat -n 0 | grep -E "^(dn|objectClass)" | head -20
```

## Documentation Development

### Code Documentation
```php
/**
 * Create a new user in the LDAP directory
 *
 * @param array $userData User data array
 * @param string $organization Organization name
 * @return bool Success status
 * @throws LdapException If LDAP operation fails
 */
function createUser($userData, $organization) {
    // Implementation
}
```

### API Documentation
```php
/**
 * @api {post} /api/users Create User
 * @apiName CreateUser
 * @apiGroup Users
 * @apiVersion 1.0.0
 *
 * @apiParam {String} firstName User's first name
 * @apiParam {String} lastName User's last name
 * @apiParam {String} email User's email address
 *
 * @apiSuccess {Object} user Created user object
 * @apiSuccess {String} user.id User ID
 */
```

## Troubleshooting Development Issues

### Common Problems
```bash
# Container won't start
docker-compose logs ldap-user-manager

# Permission issues
chmod -R 755 www/
chown -R www-data:www-data www/

# LDAP connection issues
docker-compose exec ldap ldapsearch -D "cn=admin,dc=example,dc=com" -w admin -b "dc=example,dc=com" -s base

# PHP errors
docker-compose exec ldap-user-manager php -l www/index.php
```

### Development Reset
```bash
#!/bin/bash
# scripts/dev-reset.sh

echo "Resetting development environment..."

# Stop services
docker-compose down

# Remove volumes
docker-compose down -v

# Clean cache
rm -rf cache/*
rm -rf tmp/*

# Restart services
docker-compose -f docker-compose.yml -f docker-compose.dev.yml up -d

echo "Development environment reset!"
```

## Next Steps

- **Set up development environment**: Follow this guide
- **Read code documentation**: Understand the codebase
- **Join the community**: Participate in discussions
- **Contribute code**: Submit pull requests
- **Write tests**: Improve test coverage
