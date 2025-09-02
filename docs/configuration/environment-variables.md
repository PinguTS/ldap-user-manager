# Configuration Variables Reference

This document explains all the configurable settings available in the LDAP User Manager system. These settings allow you to customize the system for your organization's needs without modifying any code.

## Environment Variables

### LDAP Server Configuration
These settings connect the system to your LDAP directory server:
- `LDAP_URI` - LDAP server address (required)
  - **Default**: `ldaps://ldap-server:636` (LDAPS for Docker setup)
  - **Development**: `ldap://localhost:389` (plain LDAP)
  - **Production**: `ldaps://your-ldap-server.com:636` (LDAPS)
- `LDAP_BASE_DN` - Base directory path in your LDAP tree (required)
- `LDAP_ADMIN_BIND_DN` - Administrator account for LDAP operations (required)
- `LDAP_ADMIN_BIND_PWD` - Administrator password (required)
- `LDAP_REQUIRE_STARTTLS` - Whether to require encrypted connections (default: FALSE)
- `LDAP_IGNORE_CERT_ERRORS` - Whether to ignore SSL certificate errors (default: TRUE for development)

### Password Security Configuration
These settings control how strong passwords must be in your system:

#### **Password Strength Requirements**
- `PASSWORD_STRENGTH_MIN_SCORE` - Minimum password strength level (default: 2)
  - **0**: Very Weak (any password accepted)
  - **1**: Weak (basic passwords allowed)
  - **2**: Fair (moderate security required) ← **Recommended for most organizations**
  - **3**: Good (strong security required)
  - **4**: Strong (excellent security required)

- `PASSWORD_STRENGTH_MIN_LENGTH` - Minimum password length (default: 8 characters)
- `PASSWORD_STRENGTH_REQUIRE_UPPERCASE` - Require capital letters (default: TRUE)
- `PASSWORD_STRENGTH_REQUIRE_LOWERCASE` - Require small letters (default: TRUE)
- `PASSWORD_STRENGTH_REQUIRE_NUMBERS` - Require numbers (default: TRUE)
- `PASSWORD_STRENGTH_REQUIRE_SYMBOLS` - Require special characters (default: FALSE)

#### **Legacy Password Settings**
- `PASSWORD_HASH` - Password encryption method (default: 'SSHA')
- `ACCEPT_WEAK_PASSWORDS` - Allow very weak passwords (default: FALSE)
  - **Note**: If set to TRUE, this overrides the minimum score requirement

#### **Password Configuration Examples**

**Development/Testing Environment (Lenient):**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=0      # Allow any password
export PASSWORD_STRENGTH_MIN_LENGTH=4     # Minimum 4 characters
export ACCEPT_WEAK_PASSWORDS=TRUE        # Allow very weak passwords
```

**Production Environment (Strict):**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=3      # Require Good or higher
export PASSWORD_STRENGTH_MIN_LENGTH=12    # Minimum 12 characters
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=TRUE  # Require special characters
```

**Balanced Environment (Recommended):**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=2      # Require Fair or higher
export PASSWORD_STRENGTH_MIN_LENGTH=8     # Minimum 8 characters
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
```

### Role Configuration
These settings define the names of different user roles in your system:
- `LDAP_ADMIN_ROLE` - Role name for system administrators (default: 'administrators')
- `LDAP_MAINTAINER_ROLE` - Role name for system maintainers (default: 'maintainers')
- `LDAP_ORG_ADMIN_ROLE` - Role name for organization administrators (default: 'org_admin')
- `LDAP_USER_ROLE` - Role name for regular users (default: 'user')

### Display Labels (Localization)
These settings control how role names appear in the user interface:
- `LDAP_ADMIN_DISPLAY_LABEL` - Display label for admin role (default: 'System Administrator')
- `LDAP_MAINTAINER_DISPLAY_LABEL` - Display label for maintainer role (default: 'System Maintainer')
- `LDAP_ORG_ADMIN_DISPLAY_LABEL` - Display label for org admin role (default: 'Organization Administrator')
- `LDAP_USER_DISPLAY_LABEL` - Display label for user role (default: 'User')

### Error Messages (Localization)
These settings control the text of error messages shown to users:
- `LDAP_ERROR_MAINTAINER_CANNOT_DELETE_ADMIN` - Error message for maintainer deletion restriction (default: 'Maintainers cannot delete administrators')
- `LDAP_ERROR_MAINTAINER_CANNOT_CREATE_ADMIN` - Error message for maintainer creation restriction (default: 'Maintainers cannot create users with administrator roles')
- `LDAP_ERROR_CANNOT_DELETE_SELF` - Error message for self-deletion prevention (default: 'You cannot delete your own account')

### Role Hierarchy and Access Control
The system automatically manages user permissions based on these built-in role levels:
- **Global Administrator** (Level 100) - Can do everything in the system
- **System Maintainer** (Level 80) - Can manage users and organizations
- **Organization Administrator** (Level 60) - Can manage their own organization only
- **Regular User** (Level 10) - Basic user with minimal privileges

**Important**: The system automatically prevents role conflicts that could break access control.

### LDAP Structure
These settings define the organization of your LDAP directory:
- `LDAP_GROUP_OU` - Groups organizational unit (default: 'groups')
- `LDAP_USER_OU` - Users organizational unit (default: 'people')
- `LDAP_ORG_OU` - Organizations organizational unit (default: 'organizations')
- `LDAP_ACCOUNT_ATTRIBUTE` - Primary account identifier attribute (default: 'mail')
- `LDAP_GROUP_ATTRIBUTE` - Group identifier attribute (default: 'cn')

### Security Configuration
These settings control security features and access control:

#### **Session Security**
- `SESSION_TIMEOUT` - Session timeout in seconds (default: 3600 - 1 hour)
- `SESSION_REGENERATE_ID` - Regenerate session ID on login (default: TRUE)
- `SESSION_SECURE_COOKIES` - Use secure cookies (default: TRUE)
- `SESSION_HTTP_ONLY` - HTTP-only cookies (default: TRUE)
- `SESSION_SAME_SITE` - SameSite cookie policy (default: 'strict')

#### **Rate Limiting**
- `RATE_LIMIT_MAX_ATTEMPTS` - Maximum login attempts (default: 5)
- `RATE_LIMIT_TIME_WINDOW` - Time window for attempts in seconds (default: 300 - 5 minutes)
- `RATE_LIMIT_LOCKOUT_DURATION` - Lockout duration in seconds (default: 900 - 15 minutes)

#### **File Upload Security**
- `FILE_UPLOAD_MAX_SIZE` - Maximum file upload size in bytes (default: 2097152 - 2MB)
- `FILE_UPLOAD_ALLOWED_TYPES` - Comma-separated list of allowed MIME types (default: 'image/jpeg,image/png,image/gif,application/pdf,text/plain')
- `FILE_UPLOAD_SCAN_VIRUS` - Enable virus scanning (default: FALSE)

#### **Security Headers**
The system automatically sets security headers:
- `X-Frame-Options: DENY` - Prevent clickjacking
- `X-Content-Type-Options: nosniff` - Prevent MIME type sniffing
- `X-XSS-Protection: 1; mode=block` - XSS protection
- `Referrer-Policy: strict-origin-when-cross-origin` - Control referrer information
- `Content-Security-Policy` - Restrict resource loading
- `Strict-Transport-Security` - Enforce HTTPS (when enabled)

#### **Audit Logging**
- `AUDIT_LOG_ENABLED` - Enable audit logging (default: TRUE)
- `AUDIT_LOG_LEVEL` - Log level (DEBUG, INFO, WARN, ERROR) (default: INFO)
- `AUDIT_LOG_FILE` - Audit log file path (default: '/var/log/ldap_user_manager/audit.log')

### User Account Configuration
These settings control how new user accounts are created:
- `DEFAULT_USER_GROUP` - Default group for new users (default: 'everybody')
- `DEFAULT_USER_SHELL` - Default shell for new users (default: '/bin/bash')
- `ENFORCE_SAFE_SYSTEM_NAMES` - Whether to enforce safe system names (default: TRUE)
- `USERNAME_FORMAT` - Username format template (default: '{first_name}-{last_name}')
- `USERNAME_REGEX` - Username validation rules (default: '^[a-z][a-zA-Z0-9\._-]{3,32}$')
- `SHOW_POSIX_ATTRIBUTES` - Whether to show POSIX attributes (default: FALSE)

### Site Configuration
These settings control the appearance and behavior of your website:
- `ORGANISATION_NAME` - Organization name (default: 'LDAP')
- `SITE_NAME` - Site name (default: '{ORGANISATION_NAME} user manager')
- `SITE_LOGIN_LDAP_ATTRIBUTE` - LDAP attribute for login (default: 'mail')
- `SITE_LOGIN_FIELD_LABEL` - Login field label (default: 'Email')
- `SERVER_HOSTNAME` - Server hostname (default: 'ldapusermanager.org')
- `SERVER_PATH` - Server path (default: '/')
- `SESSION_TIMEOUT` - Session timeout in minutes (default: 60)
- `NO_HTTPS` - Whether HTTPS is disabled (default: FALSE)

### Email Configuration
These settings control how the system sends emails:
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
These settings control whether users can request new accounts:
- `ACCOUNT_REQUESTS_ENABLED` - Whether account requests are enabled (default: FALSE)
- `ACCOUNT_REQUESTS_EMAIL` - Email for account requests

### Debugging and Troubleshooting
These settings help diagnose problems:
- `LDAP_DEBUG` - Enable LDAP debugging (default: FALSE)
- `LDAP_VERBOSE_CONNECTION_LOGS` - Enable verbose LDAP connection logging (default: FALSE)
- `SESSION_DEBUG` - Enable session debugging (default: FALSE)
- `SETUP_DEBUG` - Enable setup debugging (default: FALSE)
- `SMTP_LOG_LEVEL` - SMTP logging level (default: 0)
- `ENVIRONMENT` - Environment (development/test/production)

### File Upload
These settings control file upload limits:
- `FILE_UPLOAD_MAX_SIZE` - Maximum file upload size in bytes (default: 2MB)
- `FILE_UPLOAD_ALLOWED_MIME_TYPES` - Comma-separated list of allowed file types

## Configuration Examples

### Docker Environment
```yaml
# docker-compose.yml
version: '3.8'
services:
  ldap-user-manager:
    environment:
      # LDAP Configuration
      - LDAP_URI=ldap://ldap.example.com:389
      - LDAP_BASE_DN=dc=example,dc=com
      - LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com
      - LDAP_ADMIN_BIND_PWD=admin_password
      
      # Password Security
      - PASSWORD_STRENGTH_MIN_SCORE=2
      - PASSWORD_STRENGTH_MIN_LENGTH=8
      - PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
      - PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
      - PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
      - PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
      - ACCEPT_WEAK_PASSWORDS=FALSE
      
      # Role Configuration
      - LDAP_ADMIN_ROLE=superuser
      - LDAP_MAINTAINER_ROLE=tech_support
      - LDAP_ORG_ADMIN_ROLE=org_manager
      - LDAP_USER_ROLE=member
      
      # Site Configuration
      - ORGANISATION_NAME=Example Corp
      - SITE_NAME=Example Corp User Manager
      - SESSION_TIMEOUT=120
```

### Environment File (.env)
```bash
# LDAP Server
LDAP_URI=ldap://ldap.example.com:389
LDAP_BASE_DN=dc=example,dc=com
LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com
LDAP_ADMIN_BIND_PWD=admin_password

# Password Security
PASSWORD_STRENGTH_MIN_SCORE=2
PASSWORD_STRENGTH_MIN_LENGTH=8
PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
ACCEPT_WEAK_PASSWORDS=FALSE

# Roles
LDAP_ADMIN_ROLE=superuser
LDAP_MAINTAINER_ROLE=tech_support
LDAP_ORG_ADMIN_ROLE=org_manager
LDAP_USER_ROLE=member

# Site
ORGANISATION_NAME=Example Corp
SITE_NAME=Example Corp User Manager
SESSION_TIMEOUT=120
```

## Best Practices

### Password Security
1. **Production Environments**: Use minimum score 2 or higher
2. **Development/Testing**: Use score 0 or 1 for easier testing
3. **Length Requirements**: Minimum 8 characters for production, 4-6 for testing
4. **Character Requirements**: Require mixed case and numbers for production

### Role Configuration
1. **Unique Names**: Each role should have a different name
2. **Descriptive Labels**: Use clear, understandable role names
3. **Consistent Naming**: Follow a consistent pattern across your organization
4. **Security First**: Don't compromise security for convenience

### Environment Management
1. **Separate Configurations**: Use different settings for development, testing, and production
2. **Version Control**: Keep configuration files in version control
3. **Documentation**: Document your configuration choices
4. **Testing**: Test configuration changes in a safe environment first

## Troubleshooting

### Common Issues
1. **Password Rejection**: Check password strength requirements
2. **Login Problems**: Verify LDAP connection settings
3. **Role Access Issues**: Check role configuration for conflicts
4. **Email Problems**: Verify SMTP settings

### Getting Help
1. Check the error logs for specific error messages
2. Verify all required environment variables are set
3. Test LDAP connectivity separately
4. Review the configuration examples above

## Security Considerations

### Production Security
- Use strong password requirements (score 2+)
- Require minimum 8-12 characters
- Enable HTTPS (set NO_HTTPS=FALSE)
- Use secure LDAP connections
- Regular security updates

### Development Security
- Relaxed password requirements for testing
- Debug logging enabled
- Development-specific role names
- Test environment isolation

This configuration system allows you to customize the LDAP User Manager for your organization's specific needs while maintaining security and functionality.
