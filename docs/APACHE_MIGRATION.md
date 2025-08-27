# Apache Configuration for Docker

This document explains the Apache configuration system used in the LDAP User Manager Docker container.

## 🎯 **Why Apache Configuration?**

### **Benefits of Apache Configuration**

1. **Better Performance**: Configuration loaded once at startup
2. **Enhanced Security**: Users cannot modify server configuration
3. **Docker Best Practice**: Configuration is immutable part of container
4. **Consistency**: All container instances use identical configuration
5. **Centralized Management**: Single source of truth for configuration

## 🔧 **Configuration Structure**

### **Apache Configuration Files**
- Configuration in `apache/ldap-user-manager.conf`
- Loaded once at startup
- User-immutable
- Optimized performance

### **File Organization**
```
apache/
├── README.md                    # Configuration documentation
└── ldap-user-manager.conf      # Main Apache configuration
```

## 🐳 **Docker Integration**

### **Dockerfile Configuration**
```dockerfile
# Enable Apache modules for security, performance, and URL rewriting
RUN a2enmod rewrite ssl headers expires deflate && a2dissite 000-default default-ssl

# Copy Apache configuration
COPY apache/ /etc/apache2/conf-available/
```

### **Entrypoint Integration**
```bash
# Include LDAP User Manager configuration
Include /etc/apache2/conf-available/ldap-user-manager.conf
```

## 🚀 **Configuration Features**

### **URL Rewriting**
- Clean URLs (e.g., `/manage/users/show`)
- Parameter handling (e.g., `/manage/users/show/username`)
- Fallback handling for non-existing URLs

### **Security**
- File access protection
- Directory access control
- Security headers

### **Performance**
- Static file caching
- Gzip compression
- Optimized file serving

## ✅ **Verification**

### **Check Configuration Loading**
```bash
# Inside container
apache2ctl -S
```

### **Test URL Rewriting**
- Verify clean URLs work (e.g., `/manage/users/show`)
- Check parameter handling (e.g., `/manage/users/show/username`)
- Confirm fallback redirects work

### **Performance Testing**
- Monitor response times
- Check Apache access logs for configuration loading
- Verify configuration is properly loaded

## 🔍 **Troubleshooting**

### **Common Issues**

#### **Configuration Not Loading**
- Check file permissions in container
- Verify Apache configuration syntax
- Check Apache error logs

#### **URL Rewriting Not Working**
- Ensure `mod_rewrite` is enabled
- Check RewriteEngine is On
- Verify configuration is properly included

#### **Performance Issues**
- Check Apache configuration is loaded
- Verify configuration is optimized
- Monitor Apache worker processes

### **Debug Commands**
```bash
# Check Apache configuration
apache2ctl -t

# Check loaded modules
apache2ctl -M

# Check configuration syntax
apache2ctl -S

# View Apache configuration
apache2ctl -D DUMP_RUN_CFG
```

## 📚 **Additional Resources**

- [Apache mod_rewrite Documentation](https://httpd.apache.org/docs/current/mod/mod_rewrite.html)
- [Apache Performance Tuning](https://httpd.apache.org/docs/current/misc/perf-tuning.html)
- [Docker Best Practices](https://docs.docker.com/develop/dev-best-practices/)

## 🎉 **Result**

The LDAP User Manager Docker container provides:
- **Better performance** from optimized configuration loading
- **Enhanced security** from immutable configuration
- **Docker best practices** with containerized configuration
- **Consistent behavior** across all instances
- **Professional deployment** following industry standards
