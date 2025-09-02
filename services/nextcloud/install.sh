#!/bin/bash
# Nextcloud Installation Script with OIDC Integration

set -e

echo "=== Nextcloud Installation with OIDC Integration ==="

# Configuration
NEXTCLOUD_VERSION="25.0.0"
NEXTCLOUD_DIR="/var/www/html/nextcloud"
NEXTCLOUD_URL="https://nextcloud.example.org"
OIDC_ISSUER="https://id.example.org"
OIDC_CLIENT_ID="nextcloud"
OIDC_CLIENT_SECRET="your-nextcloud-client-secret-here"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

print_status() { echo -e "${GREEN}[INFO]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
print_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Check prerequisites
check_prerequisites() {
    print_status "Checking prerequisites..."
    command -v php >/dev/null 2>&1 || { print_error "PHP is required"; exit 1; }
    command -v mysql >/dev/null 2>&1 || { print_error "MySQL client is required"; exit 1; }
    command -v wget >/dev/null 2>&1 || { print_error "wget is required"; exit 1; }
    print_status "Prerequisites check passed"
}

# Install Nextcloud
install_nextcloud() {
    print_status "Installing Nextcloud..."
    sudo mkdir -p "$NEXTCLOUD_DIR"
    sudo chown -R $USER:$USER "$NEXTCLOUD_DIR"
    cd "$NEXTCLOUD_DIR"
    wget https://download.nextcloud.com/server/releases/nextcloud-${NEXTCLOUD_VERSION}.zip
    unzip nextcloud-${NEXTCLOUD_VERSION}.zip
    sudo chown -R www-data:www-data "$NEXTCLOUD_DIR"
    sudo chmod -R 755 "$NEXTCLOUD_DIR"
    print_status "Nextcloud installed successfully"
}

# Install OIDC app
install_oidc_app() {
    print_status "Installing OIDC Login app..."
    cd "$NEXTCLOUD_DIR"
    sudo -u www-data php occ app:install oidc_login
    print_status "OIDC Login app installed successfully"
}

# Configure Nextcloud
configure_nextcloud() {
    print_status "Configuring Nextcloud..."
    cd "$NEXTCLOUD_DIR"
    
    # Create config.php
    sudo -u www-data php occ maintenance:install \
        --database mysql \
        --database-name nextcloud \
        --database-user nextcloud \
        --database-pass nextcloud_password \
        --admin-user admin \
        --admin-pass admin123 \
        --data-dir /var/www/html/nextcloud/data
    
    # Configure OIDC
    sudo -u www-data php occ config:app:set oidc_login provider-url --value="$OIDC_ISSUER"
    sudo -u www-data php occ config:app:set oidc_login client-id --value="$OIDC_CLIENT_ID"
    sudo -u www-data php occ config:app:set oidc_login client-secret --value="$OIDC_CLIENT_SECRET"
    sudo -u www-data php occ config:app:set oidc_login redirect-url --value="$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc"
    sudo -u www-data php occ config:app:set oidc_login scope --value="openid profile email groups"
    sudo -u www-data php occ config:app:set oidc_login auto-provision --value="1"
    
    print_status "Nextcloud configured successfully"
}

# Configure web server
configure_webserver() {
    print_status "Configuring web server..."
    sudo tee /etc/apache2/sites-available/nextcloud.conf > /dev/null << EOF
<VirtualHost *:80>
    ServerName nextcloud.example.org
    DocumentRoot $NEXTCLOUD_DIR
    
    <Directory $NEXTCLOUD_DIR>
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/nextcloud_error.log
    CustomLog \${APACHE_LOG_DIR}/nextcloud_access.log combined
</VirtualHost>
EOF
    
    sudo a2ensite nextcloud.conf
    sudo systemctl reload apache2
    print_status "Web server configured successfully"
}

# Create database
create_database() {
    print_status "Creating database..."
    mysql -u root -p << EOF
CREATE DATABASE IF NOT EXISTS nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'nextcloud'@'localhost' IDENTIFIED BY 'nextcloud_password';
GRANT ALL PRIVILEGES ON nextcloud.* TO 'nextcloud'@'localhost';
FLUSH PRIVILEGES;
EOF
    print_status "Database created successfully"
}

# Test installation
test_installation() {
    print_status "Testing installation..."
    if curl -f -s "$NEXTCLOUD_URL" > /dev/null; then
        print_status "✅ Nextcloud is accessible"
    else
        print_error "❌ Nextcloud is not accessible"
        return 1
    fi
    
    if curl -f -s "$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc" > /dev/null; then
        print_status "✅ OIDC endpoint is accessible"
    else
        print_warning "⚠️  OIDC endpoint is not accessible"
    fi
    
    print_status "Installation test completed"
}

# Display next steps
display_next_steps() {
    echo ""
    print_status "=== Installation Completed Successfully ==="
    echo ""
    echo "Next steps:"
    echo "1. Access Nextcloud: $NEXTCLOUD_URL"
    echo "2. Login with admin credentials: admin / admin123"
    echo "3. Go to Apps to verify OIDC Login app"
    echo "4. Configure OIDC settings in the app"
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
    echo "Starting Nextcloud installation with OIDC integration..."
    echo "Nextcloud URL: $NEXTCLOUD_URL"
    echo "OIDC Issuer: $OIDC_ISSUER"
    echo "OIDC Client ID: $OIDC_CLIENT_ID"
    echo ""
    
    check_prerequisites
    install_nextcloud
    install_oidc_app
    create_database
    configure_nextcloud
    configure_webserver
    test_installation
    display_next_steps
}

main "$@"
