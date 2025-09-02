# AJAX Handler Documentation

This document describes the AJAX functionality available in LDAP User Manager for dynamic user data fetching.

## Overview

LDAP User Manager includes a single AJAX endpoint for fetching user data within organizations. This is used by the web interface for dynamic user management features.

## AJAX Endpoint

### User Data Fetching

**Endpoint**: `/manage/organizations/users/ajax_handler.php`

**Method**: `GET`

**Purpose**: Fetch user data for organization user management

**Authentication**: Session-based with CSRF protection

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | Must be `fetch_user_data` |
| `fetch_user_data` | string | Yes | User identifier (UUID or uid) |
| `uuid` | string | Yes* | Organization UUID |
| `org` | string | Yes* | Organization name (legacy) |
| `csrf_token` | string | Yes | CSRF security token |

*Either `uuid` or `org` is required

#### Response Format

**Success Response**:
```json
{
  "success": true,
  "user_data": {
    "givenName": "John",
    "sn": "Doe",
    "mail": "john@example.com",
    "uid": "john@example.com"
  }
}
```

**Error Response**:
```json
{
  "success": false,
  "error": "User not found",
  "user_data": null
}
```

#### HTTP Status Codes

- **200** - Success
- **400** - Bad request (missing/invalid parameters)
- **401** - Unauthenticated
- **403** - Access denied
- **404** - Organization not found
- **405** - Method not allowed

## Security Features

### 1. AJAX-Only Access
```php
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access not allowed']);
    exit;
}
```

### 2. Session Validation
```php
$is_authenticated = false;
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $is_authenticated = true;
}
```

### 3. CSRF Protection
```php
if (empty($_SESSION['csrf_token']) || 
    !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token']);
    exit;
}
```

### 4. Access Control
```php
// Only allow access if user is global admin, maintainer, or org admin
$has_access = false;
if ($user_roles['is_admin'] || $user_roles['is_maintainer']) {
    $has_access = true;
} elseif ($user_roles['is_org_admin'] && $user_roles['org_uuid'] === $org_uuid) {
    $has_access = true;
}
```

## Usage Examples

### JavaScript Integration

The main page uses jQuery AJAX to call the endpoint:

```javascript
$.ajax({
    url: 'ajax_handler.php',
    method: 'GET',
    data: {
        action: 'fetch_user_data',
        fetch_user_data: userIdentifier,
        uuid: organizationUuid,
        csrf_token: csrfToken
    },
    success: function(response) {
        if (response.success) {
            // Handle user data
            console.log('User:', response.user_data);
        } else {
            // Handle error
            console.error('Error:', response.error);
        }
    },
    error: function(xhr, status, error) {
        console.error('AJAX Error:', error);
    }
});
```

### cURL Example

```bash
curl -X GET "https://app.example.org/manage/organizations/users/ajax_handler.php" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Cookie: PHPSESSID=your_session_id" \
  -d "action=fetch_user_data" \
  -d "fetch_user_data=john@example.com" \
  -d "uuid=org-12345678-1234-1234-1234-123456789abc" \
  -d "csrf_token=your_csrf_token"
```

## User Lookup Methods

### UUID-Based Lookup
```php
if ($is_uuid) {
    // UUID-based lookup
    $ldap_connection = open_ldap_connection();
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $fetchUserParam, $usersDn);
    ldap_close($ldap_connection);
}
```

### Legacy UID-Based Lookup
```php
} else {
    // Legacy uid-based lookup
    $existingUsers = getUsersInOrg($orgName);
    if (is_array($existingUsers)) {
        foreach ($existingUsers as $user) {
            if (strtolower(get_ldap_attribute($user, 'uid')) === strtolower($fetchUserParam)) {
                $user_data = [
                    'givenName' => get_ldap_attribute($user, 'givenName'),
                    'sn' => get_ldap_attribute($user, 'sn'),
                    'mail' => get_ldap_attribute($user, 'mail'),
                    'uid' => get_ldap_attribute($user, 'uid')
                ];
                break;
            }
        }
    }
}
```

## Error Handling

### Common Error Scenarios

1. **Direct Access Attempt**
   - **Error**: "Direct access not allowed"
   - **Cause**: Missing `X-Requested-With` header
   - **Solution**: Ensure request is made via AJAX

2. **Authentication Failure**
   - **Error**: "Authentication required"
   - **Cause**: User not logged in
   - **Solution**: Redirect to login page

3. **CSRF Token Mismatch**
   - **Error**: "Invalid security token"
   - **Cause**: CSRF token missing or incorrect
   - **Solution**: Refresh page to get new token

4. **Access Denied**
   - **Error**: "Access denied"
   - **Cause**: User lacks permission for organization
   - **Solution**: Check user roles and organization membership

5. **User Not Found**
   - **Error**: "User not found"
   - **Cause**: User doesn't exist in organization
   - **Solution**: Verify user identifier and organization

## Debugging

### Session Debugging
The handler includes debug logging for session issues:

```php
error_log("AJAX Handler - Session ID: " . session_id());
error_log("AJAX Handler - Session data keys: " . implode(', ', array_keys($_SESSION)));
error_log("AJAX Handler - VALIDATED: " . (isset($_SESSION['VALIDATED']) ? ($_SESSION['VALIDATED'] ? 'TRUE' : 'FALSE') : 'NOT SET'));
```

### Test Endpoint
Use the test endpoint to check session status:

```bash
curl -X GET "https://app.example.org/manage/organizations/users/ajax_handler.php?action=test_session" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Cookie: PHPSESSID=your_session_id"
```

## Best Practices

1. **Always include CSRF tokens** in AJAX requests
2. **Handle errors gracefully** in JavaScript
3. **Validate user permissions** before making requests
4. **Use UUID-based lookups** for better reliability
5. **Implement proper error logging** for debugging
6. **Test session handling** in different browsers
7. **Monitor access patterns** for security

## Limitations

- **Single endpoint**: Only one AJAX handler available
- **Read-only**: Only supports data fetching, not updates
- **Organization-scoped**: Limited to organization user data
- **Session-dependent**: Requires active user session
- **No pagination**: Returns single user data only
