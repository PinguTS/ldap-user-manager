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

3. **Wait for LDAP to be ready, then start user manager**
   ```bash
   docker-compose -f docker-compose.app.yml up -d
   ```

4. **Complete web setup**
   - Navigate to `http://localhost:8080/setup/`
   - Follow the setup wizard to automatically create LDAP structure

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

### Step 2: Load LDAP Structure

The LDAP User Manager includes a web-based setup wizard that automatically creates all necessary LDAP structure. Simply:

1. **Access the setup wizard** at `http://localhost:8080/setup/`
2. **The wizard will automatically** create missing OUs, users, and roles
3. **No external scripts** or manual LDIF loading required

**What the wizard creates automatically:**
- Base organizational units (organizations, people, roles)
- System administrator and maintainer users
- Administrator and maintainer role groups with proper memberships
- Example organization (optional)

**Note**: The web-based setup wizard is the recommended approach as it's easier, more robust, and handles all the complexity automatically.

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

**Note**: This approach is not recommended since the web-based setup wizard handles everything automatically. However, if you need a custom LDAP image for other reasons:

**Dockerfile for Custom LDAP Image**

```dockerfile
FROM osixia/openldap:latest

# Copy LDIF files for reference
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

### Option 2: Init Container Pattern

**Note**: This approach is not recommended since the web-based setup wizard handles everything automatically. However, if you need an init container for other reasons:

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
        echo 'LDAP server ready for web-based setup'
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
# Verify LDAP server is accessible
docker exec -it ldap-server ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=configuration -s base

# Note: The system uses existing LDAP attributes - no custom schema required
```

### Verify Users

```bash
# Check system users
docker exec -it ldap-server ldapsearch -x -b ou=people,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123

# Check roles
docker exec -it ldap-server ldapsearch -x -b ou=roles,dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123
```

---

## 🚨 Troubleshooting

### Common Issues

1. **"Connection refused"**
   - LDAP container not ready: Wait for health check
   - Network issues: Verify both containers are on same network

2. **"Invalid credentials"**
   - Check admin password in environment variables
   - Verify LDAP_BASE_DN matches your setup

3. **"No such object"**
   - Base structure not loaded: Run the LDIF loading commands
   - Check if OUs exist: `ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123`

4. **"attribute type undefined"**
   - This error should not occur since we're using existing LDAP attributes
   - Check if the web-based setup wizard completed successfully

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

# Container Permission Issues
docker run --user root -it ldap-server bash
ls -la /var/run/slapd/ldapi

# LDAP Server Status
docker exec -it ldap-server systemctl status slapd
docker exec -it ldap-server slapd -V

# Enable Debug Mode
docker exec -it ldap-server bash -c 'echo "loglevel 256" >> /etc/ldap/slapd.conf'
docker exec -it ldap-server systemctl restart slapd
```

---

## 📚 Additional Resources

- [LDAP Configuration Guide](LDAP-CONFIGURATION.md) - Detailed LDAP setup and diagnostics
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
