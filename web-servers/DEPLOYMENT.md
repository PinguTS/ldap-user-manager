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

### **Static File Handling**
Both Apache and Nginx configurations are designed to serve static files (CSS, JS, images, fonts) directly without processing them through PHP:

- **CSS/JS files**: Served with proper caching headers
- **Images and fonts**: Optimized with compression and caching
- **No PHP processing**: Static files bypass the application entirely
- **Performance**: Faster loading and better caching

This ensures optimal performance and prevents unnecessary PHP processing of static assets.

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

## 🔧 **Troubleshooting**

### **Common Issues**

#### **Static Files Redirecting to Login Page**
- **Symptom**: CSS, JS, images, or favicon redirect to login page
- **Cause**: Rewrite rules catching static file requests
- **Solution**: Ensure your configuration excludes static file extensions (already configured)

#### **Clean URLs Not Working**
- **Symptom**: URLs like `/manage/users/show` return 404
- **Cause**: `mod_rewrite` not enabled (Apache) or incorrect `try_files` (Nginx)
- **Solution**: Enable required modules and reload web server

#### **Permission Denied Errors**
- **Symptom**: 403 Forbidden errors
- **Cause**: Incorrect file ownership or web server user permissions
- **Solution**: Run setup script with correct web server user/group

#### **PHP Processing Errors**
- **Symptom**: PHP code displayed as text or 500 errors
- **Cause**: PHP not properly configured or PHP-FPM not running (Nginx)
- **Solution**: Verify PHP installation and PHP-FPM status

### **Debug Steps**
1. **Check web server error logs** for specific error messages
2. **Verify module status**: `apache2ctl -M` (Apache) or `nginx -t` (Nginx)
3. **Test static file access** directly (e.g., `/bootstrap/css/bootstrap.css`)
4. **Check file permissions** and ownership
5. **Verify configuration syntax** before reloading

## 📚 **Additional Resources**

- [Apache Documentation](https://httpd.apache.org/docs/)
- [Nginx Documentation](https://nginx.org/en/docs/)
- [PHP-FPM Configuration](https://www.php.net/manual/en/install.fpm.php)

## 🎯 **Next Steps**

1. **Choose your deployment method** (Docker recommended for production)
2. **Follow the setup instructions** for your chosen method
3. **Configure LDAP settings** using environment variables
4. **Test your installation** and verify functionality
5. **Review security settings** and adjust as needed

For Docker deployment, see [DOCKER-SETUP.md](../DOCKER-SETUP.md).
For direct deployment, use the setup script in this directory.
