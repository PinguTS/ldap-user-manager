#!/bin/bash

# LDAP Setup Script for LDAP User Manager
# This script loads the required LDIF files in the correct order

set -e

echo "Setting up LDAP for LDAP User Manager..."
echo "========================================"

# Check if we're running as root (required for LDAP schema operations)
if [ "$EUID" -ne 0 ]; then
    echo "Error: This script must be run as root (use sudo)"
    echo "Root access is required to modify LDAP schema configuration"
    exit 1
fi

# Check if LDAP server is running
if ! systemctl is-active --quiet slapd; then
    echo "Error: LDAP server (slapd) is not running"
    echo "Please start the LDAP server first: sudo systemctl start slapd"
    exit 1
fi

echo "Step 1: Loading custom schema (userRole attribute)..."
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/userRole-schema.ldif
echo "✓ Custom schema loaded successfully"

echo "Step 2: Loading base directory structure..."
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/base.ldif
echo "✓ Base structure loaded successfully"

echo "Step 3: Loading system users..."
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/system_users.ldif
echo "✓ System users loaded successfully"

echo "Step 4: Loading example organization (optional)..."
ldapadd -Y EXTERNAL -H ldapi:/// -f ldif/example-org.ldif
echo "✓ Example organization loaded successfully"

echo ""
echo "LDAP setup completed successfully!"
echo ""
echo "You can now run the web-based setup to create the initial administrator account."
echo "Default credentials:"
echo "  - Admin user: admin@example.com / admin123"
echo "  - Maintainer user: maintainer@example.com / maintainer123"
echo ""
echo "IMPORTANT: Change these default passwords immediately!"
echo ""
echo "To verify the setup, you can run:"
echo "  ldapsearch -x -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w your_admin_password"
