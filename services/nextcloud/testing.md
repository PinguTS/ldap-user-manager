# Nextcloud Integration Testing

This guide provides comprehensive testing procedures for Nextcloud integration with LDAP User Manager.

## Prerequisites

- Nextcloud installed and running
- OIDC Login app installed and configured
- Dex OIDC provider accessible
- Test users created in LDAP

## Testing Checklist

### Environment Setup
- [ ] Nextcloud is accessible at configured URL
- [ ] OIDC Login app is installed and enabled
- [ ] Dex OIDC provider is running
- [ ] Network connectivity between Nextcloud and Dex
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

# Step 2: Test OIDC app endpoint
echo "2. Testing OIDC app endpoint..."
OIDC_RESPONSE=$(curl -s "$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc")

if echo "$OIDC_RESPONSE" | grep -q "redirect\|location"; then
    echo "✅ OIDC app endpoint is working"
else
    echo "❌ OIDC app endpoint failed"
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
    -d "grant_type=password&username=$TEST_USER&password=$TEST_PASSWORD&client_id=nextcloud&client_secret=your-nextcloud-client-secret-here")

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

echo "=== Nextcloud Integration Test Completed ==="
```

### Manual Testing Steps

#### 1. OIDC Flow Test
1. **Visit Nextcloud login page**
   ```bash
   curl -I https://nextcloud.example.org/login
   ```

2. **Test OIDC redirect**
   ```bash
   curl -L https://nextcloud.example.org/index.php/apps/oidc_login/oidc
   ```

3. **Verify OIDC provider response**
   ```bash
   curl -v https://id.example.org/.well-known/openid_configuration
   ```

#### 2. User Authentication Test
1. **Test token endpoint**
   ```bash
   curl -X POST https://id.example.org/token \
     -d "grant_type=password&username=test@example.com&password=test123&client_id=nextcloud&client_secret=your-secret"
   ```

2. **Test user info endpoint**
   ```bash
   curl -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
     https://id.example.org/userinfo
   ```

#### 3. User Provisioning Test
1. **Check Nextcloud user database**
   ```sql
   SELECT * FROM oc_users WHERE uid = 'test@example.com';
   ```

2. **Verify user attributes**
   ```sql
   SELECT uid, displayname, email FROM oc_users WHERE uid = 'test@example.com';
   ```

## Performance Testing

### Load Test
```bash
#!/bin/bash
# nextcloud_load_test.sh

echo "=== Nextcloud Load Test ==="

# Configuration
NEXTCLOUD_URL="https://nextcloud.example.org"
CONCURRENT_USERS=10
TEST_DURATION=60

echo "Starting Nextcloud load test with $CONCURRENT_USERS concurrent users for $TEST_DURATION seconds..."

# Use Apache Bench for load testing
ab -n 1000 -c $CONCURRENT_USERS -t $TEST_DURATION \
    -p post_data.txt \
    "$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc"

echo "=== Nextcloud Load Test Completed ==="
```

### Response Time Test
```bash
#!/bin/bash
# nextcloud_response_test.sh

echo "=== Nextcloud Response Time Test ==="

# Test response times for different endpoints
echo "Testing Nextcloud homepage response time..."
time curl -s -o /dev/null -w "%{time_total}" "$NEXTCLOUD_URL"

echo "Testing OIDC endpoint response time..."
time curl -s -o /dev/null -w "%{time_total}" "$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc"

echo "Testing OIDC provider response time..."
time curl -s -o /dev/null -w "%{time_total}" "$OIDC_ISSUER/.well-known/openid_configuration"

echo "=== Nextcloud Response Time Test Completed ==="
```

## Security Testing

### Security Test
```bash
#!/bin/bash
# nextcloud_security_test.sh

echo "=== Nextcloud Security Test ==="

# Test SSL/TLS configuration
echo "1. Testing SSL/TLS configuration..."
SSL_TEST=$(echo | openssl s_client -connect "nextcloud.example.org:443" -servername "nextcloud.example.org" 2>/dev/null | openssl x509 -noout -text)

if echo "$SSL_TEST" | grep -q "TLSv1.2\|TLSv1.3"; then
    echo "✅ SSL/TLS configuration is secure"
else
    echo "❌ SSL/TLS configuration may be insecure"
fi

# Test for open redirect
echo "2. Testing for open redirect vulnerability..."
REDIRECT_TEST=$(curl -s -I "$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc?redirect_uri=http://evil.com" | grep -i "location")

if echo "$REDIRECT_TEST" | grep -q "evil.com"; then
    echo "❌ Open redirect vulnerability detected"
else
    echo "✅ No open redirect vulnerability detected"
fi

# Test for CSRF protection
echo "3. Testing CSRF protection..."
CSRF_TEST=$(curl -s -I "$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc" | grep -i "csrf")

if echo "$CSRF_TEST" | grep -q "csrf"; then
    echo "✅ CSRF protection is enabled"
else
    echo "⚠️  CSRF protection may not be enabled"
fi

echo "=== Nextcloud Security Test Completed ==="
```

## Troubleshooting Tests

### Debug Mode Testing
```bash
#!/bin/bash
# nextcloud_debug_test.sh

echo "=== Nextcloud Debug Test ==="

# Enable debug mode
export NEXTCLOUD_DEBUG=true

# Test with debug output
echo "1. Testing with debug output..."
curl -v "$NEXTCLOUD_URL/index.php/apps/oidc_login/oidc" 2>&1 | tee debug.log

# Check Nextcloud logs
echo "2. Checking Nextcloud logs..."
tail -20 /var/log/nextcloud/nextcloud.log

# Check OIDC app logs
echo "3. Checking OIDC app logs..."
tail -20 /var/log/nextcloud/oidc_login.log

echo "=== Nextcloud Debug Test Completed ==="
```

## Continuous Integration

### CI/CD Test Configuration
```yaml
# .github/workflows/nextcloud-test.yml
name: Nextcloud Integration Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main ]

jobs:
  nextcloud-test:
    runs-on: ubuntu-latest
    steps:
    - uses: actions/checkout@v2
    
    - name: Start LDAP User Manager
      run: |
        docker-compose up -d
        sleep 30
    
    - name: Install Nextcloud
      run: |
        # Install Nextcloud and OIDC Login app
        wget https://download.nextcloud.com/server/releases/latest.zip
        unzip latest.zip -d /var/www/html/
        chown -R www-data:www-data /var/www/html/nextcloud/
    
    - name: Configure Nextcloud
      run: |
        # Configure Nextcloud for testing
        cp ../services/nextcloud/config.php /var/www/html/nextcloud/config/
        # Update configuration with test values
    
    - name: Run Nextcloud Tests
      run: |
        chmod +x ../services/nextcloud/test_nextcloud_integration.sh
        ../services/nextcloud/test_nextcloud_integration.sh
    
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
