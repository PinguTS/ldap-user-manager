# LDAP User Manager - Deployment Guide

This guide covers all deployment methods for the LDAP User Manager, from Docker containers to direct web server deployment.

## 🚀 **Deployment Options**

### **1. Docker (Recommended)**
- **Best for**: Production, development, consistent environments
- **Benefits**: Optimized performance, security, easy deployment
- **Files**: `Dockerfile`, `docker-compose.*.yml`, `apache/ldap-user-manager.conf`

### **2. Direct Web Server Deployment**
- **Best for**: Apache or Nginx servers, both root and sub-path deployment
- **Benefits**: Single configuration file, automatic path detection, unified setup
- **Files**: `web-servers/.htaccess` (Apache) or `web-servers/nginx.conf` (Nginx)
- **Setup**: `web-servers/setup.sh` (automatically detects web server and deployment type)

## 🐳 **Option 1: Docker Deployment**

### **Quick Start**
```bash
# Clone repository
git clone https://github.com/your-repo/ldap-user-manager.git
cd ldap-user-manager

# Start with Docker Compose
docker-compose -f docker-compose.app.yml up -d
```

### **Custom Build**
```bash
# Build custom image
docker build -t ldap-user-manager:latest .

# Run container
docker run -d \
  -p 80:80 \
  -p 443:443 \
  -e SERVER_HOSTNAME=your-domain.com \
  ldap-user-manager:latest
```

### **Features**
- ✅ **Optimized Apache configuration** loaded at startup
- ✅ **Security headers** and file access protection
- ✅ **Performance optimization** with caching and compression
- ✅ **SSL/TLS support** with auto-generated certificates
- ✅ **Environment variable configuration**

## 🌐 **Option 2: Direct Web Server Deployment**

### **What is Direct Deployment?**
Direct deployment allows you to install the LDAP User Manager directly on your Apache or Nginx web server, either at the root level or in a subdirectory. The setup script automatically detects your web server and configures everything appropriately.

### **Supported Scenarios**
- **Root deployment**: `http://your-domain.com/`
- **Sub-path deployment**: `http://your-domain.com/ldap-manager/`
- **Nested sub-path**: `http://your-domain.com/apps/user-manager/`

### **Prerequisites**
- Apache 2.4+ with `mod_rewrite`, `mod_headers`, `mod_expires`, `mod_deflate` OR
- Nginx 1.18+ with `try_files` support
- PHP 8.0+ with LDAP extension (PHP-FPM for Nginx)
- LDAP server access

### **Quick Start**
```bash
# Clone repository
git clone https://github.com/your-repo/ldap-user-manager.git
cd ldap-user-manager

# Run the setup script
./web-servers/setup.sh
```

### **What the Script Does**
1. **Detects your web server** (Apache or Nginx)
2. **Asks for deployment type** (root or sub-path)
3. **Configures the appropriate file** with your settings
4. **Sets proper permissions** and file ownership
5. **Checks prerequisites** and provides guidance
6. **Reloads your web server** with new configuration

### **Configuration Examples**

#### **Apache .htaccess**
```apache
# For root deployment: RewriteBase /
# For sub-path deployment: RewriteBase /ldap-manager/

RewriteBase /ldap-manager/  # Example for sub-path
```

#### **Nginx Configuration**
```nginx
# For root deployment: set $base_path "";
# For sub-path deployment: set $base_path "/ldap-manager";

set $base_path "/ldap-manager";  # Example for sub-path
```

### **Manual Setup (Alternative)**
```bash
# For Apache
cp web-servers/.htaccess /path/to/your/webroot/.htaccess
# Edit .htaccess and change RewriteBase line

# For Nginx
cp web-servers/nginx.conf /etc/nginx/sites-available/ldap-user-manager
# Edit nginx.conf and change $base_path variable
```

### **Features**
- ✅ **Clean URLs** work in both root and sub-path deployments
- ✅ **Parameter handling** for dynamic content
- ✅ **Security protection** for sensitive files
- ✅ **Performance optimization** with caching and compression
- ✅ **Security headers** for XSS and clickjacking protection

## 🔧 **Configuration Requirements**

### **Apache Modules**
```bash
# Required modules
a2enmod rewrite
a2enmod headers
a2enmod expires
a2enmod deflate

# Reload Apache
systemctl reload apache2
```

### **PHP Extensions**
```bash
# Required PHP extensions
php -m | grep -E "(ldap|openssl|mbstring|json)"
```

### **LDAP Configuration**
- LDAP server hostname/IP
- LDAP bind DN and password
- LDAP base DN
- SSL/TLS certificate (if required)

## 📊 **Performance Comparison**

| Feature | Docker | Apache | Nginx |
|---------|--------|--------|-------|
| **Setup Complexity** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐ |
| **Performance** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐⭐⭐ |
| **Security** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ |
| **Maintenance** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐ |
| **Flexibility** | ⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ |

## 🚨 **Troubleshooting**

### **Common Issues**

#### **URL Rewriting Not Working**
```bash
# Apache: Check mod_rewrite
apache2ctl -M | grep rewrite

# Nginx: Check configuration syntax
nginx -t

# Verify file permissions
ls -la www/.htaccess
```

#### **PHP Processing Issues**
```bash
# Check PHP-FPM status
systemctl status php8.0-fpm

# Verify PHP socket
ls -la /var/run/php/php8.0-fpm.sock

# Test PHP processing
echo "<?php phpinfo(); ?>" | php
```

#### **LDAP Connection Issues**
```bash
# Test LDAP connection
ldapsearch -H ldap://your-ldap-server -x -D "cn=admin,dc=example,dc=com" -w password -b "dc=example,dc=com"
```

### **Debug Commands**
```bash
# Apache error logs
tail -f /var/log/apache2/error.log

# Nginx error logs
tail -f /var/log/nginx/error.log

# PHP-FPM logs
tail -f /var/log/php8.0-fpm.log
```

## 🔒 **Security Considerations**

### **File Access Protection**
- ✅ **Sensitive files** (.htaccess, .ini, .log) are blocked
- ✅ **Includes directory** is protected from direct access
- ✅ **Configuration files** (.env, .git) are hidden

### **Security Headers**
- ✅ **X-Frame-Options**: Prevents clickjacking
- ✅ **X-Content-Type-Options**: Prevents MIME type sniffing
- ✅ **X-XSS-Protection**: Enables XSS protection
- ✅ **Referrer-Policy**: Controls referrer information

### **Performance Optimization**
- ✅ **Browser caching** for static assets
- ✅ **Gzip compression** for text content
- ✅ **Efficient routing** with minimal overhead

## 📚 **Additional Resources**

- [Apache mod_rewrite Documentation](https://httpd.apache.org/docs/current/mod/mod_rewrite.html)
- [Nginx try_files Directive](https://nginx.org/en/docs/http/ngx_http_core_module.html#try_files)
- [PHP-FPM Configuration](https://www.php.net/manual/en/install.fpm.configuration.php)
- [LDAP Configuration](LDAP-CONFIGURATION.md)

## 🎯 **Recommendation**

- **Production/Enterprise**: Use **Docker** for consistency and security
- **Shared Hosting**: Use **Direct deployment** with Apache
- **High-Performance**: Use **Direct deployment** with Nginx
- **Development**: Use **Docker** for easy setup and consistency

Choose the deployment method that best fits your infrastructure, expertise, and requirements!
