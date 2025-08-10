# LDAP User Manager - Implementation Plan for Requirements Compliance

## Overview
This document outlines the plan to modify the existing LDAP User Manager to meet the specified requirements for a hierarchical organization-based user management system with proper role-based access control.

## Requirements Analysis

### ✅ **COMPLETED REQUIREMENTS**
1. **LDAP Structure**: Updated to use standard `postalAddress` attribute instead of custom schemas
2. **Role Hierarchy**: Implemented administrator, maintainer, and organization manager roles
3. **Access Control**: Maintainers cannot modify administrator accounts
4. **Organization Structure**: Organizations contain users and have proper address information
5. **User Management**: Users are organized within organizations with email-based login

### 🔄 **IN PROGRESS REQUIREMENTS**
1. **User Creation**: Need to update user creation functions to work within organizations
2. **Passcode Support**: Need to implement passcode functionality for future logins
3. **Organization Management UI**: Need to update frontend to handle new organization structure

### ❌ **PENDING REQUIREMENTS**
1. **Setup Wizard**: Need to update setup process for new LDAP structure
2. **User Interface**: Need to update all UI components to work with new structure
3. **Testing**: Need to test all functionality with new LDAP structure

## Changes Made

### 1. LDAP Schema Updates
- **Removed**: `orgWithCountry.ldif` (custom schema no longer needed)
- **Updated**: `base.ldif` with proper role hierarchy and system users
- **Updated**: `example-org.ldif` to use `postalAddress` and new structure
- **Added**: `system_users.ldif` for administrator and maintainer accounts

### 2. PHP Code Updates
- **Updated**: `organization_functions.inc.php` to use new LDAP structure
- **Updated**: `access_functions.inc.php` with comprehensive role-based access control
- **Updated**: `config.inc.php` to remove custom schema dependencies

### 3. Documentation Updates
- **Updated**: `LDAP-CONFIGURATION.md` to reflect new standard schema approach
- **Added**: This implementation plan document

## Next Steps

### Phase 1: Complete Core Functionality (Priority: High)
1. **Update User Creation Functions**
   - Modify `ldap_functions.inc.php` to create users within organizations
   - Update user DN structure to be organization-based
   - Implement email-based login (uid = email address)

2. **Implement Passcode Support**
   - Add passcode attribute to user entries
   - Update password change functions to handle passcodes
   - Add passcode validation and hashing

3. **Update Setup Process**
   - Modify setup wizard to create new LDAP structure
   - Add organization creation during setup
   - Ensure proper role assignment

### Phase 2: User Interface Updates (Priority: Medium)
1. **Organization Management**
   - Update organization creation forms to use new address format
   - Add organization editing capabilities
   - Implement organization deletion with proper access control

2. **User Management**
   - Update user creation forms to work within organizations
   - Add organization selection for user creation
   - Implement user role management within organizations

3. **Access Control UI**
   - Add role-based UI elements
   - Implement proper permission checking in all forms
   - Add user role display and management

### Phase 3: Testing and Validation (Priority: High)
1. **LDAP Structure Testing**
   - Test organization creation with new structure
   - Verify user creation within organizations
   - Test role-based access control

2. **Functionality Testing**
   - Test all CRUD operations with new structure
   - Verify access control rules work correctly
   - Test user authentication and role checking

3. **Integration Testing**
   - Test complete setup process
   - Verify all modules work together
   - Test edge cases and error conditions

## Technical Implementation Details

### LDAP Structure
```
dc=example,dc=com
├── ou=organizations
│   ├── o=Company Name
│   │   ├── ou=users
│   │   │   ├── uid=admin@company.com
│   │   │   └── uid=user1@company.com
│   │   └── ou=roles
│   │       └── cn=org_admin
│   └── o=University Name
│       ├── ou=users
│       └── ou=roles
├── ou=system_users
│   ├── uid=admin@example.com
│   └── uid=maintainer@example.com
└── ou=roles
    ├── cn=administrator
    └── cn=maintainer
```

### Role Hierarchy
1. **Administrator**: Full access to everything
2. **Maintainer**: Access to everything except administrator accounts
3. **Organization Manager**: Access to users within their organization
4. **Regular User**: Access to own account only

### Access Control Rules
- Administrators can modify anyone
- Maintainers can modify anyone except administrators
- Organization managers can only modify users in their organization
- Users can modify their own account
- Only administrators can delete organizations

## Configuration Changes

### Environment Variables
- `LDAP_ADMINS_GROUP`: administrator (default)
- `LDAP_MAINTAINERS_GROUP`: maintainer (default)
- `LDAP_ORG_OU`: organizations (default)

### LDAP Base DN
Ensure `LDAP_BASE_DN` matches the structure in the LDIF files.

## Migration Notes

### From Old Structure
- Organizations using old locality-based structure need to be migrated
- User DNs will change to be organization-based
- Role assignments need to be updated

### Data Migration
- Export existing data
- Transform to new structure
- Import with new LDIF files
- Verify all relationships are maintained

## Success Criteria

1. **LDAP Compliance**: System uses only standard LDAP schemas
2. **Role-Based Access**: Proper access control for all user types
3. **Organization Management**: Full CRUD operations for organizations
4. **User Management**: Users properly organized within organizations
5. **Security**: Maintainers cannot access administrator accounts
6. **Usability**: Intuitive interface for all user types

## Timeline Estimate

- **Phase 1**: 2-3 weeks (core functionality)
- **Phase 2**: 2-3 weeks (UI updates)
- **Phase 3**: 1-2 weeks (testing and validation)
- **Total**: 5-8 weeks for complete implementation

## Risk Mitigation

1. **Backup Strategy**: Always backup LDAP data before changes
2. **Testing Environment**: Use separate environment for development
3. **Rollback Plan**: Maintain ability to revert to previous structure
4. **Documentation**: Keep all changes well-documented
5. **Incremental Updates**: Implement changes in small, testable increments 