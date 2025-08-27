# Organization User Management - AJAX Architecture

## Overview
This module uses a dedicated AJAX handler (`ajax_handler.php`) to separate concerns and improve security.

## Files
- **`index.php`** - Main page logic and user interface
- **`ajax_handler.php`** - Dedicated AJAX endpoint for user data fetching
- **`add.php`** - User creation form
- **`user_functions.inc.php`** - Shared user management functions

## AJAX Endpoint: `ajax_handler.php`

### Security Features
1. **AJAX-only access** - Prevents direct browser access
2. **Session validation** - Ensures user is authenticated
3. **CSRF protection** - Validates security tokens
4. **Access control** - Checks user permissions for organization access
5. **Input validation** - Validates all parameters
6. **HTTP method restriction** - Only allows GET requests

### Access Control Rules
- **Global Administrators** - Can access any organization
- **System Maintainers** - Can access any organization  
- **Organization Administrators** - Can only access their own organization

### Request Parameters
```
GET /ajax_handler.php
  ?action=fetch_user_data
  &uuid=<org_uuid> OR &org=<org_name>
  &fetch_user_data=<user_identifier>
  &csrf_token=<csrf_token>
```

### Response Format
**Success:**
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

**Error:**
```json
{
  "success": false,
  "error": "User not found"
}
```

### HTTP Status Codes
- **200** - Success
- **400** - Bad request (missing/invalid parameters)
- **401** - Unauthenticated
- **403** - Access denied
- **404** - Organization not found
- **405** - Method not allowed

## JavaScript Integration

The main page uses jQuery AJAX to call the endpoint:

```javascript
$.ajax({
    url: 'ajax_handler.php',
    method: 'GET',
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    },
    data: {
        action: 'fetch_user_data',
        uuid: orgUuid,
        fetch_user_data: userIdentifier,
        csrf_token: csrfToken
    },
    success: function(response) {
        // Handle success
    },
    error: function(xhr, status, error) {
        // Handle error
    }
});
```

## Benefits of This Architecture

1. **Separation of Concerns** - AJAX logic separate from page logic
2. **Better Security** - Dedicated security checks for AJAX requests
3. **Cleaner Code** - Single responsibility principle
4. **Easier Testing** - AJAX endpoints can be tested independently
5. **Better Performance** - No unnecessary HTML rendering for AJAX requests
6. **Maintainability** - AJAX logic is centralized and easier to modify
7. **Scalability** - Easy to add new AJAX endpoints following the same pattern
