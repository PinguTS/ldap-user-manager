# Docker Setup Guide for LDAP User Manager

This guide provides step-by-step instructions for setting up LDAP User Manager using Docker Compose or Portainer stacks, with separate containers for LDAP and the user management application.

---

## 🏗️ Architecture Overview

```
┌─────────────────┐    ┌─────────────────────┐
│   Portainer     │    │   Docker Host       │
│   (Web UI)      │    │                     │
└─────────────────┘    └─────────────────────┘
         │                       │
         │                       │
         ▼                       ▼
┌─────────────────┐    ┌─────────────────────┐
│  Stack:         │    │  Stack:             │
│  ldap-server    │    │  ldap-user-manager  │
│                 │    │                     │
│  ┌───────────┐  │    │  ┌──────────────┐   │
│  │   LDAP    │◄─┼────┼─►│  Web App    │   │
│  │  Server   │  │    │   │             │   │
│  └───────────┘  │    │   └──────────────┘   │
└─────────────────┘    └─────────────────────┘
```

---

## 📋 Prerequisites

- Docker and Docker Compose installed
- Portainer (optional, but recommended)
- Access to the Docker host
- Basic knowledge of LDAP concepts

---

## 🚀 Quick Start

### Option 1: Automated Setup (Recommended)

1. **Clone the repository**
   ```bash
   git clone https://github.com/your-repo/ldap-user-manager.git
   cd ldap-user-manager
   ```

2. **Create the LDAP server stack**
   ```bash
   docker-compose -f docker-compose.ldap.yml up -d
   ```

3. **Wait for LDAP to be ready, then load schema**
   ```bash
   docker exec -it ldap-server /setup-ldap.sh
   ```

4. **Create the user manager stack**
   ```bash
   docker-compose -f docker-compose.app.yml up -d
   ```

5. **Complete web setup**
   - Navigate to `http://localhost:8080/setup/`
   - Follow the setup wizard

### Option 2: Manual Portainer Setup

Follow the detailed steps below for manual setup in Portainer.

---

## 🔧 Detailed Setup Instructions

### Step 1: Create LDAP Server Stack

1. **In Portainer, create a new stack called `ldap-server`**

2. **Use this docker-compose.yml:**

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

3. **Deploy the stack and wait for it to be healthy**

### Step 2: Load Schema and Initial Data

1. **Connect to the LDAP container:**
   ```bash
   docker exec -it ldap-server bash
   ```

2. **Load the custom schema (required first):**
   ```bash
   ldapadd -Y EXTERNAL -H ldapi:/// -f /ldif/userRole-schema.ldif
   ```

3. **Load the base structure:**
   ```bash
   ldapadd -x -D cn=admin,dc=example,dc=com -w admin123 -f /ldif/base.ldif
   ```

4. **Load system users:**
   ```bash
   ldapadd -x -D cn=admin,dc=example,dc=com -w admin123 -f /ldif/system_users.ldif
   ```

5. **Load example organization (optional):**
   ```bash
   ldapadd -x -D cn=admin,dc=example,dc=com -w admin123 -f /ldif/example-org.ldif
   ```

6. **Verify the setup:**
   ```bash
   ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
   ```

7. **Exit the container:**
   ```bash
   exit
   ```

### Step 3: Create LDAP User Manager Stack

1. **In Portainer, create a new stack called `ldap-user-manager`**

2. **Use this docker-compose.yml:**

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

3. **Make sure to use the same network name (`ldap-network`)**
4. **Deploy the stack**

### Step 4: Complete Web-Based Setup

1. **Access the web interface at `http://your-server:8080/setup/`**
2. **Follow the setup wizard to:**
   - Verify LDAP connection
   - Create administrator and maintainer roles
   - Set up initial users and permissions

---

## 🔄 Alternative: Automated Schema Loading

### Option 1: Custom LDAP Image

Create a custom LDAP image that automatically loads the schema:

**Dockerfile (save as `ldap-custom/Dockerfile`):**

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

**Updated docker-compose.yml:**

```yaml
version: '3.8'

services:
  ldap:
    build: ./ldap-custom
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

### Option 2: Init Container Pattern

Use an init container to load the schema:

```yaml
version: '3.8'

services:
  ldap-init:
    image: osixia/openldap:latest
    container_name: ldap-init
    environment:
      LDAP_ORGANISATION: "Example Organization"
      LDAP_DOMAIN: "example.com"
      LDAP_ADMIN_PASSWORD: "admin123"
    volumes:
      - ./ldif:/ldif:ro
    command: >
      sh -c "
        sleep 10 &&
        ldapadd -Y EXTERNAL -H ldapi:/// -f /ldif/userRole-schema.ldif &&
        ldapadd -x -D cn=admin,dc=example,dc=com -w admin123 -f /ldif/base.ldif &&
        ldapadd -x -D cn=admin,dc=example,dc=com -w admin123 -f /ldif/system_users.ldif &&
        echo 'Schema loaded successfully'
      "
    depends_on:
      - ldap-server

  ldap-server:
    image: osixia/openldap:latest
    container_name: ldap-server
    ports:
      - "389:389"
    environment:
      LDAP_ORGANISATION: "Example Organization"
      LDAP_DOMAIN: "example.com"
      LDAP_ADMIN_PASSWORD: "admin123"
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

---

## 🔍 Verification and Testing

### Test LDAP Connection

```bash
# Test from host
ldapsearch -x -H ldap://localhost:389 -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123

# Test from user manager container
docker exec -it ldap-user-manager ldapsearch -x -H ldap://ldap-server:389 -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
```

### Check Schema

```bash
# Verify schema was loaded
docker exec -it ldap-server ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=configuration -s base

# Check for userRole attribute
docker exec -it ldap-server ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=configuration "(objectClass=olcAttributeType)" | grep userRole
```

### Verify Users

```bash
# Check system users
docker exec -it ldap-server ldapsearch -x -b ou=system_users,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123

# Check roles
docker exec -it ldap-server ldapsearch -x -b ou=roles,ou=organizations,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
```

---

## 🚨 Troubleshooting

### Common Issues

1. **"attribute type undefined"**
   - Schema not loaded: Run the schema loading commands
   - Check container logs: `docker logs ldap-server`

2. **"Connection refused"**
   - LDAP container not ready: Wait for health check
   - Network issues: Verify both containers are on same network

3. **"Invalid credentials"**
   - Check admin password in environment variables
   - Verify LDAP_BASE_DN matches your setup

4. **"No such object"**
   - Base structure not loaded: Run the LDIF loading commands
   - Check if OUs exist: `ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123`

### Debug Commands

```bash
# Check container status
docker ps -a

# Check container logs
docker logs ldap-server
docker logs ldap-user-manager

# Check network connectivity
docker network ls
docker network inspect ldap-network

# Test LDAP from inside container
docker exec -it ldap-server ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
```

---

## 📚 Additional Resources

- [LDAP Configuration Guide](LDAP-CONFIGURATION.md) - Detailed LDAP setup
- [Main README](README.md) - General project information
- [LDIF Files](ldif/README.md) - LDIF file documentation
- [Portainer Documentation](https://docs.portainer.io/) - Portainer usage guide

---

## 🔐 Security Considerations

- **Change default passwords** after initial setup
- **Use TLS/SSL** in production environments
- **Restrict network access** to LDAP ports
- **Regular backups** of LDAP data and configuration
- **Monitor logs** for suspicious activity
- **Keep containers updated** with security patches
