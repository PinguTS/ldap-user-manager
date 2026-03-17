# LDAP User Manager - System Overview

This document provides an overview of the LDAP User Manager system architecture and capabilities.

## System Overview

LDAP User Manager is a comprehensive web-based interface for managing LDAP user accounts, organizations, and role-based access control. It provides a unified approach to user management across both system-level and organization-level contexts.

## ✅ **Available Features**

1. **LDAP Structure**: Standard `postalAddress` attribute implementation
2. **Role Hierarchy**: Administrator, maintainer, and organization manager roles
3. **Access Control**: Maintainers cannot modify administrator accounts
4. **Organization Structure**: Organizations contain users with proper address information
5. **User Management**: Users organized within organizations with email-based login
6. **Unified User Structure**: Consistent `ou=people` naming convention across the entire LDAP tree
7. **Role Conflict Detection**: Automatic detection and prevention of configuration conflicts
8. **Role Value Synchronization**: Role values default to group names by default

## 🔄 **Core Functionality**

1. **User Creation**: User creation functions working within organizations
2. **Organization Management UI**: Frontend updated to handle organization structure

## 🚀 **Planned Enhancements**

1. **Setup Wizard**: Enhanced setup process for new LDAP structure
2. **User Interface**: Additional UI components for new structure
3. **Testing**: Comprehensive testing of all functionality

## Current LDAP Structure

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

## Current Features

### Role-Based Access Control
- **Global Administrators**: Full system access
- **System Maintainers**: Can manage organizations and users
- **Organization Managers**: Manage users within their assigned organization(s)
- **Regular Users**: Self-service account management

### Configuration Management
- Role values automatically sync to group names by default
- Automatic conflict detection and prevention
- Professional maintenance mode for configuration errors
- Setup process validation

### User Management
- System users and organization users
- Email-based login system
- Secure password generation and validation
- Role assignment and management

## System Architecture

### Core Components
1. **User Creation Functions**
   - LDAP functions for creating users within organizations
   - User DN structure based on organization context
   - Email-based login (uid = email address)

2. **Setup Process**
   - Setup wizard for new LDAP structure
   - Organization creation during setup
   - Proper role assignment

### User Interface Components
1. **Organization Management**
   - Organization creation forms with new address format
   - Organization editing capabilities
   - Organization deletion with proper access control

2. **User Management**
   - User creation forms within organizations
   - Organization selection for user creation
   - User role management within organizations

3. **Access Control UI**
   - Role-based UI elements
   - Proper permission checking in all forms

## Configuration Examples

### Role Configuration
```bash
# Synchronized defaults (recommended)
LDAP_ADMIN_GROUP_NAME=administrators
LDAP_MAINTAINER_GROUP_NAME=maintainers
# admin_role and maintainer_role automatically sync

# Custom configuration
LDAP_ADMIN_ROLE=superuser
LDAP_MAINTAINER_ROLE=tech_support
LDAP_ADMIN_GROUP_NAME=global_admins
LDAP_MAINTAINER_GROUP_NAME=system_maintainers
```

### Address Configuration
```php
// Make address fields required
$LDAP['org_address_fields'] = [
    'org_address' => ['label' => 'Street Address', 'type' => 'text', 'required' => true],
    'org_zip' => ['label' => 'Postal Code', 'type' => 'text', 'required' => true],
    'org_city' => ['label' => 'City', 'type' => 'text', 'required' => true],
    'org_state' => ['label' => 'State/Province', 'type' => 'text', 'required' => true],
    'org_country' => ['label' => 'Country', 'type' => 'text', 'required' => true]
];
```

## System Components

### Core Configuration
- `www/includes/config.inc.php` - Role synchronization, conflict detection
- `www/includes/access_functions.inc.php` - Enhanced role checking
- `www/includes/ldap_functions.inc.php` - UUID support, improved searches

### User Management
- `www/manage/users/new.php` - Simplified system user creation
- `www/manage/users/index.php` - Enhanced access control
- `www/manage/organizations/users/add.php` - Improved organization user management

### Setup and Validation
- `www/setup/ldap.php` - Role group creation
- `www/setup/verify.php` - Enhanced validation
- `www/setup/run_checks.php` - Improved runtime checks

## Testing and Validation

### Functional Testing
- User creation and management
- Organization management
- Role-based access control

### Integration Testing
- LDAP operations
- Access control enforcement
- Error handling and validation

### Security Testing
- Role-based restrictions
- Access control bypass attempts
- Edge cases and error conditions 