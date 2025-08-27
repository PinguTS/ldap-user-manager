# URL Routing and Clean URLs

This document explains the URL routing system for LDAP User Manager and how the `.htaccess` file provides clean, user-friendly URLs.

## 🎯 **Overview**

The `.htaccess` file provides clean URLs by removing `.php` extensions and handling URL rewriting. This makes URLs more professional and easier to remember.

## 🔗 **Supported Clean URLs**

### **Main Application**
- `/` → Main application entry point
- `/log_in` → Login page
- `/log_out` → Logout page
- `/change_password` → Password change page
- `/request_account` → Account request page

### **Setup and Configuration**
- `/setup` → Setup wizard
- `/setup/ldap` → LDAP configuration
- `/setup/verify` → LDAP verification
- `/setup/run_checks` → System checks

### **User Management**
- `/manage/users` → System users list
- `/manage/users/new` → Create new system user
- `/manage/users/show` → View system user
- `/manage/users/show/username` → View specific user

### **Organization Management**
- `/manage/organizations` → Organizations list
- `/manage/organizations/add` → Create new organization
- `/manage/organizations/show` → View organization
- `/manage/organizations/show/CompanyName` → View specific organization
- `/manage/organizations/users` → Organization users list
- `/manage/organizations/users/add` → Add user to organization

### **Role Management**
- `/manage/roles` → Role management
- `/manage/download` → Download functionality

## �� **How It Works**

The URL routing system uses **Apache configuration** (not `.htaccess`) to provide clean URLs and proper routing for the web application.

### **Apache Configuration**
- **Location**: `/apache/ldap-user-manager.conf` in the Docker container
- **Integration**: Automatically included in Apache VirtualHost configuration
- **Benefits**: Better performance, security, and Docker best practices

## 📋 **URL Examples**

### **Before (with .php extensions)**
```
/manage/users/show.php?user=john.doe
/manage/organizations/show/index.php?org=CompanyA
```

### **After (clean URLs)**
```
/manage/users/show/john.doe
/manage/organizations/show/CompanyA
```

## 🛡️ **Security Features**

### **File Access Protection**
- Prevents access to `.htaccess`, `.htpasswd`, `.ini`, `.log`, `.sh`, `.sql`, `.conf` files
- Blocks access to the `includes/` directory
- Protects configuration files

### **Security Headers**
- X-Frame-Options: Prevents clickjacking
- X-Content-Type-Options: Prevents MIME type sniffing
- X-XSS-Protection: Enables XSS protection
- Referrer-Policy: Controls referrer information

## ⚡ **Performance Features**

### **Caching**
- CSS and JavaScript files cached for 1 month
- Images cached for 1 month
- Fonts cached for 1 month

### **Compression**
- Enables gzip compression for text-based files
- Reduces bandwidth usage
- Improves page load times

## 🔍 **Testing the URLs**

### **Valid URLs (should work)**
```
/manage/users/show
/manage/organizations/show/MyCompany
/setup/ldap
```

### **Invalid URLs (will redirect to root)**
```
/nonexistent
/invalid/path
/old/legacy/url
```

## 📝 **Implementation Notes**

### **Apache Configuration**
- **File**: `apache/ldap-user-manager.conf`
- **Integration**: Automatically included in Docker container's Apache configuration
- **Modules Required**: `mod_rewrite`, `mod_headers`, `mod_expires`, `mod_deflate`

### **Docker Integration**
- **Dockerfile**: Copies Apache configuration to `/etc/apache2/conf-available/`
- **Entrypoint**: Includes configuration in both HTTP and HTTPS VirtualHosts
- **Modules**: Enables all necessary Apache modules during container build

### **Benefits Over .htaccess**
- **Performance**: Configuration loaded once at startup
- **Security**: Users cannot modify routing rules
- **Docker Best Practice**: Configuration is part of container
- **Consistency**: All instances use identical configuration

## 🚨 **Troubleshooting**

### **Common Issues**

1. **URLs not working**
   - Check if `mod_rewrite` is enabled
   - Verify `.htaccess` files are allowed
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

## 📚 **Related Documentation**

- [LDAP Configuration](LDAP-CONFIGURATION.md) - LDAP setup and configuration
- [Docker Setup](DOCKER-SETUP.md) - Docker deployment instructions
- [Configuration Variables](CONFIGURATION_VARIABLES.md) - Environment variables
- [Role Conflict Fixes](ROLE_CONFLICT_FIXES.md) - Access control configuration
