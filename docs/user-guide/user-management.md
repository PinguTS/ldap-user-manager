# User Management Guide

This guide explains how to manage users in LDAP User Manager.

## Overview

User management allows you to:
- Create new user accounts
- Edit existing user information
- Delete user accounts
- Assign users to organizations
- Manage user roles and permissions

## User Types

### System Users
- **Location**: `ou=people,dc=example,dc=com`
- **Purpose**: Administrators and maintainers
- **Permissions**: System-wide access
- **Fields**: Basic information only (no address)

### Organization Users
- **Location**: `ou=people,o=OrganizationName,ou=organizations,dc=example,dc=com`
- **Purpose**: Regular users within organizations
- **Permissions**: Organization-specific access
- **Fields**: Full user information including organization

## Access Control Matrix

The system uses role-based access control with the following permissions:

| Feature | Global Admin | Maintainer | Org Admin | Regular User |
|---------|-------------|------------|-----------|--------------|
| **System Users** | ✅ Full Access | ❌ None | ❌ None | ❌ None |
| **Organizations** | ✅ Full Access | ✅ Full Access | Own Only | ❌ None |
| **Organization Users** | ✅ Full Access | ✅ Full Access | Own Org Only | ❌ None |
| **Roles** | ✅ Full Access | ❌ None | ❌ None | ❌ None |
| **System Settings** | ✅ Full Access | ❌ None | ❌ None | ❌ None |
| **Password Changes** | ✅ Full Access | ✅ Full Access | Own Org Only | Own Only |
| **Account Requests** | ✅ Full Access | ✅ Full Access | Own Org Only | ✅ Create Only |

### Role Descriptions

**Global Administrator (Level 100)**
- Full access to all system features
- Can manage system users, organizations, and roles
- Can modify system configuration
- Can access all organizations and users

**System Maintainer (Level 80)**
- Can manage organizations and their users
- Cannot manage system users or roles
- Cannot access system configuration
- Can manage account requests

**Organization Administrator (Level 60)**
- Can manage their own organization only
- Can add, edit, and delete users within their organization
- Cannot access other organizations
- Cannot manage system users or roles

**Regular User (Level 10)**
- Can view their own profile
- Can change their own password
- Can request account changes
- Cannot manage other users or organizations

## Creating Users

### Step 1: Access User Management
1. **Log in** to the web interface
2. **Navigate to** "Manage" → "Users"
3. **Click** "Add New User" or "Create User"

### Step 2: Fill User Information
**Required Fields:**
- **First Name**: User's first name
- **Last Name**: User's last name
- **Email**: User's email address (used as username)
- **Organization**: Select from dropdown (for organization users)

**Optional Fields:**
- **Phone**: User's phone number
- **Website**: User's website URL
- **User Role**: Role within the organization

### Step 3: Set Password
- **Generate Password**: Click to create a secure password
- **Manual Password**: Enter a custom password
- **Password Requirements**: Must meet configured strength policy

### Step 4: Save User
- **Click** "Save" or "Create User"
- **Verify** the user appears in the user list
- **Send credentials** to the user via email (if configured)

## Editing Users

### Step 1: Find the User
1. **Navigate to** User Management
2. **Search** for the user by name or email
3. **Click** on the user's name to edit

### Step 2: Modify Information
- **Update** any field as needed
- **Change password** if required
- **Modify role** assignments

### Step 3: Save Changes
- **Click** "Save" to update the user
- **Verify** changes are applied

## Deleting Users

### Step 1: Confirm Deletion
1. **Find** the user to delete
2. **Click** "Delete" or trash icon
3. **Confirm** the deletion action

### Step 2: Handle Dependencies
- **Check** if user has assigned roles
- **Remove** role assignments first
- **Verify** no critical dependencies

### Step 3: Complete Deletion
- **Confirm** final deletion
- **Verify** user is removed from list

## User Search and Filtering

### Search Options
- **Name search**: Search by first or last name
- **Email search**: Search by email address
- **Organization filter**: Show users by organization
- **Role filter**: Show users by role

### Advanced Search
- **Date range**: Filter by creation date
- **Status**: Active/inactive users
- **Role combinations**: Multiple role filters

## Password Management

### Password Policies
- **Minimum length**: Configured minimum characters
- **Character types**: Uppercase, lowercase, numbers, symbols
- **Strength score**: Password strength requirements
- **History**: Prevent password reuse

### Password Reset
1. **Select** the user
2. **Click** "Reset Password"
3. **Generate** new password
4. **Send** to user via email

### Self-Service Password Change
- **Users can change** their own passwords
- **Access via** "Change Password" menu
- **Requires** current password verification

## Role Assignment

### Assigning Roles
1. **Select** the user
2. **Click** "Manage Roles"
3. **Select** roles from available list
4. **Save** role assignments

### Role Types
- **System Roles**: Administrators, maintainers
- **Organization Roles**: Organization-specific permissions
- **Custom Roles**: User-defined roles

### Role Permissions
- **Administrators**: Full system access
- **Maintainers**: User and organization management
- **Organization Managers**: Organization-specific management
- **Regular Users**: Self-service access only

## Best Practices

### User Creation
- **Use consistent** naming conventions
- **Verify email** addresses are valid
- **Set appropriate** initial passwords
- **Assign correct** roles immediately

### User Maintenance
- **Regular review** of user accounts
- **Update information** when users change roles
- **Remove inactive** accounts promptly
- **Monitor** for suspicious activity

### Security
- **Enforce strong** password policies
- **Limit role** assignments to minimum needed
- **Regular audit** of user permissions
- **Secure access** to user management

## Troubleshooting

### Common Issues
- **User can't log in**: Check password and role assignment
- **Missing permissions**: Verify role assignments
- **Email not received**: Check email configuration
- **Import errors**: Validate CSV format and data

### Solutions
- **Reset password** for login issues
- **Reassign roles** for permission problems
- **Check email settings** for delivery issues
- **Review import logs** for data problems

## Next Steps

- **Organization Management**: See [Organization Management](organization-management.md)
- **Role Management**: See [Role Management](role-management.md)
- **Configuration**: See [Configuration](../configuration/environment-variables.md)
