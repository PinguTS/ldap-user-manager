# Apache Configuration for LDAP User Manager

This directory contains Apache configuration files that provide clean URLs, security, and performance optimizations for the LDAP User Manager Docker container.

## Files

- **`ldap-user-manager.conf`** - Main configuration with URL rewriting, security, and performance settings

## Why Apache Config Instead of .htaccess?

### ✅ Benefits

1. **Performance**: Configuration loaded once at startup vs. reading .htaccess on every request
2. **Security**: Users cannot modify URL rewriting rules or security settings
3. **Docker Best Practice**: Configuration is part of the container, not mounted files
4. **Consistency**: All container instances use identical configuration
5. **Maintainability**: Centralized configuration management

### 🔧 Features

#### **URL Rewriting**
- Clean URLs (e.g., `/manage/users/show` instead of `/manage/users/show.php`)
- Parameter handling (e.g., `/manage/users/show/username`)
- Fallback redirects for non-existing URLs

#### **Security**
- Prevents access to sensitive files (.htaccess, .ini, .log, etc.)
- Blocks access to includes directory
- Security headers (X-Frame-Options, X-Content-Type-Options, XSS protection)
- Referrer policy

#### **Performance**
- Browser caching for static assets (CSS, JS, images, fonts)
- Gzip compression for text-based content
- Optimized file serving

## Integration

The configuration is automatically included in the Docker container through the entrypoint script, which generates the Apache VirtualHost configuration.

## Migration from .htaccess

The `.htaccess` file in the `www/` directory can now be removed as all functionality is handled by this Apache configuration.
