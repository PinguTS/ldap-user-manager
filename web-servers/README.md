# Web Server Configurations

This directory contains ready-to-use web server configurations for deploying the LDAP User Manager without Docker.

## 📁 **Available Configurations**

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

## 🚀 **Quick Setup**

### **Automated Setup (Recommended)**
```bash
# Single script for all scenarios
./web-servers/setup.sh
```

This script automatically:
- Detects your web server (Apache or Nginx)
- Asks for deployment type (root or sub-path)
- Configures everything automatically
- Provides guidance for next steps

### **Manual Setup**
```bash
# Copy configuration files
cp web-servers/.htaccess www/                    # Apache
cp web-servers/nginx.conf /etc/nginx/sites-available/  # Nginx

# Follow detailed instructions in DEPLOYMENT.md
```

## 🔧 **Configuration Features**

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

## 📚 **Documentation**

- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Complete deployment guide
- **[DOCKER-SETUP.md](../DOCKER-SETUP.md)** - Docker deployment (recommended)
- **[CONFIGURATION_VARIABLES.md](../CONFIGURATION_VARIABLES.md)** - Environment variables

## 🎯 **When to Use Each Option**

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

## 🔍 **Prerequisites**

### **Apache Requirements**
- Apache 2.4+ with mod_rewrite, mod_headers, mod_expires, mod_deflate
- PHP 8.0+ with LDAP extension
- AllowOverride All in Apache configuration

### **Nginx Requirements**
- Nginx 1.18+ with try_files support
- PHP-FPM 8.0+ with LDAP extension
- Proper PHP-FPM socket configuration

### **General Requirements**
- LDAP server access
- PHP LDAP extension
- Proper file permissions

## 🚨 **Troubleshooting**

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

## 📖 **Next Steps**

1. **Choose your deployment method** (Docker or Direct deployment)
2. **Run the setup script** or follow manual instructions
3. **Configure LDAP settings** using environment variables
4. **Test your installation** and verify functionality
5. **Review security settings** and adjust as needed

For detailed instructions, see [DEPLOYMENT.md](DEPLOYMENT.md) or [DOCKER-SETUP.md](../DOCKER-SETUP.md).
