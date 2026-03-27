# Docker Setup Guide for LDAP User Manager with OIDC

This guide provides step-by-step instructions for setting up LDAP User Manager with OpenID Connect (OIDC) using Docker Compose. The setup includes Dex as an OIDC provider, LDAP server, and the user management application, all orchestrated with Caddy as a reverse proxy.

---

## 🏗️ Architecture Overview

```
┌───────────────────────────────────────────────────────────┐
│                    External Services                      │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │    TYPO3    │  │    GitLab   │  │  Nextcloud  │        │
│  │   Server    │  │   Server    │  │   Server    │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
└───────────────────────────────────────────────────────────┘
                              │
                              ▼
                    ┌─────────────────┐
                    │   OIDC Auth     │
                    │   (HTTPS)       │
                    └─────────────────┘
                              │
                              ▼
┌───────────────────────────────────────────────────────────┐
│                   Local Infrastructure                    │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │    Caddy    │  │     Dex     │  │     LDAP    │        │
│  │   Reverse   │  │   OIDC      │  │   Server    │        │
│  │    Proxy    │  │  Provider   │  │             │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
│                              │                            │
│  ┌─────────────┐             │                            │
│  │     LDAP    │             │                            │
│  │    User     │             │                            │
│  │   Manager   │             │                            │
│  └─────────────┘             │                            │
└───────────────────────────────────────────────────────────┘
```

---

## 📋 Prerequisites

- Docker and Docker Compose installed
- Access to the Docker host
- Basic knowledge of LDAP and OIDC concepts
- Valid SSL certificates for your domains (or self-signed for development)
- DNS records configured for your domains

---

## 🚀 Quick Start

### Automated Setup (Recommended)

1. **Clone the repository**
   ```bash
   git clone https://github.com/pinguts/ldap-user-manager.git
   cd ldap-user-manager
   ```

2. **Generate SSL certificates and OIDC client secrets**
   ```bash
   ./setup-oidc.sh
   ```

3. **Start all services**
   ```bash
   docker-compose up -d
   ```

4. **Verify services are running**
   ```bash
   docker-compose ps
   ```

5. **Test OIDC discovery**
   ```bash
   curl -k https://id.example.org/.well-known/openid_configuration
   ```

6. **Complete web setup**
   - Navigate to `https://app.example.org/setup/`
   - Follow the setup wizard to automatically create LDAP structure

### External Services Integration

For detailed configuration of external services (TYPO3, GitLab, Nextcloud), see the [Services Directory](../services/).

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
      LDAP_BACKEND_OVERLAY_PPOLICY: "true"
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

**Account locking (`pwdAccountLockedTime`):** Enable **ppolicy** — **osixia:** `LDAP_BACKEND_OVERLAY_PPOLICY=true` (do not also mount `06-ppolicy.ldif`). **Bitnami:** `LDAP_CONFIGURE_PPOLICY=yes`. LDIF fallback: `docker/openldap/README.md` and `docs/ldap/setup.md` §5.1.

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
      # FALSE when LDAP has ppolicy (osixia: LDAP_BACKEND_OVERLAY_PPOLICY; Bitnami: LDAP_CONFIGURE_PPOLICY=yes)
      LDAP_ACCOUNT_LOCK_DESCRIPTION_FALLBACK: "false"
      
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
      
      # Optional: Debug Settings
      LDAP_DEBUG: "FALSE"
      SESSION_DEBUG: "FALSE"
      SETUP_DEBUG: "FALSE"
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

**Note**: The web-based setup wizard handles everything automatically. However, if you need a custom LDAP image for other reasons:

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

**Note**: The web-based setup wizard handles everything automatically. However, if you need an init container for other reasons:

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

### Check schema (ppolicy / `pwdAccountLockedTime`)

Account lock uses **`pwdAccountLockedTime`**, which requires **ppolicy** (osixia **`LDAP_BACKEND_OVERLAY_PPOLICY`**, Bitnami **`LDAP_CONFIGURE_PPOLICY=yes`**, or osixia LDIF fallback — §5.1).

```bash
# Subschema entry (includes ppolicy attribute types when loaded)
docker exec -it ldap-server ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=subschema -s base attributetypes \
  | grep -i pwdAccountLockedTime || echo "ppolicy not active — check LDAP_BACKEND_OVERLAY_PPOLICY / Bitnami LDAP_CONFIGURE_PPOLICY / LDIF fallback"
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

4. **"Undefined attribute type" / lock has no effect**
   - **ppolicy** is not loaded: on **osixia** set `LDAP_BACKEND_OVERLAY_PPOLICY=true` (or use LDIF fallback / `ldapmodify`); on **Bitnami** set `LDAP_CONFIGURE_PPOLICY=yes` (`docs/ldap/setup.md` §5.1).
   - Optionally set `LDAP_ACCOUNT_LOCK_DESCRIPTION_FALLBACK=TRUE` on the app only if you intentionally cannot enable ppolicy yet.

### Debug Commands

```bash
# Check container status
docker ps -a
```

### Debug Environment Variables

For troubleshooting setup issues, you can enable debug logging:

```yaml
environment:
  # Debug Settings
  LDAP_DEBUG: "TRUE"           # Log LDAP operations
  SESSION_DEBUG: "TRUE"         # Log session management
  SETUP_DEBUG: "TRUE"           # Log setup process details
```

**SETUP_DEBUG** will log detailed information about:
- POST data received during setup
- LDAP connection status
- Each LDAP operation (create OU, user, role)
- Success/failure of each operation
- LDAP error codes and messages
- Session summary of what was created

Debug logs are written to the container's error log, which you can view with:
```bash
docker logs ldap-user-manager
```

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
