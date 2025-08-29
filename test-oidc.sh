#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}Testing OIDC Integration Setup${NC}"
echo "=================================="

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo -e "${RED}Error: Docker is not running${NC}"
    exit 1
fi

# Check if Docker Compose is available
if ! command -v docker-compose > /dev/null 2>&1; then
    echo -e "${RED}Error: Docker Compose is not installed${NC}"
    exit 1
fi

# Check if services are running
echo -e "${YELLOW}Checking service status...${NC}"
if ! docker-compose ps | grep -q "Up"; then
    echo -e "${RED}Error: No services are running. Start them with 'docker-compose up -d'${NC}"
    exit 1
fi

# Check each service individually
echo -e "${YELLOW}Verifying individual services...${NC}"

# Check LDAP
if docker-compose ps | grep -q "ldap.*Up"; then
    echo -e "${GREEN}✓ LDAP server is running${NC}"
else
    echo -e "${RED}✗ LDAP server is not running${NC}"
fi

# Check Dex
if docker-compose ps | grep -q "dex.*Up"; then
    echo -e "${GREEN}✓ Dex OIDC provider is running${NC}"
else
    echo -e "${RED}✗ Dex OIDC provider is not running${NC}"
fi

# Check Web App
if docker-compose ps | grep -q "ldap-user-manager.*Up"; then
    echo -e "${GREEN}✓ LDAP User Manager is running${NC}"
else
    echo -e "${RED}✗ LDAP User Manager is not running${NC}"
fi

# Check Caddy
if docker-compose ps | grep -q "caddy.*Up"; then
    echo -e "${GREEN}✓ Caddy reverse proxy is running${NC}"
else
    echo -e "${RED}✗ Caddy reverse proxy is not running${NC}"
fi

# Check certificates
echo -e "${YELLOW}Checking certificates...${NC}"
if [ -f "certs/server.crt" ] && [ -f "certs/server.key" ]; then
    echo -e "${GREEN}✓ SSL certificates exist${NC}"
    
    # Check certificate validity
    if openssl x509 -checkend 0 -noout -in certs/server.crt > /dev/null 2>&1; then
        echo -e "${GREEN}✓ SSL certificate is valid${NC}"
    else
        echo -e "${RED}✗ SSL certificate has expired${NC}"
    fi
else
    echo -e "${RED}✗ SSL certificates are missing${NC}"
fi

# Check configuration files
echo -e "${YELLOW}Checking configuration files...${NC}"
if [ -f "dex/config.yaml" ]; then
    echo -e "${GREEN}✓ Dex configuration exists${NC}"
else
    echo -e "${RED}✗ Dex configuration is missing${NC}"
fi

if [ -f "caddy/Caddyfile" ]; then
    echo -e "${GREEN}✓ Caddy configuration exists${NC}"
else
    echo -e "${RED}✗ Caddy configuration is missing${NC}"
fi

if [ -f ".env" ]; then
    echo -e "${GREEN}✓ Environment file exists${NC}"
else
    echo -e "${YELLOW}⚠ Environment file is missing (copy from env.example)${NC}"
fi

# Test network connectivity
echo -e "${YELLOW}Testing network connectivity...${NC}"

# Test LDAP connection
echo -e "${YELLOW}Testing LDAP connection...${NC}"
if docker-compose exec -T ldap-user-manager ldapsearch -x -H ldaps://ldap-server:636 -b dc=example,dc=com -D cn=admin,dc=example,dc=com -w admin123 -o tls_reqcert=never > /dev/null 2>&1; then
    echo -e "${GREEN}✓ LDAP connection successful${NC}"
else
    echo -e "${RED}✗ LDAP connection failed${NC}"
fi

# Test Dex health endpoint
echo -e "${YELLOW}Testing Dex health endpoint...${NC}"
if curl -s -k https://localhost:5556/healthz > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Dex health check passed${NC}"
else
    echo -e "${YELLOW}⚠ Dex health check failed (may be expected if not accessible from host)${NC}"
fi

# Test OIDC discovery endpoint
echo -e "${YELLOW}Testing OIDC discovery endpoint...${NC}"
if curl -s -k https://localhost:5556/.well-known/openid_configuration > /dev/null 2>&1; then
    echo -e "${GREEN}✓ OIDC discovery endpoint accessible${NC}"
else
    echo -e "${YELLOW}⚠ OIDC discovery endpoint not accessible from host${NC}"
fi

# Check for common issues
echo -e "${YELLOW}Checking for common issues...${NC}"

# Check if client secrets are still default
if grep -q "your-client-secret-here" dex/config.yaml; then
    echo -e "${RED}✗ LDAP User Manager OIDC client secret is still default value${NC}"
    echo -e "${YELLOW}  Run setup-oidc.sh to generate a proper secret${NC}"
else
    echo -e "${GREEN}✓ LDAP User Manager OIDC client secret has been updated${NC}"
fi

if grep -q "your-typo3-client-secret-here" .env 2>/dev/null; then
    echo -e "${RED}✗ TYPO3 OIDC client secret is still default value${NC}"
    echo -e "${YELLOW}  Run setup-oidc.sh to generate a proper secret${NC}"
else
    echo -e "${GREEN}✓ TYPO3 OIDC client secret has been updated${NC}"
fi

# Check if hostnames are still example.org
if grep -q "example.org" dex/config.yaml; then
    echo -e "${YELLOW}⚠ Hostnames still use example.org (update for production)${NC}"
else
    echo -e "${GREEN}✓ Hostnames have been customized${NC}"
fi

# Summary
echo ""
echo -e "${BLUE}Test Summary${NC}"
echo "============"

if docker-compose ps | grep -q "Up" && [ -f "certs/server.crt" ] && [ -f "dex/config.yaml" ]; then
    echo -e "${GREEN}✓ Basic setup appears complete${NC}"
    echo ""
    echo -e "${BLUE}Next steps:${NC}"
    echo "1. Update hostnames in configuration files"
    echo "2. Test the authentication flow"
    echo "3. Verify user creation in LDAP"
    echo ""
    echo -e "${BLUE}Access URLs:${NC}"
    echo "- Application: https://app.example.org (update hostname)"
    echo "- Dex OIDC: https://id.example.org (update hostname)"
    echo "- OIDC Discovery: https://id.example.org/.well-known/openid_configuration"
else
    echo -e "${RED}✗ Setup is incomplete. Please run setup-oidc.sh first${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}Test completed!${NC}"
