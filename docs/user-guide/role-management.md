# Role Management Guide

This guide explains how to manage roles and permissions in LDAP User Manager.

## Overview

Role management allows you to:
- Create and manage roles
- Assign roles to users
- Configure role permissions
- Manage role hierarchies
- Control access to system features

## Role Types

### System Roles
- **Location**: `ou=roles,dc=example,dc=com`
- **Scope**: System-wide permissions
- **Examples**: Administrators, Maintainers
- **Management**: Only by system administrators

### Organization Roles
- **Location**: `ou=roles,o=OrganizationName,ou=organizations,dc=example,dc=com`
- **Scope**: Organization-specific permissions
- **Examples**: Organization Admin, User Manager
- **Management**: By organization administrators

## Role Hierarchy

### Permission Levels
1. **Administrators** (Level 100) - Full system access
2. **Maintainers** (Level 80) - User and organization management
3. **Organization Administrators** (Level 60) - Organization management
4. **Regular Users** (Level 10) - Self-service access

### Role Inheritance
- **Higher levels** can manage lower levels
- **Lower levels** cannot manage higher levels
- **Organization roles** are separate from system roles

## Creating Roles

### System Roles
1. **Log in** as an administrator
2. **Navigate to** "Manage" → "Roles"
3. **Click** "Create System Role"
4. **Define** role name and permissions
5. **Save** the role

### Organization Roles
1. **Navigate to** the organization
2. **Click** "Manage Roles"
3. **Click** "Create Role"
4. **Define** role name and permissions
5. **Save** the role

### Role Configuration
**Required Fields:**
- **Role Name**: Display name for the role
- **Description**: Role purpose and responsibilities

**Optional Fields:**
- **Permissions**: Specific permissions for the role
- **Parent Role**: Role that this role inherits from

## Assigning Roles

### To System Users
1. **Navigate to** User Management
2. **Select** the system user
3. **Click** "Manage Roles"
4. **Select** system roles from available list
5. **Save** role assignments

### To Organization Users
1. **Navigate to** Organization Management
2. **Select** the organization
3. **Click** "Manage Users"
4. **Select** the user
5. **Click** "Manage Roles"
6. **Select** organization roles
7. **Save** role assignments

### Role Assignment Rules
- **Users can have** multiple roles
- **Role conflicts** are automatically resolved
- **Higher level roles** override lower level permissions
- **Organization roles** are separate from system roles

## Role Permissions

### Administrator Permissions
- **Full system access** to all features
- **User management** across all organizations
- **Organization management**
- **Role management**
- **System configuration**
- **Backup and restore**

### Maintainer Permissions
- **User management** (except administrators)
- **Organization management**
- **Role assignment** (except administrator roles)
- **System monitoring**
- **Limited configuration** access

### Organization Administrator Permissions
- **User management** within organization
- **Organization settings** management
- **Role assignment** within organization
- **Organization-specific** configuration

### Regular User Permissions
- **Self-service** account management
- **Password changes**
- **Profile updates**
- **Limited read access**

## Managing Role Permissions

### Viewing Role Permissions
1. **Navigate to** Role Management
2. **Select** the role to view
3. **Click** "View Permissions"
4. **Review** assigned permissions

### Modifying Role Permissions
1. **Select** the role to modify
2. **Click** "Edit Permissions"
3. **Add or remove** permissions
4. **Save** changes

### Permission Categories
- **User Management**: Create, edit, delete users
- **Organization Management**: Manage organizations
- **Role Management**: Assign and manage roles
- **System Configuration**: Modify system settings
- **Monitoring**: View system statistics and logs

## Role Search and Filtering

### Search Options
- **Role name**: Search by role name
- **Permission type**: Filter by permission category
- **Scope**: System vs. organization roles

### Advanced Search
- **User count**: Roles by number of assigned users
- **Creation date**: Filter by role creation date
- **Status**: Active/inactive roles

## Best Practices

### Role Design
- **Use descriptive** role names
- **Define clear** responsibilities
- **Follow principle** of least privilege
- **Document role** purposes

### Role Assignment
- **Assign minimum** necessary roles
- **Regular review** of role assignments
- **Remove unused** roles
- **Monitor role** usage

### Security
- **Limit administrator** role assignments
- **Regular audit** of role permissions
- **Secure role** management access
- **Monitor role** changes

## Troubleshooting

### Common Issues
- **User can't access feature**: Check role permissions
- **Role assignment fails**: Verify role exists and is available
- **Permission conflicts**: Check role hierarchy
- **Role not appearing**: Verify role scope and user location

### Solutions
- **Review user roles** for missing permissions
- **Check role availability** for user's organization
- **Verify role hierarchy** for conflicts
- **Ensure user location** matches role scope

## Role Import/Export

### Importing Roles
1. **Prepare** CSV file with role data
2. **Navigate to** Import section
3. **Upload** CSV file
4. **Map** columns to role fields
5. **Review** and confirm import

### Exporting Roles
1. **Select** roles to export
2. **Choose** export format (CSV, LDIF)
3. **Download** export file
4. **Use** for backup or migration

## Role Monitoring

### Activity Tracking
- **Role assignments**: Track when roles are assigned
- **Permission changes**: Monitor role permission modifications
- **User access**: Track feature access by role

### Audit Logs
- **Role creation**: Log when roles are created
- **Role modification**: Log permission changes
- **Role assignment**: Log user-role assignments

## Next Steps

- **User Management**: See [User Management](user-management.md)
- **Organization Management**: See [Organization Management](organization-management.md)
- **Configuration**: See [Configuration](../configuration/environment-variables.md)
