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

**The Approach**: Use existing LDAP attributes that are available in all standard LDAP schemas.

**What This Means**: The application stores role information in the `description` attribute and passcodes in the `userPassword` attribute.

**Benefits**:
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
- `userPassword`: stores both regular passwords and app-managed passcodes
- `entryUUID`: OpenLDAP operational attribute for secure identification

**Note**: The application uses existing LDAP attributes for maximum compatibility. Passcodes are stored alongside regular passwords in the `userPassword` attribute.

---

## 3. UUID-Based Identification

### 3.1 Overview
LDAP User Manager now uses OpenLDAP's `entryUUID` operational attribute for secure, immutable identification of entries. This provides several benefits:

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

### 3.2 System Roles
- **Administrators**: Full access to all organizations, users, and settings
- **Maintainers**: Can manage all organizations and users, but cannot modify administrator accounts
- **Organization Managers**: Can manage users within their assigned organization(s)

### 3.3 Access Control Rules
- Administrators can modify anyone
- Maintainers can modify anyone except administrators
- Organization managers can only modify users in their organization
- Users can modify their own account

---

## 3. Passcode Implementation

### 3.1 Implementation Approach

Passcode functionality is implemented using existing LDAP attributes for maximum compatibility:

**`userPassword` for App-Managed Passcodes:**
- Stores application-managed passcodes alongside regular passwords
- No schema changes required
- Application handles all passcode verification logic
- Can store multiple passcodes if needed
- Uses standard LDAP hashing (SSHA, etc.)
- Regular passwords and passcodes coexist in the same attribute

**Example Implementation:**
```
userPassword: {SSHA}hashed_regular_password
userPassword: {SSHA}hashed_passcode_123456
userPassword: {SSHA}hashed_passcode_789012
```

**Benefits:**
- ✅ No custom schema required
- ✅ Works with any LDAP server
- ✅ Semantically correct attribute usage
- ✅ Standard LDAP compliance
- ✅ Flexible storage for multiple passcodes
- ✅ Preserves existing password functionality

### 3.2 Application Logic

The application:
1. **Stores passcodes** in the `userPassword` attribute alongside passwords
2. **Distinguishes between** regular passwords and passcodes by hash format
3. **Handles verification** logic for both authentication methods
4. **Manages passcode lifecycle** (creation, expiration, rotation)
5. **Uses existing LDAP attributes** for maximum compatibility

---

## 4. Setup Process

### 4.1 Web-Based Setup (Recommended)

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

### 4.2 LDIF Files (Reference Only)

The LDIF files in the `ldif/` directory are provided for reference and advanced users who want to understand the LDAP structure. They are **not required** for normal operation since the web-based setup wizard handles everything automatically.

**Available LDIF files:**
- `ldif/base.ldif` - Base directory structure
- `ldif/system_users.ldif` - System user definitions
- `ldif/example-org.ldif` - Example organization structure
- `userPassword` - Stores both regular passwords and app-managed passcodes

---

## 5. Configuration

### 5.1 Environment Variables
- `LDAP_ADMIN_ROLE`: administrator (default) - Controls both group name and role name
- `LDAP_MAINTAINER_ROLE`: maintainer (default) - Controls both group name and role name  
- `LDAP_ORG_ADMIN_ROLE`: org_admin (default) - Controls both group name and role name
- `LDAP_ORG_OU`: organizations (default)

### 5.2 LDAP Base DN
Ensure your `LDAP_BASE_DN` matches the structure in the LDIF files.

---

## 6. LDIF Files

### 6.1 Structure Files
- `ldif/base.ldif` - Base directory structure (organizations, system_users, roles OUs)
- `ldif/system_users.ldif` - System user accounts (administrator, maintainer)
- `ldif/example-org.ldif` - Example organization with users (optional)

### 6.2 Additional Schemas
- No custom schema files are needed. The system uses existing LDAP attributes like `description` for role information and `userPassword` for passcodes.

---

## 7. Troubleshooting

### 7.1 Common Errors

- **"attribute type undefined"**: The custom schema hasn't been loaded. Load `userRole-schema.ldif` first.
- **"no values for attribute type"**: `groupOfNames` object class requires at least one member value.
- **"Protocol error"**: Usually indicates schema or structural issues.

### 7.2 Verification Commands

```bash
# Check if schema was loaded
ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=configuration -s base

# Verify base structure
ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_password

# Check system users
ldapsearch -x -b ou=people,dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_password
```

---

## 8. Docker Setup

### 8.1 Docker Setup Overview

The easiest way to set up LDAP in Docker is to use the web-based setup wizard:

1. **Start your LDAP container**
2. **Start the LDAP User Manager container**
3. **Access the setup wizard** at `/setup/` in your web browser
4. **The wizard will automatically** create all necessary LDAP structure

### 8.2 Manual Docker Setup

If you prefer to set up manually or use Docker Compose:

**Step 1: Start LDAP Container**
```bash
docker run --name ldap -d \
  -p 389:389 \
  -e LDAP_ORGANISATION="Example Org" \
  -e LDAP_DOMAIN="example.com" \
  -e LDAP_ADMIN_PASSWORD="admin" \
  osixia/openldap:latest
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

#### **Alternative: Custom LDAP Image (Not Recommended)**

**Note**: This approach is not recommended since the web-based setup wizard handles everything automatically. However, if you need a custom LDAP image for other reasons:

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

# Check LDAP server accessibility
docker exec -it ldap-server ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=configuration -s base

# Verify users exist
docker exec -it ldap-server ldapsearch -x -b ou=people,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
```

---

## 9. Basic Diagnostics

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

## 10. See Also

- [ldif/README.md](ldif/README.md) - Detailed LDIF loading instructions
- Main [README.md](README.md) for general setup and environment variables
- [docs/ldap-structure.md](docs/ldap-structure.md) for detailed LDAP structure examples 

## Organization Field Configuration

The system now supports fully configurable organization fields through environment variables. This allows you to customize which fields are required, optional, or ignored when creating organizations.

### Environment Variables

```bash
# Required fields for organization creation (comma-separated LDAP attributes)
export LDAP_ORG_REQUIRED_FIELDS="o,street,city,state,postalCode,country,telephoneNumber,labeledURI,mail"

# Optional fields for organization creation (comma-separated LDAP attributes)
export LDAP_ORG_OPTIONAL_FIELDS="description,businessCategory,postalAddress,facsimileTelephoneNumber"

# Customize the organizations OU name
export LDAP_ORG_OU="organizations"
```

### Default Configuration

If no environment variables are set, the system uses these defaults:

**Required Fields:**
- `o` - Organization name
- `street` - Street address
- `city` - City
- `state` - State/Province
- `postalCode` - Postal code
- `country` - Country
- `telephoneNumber` - Phone number
- `labeledURI` - Website URL
- `mail` - Email address

**Optional Fields:**
- `description` - Organization description/status
- `businessCategory` - Business category
- `postalAddress` - Alternative postal address format
- `facsimileTelephoneNumber` - Fax number

### Custom Field Configuration Examples

#### Minimal Configuration (Name Only)
```bash
export LDAP_ORG_REQUIRED_FIELDS="o"
export LDAP_ORG_OPTIONAL_FIELDS=""
```

#### Extended Configuration (Additional Fields)
```bash
export LDAP_ORG_REQUIRED_FIELDS="o,street,city,state,postalCode,country,telephoneNumber,mail"
export LDAP_ORG_OPTIONAL_FIELDS="description,businessCategory,labeledURI,postalAddress,facsimileTelephoneNumber,seeAlso,st"
```

#### Custom Schema Configuration
```bash
export LDAP_ORG_REQUIRED_FIELDS="o,streetAddress,locality,st,postalCode,c,telephoneNumber,mail"
export LDAP_ORG_OPTIONAL_FIELDS="description,businessCategory,url,postalAddress,facsimileTelephoneNumber,seeAlso,ou"
```

### Field Mapping

The system automatically maps form field names to LDAP attributes:

| Form Field | LDAP Attribute | Description |
|------------|----------------|-------------|
| `org_name` | `o` | Organization name |
| `org_address` | `street` | Street address |
| `org_city` | `city` | City |
| `org_state` | `state` | State/Province |
| `org_zip` | `postalCode` | Postal code |
| `org_country` | `country` | Country |
| `org_phone` | `telephoneNumber` | Phone number |
| `org_website` | `labeledURI` | Website URL |
| `org_email` | `mail` | Email address |
| `org_description` | `description` | Description |
| `org_category` | `businessCategory` | Business category |
| `org_postal_address` | `postalAddress` | Alternative postal address |
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
export LDAP_ORG_REQUIRED_FIELDS="o,street,city,state,postalCode,country,telephoneNumber,mail"
export LDAP_ORG_OPTIONAL_FIELDS="description,businessCategory,labeledURI"

# Input data (including unconfigured field 'unused_field')
org_data = {
    'o': 'Example Corp',
    'street': '123 Main St',
    'city': 'Anytown',
    'state': 'CA',
    'postalCode': '12345',
    'country': 'USA',
    'telephoneNumber': '+1-555-0123',
    'mail': 'info@example.com',
    'description': 'A great company',           # Optional field - included
    'labeledURI': 'https://example.com',       # Optional field - included
    'unused_field': 'ignored value'            # Unconfigured field - ignored
}

# Result: Only configured fields are included in the LDAP entry
# Unconfigured fields are completely ignored
``` 