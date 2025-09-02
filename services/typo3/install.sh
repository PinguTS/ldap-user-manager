#!/bin/bash
# TYPO3 Installation Script with OIDC Integration

set -e

echo "=== TYPO3 Installation with OIDC Integration ==="

# Configuration
TYPO3_VERSION="11.5"
TYPO3_DIR="/var/www/html/typo3"
TYPO3_URL="https://typo3.example.org"
OIDC_ISSUER="https://id.example.org"
OIDC_CLIENT_ID="typo3"
OIDC_CLIENT_SECRET="your-typo3-client-secret-here"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to check prerequisites
check_prerequisites() {
    print_status "Checking prerequisites..."
    
    # Check if running as root
    if [[ $EUID -eq 0 ]]; then
        print_error "This script should not be run as root"
        exit 1
    fi
    
    # Check if required commands exist
    command -v composer >/dev/null 2>&1 || { print_error "Composer is required but not installed"; exit 1; }
    command -v php >/dev/null 2>&1 || { print_error "PHP is required but not installed"; exit 1; }
    command -v mysql >/dev/null 2>&1 || { print_error "MySQL client is required but not installed"; exit 1; }
    
    # Check PHP version
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    if [[ $(echo "$PHP_VERSION 7.4" | tr " " "\n" | sort -V | head -n 1) != "7.4" ]]; then
        print_error "PHP 7.4 or higher is required (found $PHP_VERSION)"
        exit 1
    fi
    
    print_status "Prerequisites check passed"
}

# Function to create TYPO3 installation
install_typo3() {
    print_status "Installing TYPO3 CMS..."
    
    # Create TYPO3 directory
    sudo mkdir -p "$TYPO3_DIR"
    sudo chown -R $USER:$USER "$TYPO3_DIR"
    
    # Install TYPO3 via Composer
    cd "$TYPO3_DIR"
    composer create-project typo3/cms-base-distribution:$TYPO3_VERSION .
    
    # Set proper permissions
    sudo chown -R www-data:www-data "$TYPO3_DIR"
    sudo chmod -R 755 "$TYPO3_DIR"
    sudo chmod -R 777 "$TYPO3_DIR/public/fileadmin"
    sudo chmod -R 777 "$TYPO3_DIR/public/typo3temp"
    
    print_status "TYPO3 CMS installed successfully"
}

# Function to install OIDC extension
install_oidc_extension() {
    print_status "Installing OIDC extension..."
    
    cd "$TYPO3_DIR"
    
    # Install Causal OIDC extension
    composer require causal/oidc
    
    # Install LDAP SSO extension (optional)
    composer require ichhabrecht/ig-ldap-sso-auth
    
    print_status "OIDC extension installed successfully"
}

# Function to configure TYPO3
configure_typo3() {
    print_status "Configuring TYPO3..."
    
    cd "$TYPO3_DIR"
    
    # Create configuration file
    cat > config/system/additional.php << EOF
<?php
defined('TYPO3') or die();

// OIDC Configuration
\$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['oidc'] = [
    'enabled' => true,
    'issuer' => '$OIDC_ISSUER',
    'clientId' => '$OIDC_CLIENT_ID',
    'clientSecret' => '$OIDC_CLIENT_SECRET',
    'redirectUri' => '$TYPO3_URL/index.php?eID=oidc',
    'scopes' => 'openid profile email groups',
    'autoLogin' => true,
    'autoLogout' => true,
    'userMapping' => [
        'username' => 'preferred_username',
        'email' => 'email',
        'firstName' => 'given_name',
        'lastName' => 'family_name',
        'groups' => 'groups'
    ]
];

// LDAP Configuration (optional)
\$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ig_ldap_sso_auth'] = [
    'enabled' => true,
    'ldapHost' => 'ldap.example.com',
    'ldapPort' => 636,
    'ldapBaseDn' => 'dc=example,dc=com',
    'ldapBindDn' => 'cn=admin,dc=example,dc=com',
    'ldapBindPassword' => 'admin123',
    'ldapUserFilter' => '(objectClass=inetOrgPerson)',
    'ldapGroupFilter' => '(objectClass=groupOfNames)'
];
EOF
    
    print_status "TYPO3 configured successfully"
}

# Function to configure web server
configure_webserver() {
    print_status "Configuring web server..."
    
    # Create Apache virtual host
    sudo tee /etc/apache2/sites-available/typo3.conf > /dev/null << EOF
<VirtualHost *:80>
    ServerName typo3.example.org
    DocumentRoot $TYPO3_DIR/public
    
    <Directory $TYPO3_DIR/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/typo3_error.log
    CustomLog \${APACHE_LOG_DIR}/typo3_access.log combined
</VirtualHost>
EOF
    
    # Enable site
    sudo a2ensite typo3.conf
    sudo systemctl reload apache2
    
    print_status "Web server configured successfully"
}

# Function to create database
create_database() {
    print_status "Creating database..."
    
    # Database configuration
    DB_NAME="typo3"
    DB_USER="typo3"
    DB_PASSWORD="typo3_password"
    
    # Create database and user
    mysql -u root -p << EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    print_status "Database created successfully"
}

# Function to run TYPO3 setup
run_typo3_setup() {
    print_status "Running TYPO3 setup..."
    
    cd "$TYPO3_DIR"
    
    # Run TYPO3 setup
    php bin/typo3 setup:run \
        --database-host=localhost \
        --database-port=3306 \
        --database-name=typo3 \
        --database-username=typo3 \
        --database-password=typo3_password \
        --admin-username=admin \
        --admin-password=admin123 \
        --site-name="TYPO3 with OIDC Integration" \
        --site-setup-type=site
    
    print_status "TYPO3 setup completed successfully"
}

# Function to enable extensions
enable_extensions() {
    print_status "Enabling extensions..."
    
    cd "$TYPO3_DIR"
    
    # Enable OIDC extension
    php bin/typo3 extension:activate oidc
    
    # Enable LDAP SSO extension
    php bin/typo3 extension:activate ig_ldap_sso_auth
    
    print_status "Extensions enabled successfully"
}

# Function to configure OIDC settings
configure_oidc_settings() {
    print_status "Configuring OIDC settings..."
    
    cd "$TYPO3_DIR"
    
    # Configure OIDC extension settings
    php bin/typo3 configuration:set EXTENSIONS.oidc.enabled true
    php bin/typo3 configuration:set EXTENSIONS.oidc.issuer "$OIDC_ISSUER"
    php bin/typo3 configuration:set EXTENSIONS.oidc.clientId "$OIDC_CLIENT_ID"
    php bin/typo3 configuration:set EXTENSIONS.oidc.clientSecret "$OIDC_CLIENT_SECRET"
    php bin/typo3 configuration:set EXTENSIONS.oidc.redirectUri "$TYPO3_URL/index.php?eID=oidc"
    php bin/typo3 configuration:set EXTENSIONS.oidc.scopes "openid profile email groups"
    
    print_status "OIDC settings configured successfully"
}

# Function to test installation
test_installation() {
    print_status "Testing installation..."
    
    # Test TYPO3 accessibility
    if curl -f -s "$TYPO3_URL" > /dev/null; then
        print_status "✅ TYPO3 is accessible"
    else
        print_error "❌ TYPO3 is not accessible"
        return 1
    fi
    
    # Test OIDC endpoint
    if curl -f -s "$TYPO3_URL/index.php?eID=oidc" > /dev/null; then
        print_status "✅ OIDC endpoint is accessible"
    else
        print_warning "⚠️  OIDC endpoint is not accessible"
    fi
    
    # Test OIDC provider
    if curl -f -s "$OIDC_ISSUER/.well-known/openid_configuration" > /dev/null; then
        print_status "✅ OIDC provider is accessible"
    else
        print_warning "⚠️  OIDC provider is not accessible"
    fi
    
    print_status "Installation test completed"
}

# Function to display next steps
display_next_steps() {
    echo ""
    print_status "=== Installation Completed Successfully ==="
    echo ""
    echo "Next steps:"
    echo "1. Access TYPO3 admin panel: $TYPO3_URL/typo3"
    echo "2. Login with admin credentials: admin / admin123"
    echo "3. Go to Admin Tools > Extensions to verify OIDC extension"
    echo "4. Configure OIDC settings in the extension configuration"
    echo "5. Test OIDC login flow"
    echo ""
    echo "Important:"
    echo "- Change default admin password"
    echo "- Update OIDC client secret in configuration"
    echo "- Configure SSL certificates for production"
    echo "- Review security settings"
    echo ""
}

# Main installation process
main() {
    echo "Starting TYPO3 installation with OIDC integration..."
    echo "TYPO3 URL: $TYPO3_URL"
    echo "OIDC Issuer: $OIDC_ISSUER"
    echo "OIDC Client ID: $OIDC_CLIENT_ID"
    echo ""
    
    check_prerequisites
    install_typo3
    install_oidc_extension
    configure_typo3
    configure_webserver
    create_database
    run_typo3_setup
    enable_extensions
    configure_oidc_settings
    test_installation
    display_next_steps
}

# Run main function
main "$@"
