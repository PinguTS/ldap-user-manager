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
6. **Unified User Structure**: Implemented consistent `ou=people` naming convention across the entire LDAP tree

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
- **Updated**: `base.ldif` with proper role hierarchy and unified `ou=people` structure
- **Updated**: `example-org.ldif` to use `postalAddress` and new `ou=people` structure
- **Updated**: `system_users.ldif` to use `ou=people` instead of `ou=system_users`

### 2. PHP Code Updates
- **Updated**: `organization_functions.inc.php` to use new LDAP structure
- **Updated**: `access_functions.inc.php` with comprehensive role-based access control
- **Updated**: `config.inc.php` to use unified `ou=people` structure
- **Updated**: All PHP files to reference new `people_dn` instead of `system_users_dn`

### 3. Documentation Updates
- **Updated**: `LDAP-CONFIGURATION.md` to reflect new standard schema approach
- **Updated**: `ldap-structure.md` to reflect unified `ou=people` structure
- **Added**: This implementation plan document

## New Unified LDAP Structure

```
dc=example,dc=com
├── ou=people                           # System-level users (admins, maintainers)
│   ├── uid=admin@example.com
│   └── uid=maintainer@example.com
├── ou=organizations
│   └── o=Example Company
│       └── ou=people                   # Organization users (same naming!)
│           ├── uid=admin@examplecompany.com
│           └── uid=user1@examplecompany.com
└── ou=roles
    ├── cn=administrators
    └── cn=maintainers
```

### Benefits of Unified Structure
- **Consistent Naming**: `ou=people` everywhere means the same thing
- **Intuitive Structure**: Users are always under `ou=people`, regardless of context
- **Easier to Understand**: LDAP administrators will immediately know where to find users
- **Better Documentation**: Simpler to explain and maintain
- **Follows Standards**: `ou=people` is the de facto standard for user containers

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

### Configuration Variables
```php
// New unified approach
$LDAP['people_dn'] = "ou=people,{$LDAP['base_dn']}";
$LDAP['org_people_dn'] = "ou=people,o={$LDAP['org_ou']},{$LDAP['base_dn']}";
```

### LDAP Search Patterns
- **System Users**: Search in `ou=people,dc=example,dc=com`
- **Organization Users**: Search in `ou=people,o=Organization,ou=organizations,dc=example,dc=com`
- **Unified Search**: Can search both locations with consistent naming

## Migration Notes

### For Existing Installations
1. **Backup**: Always backup existing LDAP data before migration
2. **Gradual Migration**: Consider migrating one organization at a time
3. **Testing**: Test thoroughly in a development environment first
4. **Rollback Plan**: Have a plan to revert changes if issues arise

### Schema Changes
- No schema changes required (using standard LDAP attributes)
- Existing user data can be migrated to new structure
- Group memberships need to be updated to reflect new DNs 