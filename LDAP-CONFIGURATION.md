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
│   │       └── cn=org_admin
│   └── o=University Name
│       ├── ou=users
│       └── ou=roles
├── ou=system_users
│   ├── uid=admin@example.com
│   └── uid=maintainer@example.com
└── ou=roles
    ├── cn=administrator
    └── cn=maintainer
```

### 2.2 Organization Attributes
Organizations use the standard `postalAddress` attribute in the format:
```
postalAddress: Street$City$State$ZIP$Country
```

### 2.3 User Attributes
Users are stored with email addresses as their `uid` and include:
- `userRole`: administrator, maintainer, org_admin, or user
- `organization`: the organization they belong to

---

## 3. Role-Based Access Control

### 3.1 System Roles
- **Administrators**: Full access to all organizations, users, and settings
- **Maintainers**: Can manage all organizations and users, but cannot modify administrator accounts
- **Organization Managers**: Can manage users within their assigned organization(s)

### 3.2 Access Control Rules
- Administrators can modify anyone
- Maintainers can modify anyone except administrators
- Organization managers can only modify users in their organization
- Users can modify their own account

---

## 4. Example LDIF Loading

To load the base structure into OpenLDAP (as root/admin):
```sh
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/base.ldif
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/system_users.ldif
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/example-org.ldif
```

Restart or reload your LDAP server if required.

---

## 5. Configuration

### 5.1 Environment Variables
- `LDAP_ADMINS_GROUP`: administrator (default)
- `LDAP_MAINTAINERS_GROUP`: maintainer (default)
- `LDAP_ORG_OU`: organizations (default)

### 5.2 LDAP Base DN
Ensure your `LDAP_BASE_DN` matches the structure in the LDIF files.

---

## 6. Troubleshooting

- **objectClass violation**: Ensure all required schemas are loaded
- **Cannot add organization**: Verify the base structure exists
- **Access denied**: Check user roles and group memberships

---

## 7. See Also

- [ldif/base.ldif](ldif/base.ldif) - Base LDAP structure
- [ldif/system_users.ldif](ldif/system_users.ldif) - System user accounts
- [ldif/example-org.ldif](ldif/example-org.ldif) - Example organization
- Main [README.md](README.md) for general setup and environment variables. 

---

## 8. Example: Loading Custom Schemas in Docker Compose

### Recommended: Use a Dedicated LDIF Directory Volume

For easier setup and maintenance, mount your entire `ldif/` directory into the container. Any LDIF file placed in this directory will be loaded automatically at startup (in filename order):

**Directory structure:**
```
project-root/
  ldif/
    10-orgWithCountry.ldif
    20-loginPasscode.ldif
    ... (other schema/data LDIFs as needed)
```

- Place all your custom and required LDIF files in the `ldif/` directory.
- Reference this directory in your `docker-compose.yml` as shown below.

### Required: Use `--copy-service` When Mounting LDIFs

When mounting LDIF files or directories into the osixia/openldap container, you **must** use the `--copy-service` command. This copies the files into the container's writable layer, allowing the container to process and remove them as needed. Without this, you will get errors like `Device or resource busy` or `Read-only file system`, and slapd will fail to start.

#### **Recommended: Mount a Directory to a Custom Subdirectory**

```yaml
services:
  ldap:
    image: osixia/openldap:latest
    command: ["--copy-service"]
    volumes:
      - ./ldif:/container/service/slapd/assets/config/bootstrap/ldif/custom
    # ... other config ...
```
- Place your LDIFs in `./ldif/` on the host; they will be loaded from `/ldif/custom` in the container.
- All LDIF files in the directory will be loaded at startup (in filename order).

#### **Alternative: Mount Individual Files**

```yaml
services:
  ldap:
    image: osixia/openldap:latest
    command: ["--copy-service"]
    volumes:
      - ./ldif/10-orgWithCountry.ldif:/container/service/slapd/assets/config/bootstrap/ldif/10-orgWithCountry.ldif
      - ./ldif/20-loginPasscode.ldif:/container/service/slapd/assets/config/bootstrap/ldif/20-loginPasscode.ldif
    # ... other config ...
```

---

### Troubleshooting

- **Error: `Device or resource busy` or `Read-only file system`**
  - This happens if you mount LDIF files or directories without using `--copy-service`.
  - Always add `command: --copy-service` to your service definition when mounting LDIFs.

- **Error: `chown: changing ownership ... Read-only file system`**
  - This also indicates the container is trying to change file ownership on a mounted file or directory. Use `--copy-service` to avoid this.

--- 