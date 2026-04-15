# Web Server Configurations

This directory contains ready-to-use web server configurations for deploying the LDAP User Manager without Docker.

## **Available Configurations**

### **Apache (.htaccess)**
- **File**: `.htaccess`
- **Use case**: Apache web servers, both root and sub-path deployment
- **Features**: Clean URLs, security protection, performance optimization
- **Setup**: Copy to your web root directory

### **Nginx**
- **File**: `nginx.conf`
- **Use case**: Nginx web servers, both root and sub-path deployment
- **Features**: Efficient routing, PHP-FPM integration, Gzip compression
- **Setup**: Use the provided configuration in your Nginx sites-available

## **Quick Setup**

### **Automatic Setup (Recommended)**
```bash
# Run the unified setup script
./web-servers/setup.sh
```

The script automatically:
- Detects your web server (Apache or Nginx)
- Prompts for deployment type (root or sub-path)
- Configures all necessary files
- Sets proper permissions
- Reloads your web server

### **Manual Setup**
1. **Apache**: Copy `web-servers/.htaccess` to your web root
2. **Nginx**: Copy `web-servers/nginx.conf` to your sites-available directory
3. **Configure paths** and domain names
4. **Enable the site** and reload your web server

## **Features**

- **Clean URLs**: `/manage/users/show` instead of `/manage/users/show.php`
- **Sub-path support**: Deploy in any subdirectory
- **Static file optimization**: CSS, JS, images served directly with caching
- **Security**: File access protection and security headers
- **Performance**: Compression and caching for optimal speed
- **Proper static file handling**: Static files served directly without PHP processing

## **Configuration Features**

### **URL Rewriting**
- Clean URLs (e.g., `/manage/users/show` instead of `/manage/users/show.php`)
- Parameter handling (e.g., `/manage/users/show/username`)
- Fallback redirects for non-existing URLs

### **Security**
- Prevents access to sensitive files (.htaccess, .ini, .log, etc.)
- Blocks access to includes directory
- Security headers (XSS protection, clickjacking prevention)

### **Performance**
- Browser caching for static assets
- Gzip compression for text content
- Optimized file serving

## **Documentation**

- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Complete deployment guide
- **[DOCKER-SETUP.md](../DOCKER-SETUP.md)** - Docker deployment (recommended)
- **[Environment Variables](../docs/configuration/environment-variables.md)** - Environment variables reference

## **When to Use Each Option**

### **Use Docker (Recommended)**
- ✅ Production environments
- ✅ Development and testing
- ✅ Consistent deployments
- ✅ Security-focused setups

### **Use Direct Deployment**
- ✅ Existing Apache or Nginx infrastructure
- ✅ Shared hosting environments
- ✅ Custom server configurations
- ✅ Both root and sub-path deployment

## **Prerequisites**

### **Apache Requirements**
- Apache 2.4+ with mod_rewrite, mod_headers, mod_expires, mod_deflate
- PHP 8.2+ with LDAP extension
- AllowOverride All in Apache configuration

### **Nginx Requirements**
- Nginx 1.18+ with try_files support
- PHP-FPM 8.2+ with LDAP extension
- Proper PHP-FPM socket configuration

### **General Requirements**
- LDAP server access
- PHP LDAP extension
- Proper file permissions

## **Troubleshooting**

### **Common Issues**
1. **URL rewriting not working** - Check module enablement
2. **PHP processing errors** - Verify PHP-FPM configuration
3. **Permission denied** - Check file ownership and permissions
4. **LDAP connection failed** - Verify LDAP server settings

### **Debug Commands**
```bash
# Apache
apache2ctl -M | grep rewrite
apache2ctl -t

# Nginx
nginx -t
systemctl status nginx

# PHP
php -m | grep ldap
systemctl status php*-fpm
```

## **Next Steps**

1. **Choose your deployment method** (Docker or Direct deployment)
2. **Run the setup script** or follow manual instructions
3. **Configure LDAP settings** using environment variables
4. **Test your installation** and verify functionality
5. **Review security settings** and adjust as needed

For detailed instructions, see [DEPLOYMENT.md](DEPLOYMENT.md) or [DOCKER-SETUP.md](../DOCKER-SETUP.md).
