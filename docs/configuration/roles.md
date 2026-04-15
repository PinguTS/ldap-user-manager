# Role Configuration Guide

This document explains how to properly configure roles and access control for the LDAP User Manager system.

## Role Configuration Overview

The system uses role-based access control with four role levels:

- **Global Administrators**: Full system access
- **System Maintainers**: Can manage organizations and users
- **Organization Administrators**: Manage users within their organization only
- **Regular Users**: Self-service account management (e.g. change own password)

## Configuration Variables

### Role Values

All four role names define the LDAP group CNs under `ou=roles` (global roles) or under each organization's `ou=roles` (org admin). They must be unique.

```bash
LDAP_ADMIN_ROLE=administrators      # Global administrators (default)
LDAP_MAINTAINER_ROLE=maintainers    # Global maintainers (default)
LDAP_ORG_ADMIN_ROLE=org_admin       # Organization administrators (default)
LDAP_USER_ROLE=user                 # Regular users (default)
```

## Important Configuration Rules

### Rule 1: Different Role Values

Role values must be different from each other:

```bash
# CORRECT - Different values
LDAP_ADMIN_ROLE=administrator
LDAP_MAINTAINER_ROLE=maintainer

# INCORRECT - Same values
LDAP_ADMIN_ROLE=admin
LDAP_MAINTAINER_ROLE=admin
```

## Recommended Configuration

Use the default values unless you have a specific reason to change them. The defaults are:

```bash
LDAP_ADMIN_ROLE=administrators
LDAP_MAINTAINER_ROLE=maintainers
LDAP_ORG_ADMIN_ROLE=org_admin
LDAP_USER_ROLE=user
```

## Configuration Validation

The system automatically validates your configuration:

1. **Setup Prevention**: Blocks setup completion if conflicts detected
2. **Runtime Checks**: Activates maintenance mode if conflicts found
3. **Clear Error Messages**: Shows exactly what is wrong and how to fix it

## Configuration Examples

### Simple Setup

```bash
# All values use defaults — no configuration required
```

### Production Environment

```bash
LDAP_ADMIN_ROLE=administrators
LDAP_MAINTAINER_ROLE=maintainers
LDAP_ORG_ADMIN_ROLE=organization_administrators
LDAP_USER_ROLE=standard_user
```

### Development Environment

```bash
LDAP_ADMIN_ROLE=dev_admins
LDAP_MAINTAINER_ROLE=dev_maintainers
LDAP_ORG_ADMIN_ROLE=org_admin
LDAP_USER_ROLE=user
```

## Security Considerations

- **Role Separation**: Keep administrator and maintainer roles distinct
- **Access Control**: Maintainers cannot manage administrator accounts
- **Self-Protection**: Users cannot delete their own accounts

## Troubleshooting

### Setup Won't Complete

- Check for duplicate role values

### Maintenance Mode Active

- Review your configuration variables
- Fix any conflicts
- Restart the application

### Access Control Issues

- Verify role values match your LDAP structure
- Ensure proper role hierarchy

## Related Documentation

- [Environment Variables](environment-variables.md) — Complete configuration reference
- [LDAP Structure](../ldap-structure.md) — Directory structure and role groups
- [Security Best Practices](../security/best-practices.md) — Security recommendations
