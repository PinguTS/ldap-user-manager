# Configuration Quick Reference

## 🚀 **Quick Start Configuration**

This guide shows you the most common configuration settings to get your LDAP User Manager running quickly.

## 🔐 **Essential LDAP Settings**

```bash
# Required - Your LDAP server details
export LDAP_URI=ldaps://ldap-server:636          # LDAPS for Docker setup
export LDAP_BASE_DN=dc=yourcompany,dc=com
export LDAP_ADMIN_BIND_DN=cn=admin,dc=yourcompany,dc=com
export LDAP_ADMIN_BIND_PWD=your_admin_password
export LDAP_IGNORE_CERT_ERRORS=true              # For development
```

## 🔒 **Password Security (Choose One)**

### **Option 1: Development/Testing (Easy)**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=0      # Allow any password
export PASSWORD_STRENGTH_MIN_LENGTH=4     # Minimum 4 characters
export ACCEPT_WEAK_PASSWORDS=TRUE        # Allow weak passwords
```

### **Option 2: Production (Secure)**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=2      # Require Fair or higher
export PASSWORD_STRENGTH_MIN_LENGTH=8     # Minimum 8 characters
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export ACCEPT_WEAK_PASSWORDS=FALSE
```

### **Option 3: High Security**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=3      # Require Good or higher
export PASSWORD_STRENGTH_MIN_LENGTH=12    # Minimum 12 characters
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=TRUE
export ACCEPT_WEAK_PASSWORDS=FALSE
```

## 🏢 **Organization Settings**

```bash
# Your organization name
export ORGANISATION_NAME="Your Company Name"
export SITE_NAME="Your Company User Manager"

# Session timeout (in minutes)
export SESSION_TIMEOUT=120
```

## 🐳 **Docker Quick Start**

Create a `docker-compose.yml` file:

```yaml
version: '3.8'
services:
  ldap-user-manager:
    image: your-registry/ldap-user-manager:latest
    environment:
      # LDAP
      - LDAP_URI=ldaps://ldap-server:636          # LDAPS for Docker setup
      - LDAP_BASE_DN=dc=yourcompany,dc=com
      - LDAP_ADMIN_BIND_DN=cn=admin,dc=yourcompany,dc=com
      - LDAP_ADMIN_BIND_PWD=your_admin_password
      - LDAP_IGNORE_CERT_ERRORS=true              # For development
      
      # Password Security (choose one option above)
      - PASSWORD_STRENGTH_MIN_SCORE=2
      - PASSWORD_STRENGTH_MIN_LENGTH=8
      - ACCEPT_WEAK_PASSWORDS=FALSE
      
      # Organization
      - ORGANISATION_NAME=Your Company Name
      - SITE_NAME=Your Company User Manager
      - SESSION_TIMEOUT=120
    ports:
      - "8080:80"
```

## 📋 **Common Configuration Patterns**

### **Small Organization**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=2      # Fair passwords
export PASSWORD_STRENGTH_MIN_LENGTH=8     # 8+ characters
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
```

### **Medium Organization**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=2      # Fair passwords
export PASSWORD_STRENGTH_MIN_LENGTH=10    # 10+ characters
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
```

### **Large Organization**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=3      # Good passwords
export PASSWORD_STRENGTH_MIN_LENGTH=12    # 12+ characters
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=TRUE
```

## ⚡ **Quick Testing Setup**

For testing and development:

```bash
# Allow any password for quick testing
export PASSWORD_STRENGTH_MIN_SCORE=0
export PASSWORD_STRENGTH_MIN_LENGTH=1
export ACCEPT_WEAK_PASSWORDS=TRUE

# No character requirements
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=FALSE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=FALSE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=FALSE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
```

## 🔍 **Testing Your Configuration**

1. **Set your environment variables**
2. **Start the system**
3. **Try creating a user with a simple password**
4. **Check if it's accepted or rejected**
5. **Adjust settings as needed**

## 🔐 **Setup lock (after setup is complete)**

Once LDAP setup verification succeeds, the `/setup/` area is locked: visitors see only a short "Setup complete" message and a link to the application login. No detailed LDAP information is shown.

- **`LDAP_SETUP_LOCK_FILE`** – Path of the lock file (default: `/tmp/ldap_user_manager_setup_complete`). Set this to a persistent path in production (e.g. a volume mount) so the lock survives restarts.
- **`LDAP_SETUP_LOCKED`** – Set to `false` to force-unlock the setup area (e.g. for development or to re-run the wizard). When `false`, the full setup flow is available even if the lock file exists.
- **Re-enable setup**: Remove the lock file, or set `LDAP_SETUP_LOCKED=false`. There is no in-app unlock; this is intentional for security.
- **Docker**: The container entrypoint runs a verification check on startup; if LDAP is reachable and passes the same checks as the web wizard, the setup lock file is created automatically (so you may see the setup area already locked after first start). This check is skipped when `ENVIRONMENT=development` so the setup wizard stays available.

## 📚 **Need More Details?**

- **Full Configuration Guide**: [Environment Variables](environment-variables.md)
- **Password Security Details**: [Password Policy](password-policy.md)
- **Docker Setup**: [Docker Setup](../../DOCKER-SETUP.md)

## 🚨 **Common Mistakes**

❌ **Don't do this:**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=0
export ACCEPT_WEAK_PASSWORDS=FALSE  # This will cause conflicts!
```

✅ **Do this instead:**
```bash
export PASSWORD_STRENGTH_MIN_SCORE=0
export ACCEPT_WEAK_PASSWORDS=TRUE   # Allow weak passwords for testing
```

## 💡 **Pro Tips**

1. **Start Simple**: Begin with relaxed requirements and increase security gradually
2. **Test First**: Always test in development before production
3. **Document Changes**: Keep track of your configuration choices
4. **Security vs Usability**: Find the right balance for your users

This quick reference should get you up and running quickly! 🎉
