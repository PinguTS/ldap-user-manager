# LDIF Files for LDAP User Manager

This directory contains LDIF files for setting up the LDAP directory structure.

## Files

- `base.ldif` - Base directory structure and organizational units
- `system_users.ldif` - System users (administrators, maintainers)
- `example-org.ldif` - Example organization with users

## 🎯 Solution: Use Web-Based Setup

The LDAP User Manager includes a comprehensive web-based setup wizard that automatically creates all necessary LDAP structure. No manual LDIF loading is required.

## 🚀 Setup Process

### Web-Based Setup (Recommended)

The LDAP User Manager includes a comprehensive web-based setup wizard that automatically creates all necessary LDAP structure:

1. **Access the setup wizard** at `/setup/` in your web browser
2. **The wizard will check** your LDAP directory and identify what needs to be created
3. **Automatically create** missing organizational units, users, and roles
4. **Set up initial administrator** account with proper permissions

**Benefits:**
- No external scripts required
- No root access needed
- Conditional creation (only creates what's missing)
- Better error handling and user feedback
- Integrated with the application workflow

### LDIF Files (Reference Only)

These LDIF files are provided for reference and advanced users who want to understand the LDAP structure. They are **not required** for normal operation since the web-based setup wizard handles everything automatically.

**Available files:**
- `base.ldif` - Base directory structure (organizations, system_users, roles OUs)
- `system_users.ldif` - System user definitions (admin, maintainer)
- `example-org.ldif` - Example organization structure

**Note**: Passcodes are stored in the `userPassword` attribute alongside regular passwords, eliminating the need for custom schema files.

## 🔍 Verification

After using the web-based setup wizard, verify the structure exists:

```bash
# Check base structure
ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_admin_password

# Check system users
ldapsearch -x -b ou=people,dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_admin_password
```

## 📚 Next Steps

1. **Use the web-based setup wizard** at `/setup/` in your web browser
2. **The wizard will automatically** create all necessary LDAP structure
3. **Test the system** to ensure everything works
4. **No manual LDIF loading** or external scripts required

## 🆘 Need Help?

See [TROUBLESHOOTING.md](../TROUBLESHOOTING.md) for detailed troubleshooting information.
