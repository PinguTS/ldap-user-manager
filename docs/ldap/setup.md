# LDAP Configuration for LDAP User Manager

This document describes all LDAP schema and configuration requirements for running LDAP User Manager, including required schemas and configuration details.

---

## 1. Required LDAP Schemas

LDAP User Manager requires the following standard OpenLDAP schemas:

- `core`
- `cosine`
- `inetorgperson`
- `organization`
- `locality`

These are typically included by default in most OpenLDAP installations.

**IMPORTANT**: LDAP User Manager uses existing LDAP attributes to ensure maximum compatibility with any LDAP server.

### 1.1 Current Implementation

**Approach**: Use existing LDAP attributes that are available in all standard LDAP schemas.

**Implementation**: The application stores role information in the `description` attribute.

**Features**:
- Works with any LDAP server
- No schema modifications required
- Standard LDAP compliance
- Reliable and tested

---

## 2. LDAP Structure

### 2.1 Base Structure
The system uses a hierarchical structure with organizations containing users and UUID-based identification:

```
dc=example,dc=com
├── ou=organizations
│   ├── o=Company Name                    # entryUUID: 550e8400-e29b-41d4-a716-446655440000
│   │   ├── ou=people                     # Organization users (same naming as system users)
│   │   │   ├── uid=admin@company.com
│   │   │   └── uid=user1@company.com
│   │   └── ou=roles                      # Organization-specific roles
│   │       └── cn=org_admin (groupOfNames with member attributes)
│   └── o=University Name                 # entryUUID: 550e8400-e29b-41d4-a716-446655440001
│       ├── ou=people
│       └── ou=roles
├── ou=people                             # System-level users (admins, maintainers)
│   ├── uid=admin@example.com            # entryUUID: 550e8400-e29b-41d4-a716-446655440002
│   └── uid=maintainer@example.com       # entryUUID: 550e8400-e29b-41d4-a716-446655440003
└── ou=roles                              # Global system roles only
    ├── cn=administrators (groupOfNames with member attributes)
    └── cn=maintainers (groupOfNames with member attributes)
```

### 2.2 Organization Attributes
Organizations use the standard `postalAddress` attribute in the format:
```
postalAddress: Street$City$State$ZIP$Country
```

### 2.3 Role Group Structure
Role groups use the `groupOfNames` object class and contain:

**Global Roles** (`ou=roles,dc=example,dc=com`):
```
dn: cn=administrators,ou=roles,dc=example,dc=com
objectClass: groupOfNames
cn: administrators
member: uid=admin@example.com,ou=people,dc=example,dc=com
```

**Organization Roles** (`ou=roles,o=OrgName,ou=organizations,dc=example,dc=com`):
```
dn: cn=org_admin,ou=roles,o=Company Name,ou=organizations,dc=example,dc=com
objectClass: groupOfNames
cn: org_admin
member: uid=admin@company.com,ou=users,o=Company Name,ou=organizations,dc=example,dc=com
```

### 2.4 User Attributes

#### System Users (ou=people)
System users have simplified attributes for administrators and maintainers:
- **Required**: `givenname`, `sn`, `mail`, `uid`
- **Auto-generated**: `cn` (constructed from `givenname` + `sn`)
- **Optional**: `telephoneNumber`, `labeledURI`
- **No address fields** - System users don't need location information

#### Organization Users (ou=people,o=OrgName)
Organization users have additional organizational context:
- **Required**: `givenname`, `sn`, `mail`, `uid`, `organization`, `description`
- **Auto-generated**: `cn` (constructed from `givenname` + `sn`)
- **Optional**: `telephoneNumber`, `labeledURI`
- **No address fields** - Address information is stored at organization level

#### Common Attributes
All users include:
- `userPassword`: stores the user's password
- `entryUUID`: OpenLDAP operational attribute for secure identification

**Note**: The application uses existing LDAP attributes for maximum compatibility.

---

## 3. UUID-Based Identification

### 3.1 Overview
LDAP User Manager uses OpenLDAP's `entryUUID` operational attribute for secure, immutable identification of entries.

**Features**:
- **Security**: UUIDs cannot be guessed or enumerated
- **Reliability**: UUIDs remain valid even if names change
- **Performance**: UUID lookups are faster than DN-based searches
- **Compatibility**: Works with any OpenLDAP server

### 3.2 Implementation
- **URL Parameters**: Use `uuid=` instead of name-based parameters when available
- **Fallback Support**: Maintains backward compatibility with name-based lookups
- **Automatic Generation**: OpenLDAP automatically generates `entryUUID` for all entries
- **Lookup Functions**: `ldap_get_organization_by_uuid()` and `ldap_get_user_by_uuid()`

### 3.3 Usage Examples
```php
// Modern UUID-based approach (preferred)
show_organization.php?uuid=550e8400-e29b-41d4-a716-446655440000

// Legacy name-based approach (fallback)
show_organization.php?org=Company%20Name
```

## 4. Role-Based Access Control

### 4.1 How Roles Work

**Roles are managed via LDAP groups, not user attributes:**

1. **Global roles** are stored in `ou=roles,dc=example,dc=com`
   - `cn=administrators` - System administrators
- `cn=maintainers` - System maintainers

2. **Organization roles** are stored in `ou=roles,o=OrgName,ou=organizations,dc=example,dc=com`
   - `cn=org_admin` - Organization administrators

3. **Users are added as members** to these role groups via the `member` attribute

4. **Role checking** is done by verifying group membership, not by reading user attributes

### 4.2 System Roles
- **Administrators**: Full access to all organizations, users, and settings
- **Maintainers**: Can manage all organizations and users, but cannot modify administrator accounts
- **Organization Managers**: Can manage users within their assigned organization(s)

### 4.3 Access Control Rules
- Administrators can modify anyone
- Maintainers can modify anyone except administrators
- Organization managers can only modify users in their organization
- Users can modify their own account

---

## 5. Account Disabling Implementation

### 5.1 Standard LDAP Account Disabling

LDAP User Manager implements account disabling using the **`pwdAccountLockedTime`** attribute (password policy / **ppolicy** in OpenLDAP).

**Disable value**

- **Standard value**: `000001010000Z` (January 1, 1970 00:00:00 UTC)

**OpenLDAP schema requirement**

`pwdAccountLockedTime` is **not** part of core `inetOrgPerson`. On OpenLDAP it comes from the **ppolicy** overlay: the module must be loaded, the overlay attached to your main database, and a **default password policy** configured (e.g. `olcPPolicyDefault`). Until that is in place, modifying user entries can produce **`Undefined attribute type`**.

**Docker: osixia/openldap (default in this repository’s `docker-compose.yml`)**

Ppolicy works with **osixia** once the module and overlay are applied. Prefer:

- **`LDAP_BACKEND_OVERLAY_PPOLICY=true`** (Docker Compose: `"true"`) — supported on current **osixia/openldap** images for automatic ppolicy overlay; behaviour can depend on image tag (see root `docker-compose.yml`).

**Do not** enable this **and** mount **`06-ppolicy.ldif`** — the overlay would be added twice.

**Fallback** if your tag does not honour that variable: mount `docker/openldap/bootstrap/ldif/custom/06-ppolicy.ldif` → `/container/service/slapd/assets/config/bootstrap/ldif/custom` with **`--copy-service`** (first init only, empty `slapd.d`), or **`ldapmodify` on `cn=config`**.

**Docker: Bitnami OpenLDAP (ppolicy via env, no repo LDIF)**

As an alternative, **Bitnami** can turn on ppolicy using only environment variables:

- **`LDAP_CONFIGURE_PPOLICY=yes`**
- Optional: **`LDAP_PPOLICY_USE_LOCKOUT=yes`**, **`LDAP_PPOLICY_HASH_CLEARTEXT=yes`**

See the [Bitnami OpenLDAP README](https://github.com/bitnami/containers/blob/main/bitnami/openldap/README.md) for ports (`LDAP_PORT_NUMBER` often **1389** inside the container), `LDAP_ROOT`, `LDAP_ADMIN_USERNAME`, and TLS.

**Example LDIF (disabled entries):**
```ldif
# Disabled user account
dn: uid=user@org.com,ou=people,o=OrgName,ou=organizations,dc=example,dc=com
objectClass: inetOrgPerson
objectClass: top
uid: user@org.com
cn: User Name
sn: Name
givenName: User
mail: user@org.com
pwdAccountLockedTime: 000001010000Z  # Account is disabled
userPassword: {SSHA}hashed_password

# Disabled organization
dn: o=OrgName,ou=organizations,dc=example,dc=com
objectClass: top
objectClass: organization
objectClass: extensibleObject
o: OrgName
pwdAccountLockedTime: 000001010000Z  # Organization is disabled
```

### 5.2 Account Disabling Features

**User Account Disabling:**
- **Individual Disable**: Disable specific user accounts
- **Permission-Based**: Only authorized users can disable/enable accounts
- **Self-Protection**: Users cannot disable their own accounts
- **Role Restrictions**: Maintainers cannot disable administrator accounts

**Organization Disabling:**
- **Bulk Disable**: Disable entire organizations and all their users
- **Cascade Effect**: When an organization is disabled, all users are automatically disabled
- **Permission-Based**: Only global admins and maintainers can disable organizations
- **Self-Protection**: Organization admins cannot disable their own organization

**Authentication Integration:**
- **Login Prevention**: Disabled accounts cannot authenticate
- **Clear Messages**: Users see specific error messages for disabled accounts
- **Audit Trail**: All disable/enable actions are logged

### 5.3 Access Control for Account Disabling

**User Disable/Enable Permissions:**
- **Global Administrators**: Can disable/enable any user account
- **Maintainers**: Can disable/enable users (except administrators)
- **Organization Administrators**: Can disable/enable users in their organization only

**Organization Disable/Enable Permissions:**
- **Global Administrators**: Can disable/enable any organization
- **Maintainers**: Can disable/enable any organization
- **Organization Administrators**: Cannot disable/enable their own organization

**Disable Status Viewing:**
- **Administrators, Maintainers, Organization Admins**: Can view disable status
- **Regular Users**: Cannot view disable status of other accounts

### 5.4 Technical Implementation

**LDAP Functions:**
```php
// Check if user is disabled
ldap_user_is_disabled($ldap_connection, $user_dn)

// Check if organization is disabled
ldap_organization_is_disabled($ldap_connection, $org_name)

// Disable user account
ldap_disable_user_account($ldap_connection, $user_dn)

// Enable user account
ldap_enable_user_account($ldap_connection, $user_dn)

// Disable organization and all users
ldap_disable_organization($ldap_connection, $org_name)

// Enable organization and all users
ldap_enable_organization($ldap_connection, $org_name)
```

**Authentication Flow:**
1. User attempts login
2. System checks `pwdAccountLockedTime` attribute
3. If disabled, login is denied with clear error message
4. If organization is disabled, user login is also denied
5. Failed login attempts are logged for security

**Benefits:**
- **Standard practice on OpenLDAP**: Uses ppolicy’s `pwdAccountLockedTime` when the server provides it
- **Security enhancement**: Immediate access control without deletion
- **Audit Trail**: Complete logging of all disable/enable operations
- **Permission-Based**: Granular access control for disable operations
- **Reversible**: Easy to enable accounts when needed

---

## 6. Setup Process

### 6.1 Web-Based Setup (Recommended)

The LDAP User Manager includes a comprehensive web-based setup wizard that automatically creates all necessary LDAP structure:

1. **Access the setup wizard** at `/setup/` in your web browser
2. **The wizard will check** your LDAP directory and identify what needs to be created
3. **Automatically create** missing organizational units, users, and roles
4. **Set up initial administrator** account with proper permissions

**Benefits of web-based setup:**
- No external scripts required
- No root access needed
- Conditional creation (only creates what's missing)
- Better error handling and user feedback
- Integrated with the application workflow

**What the wizard creates automatically:**
- Base organizational units (organizations, system_users, roles)
- System administrator and maintainer users
- Administrator and maintainer roles with proper memberships
- Example organization (optional)

### 6.2 LDIF Files (Reference Only)

The LDIF files in the `ldif/` directory are provided for reference and advanced users who want to understand the LDAP structure. They are **not required** for normal operation since the web-based setup wizard handles everything automatically.

**Note**: LDIF files are provided for reference and manual setup. The web-based setup wizard handles everything automatically.

**Available LDIF files:**
- `ldif/base.ldif` - Base directory structure
- `ldif/system_users.ldif` - System user definitions
- `ldif/example-org.ldif` - Example organization structure

---

## 7. Configuration

### 7.1 Environment Variables
- `LDAP_ADMIN_ROLE`: administrator (default) - Controls both group name and role name
- `LDAP_MAINTAINER_ROLE`: maintainer (default) - Controls both group name and role name  
- `LDAP_ORG_ADMIN_ROLE`: org_admin (default) - Controls both group name and role name
- `LDAP_ORG_OU`: organizations (default)

### 7.2 LDAP Base DN
Ensure your `LDAP_BASE_DN` matches the structure in the LDIF files.

---

## 8. LDIF Files

### 8.1 Structure Files
- `ldif/base.ldif` - Base directory structure (organizations, system_users, roles OUs)
- `ldif/system_users.ldif` - System user accounts (administrator, maintainer)
- `ldif/example-org.ldif` - Example organization with users (optional)

### 8.2 Additional Schemas
- No custom schema files are needed. The system uses existing LDAP attributes like `description` for role information.

---

## 9. Troubleshooting

### 9.1 Common Errors

- **"attribute type undefined"**: The custom schema hasn't been loaded. Load `userRole-schema.ldif` first.
- **"no values for attribute type"**: `groupOfNames` object class requires at least one member value.
- **"Protocol error"**: Usually indicates schema or structural issues.

### 9.2 Verification Commands

```bash
# Check if schema was loaded
ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=configuration -s base

# Verify base structure
ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_password

# Check system users
ldapsearch -x -b ou=people,dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_password
```

---

## 10. Docker Setup

### 10.1 Docker Setup Overview

The easiest way to set up LDAP in Docker is to use the web-based setup wizard:

1. **Start your LDAP container** with ppolicy enabled (**osixia:** `LDAP_BACKEND_OVERLAY_PPOLICY=true`, or LDIF fallback in §5.1; **Bitnami:** `LDAP_CONFIGURE_PPOLICY=yes`)
2. **Start the LDAP User Manager container**
3. **Access the setup wizard** at `/setup/` in your web browser
4. **The wizard will automatically** create all necessary LDAP structure

### 10.2 Manual Docker Setup

If you prefer to set up manually or use Docker Compose:

**Step 1a: osixia (`LDAP_BACKEND_OVERLAY_PPOLICY` — same idea as root `docker-compose.yml`)**

```bash
docker run --name ldap -d \
  -p 389:389 \
  -e LDAP_ORGANISATION="Example Org" \
  -e LDAP_DOMAIN="example.com" \
  -e LDAP_ADMIN_PASSWORD="admin" \
  -e LDAP_BACKEND="mdb" \
  -e LDAP_BACKEND_OVERLAY_PPOLICY=true \
  osixia/openldap:latest --copy-service
```

**Fallback — osixia with bootstrap LDIF only** (omit `LDAP_BACKEND_OVERLAY_PPOLICY` if you use this):

```bash
docker run --name ldap -d \
  -p 389:389 \
  -e LDAP_ORGANISATION="Example Org" \
  -e LDAP_DOMAIN="example.com" \
  -e LDAP_ADMIN_PASSWORD="admin" \
  -e LDAP_BACKEND="mdb" \
  -v "$(pwd)/docker/openldap/bootstrap/ldif/custom:/container/service/slapd/assets/config/bootstrap/ldif/custom:ro" \
  osixia/openldap:latest --copy-service
```

**Step 1b: Bitnami (ppolicy via env — no custom LDIF)**

```bash
docker run --name ldap -d \
  -p 389:1389 \
  -e LDAP_ROOT="dc=example,dc=com" \
  -e LDAP_ADMIN_USERNAME="admin" \
  -e LDAP_ADMIN_PASSWORD="adminpassword" \
  -e LDAP_CONFIGURE_PPOLICY=yes \
  bitnami/openldap:latest
```

**Step 2: Complete Setup**
```bash
# Connect to the LDAP container
docker exec -it ldap-server bash

# The LDAP server is now ready for the web-based setup wizard
# No manual LDIF loading is required
```

**Step 3: Complete Web-Based Setup**
Access the web interface at `/setup/` to:
- Verify LDAP connection
- Automatically create all necessary LDAP structure
- Set up initial administrator account and roles

**Note**: The web-based setup wizard handles everything automatically. No manual LDIF loading is required.

**Note**: The web-based setup wizard handles everything automatically. However, if you need a custom LDAP image for other reasons:

**Dockerfile for Custom LDAP Image**

```dockerfile
FROM osixia/openldap:latest

# Copy LDIF files for reference only
COPY ldif/ /ldif/

# Create startup script
RUN echo '#!/bin/bash\n\
# Wait for slapd to start\n\
sleep 10\n\
\n\
# Keep container running\n\
exec "$@"' > /startup.sh && chmod +x /startup.sh

# Override entrypoint
ENTRYPOINT ["/startup.sh"]
CMD ["/container/tool/run"]
```

**Note**: The LDIF files are copied for reference only. The web-based setup wizard will create all necessary LDAP structure automatically.

#### **Portainer-Specific Notes**

- **Networks**: Make sure both stacks use the same external network
- **Volumes**: Use named volumes for persistent data
- **Environment Variables**: Set sensitive values through Portainer's environment variable interface
- **Health Checks**: Monitor container health before proceeding with setup
- **Logs**: Check container logs for any errors during startup

#### **Verification Commands**

```bash
# Test LDAP connection from user manager container
docker exec -it ldap-user-manager ldapsearch -x -H ldap://ldap-server:389 -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123

# Confirm pwdAccountLockedTime exists in schema (ppolicy loaded)
docker exec -it ldap-server ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributetypes \
  | grep -i pwdAccountLockedTime || echo "ppolicy not active — check LDAP_BACKEND_OVERLAY_PPOLICY, LDAP_CONFIGURE_PPOLICY, or LDIF fallback"

# Verify users exist
docker exec -it ldap-server ldapsearch -x -b ou=people,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
```

---

## 11. Basic Diagnostics

### Diagnostic Commands

If you encounter issues, these commands can help diagnose the problem:

```bash
# Check LDAP server status
sudo systemctl status slapd

# Check slapd version
slapd -V

# Test basic connection
ldapsearch -x -H ldap://localhost:389 -b dc=example,dc=com

# Verify setup
ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_admin_password

# Check system users
ldapsearch -x -b ou=people,dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_admin_password
```

### Common Issues

1. **"Connection refused"** - LDAP server not running or wrong port
2. **"Invalid credentials"** - Check admin DN and password
3. **"No such object"** - Base structure not created, run the web-based setup wizard
4. **"attribute type undefined"** - Should not occur with current approach using existing attributes

### Getting Help

- **Check logs**: `sudo journalctl -u slapd -f`
- **Enable debug mode**: Add `loglevel 256` to slapd configuration
- **Community resources**: [OpenLDAP Documentation](https://www.openldap.org/doc/)

---

## 12. See Also

- [LDAP Examples](examples.md) - Sample LDIF files and loading instructions
- [Documentation Hub](../README.md) for general setup and environment variables
- [LDAP Structure](../ldap-structure.md) for detailed LDAP structure examples

The system supports configurable organization fields through environment variables. You can customize which LDAP attributes are treated as required or optional when creating/editing organizations.

### Environment Variables

```bash
# Required fields for organization creation (comma-separated LDAP attributes)
export LDAP_ORG_REQUIRED_FIELDS="o,telephoneNumber,labeledURI,mail"

# Optional fields for organization creation (comma-separated LDAP attributes)
export LDAP_ORG_OPTIONAL_FIELDS="description,businessCategory,postalAddress,facsimileTelephoneNumber"

# Customize the organizations OU name
export LDAP_ORG_OU="organizations"
```

### Default Configuration

If no environment variables are set, the system uses these defaults:

**Required Fields:**
- `o` - Organization name

**Optional Fields:**
- `telephoneNumber` - Phone number
- `labeledURI` - Website URL
- `mail` - Email address
- `description` - Organization description/status
- `businessCategory` - Business category
- `postalAddress` - Postal address (composite value stored in LDAP)
- `memberNumber` - Membership number (optional metadata)
- `memberSince` - Membership since date (optional metadata)

### Custom Field Configuration Examples

#### Minimal Configuration (Name Only)
```bash
export LDAP_ORG_REQUIRED_FIELDS="o"
export LDAP_ORG_OPTIONAL_FIELDS=""
```

#### Extended Configuration (Additional Fields)
```bash
export LDAP_ORG_REQUIRED_FIELDS="o,telephoneNumber,mail"
export LDAP_ORG_OPTIONAL_FIELDS="description,businessCategory,labeledURI,postalAddress,facsimileTelephoneNumber"
```

#### Custom Schema Configuration
```bash
export LDAP_ORG_REQUIRED_FIELDS="o,telephoneNumber,mail"
export LDAP_ORG_OPTIONAL_FIELDS="description,businessCategory,postalAddress,facsimileTelephoneNumber,labeledURI"
```

### Field Mapping

The system maps organization form fields to LDAP attributes. Address entry is collected via UI fields and persisted as a single composite `postalAddress` value.

| Form Field | LDAP Attribute | Description |
|------------|----------------|-------------|
| `org_name` | `o` | Organization name |
| `org_address` | `postalAddress` (composite) | Street address (part of composite) |
| `org_zip` | `postalAddress` (composite) | Postal code (part of composite) |
| `org_city` | `postalAddress` (composite) | City (part of composite) |
| `org_state` | `postalAddress` (composite) | State/Province (part of composite) |
| `org_country` | `postalAddress` (composite) | Country (part of composite) |
| `org_phone` | `telephoneNumber` | Phone number |
| `org_website` | `labeledURI` | Website URL |
| `org_email` | `mail` | Email address |
| `org_description` | `description` | Description |
| `org_category` | `businessCategory` | Business category |
| `org_postal_address` | `postalAddress` | Postal address (composite) |
| `org_fax` | `facsimileTelephoneNumber` | Fax number |

### Benefits

1. **Flexible Schema Support**: Adapt to different LDAP schemas and organizational requirements
2. **Customizable Requirements**: Set only the fields that are truly required for your use case
3. **Extensible**: Add new fields without code changes
4. **Environment-Specific**: Different configurations for development, staging, and production
5. **Compliance**: Meet specific organizational or regulatory requirements

### Notes

- Fields not listed in either `LDAP_ORG_REQUIRED_FIELDS` or `LDAP_ORG_OPTIONAL_FIELDS` are completely ignored
- The `o` (organization name) field is always required as it's used as the RDN
- Field validation is performed on both client and server side
- The web interface automatically adapts to show only the configured fields

### Field Handling During Organization Creation

When creating an organization, the system processes fields as follows:

1. **Required Fields**: Must be present and non-empty, otherwise creation fails
2. **Optional Fields**: Included in the LDAP entry if provided, ignored if empty
3. **Unconfigured Fields**: Completely ignored regardless of whether they're provided
4. **Special Fields**: Some fields like `postalAddress` are automatically generated from component fields

**Example:**
```bash
# Configuration
export LDAP_ORG_REQUIRED_FIELDS="o,telephoneNumber,mail"
export LDAP_ORG_OPTIONAL_FIELDS="description,businessCategory,labeledURI"

# Input data (including unconfigured field 'unused_field')
org_data = {
    'o': 'Example Corp',
    'telephoneNumber': '+1-555-0123',
    'mail': 'info@example.com',
    'description': 'A great company',           # Optional field - included
    'labeledURI': 'https://example.com',       # Optional field - included
    'unused_field': 'ignored value'            # Unconfigured field - ignored
}

# Result: Only configured fields are included in the LDAP entry
# Unconfigured fields are completely ignored
``` 