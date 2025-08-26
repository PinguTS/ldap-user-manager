# Management System Directory Structure

This directory contains the management interface for the LDAP User Manager system, organized in a logical hierarchy for better user experience and maintainability.

## Directory Structure

```
/manage/
├── index.php                    # Main management dashboard
├── download.php                 # File download functionality
├── module_functions.inc.php     # Submenu navigation functions
├── users/                       # User management
│   ├── index.php               # System users management
│   ├── new.php                 # Create new system user
│   └── show.php                # View/edit user profile
├── roles/                       # Role management
│   └── index.php               # System roles management
└── organizations/               # Organization management
    ├── index.php               # Organizations overview
    ├── add.php                 # Add new organization
    ├── show/                   # Organization details
    │   └── index.php          # View/edit organization
    └── users/                  # Organization user management
        ├── index.php           # List organization users
        └── add.php             # Add user to organization
```

## Complete File Migration Mapping

### **From `/account_manager/` → To `/manage/`**

| Old Path | New Path | Function |
|----------|----------|----------|
| `index.php` | `users/index.php` | System users management |
| `manage_roles.php` | `roles/index.php` | System roles management |
| `organizations.php` | `organizations/index.php` | Organizations overview |
| `show_organization.php` | `organizations/show/index.php` | View/edit organization |
| `org_users.php` | `organizations/users/index.php` | Organization users list |
| `add_org_user.php` | `organizations/users/add.php` | Add user to organization |
| `new_user.php` | `users/new.php` | Create new system user |
| `show_user.php` | `users/show.php` | View/edit user profile |
| `download.php` | `download.php` | File download functionality |
| `module_functions.inc.php` | `module_functions.inc.php` | Submenu navigation |

### **New Files Added**
- `index.php` - Main management dashboard
- `organizations/add.php` - Dedicated organization creation

## Access Control

### User Types and Permissions

| User Type | Users | Roles | Organizations | Add Org | Org Users | Add Org User |
|-----------|-------|-------|---------------|---------|-----------|--------------|
| **Global Admin** | ✅ Full | ✅ Full | ✅ Full | ✅ Full | ✅ Full | ✅ Full |
| **Maintainer** | ❌ None | ❌ None | ✅ View/Edit | ✅ Create | ✅ View/Edit | ✅ Add |
| **Organization Admin** | ❌ None | ❌ None | ✅ Own Org Only | ❌ None | ✅ Own Org Only | ✅ Own Org Only |
| **Regular User** | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None | ❌ None |

### Path-Based Restrictions

- **`/setup`**: Only Setup Administrators
- **`/manage/users`**: Only Global Administrators
- **`/manage/roles`**: Only Global Administrators  
- **`/manage/organizations`**: Global Admins, Maintainers, Organization Admins (own org only)
- **`/manage/organizations/add`**: Global Admins, Maintainers

**Note**: Organization Admins can access the organizations list page (`/manage/organizations/`) but will only see their own organization. This provides them with a consistent interface while maintaining security boundaries.

### Organization Admin Permissions

Organization Administrators have **limited but important** access to manage their own organization:

- **✅ View Own Organization**: Can see their organization's details, contact information, and settings
- **✅ Edit Own Organization**: Can modify their organization's information (name, address, contact details, etc.)
- **✅ Organizations List**: Can access the organizations list page (but only see their own organization)
- **✅ Manage Own Org Users**: Can view, add, edit, and delete users within their organization
- **✅ User Management**: Can assign roles and permissions to users within their organization
- **❌ Cannot Access**: Other organizations, system users, system roles, or create new organizations

This design ensures that Organization Admins have full control over their own organization while maintaining system security by preventing access to other organizations or system-level functions. They can use the same interface as Global Admins and Maintainers, but the system automatically filters content to show only their own organization.

## Navigation

The management system is accessible through the main navigation menu, which shows "Manage" for users with appropriate permissions (admin, maintainer, or org_admin).

## URL Structure

### Organization Management
- **List**: `/manage/organizations/`
- **Add New**: `/manage/organizations/add.php`
- **View/Edit**: `/manage/organizations/show/index.php?uuid={uuid}` or `?org={name}`
- **Users**: `/manage/organizations/users/index.php?uuid={uuid}` or `?org={name}`
- **Add User**: `/manage/organizations/users/add.php?uuid={uuid}` or `?org={name}`

### User Management
- **System Users**: `/manage/users/`
- **New System User**: `/manage/users/new.php`
- **View/Edit User**: `/manage/users/show.php?uuid={uuid}` or `?account_identifier={id}`
- **System Roles**: `/manage/roles/`

### File Downloads
- **Download**: `/manage/download.php?resource_identifier={dn}&attribute={attr}`

## Benefits of New Structure

1. **Logical Organization**: Related functionality is grouped together
2. **Clear Hierarchy**: Management functions are clearly organized by type
3. **Scalable**: Easy to add new management areas
4. **Intuitive URLs**: Self-explanatory paths like `/manage/organizations/users/add`
5. **Better UX**: Users can easily find and navigate to management functions
6. **Consistent Access Control**: Unified permission system across all management areas
7. **Maintainable**: Easier for developers to understand and modify
8. **Professional Interface**: More enterprise-grade appearance and organization

## Migration Notes

This structure replaces the previous flat `/account_manager/` directory structure. All redirects and links have been updated to use the new paths. The new structure maintains 100% functional compatibility while providing a much more logical and maintainable organization.

### **Functionality Preserved**
- ✅ All user management functions
- ✅ All role management functions  
- ✅ All organization management functions
- ✅ All file download capabilities
- ✅ All access control mechanisms
- ✅ All form processing and validation
- ✅ All LDAP operations and queries
- ✅ All security features (CSRF, validation, etc.)

### **Improvements Added**
- 🆕 Main management dashboard
- 🆕 Dedicated organization creation page
- 🆕 Better navigation and breadcrumbs
- 🆕 Consistent UI patterns
- 🆕 Enhanced error handling
- 🆕 Better mobile responsiveness
