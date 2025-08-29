#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
CERT_DIR="certs"
DOMAIN_APP="app.example.org"
DOMAIN_ID="id.example.org"
CERT_DAYS=365

echo -e "${BLUE}Setting up OIDC environment for LDAP User Manager${NC}"
echo "=================================================="

# Check if OpenSSL is available
if ! command -v openssl &> /dev/null; then
    echo -e "${RED}Error: OpenSSL is not installed. Please install OpenSSL first.${NC}"
    exit 1
fi

# Create certificates directory
echo -e "${YELLOW}Creating certificates directory...${NC}"
mkdir -p "$CERT_DIR"

# Generate CA private key and certificate
echo -e "${YELLOW}Generating CA private key and certificate...${NC}"
openssl genrsa -out "$CERT_DIR/ca.key" 4096
openssl req -x509 -new -nodes \
    -key "$CERT_DIR/ca.key" \
    -sha256 -days $CERT_DAYS \
    -out "$CERT_DIR/ca.crt" \
    -subj "/C=US/ST=State/L=City/O=Organization/OU=IT/CN=LDAP-User-Manager-CA"

# Generate server private key
echo -e "${YELLOW}Generating server private key...${NC}"
openssl genrsa -out "$CERT_DIR/server.key" 2048

# Create server certificate signing request
echo -e "${YELLOW}Creating server certificate signing request...${NC}"
cat > "$CERT_DIR/server.conf" << EOF
[req]
req_extensions = v3_req
distinguished_name = req_distinguished_name
prompt = no

[req_distinguished_name]
C = US
ST = State
L = City
O = Organization
OU = IT
CN = $DOMAIN_APP

[v3_req]
keyUsage = keyEncipherment, dataEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = $DOMAIN_APP
DNS.2 = $DOMAIN_ID
DNS.3 = localhost
IP.1 = 127.0.0.1
EOF

# Generate server certificate
echo -e "${YELLOW}Generating server certificate...${NC}"
openssl req -new -key "$CERT_DIR/server.key" \
    -out "$CERT_DIR/server.csr" \
    -config "$CERT_DIR/server.conf"

# Sign server certificate with CA
openssl x509 -req -in "$CERT_DIR/server.csr" \
    -CA "$CERT_DIR/ca.crt" \
    -CAkey "$CERT_DIR/ca.key" \
    -CAcreateserial \
    -out "$CERT_DIR/server.crt" \
    -days $CERT_DAYS \
    -extensions v3_req \
    -extfile "$CERT_DIR/server.conf"

# Copy certificate for Dex (it expects ca.crt)
echo -e "${YELLOW}Copying certificate for Dex...${NC}"
cp "$CERT_DIR/server.crt" "$CERT_DIR/dex.crt"

# Set proper permissions
echo -e "${YELLOW}Setting certificate permissions...${NC}"
chmod 600 "$CERT_DIR"/*.key
chmod 644 "$CERT_DIR"/*.crt

# Clean up temporary files
echo -e "${YELLOW}Cleaning up temporary files...${NC}"
rm -f "$CERT_DIR/server.csr" "$CERT_DIR/server.conf" "$CERT_DIR/ca.srl"

# Create .env file from example if it doesn't exist
if [ ! -f ".env" ]; then
    echo -e "${YELLOW}Creating .env file from example...${NC}"
    if [ -f "env.example" ]; then
        cp env.example .env
        echo -e "${GREEN}Created .env file. Please review and update the values.${NC}"
    else
        echo -e "${YELLOW}No env.example found. Please create .env file manually.${NC}"
    fi
fi

# Generate random client secrets
echo -e "${YELLOW}Generating OIDC client secrets...${NC}"
CLIENT_SECRET=$(openssl rand -base64 32)
TYPO3_CLIENT_SECRET=$(openssl rand -base64 32)
GITLAB_CLIENT_SECRET=$(openssl rand -base64 32)
NEXTCLOUD_CLIENT_SECRET=$(openssl rand -base64 32)
echo -e "${GREEN}Generated LDAP User Manager client secret: $CLIENT_SECRET${NC}"
echo -e "${GREEN}Generated TYPO3 client secret: $TYPO3_CLIENT_SECRET${NC}"
echo -e "${GREEN}Generated GitLab client secret: $GITLAB_CLIENT_SECRET${NC}"
echo -e "${GREEN}Generated Nextcloud client secret: $NEXTCLOUD_CLIENT_SECRET${NC}"
echo -e "${YELLOW}Please update these in your .env file and Dex configuration.${NC}"
echo -e "${YELLOW}Note: TYPO3, GitLab, and Nextcloud run on external servers.${NC}"

# Update Dex configuration with the generated secrets
if [ -f "dex/config.yaml" ]; then
    echo -e "${YELLOW}Updating Dex configuration with generated secrets...${NC}"
    sed -i.bak "s/your-client-secret-here/$CLIENT_SECRET/g" dex/config.yaml
    echo -e "${GREEN}Updated Dex configuration for LDAP User Manager.${NC}"
fi

# Update environment file with all client secrets
if [ -f ".env" ]; then
    echo -e "${YELLOW}Updating .env file with all client secrets...${NC}"
    sed -i.bak "s/your-typo3-client-secret-here/$TYPO3_CLIENT_SECRET/g" .env
    sed -i.bak "s/your-gitlab-client-secret-here/$GITLAB_CLIENT_SECRET/g" .env
    sed -i.bak "s/your-nextcloud-client-secret-here/$NEXTCLOUD_CLIENT_SECRET/g" .env
    echo -e "${GREEN}Updated .env file with all client secrets.${NC}"
fi

echo ""
echo -e "${GREEN}Setup completed successfully!${NC}"
echo "=================================================="
echo -e "${BLUE}Next steps:${NC}"
echo "1. Review and update the .env file with your configuration"
echo "2. Update the hostnames in dex/config.yaml if needed"
echo "3. Run 'docker-compose up -d' to start all services"
echo "4. Check service status with 'docker-compose ps'"
echo ""
echo -e "${YELLOW}Important:${NC}"
echo "- The generated certificates are self-signed and for development only"
echo "- For production, use valid SSL certificates from a trusted CA"
echo "- Update the OIDC client secret in both .env and Dex configuration"
echo "- Ensure your DNS resolves app.example.org and id.example.org to your server"
