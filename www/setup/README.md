# LDAP User Manager Setup Module

This directory contains the setup module for LDAP User Manager.

## Setup Process

The setup process follows these steps:

1. **Initial Login** (`index.php`) - Authenticate with LDAP admin credentials
2. **System Checks** (`run_checks.php`) - Verify LDAP structure and identify missing components
3. **LDAP Setup** (`ldap.php`) - Create missing OUs, users, and roles
4. **Automatic Verification** - Built-in verification runs automatically after setup completion
5. **Manual Verification** (`verify.php`) - Optional comprehensive testing (available after setup)

## What Gets Created

### Organizational Units
- `ou=organizations,dc=example,dc=com` - Container for organizations
- `ou=people,dc=example,dc=com` - Container for system users (admin, maintainer)
- `ou=roles,dc=example,dc=com` - Container for global system roles

### System Users
- **Administrator**: `uid=admin@example.com,ou=people,dc=example,dc=com`
  - Full system administrator privileges
  - Member of `cn=administrators` group
- **Maintainer**: `uid=maintainer@example.com,ou=people,dc=example,dc=com`
  - System maintainer with limited privileges
  - Member of `cn=maintainers` group

### Role Groups
- **Administrators**: `cn=administrators,ou=roles,dc=example,dc=com`
  - Full access to all LDAP operations
  - Contains admin user as member
- **Maintainers**: `cn=maintainers,ou=roles,dc=example,dc=com`
  - Limited access (can manage org users but not admin users)
  - Contains maintainer user as member

## Setup Verification

### Automatic Verification
During the setup process, the system automatically:
- Verifies that admin and maintainer users are properly created
- Ensures users are correctly added to their respective role groups
- Reports any issues and attempts to fix them automatically
- Provides a summary of the verification results

### Manual Verification
The `verify.php` script performs comprehensive testing:

1. **OU Verification** - Checks all required organizational units exist
2. **User Verification** - Confirms system users are created correctly
3. **Role Verification** - Ensures role groups exist and have proper members
4. **Membership Verification** - Confirms users are properly added to their roles
5. **Authentication Test** - Validates user entries are readable and valid

## Troubleshooting

### Common Issues
- **Missing OUs**: The setup will automatically create missing organizational units
- **User Creation Failures**: Check LDAP connection and admin credentials
- **Role Membership Issues**: Use the verification script to identify and fix problems
- **Permission Errors**: Ensure the setup is run with proper LDAP admin access

### Manual Verification
If the web setup fails, you can manually verify the LDAP structure:

```bash
# Check if OUs exist
ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123 "(objectClass=organizationalUnit)"

# Check if users exist
ldapsearch -x -b ou=people,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123 "(objectClass=inetOrgPerson)"

# Check if roles exist
ldapsearch -x -b ou=roles,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123 "(objectClass=groupOfNames)"
```

## Security Notes

- Default passwords are set during setup (admin123, maintainer123)
- **Change these passwords immediately** after setup completion
- The setup process requires LDAP admin credentials
- All LDAP operations are logged for audit purposes 