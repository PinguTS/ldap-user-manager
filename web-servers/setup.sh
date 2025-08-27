#!/bin/bash

# LDAP User Manager - Setup Script
# This script handles both root and sub-path deployment for Apache and Nginx
# It automatically detects your web server and configures it appropriately

set -e

echo "🚀 LDAP User Manager - Setup Script"
echo "==================================="

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   echo "❌ This script should not be run as root"
   echo "   Please run as a regular user with sudo privileges"
   exit 1
fi

# Detect web server
WEB_SERVER=""
if command -v apache2 &> /dev/null; then
    WEB_SERVER="apache"
    echo "✅ Detected Apache web server"
elif command -v nginx &> /dev/null; then
    WEB_SERVER="nginx"
    echo "✅ Detected Nginx web server"
else
    echo "❌ No supported web server detected"
    echo "   Please install Apache or Nginx first"
    exit 1
fi

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed"
    echo "   Please install PHP first: sudo apt install php php-ldap"
    exit 1
fi

# Check PHP-FPM for Nginx
if [ "$WEB_SERVER" = "nginx" ]; then
    if ! command -v php-fpm &> /dev/null && ! systemctl list-unit-files | grep -q "php.*fpm"; then
        echo "❌ PHP-FPM is not installed"
        echo "   Please install PHP-FPM first: sudo apt install php-fpm php-ldap"
        exit 1
    fi
fi

echo "✅ Prerequisites check passed"

# Get installation directory
read -p "Enter installation directory [/var/www/ldap-user-manager]: " INSTALL_DIR
INSTALL_DIR=${INSTALL_DIR:-/var/www/ldap-user-manager}

# Get web server user
read -p "Enter web server user [www-data]: " WEB_USER
WEB_USER=${WEB_USER:-www-data}

# Get web server group
read -p "Enter web server group [www-data]: " WEB_GROUP
WEB_GROUP=${WEB_GROUP:-www-data}

# Get deployment type
echo ""
echo "🌐 Deployment Type:"
echo "   1. Root deployment (http://your-domain.com/)"
echo "   2. Sub-path deployment (http://your-domain.com/ldap-manager/)"
read -p "Choose deployment type (1 or 2): " DEPLOYMENT_TYPE

if [ "$DEPLOYMENT_TYPE" = "2" ]; then
    # Get sub-path
    read -p "Enter sub-path (e.g., ldap-manager, apps/user-manager): " SUB_PATH
    if [ -z "$SUB_PATH" ]; then
        echo "❌ Sub-path is required"
        exit 1
    fi
    
    # Remove leading and trailing slashes
    SUB_PATH=$(echo "$SUB_PATH" | sed 's|^/||' | sed 's|/$||')
    DEPLOYMENT_NAME="sub-path /$SUB_PATH"
else
    SUB_PATH=""
    DEPLOYMENT_NAME="root"
fi

# Get domain name (for Nginx)
if [ "$WEB_SERVER" = "nginx" ]; then
    read -p "Enter your domain name [localhost]: " DOMAIN
    DOMAIN=${DOMAIN:-localhost}
    
    # Get PHP-FPM socket path
    read -p "Enter PHP-FPM socket path [/var/run/php/php8.0-fpm.sock]: " PHP_SOCKET
    PHP_SOCKET=${PHP_SOCKET:-/var/run/php/php8.0-fpm.sock}
fi

echo ""
echo "📋 Installation Summary:"
echo "   Web Server: $WEB_SERVER"
echo "   Directory: $INSTALL_DIR"
echo "   User: $WEB_USER"
echo "   Group: $WEB_GROUP"
echo "   Deployment: $DEPLOYMENT_NAME"
if [ "$WEB_SERVER" = "nginx" ]; then
    echo "   Domain: $DOMAIN"
    echo "   PHP-FPM Socket: $PHP_SOCKET"
fi
echo ""

read -p "Continue with installation? (y/N): " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Installation cancelled"
    exit 1
fi

echo "🔧 Setting up $WEB_SERVER configuration for $DEPLOYMENT_NAME deployment..."

# Create installation directory
sudo mkdir -p "$INSTALL_DIR"

if [ "$WEB_SERVER" = "apache" ]; then
    # Apache setup
    echo "📝 Configuring Apache .htaccess..."
    
    if [ -z "$SUB_PATH" ]; then
        # Root deployment
        sudo cp web-servers/.htaccess "$INSTALL_DIR/www/.htaccess"
        echo "✅ .htaccess configured for root deployment"
    else
        # Sub-path deployment
        sudo cp web-servers/.htaccess "$INSTALL_DIR/www/.htaccess"
        # Update RewriteBase
        sudo sed -i "s|RewriteBase /|RewriteBase /$SUB_PATH/|" "$INSTALL_DIR/www/.htaccess"
        echo "✅ .htaccess configured for sub-path /$SUB_PATH"
    fi
    
elif [ "$WEB_SERVER" = "nginx" ]; then
    # Nginx setup
    echo "📝 Configuring Nginx configuration..."
    
    sudo cp web-servers/nginx.conf /etc/nginx/sites-available/ldap-user-manager
    
    # Update configuration with user values
    sudo sed -i "s|your-domain.com|$DOMAIN|g" /etc/nginx/sites-available/ldap-user-manager
    sudo sed -i "s|/var/www/ldap-user-manager/www|$INSTALL_DIR/www|g" /etc/nginx/sites-available/ldap-user-manager
    sudo sed -i "s|/var/run/php/php8.0-fpm.sock|$PHP_SOCKET|g" /etc/nginx/sites-available/ldap-user-manager
    
    if [ -z "$SUB_PATH" ]; then
        # Root deployment
        sudo sed -i 's|set $base_path "";|set $base_path "";|' /etc/nginx/sites-available/ldap-user-manager
        echo "✅ Nginx configuration configured for root deployment"
    else
        # Sub-path deployment
        sudo sed -i "s|set \$base_path \"\";|set \$base_path \"/$SUB_PATH\";|" /etc/nginx/sites-available/ldap-user-manager
        echo "✅ Nginx configuration configured for sub-path /$SUB_PATH"
    fi
    
    # Enable site
    sudo ln -sf /etc/nginx/sites-available/ldap-user-manager /etc/nginx/sites-enabled/
    
    # Remove default site if it exists
    if [ -L /etc/nginx/sites-enabled/default ]; then
        sudo rm /etc/nginx/sites-enabled/default
        echo "✅ Removed default Nginx site"
    fi
fi

# Set permissions
sudo chown -R "$WEB_USER:$WEB_GROUP" "$INSTALL_DIR"
sudo chmod -R 755 "$INSTALL_DIR"
if [ "$WEB_SERVER" = "apache" ]; then
    sudo chmod 644 "$INSTALL_DIR/www/.htaccess"
fi

echo "✅ File permissions set"

# Check web server modules/extensions
echo "🔍 Checking web server configuration..."

if [ "$WEB_SERVER" = "apache" ]; then
    # Check Apache modules
    REQUIRED_MODULES=("rewrite" "headers" "expires" "deflate")
    MISSING_MODULES=()
    
    for module in "${REQUIRED_MODULES[@]}"; do
        if ! apache2ctl -M | grep -q "$module"; then
            MISSING_MODULES+=("$module")
        fi
    done
    
    if [ ${#MISSING_MODULES[@]} -ne 0 ]; then
        echo "⚠️  Missing Apache modules: ${MISSING_MODULES[*]}"
        echo "   Enable them with: sudo a2enmod ${MISSING_MODULES[*]}"
        echo "   Then restart Apache: sudo systemctl restart apache2"
    else
        echo "✅ All required Apache modules are enabled"
    fi
    
elif [ "$WEB_SERVER" = "nginx" ]; then
    # Test Nginx configuration
    echo "🔍 Testing Nginx configuration..."
    if sudo nginx -t; then
        echo "✅ Nginx configuration is valid"
    else
        echo "❌ Nginx configuration has errors"
        echo "   Please check the configuration and try again"
        exit 1
    fi
    
    # Check PHP-FPM status
    echo "🔍 Checking PHP-FPM status..."
    if systemctl is-active --quiet php*-fpm; then
        echo "✅ PHP-FPM is running"
    else
        echo "⚠️  PHP-FPM is not running"
        echo "   Start it with: sudo systemctl start php*-fpm"
    fi
    
    # Check PHP socket
    if [ -S "$PHP_SOCKET" ]; then
        echo "✅ PHP-FPM socket found: $PHP_SOCKET"
    else
        echo "⚠️  PHP-FPM socket not found: $PHP_SOCKET"
        echo "   Please check your PHP-FPM configuration"
    fi
fi

# Check PHP extensions
echo "🔍 Checking PHP extensions..."

REQUIRED_EXTENSIONS=("ldap" "openssl" "mbstring" "json")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -q "$ext"; then
        MISSING_EXTENSIONS+=("$ext")
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -ne 0 ]; then
    echo "⚠️  Missing PHP extensions: ${MISSING_EXTENSIONS[*]}"
    echo "   Install them with: sudo apt install php-${MISSING_EXTENSIONS[*]}"
    if [ "$WEB_SERVER" = "nginx" ]; then
        echo "   Then restart PHP-FPM: sudo systemctl restart php*-fpm"
    fi
else
    echo "✅ All required PHP extensions are installed"
fi

# Reload web server
if [ "$WEB_SERVER" = "apache" ]; then
    echo "🔄 Reloading Apache..."
    sudo systemctl reload apache2
elif [ "$WEB_SERVER" = "nginx" ]; then
    echo "🔄 Reloading Nginx..."
    sudo systemctl reload nginx
fi

echo ""
echo "🎉 $WEB_SERVER setup completed for $DEPLOYMENT_NAME deployment!"
echo ""

# Display next steps
if [ "$WEB_SERVER" = "apache" ]; then
    echo "📝 Next steps:"
    echo "   1. Configure your Apache VirtualHost to point to: $INSTALL_DIR/www"
    echo "   2. Ensure AllowOverride All is set for the directory"
    echo "   3. Copy CONFIGURATION_VARIABLES.md to .env and configure LDAP settings"
    echo "   4. Restart Apache: sudo systemctl restart apache2"
    if [ -n "$SUB_PATH" ]; then
        echo "   5. Access your application at: http://your-domain.com/$SUB_PATH"
    else
        echo "   5. Access your application at: http://your-domain.com"
    fi
    
    echo ""
    echo "🔧 Apache VirtualHost Example:"
    echo "   <VirtualHost *:80>"
    echo "       ServerName your-domain.com"
    echo "       DocumentRoot $INSTALL_DIR/www"
    echo "       <Directory $INSTALL_DIR/www>"
    echo "           AllowOverride All"
    echo "           Require all granted"
    echo "       </Directory>"
    echo "   </VirtualHost>"
    
elif [ "$WEB_SERVER" = "nginx" ]; then
    echo "📝 Next steps:"
    echo "   1. Copy your application files to: $INSTALL_DIR/www"
    echo "   2. Copy CONFIGURATION_VARIABLES.md to .env and configure LDAP settings"
    echo "   3. Ensure PHP-FPM is running: sudo systemctl status php*-fpm"
    if [ -n "$SUB_PATH" ]; then
        echo "   4. Test your site: http://$DOMAIN/$SUB_PATH"
    else
        echo "   4. Test your site: http://$DOMAIN"
    fi
    
    echo ""
    echo "🔧 Nginx Configuration Details:"
    echo "   - Site configuration: /etc/nginx/sites-available/ldap-user-manager"
    echo "   - Enabled at: /etc/nginx/sites-enabled/ldap-user-manager"
    if [ -n "$SUB_PATH" ]; then
        echo "   - Sub-path configured: /$SUB_PATH"
    else
        echo "   - Root deployment configured"
    fi
fi

echo ""
echo "📚 For detailed instructions, see: web-servers/DEPLOYMENT.md"
echo "🐳 For Docker deployment, see: DOCKER-SETUP.md"
echo ""

# Display useful commands
if [ "$WEB_SERVER" = "apache" ]; then
    echo "🔧 Useful commands:"
    echo "   - Check Apache modules: apache2ctl -M"
    echo "   - Test Apache config: apache2ctl -t"
    echo "   - Restart Apache: sudo systemctl restart apache2"
elif [ "$WEB_SERVER" = "nginx" ]; then
    echo "🔧 Useful commands:"
    echo "   - Test Nginx config: sudo nginx -t"
    echo "   - Reload Nginx: sudo systemctl reload nginx"
    echo "   - Check Nginx status: sudo systemctl status nginx"
    echo "   - Check PHP-FPM status: sudo systemctl status php*-fpm"
fi

echo ""
echo "🌐 Your application will be accessible at:"
if [ -n "$SUB_PATH" ]; then
    echo "   - Main page: http://your-domain.com/$SUB_PATH"
    echo "   - Setup: http://your-domain.com/$SUB_PATH/setup/"
    echo "   - Management: http://your-domain.com/$SUB_PATH/manage/"
else
    echo "   - Main page: http://your-domain.com"
    echo "   - Setup: http://your-domain.com/setup/"
    echo "   - Management: http://your-domain.com/manage/"
fi
