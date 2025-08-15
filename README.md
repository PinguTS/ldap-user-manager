# LDAP User Manager

A PHP-based web interface for managing LDAP user accounts, organizations, and role-based access control. Designed to work with OpenLDAP and containerized deployments.

***

## Features

- **Setup Wizard**: Creates necessary LDAP structure and initial admin user
- **Organization Management**: Create, edit, and delete organizations (companies, universities, etc.)
- **Role-based Access Control**: Administrators, maintainers, organization managers, and regular users
- **User Management**: Create, edit, and delete user accounts with secure password generation
- **Group Management**: Create and manage LDAP groups
- **Self-service**: Users can manage their own accounts and change passwords
- **Email Integration**: Optional email notifications for new accounts and credential updates
- **Passcode Support**: Optional passcode attributes for additional authentication

***

## Quick Setup with Docker Compose

1. **Start LDAP server:**
   ```bash
   docker-compose -f docker-compose.ldap.yml up -d
   ```

2. **Start user manager:**
   ```bash
   docker-compose -f docker-compose.app.yml up -d
   ```

3. **Complete setup at `http://localhost:8080/setup/`**

The web-based setup wizard will automatically create all necessary LDAP structure, users, and roles.

***

## Documentation

- **[DOCKER-SETUP.md](DOCKER-SETUP.md)** - Complete Docker setup guide with Portainer instructions and troubleshooting
- **[LDAP-CONFIGURATION.md](LDAP-CONFIGURATION.md)** - LDAP schema requirements and configuration details
- **[ldif/README.md](ldif/README.md)** - LDIF file documentation and setup process

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

***

## Screenshots

**Account Management:**
![account_overview](https://user-images.githubusercontent.com/17613683/59344255-9c692480-8d05-11e9-9207-051291bafd91.png)

**Group Management:**
![group_membership](https://user-images.githubusercontent.com/17613683/59344247-97a47080-8d05-11e9-8606-0bcc40471458.png)

**Self-service Password Change:**
![self_service_password_change](https://user-images.githubusercontent.com/17613683/59344258-9ffcab80-8d05-11e9-8606-0bcc40471458.png)

***

## ✅ Success Checklist

After setup, verify these items:

- [ ] LDAP server is running and accessible
- [ ] Base structure (OUs) exists
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
