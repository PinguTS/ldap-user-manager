# TYPO3 Integration Testing

This guide provides comprehensive testing procedures for TYPO3 integration with LDAP User Manager.

## Prerequisites

- TYPO3 CMS installed and running
- OIDC extension installed and configured
- Dex OIDC provider accessible
- Test users created in LDAP

## Testing Checklist

### Environment Setup
- [ ] TYPO3 is accessible at configured URL
- [ ] OIDC extension is installed and enabled
- [ ] Dex OIDC provider is running
- [ ] Network connectivity between TYPO3 and Dex
- [ ] SSL certificates are valid

### Configuration Verification
- [ ] OIDC client ID matches Dex configuration
- [ ] OIDC client secret is correct
- [ ] Redirect URI matches exactly
- [ ] Scopes are properly configured
- [ ] User mapping is set up correctly

## Automated Testing

### Test Script
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

# Step 2: Test OIDC extension endpoint
echo "2. Testing OIDC extension endpoint..."
OIDC_RESPONSE=$(curl -s "$TYPO3_URL/index.php?eID=oidc&action=login")

if echo "$OIDC_RESPONSE" | grep -q "redirect\|location"; then
    echo "✅ OIDC extension endpoint is working"
else
    echo "❌ OIDC extension endpoint failed"
    echo "Response: $OIDC_RESPONSE"
fi

# Step 3: Test OIDC provider connectivity
echo "3. Testing OIDC provider connectivity..."
if curl -f -s "$OIDC_ISSUER/.well-known/openid_configuration" > /dev/null; then
    echo "✅ OIDC provider is accessible"
else
    echo "❌ OIDC provider is not accessible"
fi

# Step 4: Test user authentication
echo "4. Testing user authentication..."
AUTH_RESPONSE=$(curl -s -X POST "$OIDC_ISSUER/token" \
    -d "grant_type=password&username=$TEST_USER&password=$TEST_PASSWORD&client_id=typo3&client_secret=your-typo3-client-secret-here")

if echo "$AUTH_RESPONSE" | jq -e '.access_token' > /dev/null 2>&1; then
    echo "✅ User authentication is working"
    ACCESS_TOKEN=$(echo "$AUTH_RESPONSE" | jq -r '.access_token')
    
    # Test user info endpoint
    USER_INFO=$(curl -s -H "Authorization: Bearer $ACCESS_TOKEN" \
        "$OIDC_ISSUER/userinfo")
    
    if echo "$USER_INFO" | jq -e '.sub' > /dev/null 2>&1; then
        echo "✅ User info endpoint is working"
        USER_NAME=$(echo "$USER_INFO" | jq -r '.name // .preferred_username')
        echo "   User: $USER_NAME"
    else
        echo "❌ User info endpoint failed"
    fi
else
    echo "❌ User authentication failed"
    echo "Response: $AUTH_RESPONSE"
fi

echo "=== TYPO3 Integration Test Completed ==="
```

### Manual Testing Steps

#### 1. OIDC Flow Test
1. **Visit TYPO3 login page**
   ```bash
   curl -I https://typo3.example.org/login
   ```

2. **Test OIDC redirect**
   ```bash
   curl -L https://typo3.example.org/index.php?eID=oidc&action=login
   ```

3. **Verify OIDC provider response**
   ```bash
   curl -v https://id.example.org/.well-known/openid_configuration
   ```

#### 2. User Authentication Test
1. **Test token endpoint**
   ```bash
   curl -X POST https://id.example.org/token \
     -d "grant_type=password&username=test@example.com&password=test123&client_id=typo3&client_secret=your-secret"
   ```

2. **Test user info endpoint**
   ```bash
   curl -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
     https://id.example.org/userinfo
   ```

#### 3. User Provisioning Test
1. **Check TYPO3 user database**
   ```sql
   SELECT * FROM fe_users WHERE username = 'test@example.com';
   ```

2. **Verify user attributes**
   ```sql
   SELECT username, email, first_name, last_name FROM fe_users WHERE username = 'test@example.com';
   ```

## Performance Testing

### Load Test
```bash
#!/bin/bash
# typo3_load_test.sh

echo "=== TYPO3 Load Test ==="

# Configuration
TYPO3_URL="https://typo3.example.org"
CONCURRENT_USERS=10
TEST_DURATION=60

echo "Starting TYPO3 load test with $CONCURRENT_USERS concurrent users for $TEST_DURATION seconds..."

# Use Apache Bench for load testing
ab -n 1000 -c $CONCURRENT_USERS -t $TEST_DURATION \
    -p post_data.txt \
    "$TYPO3_URL/index.php?eID=oidc&action=login"

echo "=== TYPO3 Load Test Completed ==="
```

### Response Time Test
```bash
#!/bin/bash
# typo3_response_test.sh

echo "=== TYPO3 Response Time Test ==="

# Test response times for different endpoints
echo "Testing TYPO3 homepage response time..."
time curl -s -o /dev/null -w "%{time_total}" "$TYPO3_URL"

echo "Testing OIDC endpoint response time..."
time curl -s -o /dev/null -w "%{time_total}" "$TYPO3_URL/index.php?eID=oidc&action=login"

echo "Testing OIDC provider response time..."
time curl -s -o /dev/null -w "%{time_total}" "$OIDC_ISSUER/.well-known/openid_configuration"

echo "=== TYPO3 Response Time Test Completed ==="
```

## Security Testing

### Security Test
```bash
#!/bin/bash
# typo3_security_test.sh

echo "=== TYPO3 Security Test ==="

# Test SSL/TLS configuration
echo "1. Testing SSL/TLS configuration..."
SSL_TEST=$(echo | openssl s_client -connect "typo3.example.org:443" -servername "typo3.example.org" 2>/dev/null | openssl x509 -noout -text)

if echo "$SSL_TEST" | grep -q "TLSv1.2\|TLSv1.3"; then
    echo "✅ SSL/TLS configuration is secure"
else
    echo "❌ SSL/TLS configuration may be insecure"
fi

# Test for open redirect
echo "2. Testing for open redirect vulnerability..."
REDIRECT_TEST=$(curl -s -I "$TYPO3_URL/index.php?eID=oidc&redirect_uri=http://evil.com" | grep -i "location")

if echo "$REDIRECT_TEST" | grep -q "evil.com"; then
    echo "❌ Open redirect vulnerability detected"
else
    echo "✅ No open redirect vulnerability detected"
fi

# Test for CSRF protection
echo "3. Testing CSRF protection..."
CSRF_TEST=$(curl -s -I "$TYPO3_URL/index.php?eID=oidc" | grep -i "csrf")

if echo "$CSRF_TEST" | grep -q "csrf"; then
    echo "✅ CSRF protection is enabled"
else
    echo "⚠️  CSRF protection may not be enabled"
fi

echo "=== TYPO3 Security Test Completed ==="
```

## Troubleshooting Tests

### Debug Mode Testing
```bash
#!/bin/bash
# typo3_debug_test.sh

echo "=== TYPO3 Debug Test ==="

# Enable debug mode
export TYPO3_DEBUG=true

# Test with debug output
echo "1. Testing with debug output..."
curl -v "$TYPO3_URL/index.php?eID=oidc&action=login" 2>&1 | tee debug.log

# Check TYPO3 logs
echo "2. Checking TYPO3 logs..."
tail -20 /var/log/typo3/typo3.log

# Check OIDC extension logs
echo "3. Checking OIDC extension logs..."
tail -20 /var/log/typo3/oidc.log

echo "=== TYPO3 Debug Test Completed ==="
```

## Continuous Integration

### CI/CD Test Configuration
```yaml
# .github/workflows/typo3-test.yml
name: TYPO3 Integration Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main ]

jobs:
  typo3-test:
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v2
    
    - name: Start LDAP User Manager
      run: |
        docker-compose up -d
        sleep 30
    
    - name: Install TYPO3
      run: |
        # Install TYPO3 and OIDC extension
        composer create-project typo3/cms-base-distribution typo3
        cd typo3
        composer require causal/oidc
    
    - name: Configure TYPO3
      run: |
        # Configure TYPO3 for testing
        cp ../services/typo3/oidc-config.yaml config/
        # Update configuration with test values
    
    - name: Run TYPO3 Tests
      run: |
        chmod +x ../services/typo3/test_typo3_integration.sh
        ../services/typo3/test_typo3_integration.sh
    
    - name: Cleanup
      if: always()
      run: docker-compose down
```

## Test Data

### Test Users
```bash
# Create test users in LDAP
ldapadd -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 << EOF
dn: uid=testuser1,ou=people,dc=example,dc=com
objectClass: inetOrgPerson
uid: testuser1
cn: Test User 1
sn: User1
mail: testuser1@example.com
userPassword: test123
memberOf: cn=users,ou=roles,dc=example,dc=com

dn: uid=testuser2,ou=people,dc=example,dc=com
objectClass: inetOrgPerson
uid: testuser2
cn: Test User 2
sn: User2
mail: testuser2@example.com
userPassword: test456
memberOf: cn=admins,ou=roles,dc=example,dc=com
EOF
```

### Test Groups
```bash
# Create test groups in LDAP
ldapadd -H ldaps://ldap.example.com:636 \
  -D "cn=admin,dc=example,dc=com" \
  -w admin123 << EOF
dn: cn=testgroup1,ou=roles,dc=example,dc=com
objectClass: groupOfNames
cn: testgroup1
member: uid=testuser1,ou=people,dc=example,dc=com

dn: cn=testgroup2,ou=roles,dc=example,dc=com
objectClass: groupOfNames
cn: testgroup2
member: uid=testuser2,ou=people,dc=example,dc=com
EOF
```

## Best Practices

### Test Environment
1. **Separate Test Environment**: Use dedicated test environment
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
