# Migration from .htaccess to Apache Configuration

This document explains the migration from using `.htaccess` files to Apache configuration for the LDAP User Manager Docker container.

## 🎯 **Why This Migration?**

### **Problems with .htaccess in Docker**

1. **Performance Overhead**: `.htaccess` files are read on every request
2. **Security Risk**: Users could potentially modify URL rewriting rules
3. **Docker Anti-Pattern**: Configuration should be part of the container
4. **Inconsistency**: Different instances might have different configurations
5. **Maintenance Issues**: Harder to manage in containerized environments

### **Benefits of Apache Configuration**

1. **Better Performance**: Configuration loaded once at startup
2. **Enhanced Security**: Users cannot modify server configuration
3. **Docker Best Practice**: Configuration is immutable part of container
4. **Consistency**: All container instances use identical configuration
5. **Centralized Management**: Single source of truth for configuration

## 🔧 **What Changed**

### **Before (.htaccess)**
- Configuration in `www/.htaccess`
- Read on every request
- User-modifiable
- Performance overhead

### **After (Apache Config)**
- Configuration in `apache/ldap-user-manager.conf`
- Loaded once at startup
- User-immutable
- Optimized performance

## 📁 **New File Structure**

```
apache/
├── README.md                    # Configuration documentation
└── ldap-user-manager.conf      # Main Apache configuration
```

## 🐳 **Docker Integration**

### **Dockerfile Changes**
```dockerfile
# Enable Apache modules for security, performance, and URL rewriting
RUN a2enmod rewrite ssl headers expires deflate && a2dissite 000-default default-ssl

# Copy Apache configuration
COPY apache/ /etc/apache2/conf-available/
```

### **Entrypoint Changes**
```bash
# Include LDAP User Manager configuration
Include /etc/apache2/conf-available/ldap-user-manager.conf
```

## 🚀 **Migration Steps**

### **1. Remove .htaccess**
```bash
rm www/.htaccess
```

### **2. Add Apache Configuration**
- Copy `apache/` directory to project
- Update Dockerfile to copy configuration
- Update entrypoint to include configuration

### **3. Update Documentation**
- Remove `.htaccess` references
- Update URL routing documentation
- Add Apache configuration documentation

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
- Monitor response times before/after
- Check Apache access logs for configuration loading
- Verify no `.htaccess` file access attempts

## 🔍 **Troubleshooting**

### **Common Issues**

#### **Configuration Not Loading**
- Check file permissions in container
- Verify Apache configuration syntax
- Check Apache error logs

#### **URL Rewriting Not Working**
- Ensure `mod_rewrite` is enabled
- Check RewriteEngine is On
- Verify RewriteBase is correct

#### **Performance Issues**
- Check Apache configuration is loaded
- Verify no `.htaccess` files are being read
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

After migration, the LDAP User Manager will have:
- **Better performance** from optimized configuration loading
- **Enhanced security** from immutable configuration
- **Docker best practices** with containerized configuration
- **Consistent behavior** across all instances
- **Professional deployment** following industry standards
