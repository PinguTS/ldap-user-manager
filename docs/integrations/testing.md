# Testing Integration

This guide provides comprehensive testing procedures for LDAP User Manager integrations.

## Overview

Testing your integration ensures:
- **Authentication works correctly**
- **User data is properly synchronized**
- **Group memberships are accurate**
- **Error handling works as expected**
- **Performance meets requirements**

## Pre-Testing Checklist

### Environment Setup
- [ ] LDAP User Manager is running and accessible
- [ ] Test users are created in LDAP
- [ ] Test groups are configured
- [ ] Network connectivity is verified
- [ ] SSL certificates are valid

### Test Data Preparation
- [ ] Create test users with different roles
- [ ] Set up test organizations
- [ ] Configure test groups
- [ ] Prepare test credentials
- [ ] Document expected test results

## OIDC Integration Testing

### Basic OIDC Flow Test

#### Test Script
```bash
#!/bin/bash
# test_oidc_basic.sh

echo "=== OIDC Basic Flow Test ==="

# Configuration
OIDC_ISSUER="https://id.example.org"
CLIENT_ID="test-client"
CLIENT_SECRET="test-secret"
REDIRECT_URI="https://test.example.org/callback"

# Step 1: Test OIDC provider accessibility
echo "1. Testing OIDC provider accessibility..."
if curl -f -s "$OIDC_ISSUER/.well-known/openid_configuration" > /dev/null; then
    echo "✅ OIDC provider is accessible"
else
    echo "❌ OIDC provider is not accessible"
    exit 1
fi

# Step 2: Test client configuration
echo "2. Testing client configuration..."
TOKEN_RESPONSE=$(curl -s -X POST "$OIDC_ISSUER/token" \
    -d "grant_type=client_credentials&client_id=$CLIENT_ID&client_secret=$CLIENT_SECRET")

if echo "$TOKEN_RESPONSE" | jq -e '.access_token' > /dev/null; then
    echo "✅ Client configuration is working"
    ACCESS_TOKEN=$(echo "$TOKEN_RESPONSE" | jq -r '.access_token')
else
    echo "❌ Client configuration failed"
    echo "Response: $TOKEN_RESPONSE"
    exit 1
fi

# Step 3: Test user authentication
echo "3. Testing user authentication..."
USER_TOKEN_RESPONSE=$(curl -s -X POST "$OIDC_ISSUER/token" \
    -d "grant_type=password&username=admin@example.com&password=admin123&client_id=$CLIENT_ID&client_secret=$CLIENT_SECRET")

if echo "$USER_TOKEN_RESPONSE" | jq -e '.access_token' > /dev/null; then
    echo "✅ User authentication is working"
    USER_ACCESS_TOKEN=$(echo "$USER_TOKEN_RESPONSE" | jq -r '.access_token')
else
    echo "❌ User authentication failed"
    echo "Response: $USER_TOKEN_RESPONSE"
    exit 1
fi

# Step 4: Test user info endpoint
echo "4. Testing user info endpoint..."
USER_INFO=$(curl -s -H "Authorization: Bearer $USER_ACCESS_TOKEN" \
    "$OIDC_ISSUER/userinfo")

if echo "$USER_INFO" | jq -e '.sub' > /dev/null; then
    echo "✅ User info endpoint is working"
    USER_NAME=$(echo "$USER_INFO" | jq -r '.name // .preferred_username')
    echo "   User: $USER_NAME"
else
    echo "❌ User info endpoint failed"
    echo "Response: $USER_INFO"
    exit 1
fi

echo "=== OIDC Basic Flow Test Completed Successfully ==="
```

#### Manual Testing Steps
1. **Access OIDC Provider**
   ```bash
   curl -v https://id.example.org/.well-known/openid_configuration
   ```

2. **Test Client Credentials**
   ```bash
   curl -X POST https://id.example.org/token \
     -d "grant_type=client_credentials&client_id=your-client-id&client_secret=your-client-secret"
   ```

3. **Test User Authentication**
   ```bash
   curl -X POST https://id.example.org/token \
     -d "grant_type=password&username=test@example.com&password=test123&client_id=your-client-id&client_secret=your-client-secret"
   ```

4. **Test User Info**
   ```bash
   curl -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
     https://id.example.org/userinfo
   ```

### OIDC Authorization Code Flow Test

#### Test Script
```bash
#!/bin/bash
# test_oidc_auth_code.sh

echo "=== OIDC Authorization Code Flow Test ==="

# Configuration
OIDC_ISSUER="https://id.example.org"
CLIENT_ID="test-client"
CLIENT_SECRET="test-secret"
REDIRECT_URI="https://test.example.org/callback"
STATE=$(openssl rand -hex 16)

# Step 1: Generate authorization URL
echo "1. Generating authorization URL..."
AUTH_URL="$OIDC_ISSUER/auth?response_type=code&client_id=$CLIENT_ID&redirect_uri=$REDIRECT_URI&scope=openid%20profile%20email%20groups&state=$STATE"
echo "Authorization URL: $AUTH_URL"

# Step 2: Simulate user consent (requires manual intervention)
echo "2. Manual step required:"
echo "   - Open the authorization URL in a browser"
echo "   - Complete the login process"
echo "   - Copy the authorization code from the redirect URL"
echo "   - Enter the authorization code:"
read -p "Authorization Code: " AUTH_CODE

# Step 3: Exchange code for token
echo "3. Exchanging authorization code for token..."
TOKEN_RESPONSE=$(curl -s -X POST "$OIDC_ISSUER/token" \
    -d "grant_type=authorization_code&client_id=$CLIENT_ID&client_secret=$CLIENT_SECRET&code=$AUTH_CODE&redirect_uri=$REDIRECT_URI")

if echo "$TOKEN_RESPONSE" | jq -e '.access_token' > /dev/null; then
    echo "✅ Authorization code exchange successful"
    ACCESS_TOKEN=$(echo "$TOKEN_RESPONSE" | jq -r '.access_token')
    ID_TOKEN=$(echo "$TOKEN_RESPONSE" | jq -r '.id_token')
else
    echo "❌ Authorization code exchange failed"
    echo "Response: $TOKEN_RESPONSE"
    exit 1
fi

# Step 4: Validate ID token (basic check)
echo "4. Validating ID token..."
if [ "$ID_TOKEN" != "null" ] && [ "$ID_TOKEN" != "" ]; then
    echo "✅ ID token received"
    # Decode JWT payload (without signature verification)
    PAYLOAD=$(echo "$ID_TOKEN" | cut -d'.' -f2 | base64 -d 2>/dev/null | jq '.')
    echo "   Token payload: $PAYLOAD"
else
    echo "❌ ID token not received"
fi

echo "=== OIDC Authorization Code Flow Test Completed ==="
```

## LDAP Integration Testing

### Basic LDAP Connectivity Test

#### Test Script
```bash
#!/bin/bash
# test_ldap_basic.sh

echo "=== LDAP Basic Connectivity Test ==="

# Configuration
LDAP_HOST="ldap.example.com"
LDAP_PORT="636"
LDAP_BASE_DN="dc=example,dc=com"
LDAP_BIND_DN="cn=admin,dc=example,dc=com"
LDAP_BIND_PWD="admin123"

# Step 1: Test LDAP connectivity
echo "1. Testing LDAP connectivity..."
if ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
    -D "$LDAP_BIND_DN" \
    -w "$LDAP_BIND_PWD" \
    -b "$LDAP_BASE_DN" \
    -s base > /dev/null 2>&1; then
    echo "✅ LDAP connectivity is working"
else
    echo "❌ LDAP connectivity failed"
    exit 1
fi

# Step 2: Test user search
echo "2. Testing user search..."
USER_COUNT=$(ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
    -D "$LDAP_BIND_DN" \
    -w "$LDAP_BIND_PWD" \
    -b "$LDAP_BASE_DN" \
    -s sub "(objectClass=inetOrgPerson)" | grep -c "^dn:")

if [ "$USER_COUNT" -gt 0 ]; then
    echo "✅ User search is working (found $USER_COUNT users)"
else
    echo "❌ User search failed"
    exit 1
fi

# Step 3: Test group search
echo "3. Testing group search..."
GROUP_COUNT=$(ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
    -D "$LDAP_BIND_DN" \
    -w "$LDAP_BIND_PWD" \
    -b "$LDAP_BASE_DN" \
    -s sub "(objectClass=groupOfNames)" | grep -c "^dn:")

if [ "$GROUP_COUNT" -gt 0 ]; then
    echo "✅ Group search is working (found $GROUP_COUNT groups)"
else
    echo "❌ Group search failed"
    exit 1
fi

# Step 4: Test specific user lookup
echo "4. Testing specific user lookup..."
TEST_USER="admin@example.com"
USER_INFO=$(ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
    -D "$LDAP_BIND_DN" \
    -w "$LDAP_BIND_PWD" \
    -b "$LDAP_BASE_DN" \
    -s sub "(uid=$TEST_USER)")

if echo "$USER_INFO" | grep -q "^dn:"; then
    echo "✅ Specific user lookup is working"
    USER_NAME=$(echo "$USER_INFO" | grep "^cn:" | head -1 | cut -d: -f2 | tr -d ' ')
    echo "   User: $USER_NAME"
else
    echo "❌ Specific user lookup failed"
    exit 1
fi

echo "=== LDAP Basic Connectivity Test Completed Successfully ==="
```

#### Manual Testing Steps
1. **Test LDAP Connection**
   ```bash
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s base
   ```

2. **Search for Users**
   ```bash
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(objectClass=inetOrgPerson)"
   ```

3. **Search for Groups**
   ```bash
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "cn=admin,dc=example,dc=com" \
     -w admin123 \
     -b "dc=example,dc=com" \
     -s sub "(objectClass=groupOfNames)"
   ```

4. **Test User Authentication**
   ```bash
   ldapsearch -H ldaps://ldap.example.com:636 \
     -D "uid=testuser,ou=people,dc=example,dc=com" \
     -w testpassword \
     -b "dc=example,dc=com" \
     -s base
   ```

### LDAP User Authentication Test

#### Test Script
```bash
#!/bin/bash
# test_ldap_auth.sh

echo "=== LDAP User Authentication Test ==="

# Configuration
LDAP_HOST="ldap.example.com"
LDAP_PORT="636"
LDAP_BASE_DN="dc=example,dc=com"
LDAP_BIND_DN="cn=admin,dc=example,dc=com"
LDAP_BIND_PWD="admin123"

# Test users
declare -a TEST_USERS=(
    "admin@example.com:admin123"
    "user1@example.com:user123"
    "user2@example.com:user456"
)

for USER_PAIR in "${TEST_USERS[@]}"; do
    IFS=':' read -r USERNAME PASSWORD <<< "$USER_PAIR"
    
    echo "Testing authentication for: $USERNAME"
    
    # Find user DN
    USER_DN=$(ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
        -D "$LDAP_BIND_DN" \
        -w "$LDAP_BIND_PWD" \
        -b "$LDAP_BASE_DN" \
        -s sub "(uid=$USERNAME)" | grep "^dn:" | head -1 | cut -d: -f2 | tr -d ' ')
    
    if [ -z "$USER_DN" ]; then
        echo "❌ User not found: $USERNAME"
        continue
    fi
    
    # Test authentication
    if ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
        -D "$USER_DN" \
        -w "$PASSWORD" \
        -b "$LDAP_BASE_DN" \
        -s base > /dev/null 2>&1; then
        echo "✅ Authentication successful: $USERNAME"
        
        # Get user groups
        GROUPS=$(ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
            -D "$LDAP_BIND_DN" \
            -w "$LDAP_BIND_PWD" \
            -b "$LDAP_BASE_DN" \
            -s sub "(uid=$USERNAME)" | grep "^memberOf:" | cut -d: -f2 | tr -d ' ')
        
        if [ -n "$GROUPS" ]; then
            echo "   Groups: $GROUPS"
        else
            echo "   No groups found"
        fi
    else
        echo "❌ Authentication failed: $USERNAME"
    fi
done

echo "=== LDAP User Authentication Test Completed ==="
```

## Integration-Specific Testing

### TYPO3 Integration Test

#### Test Script
```bash
#!/bin/bash
# test_typo3_integration.sh

echo "=== TYPO3 Integration Test ==="

# Configuration
TYPO3_URL="https://typo3.example.org"
OIDC_ISSUER="https://id.example.org"
TEST_USER="admin@example.com"
TEST_PASSWORD="admin123"

# Step 1: Test TYPO3 accessibility
echo "1. Testing TYPO3 accessibility..."
if curl -f -s "$TYPO3_URL" > /dev/null; then
    echo "✅ TYPO3 is accessible"
else
    echo "❌ TYPO3 is not accessible"
    exit 1
fi

# Step 2: Test OIDC login endpoint
echo "2. Testing OIDC login endpoint..."
LOGIN_RESPONSE=$(curl -s "$TYPO3_URL/index.php?eID=oidc&action=login")

if echo "$LOGIN_RESPONSE" | grep -q "redirect"; then
    echo "✅ OIDC login endpoint is working"
else
    echo "❌ OIDC login endpoint failed"
    echo "Response: $LOGIN_RESPONSE"
fi

# Step 3: Test user synchronization
echo "3. Testing user synchronization..."
# This would require actual login flow simulation
echo "   Manual testing required for user synchronization"

echo "=== TYPO3 Integration Test Completed ==="
```

### Nextcloud Integration Test

#### Test Script
```bash
#!/bin/bash
# test_nextcloud_integration.sh

echo "=== Nextcloud Integration Test ==="

# Configuration
NEXTCLOUD_URL="https://nextcloud.example.org"
OIDC_ISSUER="https://id.example.org"
TEST_USER="admin@example.com"
TEST_PASSWORD="admin123"

# Step 1: Test Nextcloud accessibility
echo "1. Testing Nextcloud accessibility..."
if curl -f -s "$NEXTCLOUD_URL" > /dev/null; then
    echo "✅ Nextcloud is accessible"
else
    echo "❌ Nextcloud is not accessible"
    exit 1
fi

# Step 2: Test OIDC login endpoint
echo "2. Testing OIDC login endpoint..."
LOGIN_RESPONSE=$(curl -s "$NEXTCLOUD_URL/index.php/apps/oidc_login/login")

if echo "$LOGIN_RESPONSE" | grep -q "redirect"; then
    echo "✅ OIDC login endpoint is working"
else
    echo "❌ OIDC login endpoint failed"
    echo "Response: $LOGIN_RESPONSE"
fi

# Step 3: Test LDAP user backend
echo "3. Testing LDAP user backend..."
LDAP_TEST=$(curl -s "$NEXTCLOUD_URL/index.php/apps/user_ldap/test")

if echo "$LDAP_TEST" | grep -q "success"; then
    echo "✅ LDAP user backend is working"
else
    echo "❌ LDAP user backend failed"
    echo "Response: $LDAP_TEST"
fi

echo "=== Nextcloud Integration Test Completed ==="
```

## Performance Testing

### Load Testing

#### OIDC Load Test
```bash
#!/bin/bash
# test_oidc_load.sh

echo "=== OIDC Load Test ==="

# Configuration
OIDC_ISSUER="https://id.example.org"
CLIENT_ID="test-client"
CLIENT_SECRET="test-secret"
CONCURRENT_USERS=10
TEST_DURATION=60

echo "Starting OIDC load test with $CONCURRENT_USERS concurrent users for $TEST_DURATION seconds..."

# Use Apache Bench for load testing
ab -n 1000 -c $CONCURRENT_USERS -t $TEST_DURATION \
    -p post_data.txt \
    "$OIDC_ISSUER/token"

echo "=== OIDC Load Test Completed ==="
```

#### LDAP Load Test
```bash
#!/bin/bash
# test_ldap_load.sh

echo "=== LDAP Load Test ==="

# Configuration
LDAP_HOST="ldap.example.com"
LDAP_PORT="636"
LDAP_BASE_DN="dc=example,dc=com"
LDAP_BIND_DN="cn=admin,dc=example,dc=com"
LDAP_BIND_PWD="admin123"
CONCURRENT_SEARCHES=10
TOTAL_SEARCHES=100

echo "Starting LDAP load test with $CONCURRENT_SEARCHES concurrent searches..."

# Parallel LDAP searches
for i in $(seq 1 $TOTAL_SEARCHES); do
    ldapsearch -H "ldaps://$LDAP_HOST:$LDAP_PORT" \
        -D "$LDAP_BIND_DN" \
        -w "$LDAP_BIND_PWD" \
        -b "$LDAP_BASE_DN" \
        -s sub "(objectClass=inetOrgPerson)" > /dev/null 2>&1 &
    
    # Limit concurrent processes
    if (( i % CONCURRENT_SEARCHES == 0 )); then
        wait
    fi
done

wait
echo "=== LDAP Load Test Completed ==="
```

## Security Testing

### Security Test Script
```bash
#!/bin/bash
# test_security.sh

echo "=== Security Test ==="

# Configuration
OIDC_ISSUER="https://id.example.org"
LDAP_HOST="ldap.example.com"
LDAP_PORT="636"

# Step 1: Test SSL/TLS configuration
echo "1. Testing SSL/TLS configuration..."
SSL_TEST=$(echo | openssl s_client -connect "$LDAP_HOST:$LDAP_PORT" -servername "$LDAP_HOST" 2>/dev/null | openssl x509 -noout -text)

if echo "$SSL_TEST" | grep -q "TLSv1.2\|TLSv1.3"; then
    echo "✅ SSL/TLS configuration is secure"
else
    echo "❌ SSL/TLS configuration may be insecure"
fi

# Step 2: Test for common vulnerabilities
echo "2. Testing for common vulnerabilities..."

# Test for open redirect
REDIRECT_TEST=$(curl -s -I "$OIDC_ISSUER/auth?redirect_uri=http://evil.com" | grep -i "location")

if echo "$REDIRECT_TEST" | grep -q "evil.com"; then
    echo "❌ Open redirect vulnerability detected"
else
    echo "✅ No open redirect vulnerability detected"
fi

# Test for CSRF protection
CSRF_TEST=$(curl -s -I "$OIDC_ISSUER/auth" | grep -i "csrf")

if echo "$CSRF_TEST" | grep -q "csrf"; then
    echo "✅ CSRF protection is enabled"
else
    echo "⚠️  CSRF protection may not be enabled"
fi

echo "=== Security Test Completed ==="
```

## Automated Testing

### Continuous Integration Test
```yaml
# .github/workflows/integration-test.yml
name: Integration Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main ]

jobs:
  oidc-test:
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v2
    
    - name: Start LDAP User Manager
      run: |
        docker-compose up -d
        sleep 30
    
    - name: Run OIDC Tests
      run: |
        chmod +x tests/test_oidc_basic.sh
        ./tests/test_oidc_basic.sh
    
    - name: Run LDAP Tests
      run: |
        chmod +x tests/test_ldap_basic.sh
        ./tests/test_ldap_basic.sh
    
    - name: Cleanup
      if: always()
      run: docker-compose down
```

## Troubleshooting

### Common Test Failures

#### OIDC Test Failures
```bash
# Check OIDC provider status
curl -v https://id.example.org/.well-known/openid_configuration

# Check client configuration
curl -X POST https://id.example.org/token \
  -d "grant_type=client_credentials&client_id=your-client-id&client_secret=your-client-secret"

# Check network connectivity
telnet id.example.org 443
```

#### LDAP Test Failures
```bash
# Check LDAP connectivity
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "dc=example,dc=com" \
  -s base

# Check SSL certificate
openssl s_client -connect ldap.example.com:636 -servername ldap.example.com

# Check LDAP schema
ldapsearch -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 \
  -b "cn=schema,cn=configuration,dc=example,dc=com" \
  -s sub "(objectClass=attributeSchema)"
```

### Debug Mode Testing
```bash
# Enable debug mode for detailed logging
export DEBUG=true
export LDAP_DEBUG=1

# Run tests with debug output
./tests/test_oidc_basic.sh 2>&1 | tee test.log
./tests/test_ldap_basic.sh 2>&1 | tee test.log
```

## Best Practices

### Test Environment
1. **Separate Test Environment**: Use a dedicated test environment
2. **Test Data**: Use realistic but safe test data
3. **Cleanup**: Always clean up test data after tests
4. **Isolation**: Ensure tests don't interfere with each other

### Test Execution
1. **Automated Tests**: Automate repetitive tests
2. **Manual Verification**: Include manual verification steps
3. **Documentation**: Document test procedures and expected results
4. **Monitoring**: Monitor system resources during tests

### Test Maintenance
1. **Regular Updates**: Update tests when integration changes
2. **Version Control**: Keep test scripts in version control
3. **Documentation**: Keep test documentation up to date
4. **Review**: Regularly review and improve test coverage

## Support

For testing support:
- **Test Documentation**: [Integration Testing Guide](https://docs.example.org/testing)
- **Community Support**: [Stack Overflow](https://stackoverflow.com/)
- **Professional Support**: [Contact Support](mailto:support@yourcompany.com)
