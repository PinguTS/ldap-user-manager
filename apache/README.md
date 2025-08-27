# Apache Configuration for LDAP User Manager

This directory contains Apache configuration files that provide clean URLs, security, and performance optimizations for the LDAP User Manager Docker container.

## Files

- **`ldap-user-manager.conf`** - Main configuration with URL rewriting, security, and performance settings

## Why Apache Configuration?

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
- Fallback handling for non-existing URLs (redirects to index.php)

#### **Security**
- Prevents access to sensitive files (.htaccess, .ini, .log, etc.)
- Blocks access to includes directory
- Security headers (X-Frame-Options, X-Content-Type-Options, XSS protection)
- Referrer policy

#### **Performance**
- Browser caching for static assets (CSS, JS, images, fonts)
- Gzip compression for text-based content
- Optimized file serving

## Important Configuration Notes

### **Directory-Based Configuration**
- **URL rewriting**: All rewrite rules are contained within `<Directory "/opt/ldap_user_manager">` block
- **Scope limitation**: Rewrite rules only apply to the web application directory, not globally
- **Static file protection**: Static files (CSS, JS, images) are served directly by Apache without PHP processing

### **Static File Handling**
- **Direct serving**: Static files bypass PHP entirely and are served directly by Apache
- **Performance**: No unnecessary PHP processing for static assets
- **Caching**: Static files get proper caching headers and compression

### **Fallback Rule**
- **Selective rewriting**: Only non-static file URLs are rewritten to `/index.php`
- **Pattern matching**: Uses `RewriteCond %{REQUEST_URI} !\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|pdf|zip|txt|xml|json)$` to exclude static files
- **Clean URLs**: Users see the original URL in their browser

## Integration

The configuration is automatically included in the Docker container through the entrypoint script, which generates the Apache VirtualHost configuration.

## Configuration Details

This Apache configuration provides all the functionality needed for the LDAP User Manager web application, including clean URLs, security protection, and performance optimization.
