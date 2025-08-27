# LDAP User Manager

A PHP-based web interface for managing LDAP user accounts, organizations, and role-based access control. Designed to work with OpenLDAP and containerized deployments.

***

## 🚀 **Quick Start**

### **Option 1: Docker (Recommended)**
```bash
# Clone repository
git clone https://github.com/your-repo/ldap-user-manager.git
cd ldap-user-manager

# Start with Docker Compose
docker-compose -f docker-compose.app.yml up -d
```

### **Option 2: Direct Web Server Deployment**
```bash
# Clone repository
git clone https://github.com/your-repo/ldap-user-manager.git
cd ldap-user-manager

# Single setup script for all scenarios
./web-servers/setup.sh
```

## 📚 **Documentation**

- [LDAP Configuration](LDAP-CONFIGURATION.md) - LDAP schema and setup
- [Docker Setup](DOCKER-SETUP.md) - Container deployment guide
- [Web Server Deployment](web-servers/README.md) - Apache, Nginx, and direct deployment
- [Configuration Variables](CONFIGURATION_VARIABLES.md) - Environment variables
- [Role Configuration](ROLE_CONFIGURATION.md) - Access control configuration
- [URL Routing](docs/URL_ROUTING.md) - Clean URLs and routing system
- [Apache Configuration](apache/README.md) - Server configuration and optimization

***

## LDAP Structure

LDAP User Manager uses a unified and intuitive structure with UUID-based identification:

```
dc=example,dc=com
├── ou=people                           # System-level users (admins, maintainers)
│   ├── uid=admin@example.com          # entryUUID: 550e8400-e29b-41d4-a716-446655440000
│   └── uid=maintainer@example.com     # entryUUID: 550e8400-e29b-41d4-a716-446655440001
├── ou=organizations
│   └── o=Example Company              # entryUUID: 550e8400-e29b-41d4-a716-446655440002
│       ├── ou=people                   # Organization users (same naming!)
│       │   ├── uid=user1@examplecompany.com
│       │   └── uid=user2@examplecompany.com
│       └── ou=roles                    # Organization-specific roles
│           └── cn=org_admin            # Organization administrators (groupOfNames)
├── ou=roles                            # Global system roles only
│   ├── cn=administrators
│   └── cn=maintainers
```

### Benefits of Unified Structure
- **Consistent Naming**: `ou=people` everywhere means the same thing
- **Intuitive Structure**: Users are always under `ou=people`, regardless of context
- **Easier to Understand**: LDAP administrators will immediately know where to find users
- **Follows Standards**: `ou=people` is the de facto standard for user containers
- **Clean Organization Structure**: Roles properly organized under `ou=roles`
- **UUID Security**: Uses `entryUUID` for secure, immutable identification

***

## LDAP Requirements

LDAP User Manager works with standard OpenLDAP schemas and uses existing attributes for maximum compatibility:

- **Standard schemas**: core, cosine, inetorgperson, organization, locality
- **Role storage**: Uses LDAP groups with `groupOfNames` object class
- **Passcode storage**: Uses existing `userPassword` attribute for both passwords and passcodes
- **Compatibility**: Works with any LDAP server that supports standard schemas

For detailed LDAP setup instructions, see [LDAP-CONFIGURATION.md](LDAP-CONFIGURATION.md).

***

## Role-based Access Control

- **Administrators**: Full system access
- **Maintainers**: Can manage organizations and users
- **Organization Managers**: Manage users within their organization
- **Regular Users**: Self-service account management

## User Management

### System Users (ou=people)
System users are administrators and maintainers with simplified field requirements:
- **Required**: First Name, Last Name, Email
- **Auto-generated**: Common Name (from First + Last), UID (from email)
- **Optional**: Phone, Website
- **No address fields** - System users don't need location information

### Organization Users (ou=people,o=OrgName)
Organization users have additional fields for organizational context:
- **Required**: First Name, Last Name, Email, Organization
- **Auto-generated**: Common Name (from First + Last), UID (from email)
- **Optional**: Phone, Website, User Role
- **No address fields** - Address information is stored at organization level

***

## Screenshots

**Account Management:**
![account_overview](https://user-images.githubusercontent.com/17613683/59344255-9c692480-8d05-11e9-8607-051291bafd91.png)

**Group Management:**
![group_membership](https://user-images.githubusercontent.com/17613683/59344247-97a47080-8d05-11e9-8606-0bcc40471458.png)

**Self-service Password Change:**
![self_service_password_change](https://user-images.githubusercontent.com/17613683/59344258-9ffcab80-8d05-11e9-8606-0bcc40471458.png)

***

## ✅ Success Checklist

After setup, verify these items:

- [ ] LDAP server is running and accessible
- [ ] Base structure (OUs) exists with unified `ou=people` naming
- [ ] Web interface is accessible at `http://localhost:8080`
- [ ] Setup wizard completes without errors at `/setup/`
- [ ] Users can be created and managed
- [ ] Role-based access control works
- [ ] Passcode functionality works alongside regular passwords

---

## Configuration

### Environment Variables

- `LDAP_URI`: LDAP server URI
- `LDAP_BASE_DN`: Base DN for the LDAP directory
- `LDAP_ADMIN_BIND_DN`: Admin user DN
- `LDAP_ADMIN_BIND_PWD`: Admin password
- `SERVER_HOSTNAME`: Server hostname for the application
- `ORGANISATION_NAME`: Organization name displayed in the UI
- `SITE_NAME`: Site name displayed in the UI

### File Upload Settings

- `FILE_UPLOAD_MAX_SIZE`: Maximum file upload size in bytes (default: 2MB)
- `FILE_UPLOAD_ALLOWED_MIME_TYPES`: Comma-separated list of allowed MIME types

For complete configuration options, see [LDAP-CONFIGURATION.md](LDAP-CONFIGURATION.md).

***

## Support

- **Documentation**: See the documentation files above
- **Issues**: Report problems in the project issue tracker
- **Setup Help**: Start with [DOCKER-SETUP.md](DOCKER-SETUP.md) for Docker deployments
