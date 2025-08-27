# Role Configuration Guide

This document explains how to properly configure roles and access control for the LDAP User Manager system.

## 🎯 **Role Configuration Overview**

The system uses role-based access control with two main role types:

- **Global Administrators**: Full system access
- **System Maintainers**: Can manage organizations and users
- **Organization Administrators**: Manage users within their organization
- **Regular Users**: Self-service account management

## 🔧 **Configuration Variables**

### **Role Values**
```bash
# Group names define the LDAP groups under ou=roles
LDAP_ADMIN_ROLE=administrators
LDAP_MAINTAINER_ROLE=maintainers
```

## ⚠️ **Important Configuration Rules**

### **Rule 1: Different Role Values**
Role values must be different from each other:
```bash
# ✅ CORRECT - Different values
LDAP_ADMIN_ROLE=administrator
LDAP_MAINTAINER_ROLE=maintainer

# ❌ INCORRECT - Same values
LDAP_ADMIN_ROLE=admin
LDAP_MAINTAINER_ROLE=admin
```

## 🚀 **Recommended Configuration**

## 🔍 **Configuration Validation**

The system automatically validates your configuration:

1. **Setup Prevention**: Blocks setup completion if conflicts detected
2. **Runtime Checks**: Activates maintenance mode if conflicts found
3. **Clear Error Messages**: Shows exactly what's wrong and how to fix it

## 📋 **Configuration Examples**

### **Simple Setup**
```bash
# All values use defaults
```

### **Production Environment**
```bash
LDAP_ADMIN_ROLE=administrators
LDAP_MAINTAINER_ROLE=maintainers
LDAP_ORG_ADMIN_ROLE=organization_administrators
LDAP_USER_ROLE=standard_user
```

### **Development Environment**
```bash
LDAP_ADMIN_ROLE=dev_admins
LDAP_MAINTAINER_ROLE=dev_maintainers
LDAP_ORG_ADMIN_ROLE=org_admin
LDAP_USER_ROLE=user
```

## 🛡️ **Security Considerations**

- **Role Separation**: Keep administrator and maintainer roles distinct
- **Access Control**: Maintainers cannot manage administrator accounts
- **Self-Protection**: Users cannot delete their own accounts

## 🔧 **Troubleshooting**

### **Setup Won't Complete**
- Check for duplicate role values

### **Maintenance Mode Active**
- Review your configuration variables
- Fix any conflicts
- Restart the application

### **Access Control Issues**
- Verify role values match your LDAP structure
- Ensure proper role hierarchy

## 📚 **Related Documentation**

- [Configuration Variables](CONFIGURATION_VARIABLES.md) - Complete environment variable reference
- [LDAP Configuration](LDAP-CONFIGURATION.md) - LDAP schema and setup
- [Access Control](docs/ACCESS_CONTROL.md) - How roles and permissions work
