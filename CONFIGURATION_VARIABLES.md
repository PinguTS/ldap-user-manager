# Configuration Variables Reference

This document lists all the configurable variables available in the LDAP User Manager system.

## Environment Variables

### LDAP Server Configuration
- `LDAP_URI` - LDAP server URI (required)
- `LDAP_BASE_DN` - Base DN for the LDAP tree (required)
- `LDAP_ADMIN_BIND_DN` - Admin bind DN for LDAP operations (required)
- `LDAP_ADMIN_BIND_PWD` - Admin bind password (required)
- `LDAP_REQUIRE_STARTTLS` - Whether to require STARTTLS (default: FALSE)
- `LDAP_IGNORE_CERT_ERRORS` - Whether to ignore SSL certificate errors (default: FALSE)

### Role Configuration
- `LDAP_ADMIN_ROLE` - Role name for system administrators (default: 'administrators' - same as admin group name)
- `LDAP_MAINTAINER_ROLE` - Role name for system maintainers (default: 'maintainers' - same as maintainer group name)
- `LDAP_ORG_ADMIN_ROLE` - Role name for organization administrators (default: 'org_admin')
- `LDAP_USER_ROLE` - Role name for regular users (default: 'user')

**Note**: Role values now default to group names by default to eliminate duplication. You can still override them with environment variables if needed.

### Group Configuration
- `LDAP_ADMIN_GROUP_NAME` - Group name for administrators (default: 'administrators')
- `LDAP_MAINTAINER_GROUP_NAME` - Group name for maintainers (default: 'maintainers')

### Display Labels (Localization)
- `LDAP_ADMIN_DISPLAY_LABEL` - Display label for admin role (default: 'System Administrator')
- `LDAP_MAINTAINER_DISPLAY_LABEL` - Display label for maintainer role (default: 'System Maintainer')
- `LDAP_ORG_ADMIN_DISPLAY_LABEL` - Display label for org admin role (default: 'Organization Administrator')
- `LDAP_USER_DISPLAY_LABEL` - Display label for user role (default: 'User')

### Error Messages (Localization)
- `LDAP_ERROR_MAINTAINER_CANNOT_DELETE_ADMIN` - Error message for maintainer deletion restriction (default: 'Maintainers cannot delete administrators')
- `LDAP_ERROR_MAINTAINER_CANNOT_CREATE_ADMIN` - Error message for maintainer creation restriction (default: 'Maintainers cannot create users with administrator roles')
- `LDAP_ERROR_CANNOT_DELETE_SELF` - Error message for self-deletion prevention (default: 'You cannot delete your own account')

### Role Hierarchy and Conflict Prevention
- `LDAP_PREVENT_ROLE_CONFLICTS` - Whether to prevent role configuration conflicts (default: 'TRUE')
- **Role Hierarchy Levels** (built-in, not configurable):
  - `global_admin` = 100 (highest - can do everything)
  - `maintainer` = 80 (high - can manage users and orgs)
  - `org_admin` = 60 (medium - can manage their org)
  - `user` = 10 (lowest - basic user)

**⚠️ Role Conflict Detection**: The system automatically detects and prevents these configuration errors:
- Admin and Maintainer roles set to the same value

**When conflicts are detected**:
1. **Setup Process**: Configuration validation blocks setup completion
2. **Runtime Operation**: System goes into maintenance mode
3. **Maintenance Mode**: Professional error page with clear instructions
4. **Security Protection**: System cannot operate with broken access control

### LDAP Structure
- `LDAP_GROUP_OU` - Groups organizational unit (default: 'groups')
- `LDAP_USER_OU` - Users organizational unit (default: 'people')
- `LDAP_ORG_OU` - Organizations organizational unit (default: 'organizations')
- `LDAP_ACCOUNT_ATTRIBUTE` - Primary account identifier attribute (default: 'mail')
- `LDAP_GROUP_ATTRIBUTE` - Group identifier attribute (default: 'cn')

### User Account Configuration
- `DEFAULT_USER_GROUP` - Default group for new users (default: 'everybody')
- `DEFAULT_USER_SHELL` - Default shell for new users (default: '/bin/bash')
- `ENFORCE_SAFE_SYSTEM_NAMES` - Whether to enforce safe system names (default: TRUE)
- `USERNAME_FORMAT` - Username format template (default: '{first_name}-{last_name}')
- `USERNAME_REGEX` - Username validation regex (default: '^[a-z][a-zA-Z0-9\._-]{3,32}$')
- `PASSWORD_HASH` - Password hashing algorithm (default: 'SSHA')
- `ACCEPT_WEAK_PASSWORDS` - Whether to accept weak passwords (default: FALSE)
- `SHOW_POSIX_ATTRIBUTES` - Whether to show POSIX attributes (default: FALSE)

### Site Configuration
- `ORGANISATION_NAME` - Organization name (default: 'LDAP')
- `SITE_NAME` - Site name (default: '{ORGANISATION_NAME} user manager')
- `SITE_LOGIN_LDAP_ATTRIBUTE` - LDAP attribute for login (default: 'mail')
- `SITE_LOGIN_FIELD_LABEL` - Login field label (default: 'Email')
- `SERVER_HOSTNAME` - Server hostname (default: 'ldapusermanager.org')
- `SERVER_PATH` - Server path (default: '/')
- `SESSION_TIMEOUT` - Session timeout in minutes (default: 60)
- `NO_HTTPS` - Whether HTTPS is disabled (default: FALSE)

### Email Configuration
- `SMTP_HOSTNAME` - SMTP server hostname
- `SMTP_USERNAME` - SMTP username
- `SMTP_PASSWORD` - SMTP password
- `SMTP_HOST_PORT` - SMTP port (default: 25)
- `SMTP_HELO_HOST` - SMTP HELO hostname
- `SMTP_USE_SSL` - Whether to use SSL (default: FALSE)
- `SMTP_USE_TLS` - Whether to use TLS (default: FALSE)
- `EMAIL_DOMAIN` - Default email domain
- `EMAIL_FROM_ADDRESS` - From email address
- `EMAIL_FROM_NAME` - From email name
- `PHPMailer_PATH` - PHPMailer installation path (default: '/opt/PHPMailer/src')

### Account Requests
- `ACCOUNT_REQUESTS_ENABLED` - Whether account requests are enabled (default: FALSE)
- `ACCOUNT_REQUESTS_EMAIL` - Email for account requests

### Debugging
- `LDAP_DEBUG` - Enable LDAP debugging (default: FALSE)
- `LDAP_VERBOSE_CONNECTION_LOGS` - Enable verbose LDAP connection logging (default: FALSE)
- `SESSION_DEBUG` - Enable session debugging (default: FALSE)
- `SETUP_DEBUG` - Enable setup debugging (default: FALSE)
- `SMTP_LOG_LEVEL` - SMTP logging level (default: 0)
- `ENVIRONMENT` - Environment (development/test/production)

### File Upload
- `FILE_UPLOAD_MAX_SIZE` - Maximum file upload size in bytes (default: 2MB)
- `FILE_UPLOAD_ALLOWED_MIME_TYPES` - Comma-separated list of allowed MIME types

## Configuration Arrays

### Role Display Labels
```php
$LDAP['role_display_labels'] = [
    'admin_role' => 'System Administrator',
    'maintainer_role' => 'System Maintainer',
    'org_admin_role' => 'Organization Administrator',
    'user_role' => 'User'
];
```

### Error Messages
```php
$LDAP['error_messages'] = [
    'maintainer_cannot_delete_admin' => 'Maintainers cannot delete administrators',
    'maintainer_cannot_create_admin' => 'Maintainers cannot create users with administrator roles',
    'cannot_delete_self' => 'You cannot delete your own account'
];
```

### User Field Configuration
```php
$LDAP['user_required_fields'] = ['uid', 'givenname', 'sn', 'mail'];
$LDAP['user_optional_fields'] = ['cn', 'organization', 'description', 'telephoneNumber', 'labeledURI'];
$LDAP['user_field_mappings'] = [
    'first_name' => 'givenname',
    'last_name' => 'sn',
    'email' => 'mail',
    'common_name' => 'cn',
    'uid' => 'uid',
    'organization' => 'organization',
    'user_role' => 'description',
    'phone' => 'telephoneNumber',
    'website' => 'labeledURI'
];
```

### Organization Field Configuration
```php
$LDAP['org_required_fields'] = ['o'];
$LDAP['org_optional_fields'] = ['telephoneNumber', 'labeledURI', 'mail', 'description', 'businessCategory', 'postalAddress'];
$LDAP['org_field_mappings'] = [
    'org_name' => 'o',
    'org_phone' => 'telephoneNumber',
    'org_website' => 'labeledURI',
    'org_email' => 'mail',
    'org_description' => 'description',
    'org_category' => 'businessCategory'
];
```

## Usage Examples

### In PHP Files
```php
// Use configurable role names
if ($user_role === $LDAP['admin_role']) {
    // Handle admin user
}

// Use configurable display labels
echo $LDAP['role_display_labels']['admin_role']; // Outputs: "System Administrator"

// Use configurable error messages
echo $LDAP['error_messages']['maintainer_cannot_delete_admin'];
```

### In Environment Files
```bash
# .env file example
LDAP_ADMIN_ROLE=superuser
LDAP_MAINTAINER_ROLE=tech_support
LDAP_ADMIN_DISPLAY_LABEL=Super User
LDAP_MAINTAINER_DISPLAY_LABEL=Technical Support
LDAP_ERROR_MAINTAINER_CANNOT_DELETE_ADMIN=Technical support cannot delete super users
```

## Benefits of Configuration

1. **Localization**: Easy to translate role names and error messages
2. **Customization**: Organizations can use their own role naming conventions
3. **Maintenance**: Centralized configuration makes updates easier
4. **Flexibility**: Different environments can have different configurations
5. **Standards Compliance**: Easy to adapt to different LDAP schemas

## Role Configuration Best Practices

### ⚠️ **Critical: Avoid Role Conflicts**

**NEVER** set different role variables to the same value:
```bash
# ❌ WRONG - This configuration will break access control!
LDAP_ADMIN_ROLE=admin
LDAP_ORG_ADMIN_ROLE=admin

# ✅ CORRECT - Each role has a unique value
LDAP_ADMIN_ROLE=superuser
LDAP_ORG_ADMIN_ROLE=org_manager
```

### **Why Role Conflicts Break Access Control:**

1. **Login Logic Failure**: The system checks global admin first, then org admin
2. **Privilege Escalation**: Users might get unintended access levels
3. **Security Vulnerabilities**: Role-based restrictions become unreliable
4. **Debugging Nightmares**: Hard to trace permission issues

### **Safe Role Configuration Examples:**

```bash
# Example 1: Descriptive names
LDAP_ADMIN_ROLE=superuser
LDAP_MAINTAINER_ROLE=tech_support
LDAP_ORG_ADMIN_ROLE=org_manager
LDAP_USER_ROLE=member

# Example 2: Abbreviated names
LDAP_ADMIN_ROLE=admin
LDAP_MAINTAINER_ROLE=maint
LDAP_ORG_ADMIN_ROLE=org_admin
LDAP_USER_ROLE=user

# Example 3: Role-based names
LDAP_ADMIN_ROLE=global_admin
LDAP_MAINTAINER_ROLE=system_maintainer
LDAP_ORG_ADMIN_ROLE=organization_admin
LDAP_USER_ROLE=regular_user
```

### **Role Hierarchy Rules:**

1. **Global Admin** (100) - Can do everything, overrides all other roles
2. **Maintainer** (80) - Can manage users and organizations, overrides org admin
3. **Organization Admin** (60) - Can manage their own organization only
4. **User** (10) - Basic user with minimal privileges

### **Access Control Logic:**

```php
// The system automatically handles role conflicts:
if ($is_admin) {
    // Global admin overrides all other roles
    $is_maintainer = false;
    $is_org_admin = false;
} elseif ($is_maintainer) {
    // Maintainer overrides org admin
    $is_org_admin = false;
}
```

## Migration Notes

When upgrading from older versions:
1. New configuration variables have sensible defaults
2. Existing functionality will continue to work
3. Gradually replace hardcoded strings with configuration variables
4. Test thoroughly after making configuration changes
