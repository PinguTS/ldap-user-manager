# URL Routing and Clean URLs

This document explains the URL routing system for LDAP User Manager and how it provides clean, user-friendly URLs.

## Overview

The system provides clean URLs by removing `.php` extensions and handling URL rewriting. This makes URLs more professional and easier to remember.

## Supported Clean URLs

### **Main Application**
- `/` → Main application entry point
- `/login/` → Login page
- `/logout/` → Logout page
- `/password/change/` → Password change page
- `/account/request/` → Account request page
- `/oidc/callback.php` → OIDC callback endpoint (used when OIDC is enabled)

### **Setup and Configuration**
- `/setup` → Setup wizard
- `/setup/ldap` → LDAP configuration
- `/setup/verify` → LDAP verification
- `/setup/run_checks` → System checks

### **User Management**
- `/manage/users/` → System users list
- `/manage/users/new/` (or `/manage/users/new.php`) → Create new system user
- `/manage/users/show.php?uuid={uuid}` → View system user (canonical)
- `/manage/users/{uuid}` → View system user (UUID-in-path via rewrite rule)

### **Organization Management**
- `/manage/organizations/` → Organizations list
- `/manage/organizations/add.php` (or `/manage/organizations/add/`) → Create new organization
- `/manage/organizations/show/index.php?uuid={uuid}` → View organization (canonical)
- `/manage/organizations/{uuid}` → View organization (UUID-in-path via rewrite rule)
- `/manage/organizations/{uuid}/users` → Organization users list (UUID-in-path via rewrite rule)
- `/manage/organizations/{uuid}/users/new` → Add user to organization (UUID-in-path via rewrite rule)

### **Role Management**
- `/manage/roles/` → Role management
- `/manage/download.php?...` → Download functionality

## How It Works

The URL routing system uses **Apache configuration** to provide clean URLs and proper routing for the web application.

### **Apache Configuration**
- **Location**: `/apache/ldap-user-manager.conf` in the Docker container
- **Integration**: Automatically included in Apache VirtualHost configuration
- **Benefits**: Better performance, security, and Docker best practices

## URL Examples

### **Before (with .php extensions)**
```
/manage/users/show.php?uuid=550e8400-e29b-41d4-a716-446655440000
/manage/organizations/show/index.php?org=CompanyA
```

### **After (clean URLs)**
```
/manage/users/550e8400-e29b-41d4-a716-446655440000
/manage/organizations/550e8400-e29b-41d4-a716-446655440000
```

## Security Features

### **File Access Protection**
- Prevents access to `.htaccess`, `.htpasswd`, `.ini`, `.log`, `.sh`, `.sql`, `.conf` files
- Blocks access to the `includes/` directory
- Protects configuration files

### **Security Headers**
- X-Frame-Options: Prevents clickjacking
- X-Content-Type-Options: Prevents MIME type sniffing
- X-XSS-Protection: Enables XSS protection
- Referrer-Policy: Controls referrer information

## Performance Features

### **Caching**
- CSS and JavaScript files cached for 1 month
- Images cached for 1 month
- Fonts cached for 1 month

### **Compression**
- Enables gzip compression for text-based files
- Reduces bandwidth usage
- Improves page load times

## Testing the URLs

### **Valid URLs (should work)**
```
/manage/users/
/manage/organizations/550e8400-e29b-41d4-a716-446655440000
/setup/ldap
```

### **Invalid URLs (will redirect to root)**
```
/nonexistent
/invalid/path
/old/legacy/url
```

## Implementation Notes

### **Apache Configuration**
- **File**: `apache/ldap-user-manager.conf`
- **Integration**: Automatically included in Docker container's Apache configuration
- **Modules Required**: `mod_rewrite`, `mod_headers`, `mod_expires`, `mod_deflate`

### **OIDC callback clean URL**
The code provides `www/oidc/callback.php`. If you want a clean URL like `/oidc/callback`, add an Apache rewrite rule mapping `/oidc/callback` to `/oidc/callback.php`.

### **Docker Integration**
- **Dockerfile**: Copies Apache configuration to `/etc/apache2/conf-available/`
- **Entrypoint**: Includes configuration in both HTTP and HTTPS VirtualHosts
- **Modules**: Enables all necessary Apache modules during container build

### **Benefits Over .htaccess**
- **Performance**: Configuration loaded once at startup
- **Security**: Users cannot modify routing rules
- **Docker Best Practice**: Configuration is part of container
- **Consistency**: All instances use identical configuration

## Troubleshooting

### **Common Issues**

1. **URLs not working**
   - Check if `mod_rewrite` is enabled
   - Verify Apache configuration is properly loaded
   - Check Apache error logs

2. **Infinite redirects**
   - Ensure the fallback rule is last
   - Check for conflicting rewrite rules

3. **Security errors**
   - Verify file permissions
   - Check Apache security modules

### **Debugging**
Enable Apache rewrite logging:

```apache
RewriteLog "/var/log/apache2/rewrite.log"
RewriteLogLevel 3
```

## Related Documentation

- [LDAP Configuration](../ldap/setup.md) - LDAP setup and configuration
- [Docker Setup](../../DOCKER-SETUP.md) - Docker deployment instructions
- [Environment Variables](../configuration/environment-variables.md) - Environment variables
- [Role Configuration](../configuration/roles.md) - Access control configuration
