# Code Quality Standards

This document describes the code quality standards and tools used in the LDAP User Manager project.

## Overview

The project has been updated to follow modern PHP coding standards and includes automated tools for maintaining code quality.

**AI-assisted development:** The file [.cursorrules.md](../../.cursorrules.md) at the project root is the authoritative single source of truth for all AI-assisted code generation. When you add or change architecture (e.g. new roles, status groups, or endpoints), update `.cursorrules.md` and mention it in your PR so the rules stay in sync with the codebase.

## Standards Applied

### PSR-12 Compliance
- **Indentation**: 4 spaces (no tabs)
- **Line length**: Maximum 120 characters
- **Braces**: Opening brace on new line for classes/functions, same line for control structures
- **Spacing**: One space after control keywords, before parentheses
- **File endings**: Exactly one newline at end of file

### Code Structure
- **Strict types**: `declare(strict_types=1);` added to all PHP files
- **Array syntax**: Short array syntax `[]` instead of `array()`
- **Comparison operators**: Strict comparison `===` and `!==` instead of `==` and `!=`
- **Null coalescing**: Use `??` operator where appropriate
- **Ternary operators**: Simplified ternary expressions

### Documentation
- **PHPDoc**: Added comprehensive documentation for public functions
- **File headers**: Added file purpose descriptions
- **Parameter documentation**: `@param` tags with types
- **Return documentation**: `@return` tags with types
- **Exception documentation**: `@throws` tags where applicable

## Quality Tools

### 1. PHP_CodeSniffer (PHPCS)
- **Purpose**: Enforces coding standards
- **Configuration**: `.phpcs.xml`
- **Standard**: PSR-12
- **Usage**: `make cs` or `vendor/bin/phpcs --standard=.phpcs.xml`

### 2. PHP-CS-Fixer
- **Purpose**: Automatically fixes code style issues
- **Configuration**: `.php-cs-fixer.dist.php`
- **Usage**: `make fix` or `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php`

### 3. PHPStan
- **Purpose**: Static analysis for type safety and error detection
- **Configuration**: `phpstan.neon`
- **Level**: Maximum (8)
- **Usage**: `make stan` or `vendor/bin/phpstan analyse --configuration=phpstan.neon`

### 4. Rector
- **Purpose**: Automated code modernization and refactoring
- **Usage**: `make rector` or `vendor/bin/rector process www/ --dry-run`

## Quick Start

### 1. Install Dependencies
```bash
make install
```

### 2. Check Code Quality
```bash
make quality
```

### 3. Auto-fix Issues
```bash
make fix-all
```

### 4. Verify Fixes
```bash
make quality
```

## Available Commands

| Command | Description |
|---------|-------------|
| `make help` | Show all available commands |
| `make install` | Install Composer dependencies |
| `make cs` | Check coding standards |
| `make cs-fix` | Auto-fix coding standards |
| `make stan` | Run static analysis |
| `make fix` | Fix code style with PHP-CS-Fixer |
| `make rector` | Run Rector (dry-run) |
| `make quality` | Run all quality checks |
| `make fix-all` | Fix all code style issues |
| `make setup-dev` | Set up development environment |

## Configuration Files

### `.phpcs.xml`
- PSR-12 coding standards
- Excludes JavaScript, CSS, and configuration files
- Focuses on PHP files in `www/` directory

### `.php-cs-fixer.dist.php`
- Comprehensive code style rules
- PSR-12 compliance
- Additional quality improvements

### `phpstan.neon`
- Maximum analysis level
- Ignores LDAP function calls (external extensions)
- Focuses on application logic

### `composer.json`
- Development dependencies for quality tools
- Convenient scripts for common tasks
- PSR-4 autoloading structure

## Best Practices

### 1. Before Committing
```bash
make quality
make fix-all
make quality  # Verify fixes
```

### 2. Regular Maintenance
```bash
make stan     # Check for type issues
make rector   # Check for modernization opportunities
```

### 3. Continuous Integration
- Run `make quality` in CI/CD pipelines
- Fail builds on coding standard violations
- Use `make stan` for static analysis

## Excluded Files

The following files and directories are excluded from quality checks:
- `www/js/*.js` - JavaScript files
- `www/bootstrap/*` - Bootstrap framework files
- `www/request_account/fonts/*` - Font files
- `vendor/*` - Composer dependencies
- Configuration files (`.yml`, `.yaml`, `.toml`)

## Troubleshooting

### Common Issues

1. **PHPCS not found**
   ```bash
   make install
   ```

2. **Permission denied**
   ```bash
   chmod +x vendor/bin/*
   ```

3. **Memory issues with PHPStan**
   ```bash
   php -d memory_limit=2G vendor/bin/phpstan analyse
   ```

### Customization

To modify quality rules:
1. Edit the respective configuration files
2. Run `make clean` to clear caches
3. Test changes with `make quality`

## Migration Notes

### From Old Code Style
- **Arrays**: `array()` → `[]`
- **Comparisons**: `==` → `===`, `!=` → `!==`
- **Ternary**: `$var ? $var : 'default'` → `$var ?: 'default'`
- **Spacing**: Added spaces around operators
- **Indentation**: Standardized to 4 spaces

### Benefits
- **Consistency**: Uniform code style across the project
- **Maintainability**: Easier to read and modify
- **Quality**: Fewer bugs through static analysis
- **Standards**: Industry-standard coding practices
- **Automation**: Less manual code review needed

## Contributing

When contributing to the project:
1. Follow the established coding standards
2. Run quality checks before submitting
3. Use the provided tools to maintain consistency
4. Document new functions with PHPDoc
5. Test your changes thoroughly

## Support

For questions about code quality tools:
- Check the tool documentation
- Review configuration files
- Use `make help` for available commands
- Consult the project maintainers
