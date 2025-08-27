# Role Conflict Prevention and Detection

This document describes the role conflict prevention and detection system that ensures the access control system works correctly.

## 🎯 **Role Value Synchronization**

Role values now default to group names by default:

```php
// Default behavior: Role values = Group names
$LDAP['admin_role'] = 'administrators';          // Role value
$LDAP['admin_group_name'] = 'administrators';    // Group name
```

**How it works**:
1. If `LDAP_ADMIN_ROLE` is not set, it defaults to `LDAP_ADMIN_GROUP_NAME`
2. If `LDAP_MAINTAINER_ROLE` is not set, it defaults to `LDAP_MAINTAINER_GROUP_NAME`
3. You can still override either independently if needed

## 🛡️ **Role Conflict Detection System**

The system automatically detects and prevents these critical configuration errors:

1. **Admin/Maintainer Role Conflict**: `LDAP_ADMIN_ROLE = LDAP_MAINTAINER_ROLE`
2. **Admin/Maintainer Group Conflict**: `LDAP_ADMIN_GROUP_NAME = LDAP_MAINTAINER_GROUP_NAME`
3. **Role/Group Cross-Conflict**: `LDAP_ADMIN_ROLE = LDAP_MAINTAINER_GROUP_NAME`
4. **Role/Group Cross-Conflict**: `LDAP_MAINTAINER_ROLE = LDAP_ADMIN_GROUP_NAME`

### **🔒 Two-Level Protection**

#### **Level 1: Setup Prevention**
- **Location**: `www/includes/config.inc.php` configuration validation
- **Action**: Blocks setup completion with detailed error messages
- **Result**: System cannot be configured with broken access control

#### **Level 2: Runtime Maintenance Mode**
- **Location**: All major entry points (index.php, login, setup)
- **Action**: Activates maintenance mode if conflicts detected
- **Result**: System cannot operate with broken access control

### **🎯 Maintenance Mode Features**

When conflicts are detected, the system displays a professional maintenance page with:

- **Clear Error Description**: What went wrong and why
- **Current Configuration**: Shows the conflicting values
- **Step-by-Step Solutions**: Multiple configuration examples
- **Professional Styling**: User-friendly error presentation
- **Security Protection**: Prevents any system operation

### **💡 Configuration Examples**

#### **❌ Invalid Configurations (Will Be Blocked)**
```bash
# Same role values - BLOCKED
LDAP_ADMIN_ROLE=admin
LDAP_MAINTAINER_ROLE=admin

# Same group names - BLOCKED
LDAP_ADMIN_GROUP_NAME=admins
LDAP_MAINTAINER_GROUP_NAME=admins

# Role conflicts with group - BLOCKED
LDAP_ADMIN_ROLE=maintainer
LDAP_MAINTAINER_GROUP_NAME=maintainer
```

#### **✅ Valid Configurations (Will Work)**
```bash
# Different values
LDAP_ADMIN_ROLE=administrator
LDAP_MAINTAINER_ROLE=maintainer
LDAP_ADMIN_GROUP_NAME=administrators
LDAP_MAINTAINER_GROUP_NAME=maintainers

# Synchronized defaults (recommended)
LDAP_ADMIN_GROUP_NAME=administrators
LDAP_MAINTAINER_GROUP_NAME=maintainers
# admin_role and maintainer_role automatically sync to group names
```

### **🔧 Testing the Conflict Detection**

To test the system:

1. **Set conflicting environment variables**:
   ```bash
   export LDAP_ADMIN_ROLE=admin
   export LDAP_MAINTAINER_ROLE=admin
   ```

2. **Access any page** - system will show maintenance mode

3. **Fix configuration** and restart application

## 🚨 **Common Configuration Errors**

### **Error 1: Same Role Values**
```bash
LDAP_ADMIN_ROLE=admin
LDAP_MAINTAINER_ROLE=admin
```
**Problem**: System cannot distinguish between administrators and maintainers
**Solution**: Use different values for each role

### **Error 2: Same Group Names**
```bash
LDAP_ADMIN_GROUP_NAME=admins
LDAP_MAINTAINER_GROUP_NAME=admins
```
**Problem**: LDAP groups would conflict, breaking access control
**Solution**: Use different group names for each role

### **Error 3: Role/Group Cross-Conflicts**
```bash
LDAP_ADMIN_ROLE=maintainer
LDAP_MAINTAINER_GROUP_NAME=maintainer
```
**Problem**: Admin role value conflicts with maintainer group name
**Solution**: Ensure role values don't conflict with group names

## 📋 **Files Modified**

1. **`www/includes/config.inc.php`**: Enhanced validation + conflict detection functions
2. **`www/index.php`**: Runtime conflict check
3. **`www/log_in/index.php`**: Runtime conflict check
4. **`www/setup/index.php`**: Runtime conflict check

## 🎯 **Benefits**

- ✅ **Prevents Security Vulnerabilities**: System cannot operate with broken access control
- ✅ **Setup Protection**: Cannot complete setup with invalid configuration
- ✅ **Runtime Safety**: Automatic detection and maintenance mode activation
- ✅ **Professional Error Handling**: Clear, helpful error messages with solutions
- ✅ **User Experience**: Professional maintenance page instead of broken functionality
