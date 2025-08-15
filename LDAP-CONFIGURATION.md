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
The system uses a hierarchical structure with organizations containing users:

```
dc=example,dc=com
├── ou=organizations
│   ├── o=Company Name
│   │   ├── ou=users
│   │   │   ├── uid=admin@company.com
│   │   │   └── uid=user1@company.com
│   │   └── ou=roles
│   │       └── cn=org_admin (groupOfNames with member attributes)
│   └── o=University Name
│       ├── ou=users
│       └── ou=roles
├── ou=people
│   ├── uid=admin@example.com
│   └── uid=maintainer@example.com
└── ou=roles
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
Users are stored with email addresses as their `uid` and include:
- `organization`: the organization they belong to
- `userPassword`: stores both regular passwords and app-managed passcodes

**Note**: The application uses existing LDAP attributes for maximum compatibility. Passcodes are stored alongside regular passwords in the `userPassword` attribute.

---

## 3. Role-Based Access Control

### 3.1 How Roles Work

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
- `LDAP_ADMINS_GROUP`: administrator (default)
- `LDAP_MAINTAINERS_GROUP`: maintainer (default)
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