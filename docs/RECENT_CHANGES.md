# Features and Capabilities

This document provides an overview of the key features and capabilities of LDAP User Manager.

---

## 🚀 **Core Features**

### 1. UUID-Based Identification System
**Status**: ✅ **Available**

**What It Provides:**
- Support for OpenLDAP's `entryUUID` operational attribute
- Secure, immutable identification for all LDAP entries
- Fallback support for legacy name-based lookups

**Key Functions:**
- `ldap_get_organization_by_uuid()`
- `ldap_get_user_by_uuid()`

**Usage:**
- URL parameters support `uuid=` (preferred) and legacy `org=`/`account_identifier=`
- All organization and user links use UUIDs when available

---

### 2. System User Management
**Status**: ✅ **Available**

**What It Provides:**
- Streamlined system user creation with essential fields only
- Auto-generation of `cn` from `givenname` + `sn`
- Auto-generation of `uid` from email address

**Configuration:**
```php
// Essential fields only for system users
$LDAP['user_optional_fields'] = [
    'cn', 'organization', 'description', 'telephoneNumber', 'labeledURI'
];
```

**Features:**
- Auto-generated Common Name and UID
- Simplified workflow for administrators
- Essential fields only for system users

---

### 3. Organization Address Handling
**Status**: ✅ **Available**

**What It Provides:**
- Single `postalAddress` attribute for complete address information
- Dynamic form generation based on configuration
- Respects required/optional field settings from configuration

**Address Format:**
```ldif
# Single composite attribute (standard schema)
postalAddress: 123 Main St$10001$New York$NY$USA
```

**Configuration:**
- Address fields generated from configuration
- Required/optional status controlled by configuration
- Uses standard LDAP attributes only

---

### 4. Role Management
**Status**: ✅ **Available**

**What It Provides:**
- Organization admin role placement under `ou=roles`
- Role hierarchy enforcement
- Automatic conflict detection and prevention

**Role Hierarchy:**
- `global_admin` = 100 (highest - can do everything)
- `maintainer` = 80 (high - can manage users and orgs)
- `org_admin` = 60 (medium - can manage their org)
- `user` = 10 (lowest - basic user)

**Conflict Prevention:**
- Automatic detection of conflicting role configurations
- Setup blocked if critical conflicts detected
- Runtime maintenance mode for configuration errors

---

### 5. Role Value Synchronization
**Status**: ✅ **Available**

**What It Provides:**
- Role values automatically default to group names
- Eliminates duplication between role values and group names
- Maintains full flexibility for custom configurations

**Default Behavior:**
```php
// Role values automatically sync to group names
$LDAP['admin_role'] = 'administrators';          // Defaults to admin_group_name
$LDAP['maintainer_role'] = 'maintainers';        // Defaults to maintainer_group_name
```

**Configuration Options:**
- Use synchronized defaults (recommended)
- Override with environment variables if needed
- System automatically prevents conflicts

---

### 6. Error Handling
**Status**: ✅ **Available**

**What It Provides:**
- Professional maintenance mode for configuration errors
- Clear error messages with step-by-step solutions
- Automatic conflict detection and prevention

**Error Handling:**
- Setup process validation
- Runtime conflict detection
- Professional maintenance pages
- Clear configuration instructions

---

## 🔧 **Configuration Examples**

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

---

## 📋 **System Architecture**

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

---

## 🎯 **Benefits**

- **Enhanced Security**: Role conflict prevention and enhanced access control
- **Better User Experience**: Simplified forms and professional error handling
- **Improved Reliability**: UUID-based identification and conflict detection
- **Configuration Flexibility**: Role synchronization with custom override options
- **Professional Error Handling**: Clear messages and maintenance mode

