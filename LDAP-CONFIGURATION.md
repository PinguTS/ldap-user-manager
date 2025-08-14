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

**IMPORTANT**: LDAP User Manager also requires a custom schema that defines the `userRole` attribute. This schema is provided in `ldif/userRole-schema.ldif` and must be loaded before any data that uses the `userRole` attribute.

### 1.1 Loading the Custom Schema

The custom schema must be loaded first, before any other LDIF files:

```bash
# Load the custom schema that defines the userRole attribute
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/userRole-schema.ldif
```

If you cannot load custom schemas, you will need to modify the system to use standard attributes instead of `userRole`.

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

**Note**: All users with the `userRole` attribute must include the `ldapUserManager` object class in addition to `inetOrgPerson`.

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

## 4. Setup Process

### 4.1 Automated Setup (Recommended)

Use the provided setup script for easy installation:

```bash
# Make the script executable and run as root
chmod +x setup-ldap.sh
sudo ./setup-ldap.sh
```

### 4.2 Manual Setup

**Step 1: Load the Custom Schema (Required First)**
```sh
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/userRole-schema.ldif
```

**Step 2: Load the Base Structure**
```sh
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/base.ldif
```

**Step 3: Load System Users**
```sh
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/system_users.ldif
```

**Step 4: Load Example Organization (Optional)**
```sh
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/example-org.ldif
```

**Step 5: Run Web-Based Setup**
After loading the LDIF files, use the web interface at `/setup/` to:
- Create the administrator and maintainer roles
- Set up role memberships
- Create example organization (if desired)

**Note**: The schema must be loaded first, otherwise you will get "attribute type undefined" errors when trying to create users with the `userRole` attribute.

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

### 6.1 Schema Files
- `ldif/userRole-schema.ldif` - Custom schema defining the userRole attribute and ldapUserManager object class

### 6.2 Structure Files
- `ldif/base.ldif` - Base directory structure (organizations, system_users, roles OUs)
- `ldif/system_users.ldif` - System user accounts (administrator, maintainer)
- `ldif/example-org.ldif` - Example organization with users (optional)

### 6.3 Additional Schemas
- `ldif/loginPasscode.ldif` - Schema for optional user passcodes

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
ldapsearch -x -b ou=system_users,dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_password
```

---

## 8. Docker Setup

### 8.1 Using the Setup Script

The easiest way to set up LDAP in Docker is to use the provided setup script:

```bash
# Run the script inside the LDAP container
sudo ./setup-ldap.sh
```

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

**Step 2: Load Schema and Data**
```bash
# Wait for container to start, then load schema
docker exec ldap ldapadd -Y EXTERNAL -H ldapi:/// -f /ldif/userRole-schema.ldif

# Load base structure
docker exec ldap ldapadd -x -D cn=admin,dc=example,dc=com -w admin -f /ldif/base.ldif

# Load system users
docker exec ldap ldapadd -x -D cn=admin,dc=example,dc=com -w admin -f /ldif/system_users.ldif
```

### 8.3 Docker Compose / Portainer Stack Setup

For production deployments with separate containers, here's a complete setup:

#### **docker-compose.yml for LDAP Server**

```yaml
version: '3.8'

services:
  ldap:
    image: osixia/openldap:latest
    container_name: ldap-server
    hostname: ldap-server
    ports:
      - "389:389"
      - "636:636"
    environment:
      LDAP_ORGANISATION: "Example Organization"
      LDAP_DOMAIN: "example.com"
      LDAP_ADMIN_PASSWORD: "admin123"
      LDAP_CONFIG_PASSWORD: "config123"
      LDAP_READONLY_USER: "false"
      LDAP_RFC2307BIS_SCHEMA: "false"
      LDAP_BACKEND: "mdb"
      LDAP_TLS: "false"
    volumes:
      - ldap_data:/var/lib/ldap
      - ldap_config:/etc/ldap/slapd.d
      - ./ldif:/ldif:ro
    command: ["--copy-service"]
    restart: unless-stopped
    networks:
      - ldap-network

volumes:
  ldap_data:
  ldap_config:

networks:
  ldap-network:
    driver: bridge
```

#### **docker-compose.yml for LDAP User Manager**

```yaml
version: '3.8'

services:
  ldap-user-manager:
    image: your-ldap-user-manager:latest  # or build from source
    container_name: ldap-user-manager
    hostname: ldap-user-manager
    ports:
      - "8080:80"
    environment:
      # LDAP Connection Settings
      LDAP_URI: "ldap://ldap-server:389"
      LDAP_BASE_DN: "dc=example,dc=com"
      LDAP_ADMIN_BIND_DN: "cn=admin,dc=example,dc=com"
      LDAP_ADMIN_BIND_PWD: "admin123"
      
      # Application Settings
      ORGANISATION_NAME: "LDAP User Manager"
      SITE_NAME: "LDAP User Manager"
      SERVER_HOSTNAME: "ldap-user-manager.example.com"
      SERVER_PATH: "/"
      
      # Optional: Email Configuration
      SMTP_HOSTNAME: "smtp.example.com"
      SMTP_USERNAME: "noreply@example.com"
      SMTP_PASSWORD: "your-smtp-password"
      SMTP_USE_TLS: "TRUE"
      
      # Optional: Security Settings
      LDAP_REQUIRE_STARTTLS: "FALSE"
      LDAP_IGNORE_CERT_ERRORS: "TRUE"
    depends_on:
      - ldap-server
    restart: unless-stopped
    networks:
      - ldap-network

networks:
  ldap-network:
    external: true
```

#### **Complete Stack Setup Process**

**Step 1: Create LDAP Server Stack**

1. In Portainer, create a new stack called `ldap-server`
2. Copy the LDAP docker-compose.yml content
3. Deploy the stack
4. Wait for the container to be healthy

**Step 2: Load Schema and Initial Data**

```bash
# Connect to the LDAP container
docker exec -it ldap-server bash

# Load the custom schema (this must be done first)
ldapadd -Y EXTERNAL -H ldapi:/// -f /ldif/userRole-schema.ldif

# Load the base structure
ldapadd -x -D cn=admin,dc=example,dc=com -w admin123 -f /ldif/base.ldif

# Load system users
ldapadd -x -D cn=admin,dc=example,dc=com -w admin123 -f /ldif/system_users.ldif

# Load example organization (optional)
ldapadd -x -D cn=admin,dc=example,dc=com -w admin123 -f /ldif/example-org.ldif

# Verify the setup
ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
```

**Step 3: Create LDAP User Manager Stack**

1. In Portainer, create a new stack called `ldap-user-manager`
2. Copy the LDAP User Manager docker-compose.yml content
3. Make sure to use the same network name (`ldap-network`)
4. Deploy the stack

**Step 4: Complete Web-Based Setup**

1. Access the web interface at `http://your-server:8080/setup/`
2. Follow the setup wizard to:
   - Verify LDAP connection
   - Create administrator and maintainer roles
   - Set up initial users and permissions

#### **Alternative: Automated Schema Loading**

You can also create a custom LDAP image that includes the schema:

**Dockerfile for Custom LDAP Image**

```dockerfile
FROM osixia/openldap:latest

# Copy LDIF files
COPY ldif/ /ldif/

# Create startup script
RUN echo '#!/bin/bash\n\
# Wait for slapd to start\n\
sleep 10\n\
\n\
# Load schema first\n\
ldapadd -Y EXTERNAL -H ldapi:/// -f /ldif/userRole-schema.ldif\n\
\n\
# Load data\n\
ldapadd -x -D cn=admin,dc=example,dc=com -w $LDAP_ADMIN_PASSWORD -f /ldif/base.ldif\n\
ldapadd -x -D cn=admin,dc=example,dc=com -w $LDAP_ADMIN_PASSWORD -f /ldif/system_users.ldif\n\
ldapadd -x -D cn=admin,dc=example,dc=com -w $LDAP_ADMIN_PASSWORD -f /ldif/example-org.ldif\n\
\n\
# Keep container running\n\
exec "$@"' > /startup.sh && chmod +x /startup.sh

# Override entrypoint
ENTRYPOINT ["/startup.sh"]
CMD ["/container/tool/run"]
```

**Updated docker-compose.yml with Custom Image**

```yaml
version: '3.8'

services:
  ldap:
    build: ./ldap-custom  # Build from the Dockerfile above
    container_name: ldap-server
    hostname: ldap-server
    ports:
      - "389:389"
      - "636:636"
    environment:
      LDAP_ORGANISATION: "Example Organization"
      LDAP_DOMAIN: "example.com"
      LDAP_ADMIN_PASSWORD: "admin123"
      LDAP_CONFIG_PASSWORD: "config123"
    volumes:
      - ldap_data:/var/lib/ldap
      - ldap_config:/etc/ldap/slapd.d
    restart: unless-stopped
    networks:
      - ldap-network

volumes:
  ldap_data:
  ldap_config:

networks:
  ldap-network:
    driver: bridge
```

#### **Portainer-Specific Notes**

- **Networks**: Make sure both stacks use the same external network
- **Volumes**: Use named volumes for persistent data
- **Environment Variables**: Set sensitive values through Portainer's environment variable interface
- **Health Checks**: Monitor container health before proceeding with schema loading
- **Logs**: Check container logs for any errors during startup

#### **Verification Commands**

```bash
# Test LDAP connection from user manager container
docker exec -it ldap-user-manager ldapsearch -x -H ldap://ldap-server:389 -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123

# Check if schema was loaded
docker exec -it ldap-server ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=configuration -s base

# Verify users exist
docker exec -it ldap-server ldapsearch -x -b ou=system_users,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
```

---

## 9. See Also

- [ldif/README.md](ldif/README.md) - Detailed LDIF loading instructions
- [setup-ldap.sh](setup-ldap.sh) - Automated setup script
- Main [README.md](README.md) for general setup and environment variables
- [docs/ldap-structure.md](docs/ldap-structure.md) for detailed LDAP structure examples 