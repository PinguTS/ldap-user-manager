# Organization Management Guide

This guide explains how to manage organizations in LDAP User Manager.

## Overview

Organization management allows you to:
- Create new organizations
- Edit organization information
- Set membership status (member organization) and optional metadata (e.g. member number, member since)
- Activate or deactivate organizations (deactivated organizations are excluded from exports)
- Delete organizations
- Manage users within organizations
- Configure organization-specific settings

## Organization Structure

### LDAP Structure
```
dc=example,dc=com
├── ou=organizations
│   ├── o=Company A                    # Organization entry
│   │   ├── ou=people                 # Organization users
│   │   │   ├── uid=user1@companya.com
│   │   │   └── uid=user2@companya.com
│   │   └── ou=roles                  # Organization roles
│   │       └── cn=org_admin
│   └── o=Company B                    # Another organization
│       ├── ou=people
│       └── ou=roles
```

### Organization Attributes
- **Name**: Organization display name
- **Address**: Full postal address (street, city, state, zip, country)
- **Phone**: Contact phone number
- **Website**: Organization website URL
- **Email**: Contact email address

### Membership and status in the UI
- **Organization list** (`Manage → Organizations`): Each row can show status badges such as **Member** (active membership) and **Inactive** (organization deactivated). Admins and maintainers can change these from the list or from the organization detail page.
- **Organization detail / edit page**: A **Membership** section lets you toggle "Member organization" and, when enabled, edit optional metadata (e.g. membership number, member since date, tax ID, primary contact). A separate toggle controls whether the organization is **active** or **inactive**.

## Creating Organizations

### Step 1: Access Organization Management
1. **Log in** to the web interface
2. **Navigate to** "Manage" → "Organizations"
3. **Click** "Add New Organization" or "Create Organization"

### Step 2: Fill Organization Information
**Required Fields:**
- **Organization Name**: Display name for the organization
- **Address**: Complete postal address
- **Phone**: Contact phone number
- **Email**: Contact email address

**Optional Fields:**
- **Website**: Organization website URL
- **Description**: Additional notes about the organization

### Step 3: Address Format
Enter the address in the format:
```
Street Address
City, State ZIP
Country
```

### Step 4: Save Organization
- **Click** "Save" or "Create Organization"
- **Verify** the organization appears in the list
- **Create initial users** for the organization

## Editing Organizations

### Step 1: Find the Organization
1. **Navigate to** Organization Management
2. **Search** for the organization by name
3. **Click** on the organization name to edit

### Step 2: Modify Information
- **Update** any field as needed
- **Change address** information
- **Modify contact** details

### Step 3: Save Changes
- **Click** "Save" to update the organization
- **Verify** changes are applied

### Membership and activation status (admins and maintainers)
On the organization detail/edit page you can:
- **Member organization**: Turn membership on or off. When on, you can optionally set membership metadata (e.g. member number, member since date, tax identification number, primary contact person).
- **Organization active / inactive**: Deactivate an organization to exclude it from member exports and treat it as inactive. Activate to restore normal status.
These toggles and fields are in the **Membership** section of the same form; saving updates both the organization entry and the corresponding LDAP status groups.

## Deleting Organizations

### Step 1: Check Dependencies
1. **Find** the organization to delete
2. **Check** if organization has users
3. **Remove** all users first (if required)

### Step 2: Confirm Deletion
1. **Click** "Delete" or trash icon
2. **Confirm** the deletion action
3. **Verify** no critical dependencies

### Step 3: Complete Deletion
- **Confirm** final deletion
- **Verify** organization is removed from list

## Managing Organization Users

### Adding Users to Organizations
1. **Navigate to** the organization
2. **Click** "Manage Users"
3. **Click** "Add User"
4. **Fill** user information
5. **Save** the user

### Viewing Organization Users
1. **Select** the organization
2. **Click** "View Users"
3. **See** all users in the organization
4. **Filter** or search users as needed

### Removing Users from Organizations
1. **Find** the user in the organization
2. **Click** "Remove" or "Delete"
3. **Confirm** the removal
4. **Verify** user is removed

## Organization Roles

### Creating Organization Roles
1. **Navigate to** the organization
2. **Click** "Manage Roles"
3. **Click** "Create Role"
4. **Define** role name and permissions
5. **Save** the role

### Assigning Users to Roles
1. **Select** a user in the organization
2. **Click** "Manage Roles"
3. **Select** roles from available list
4. **Save** role assignments

### Organization Role Types
- **Organization Admin**: Full organization management
- **User Manager**: Can manage users in organization
- **Regular User**: Basic access within organization

## Organization Search and Filtering

### Search Options
- **Name search**: Search by organization name
- **Address search**: Search by location
- **Email search**: Search by contact email

### Advanced Search
- **Date range**: Filter by creation date
- **User count**: Organizations by user count
- **Status**: Active/inactive organizations

## Best Practices

### Organization Creation
- **Use descriptive** organization names
- **Include complete** address information
- **Set up contact** information
- **Create initial** admin user

### Organization Maintenance
- **Regular review** of organization information
- **Update contact** details when they change

### User Management
- **Assign appropriate** roles to users
- **Regular audit** of user permissions
- **Remove inactive** users promptly
- **Maintain** user contact information

## Troubleshooting

### Common Issues
- **Can't create organization**: Check required fields
- **Users not appearing**: Verify user assignment
- **Role assignment errors**: Check role permissions
- **Address format issues**: Verify address structure

### Solutions
- **Complete all required** fields for organization creation
- **Verify user assignment** to correct organization
- **Check role permissions** for assignment issues
- **Use correct address** format for postal information

## Import/Export

### Importing Organizations
1. **Prepare** CSV file with organization data
2. **Navigate to** Import section
3. **Upload** CSV file
4. **Map** columns to organization fields
5. **Review** and confirm import

### Exporting Organizations
1. **Select** organizations to export
2. **Choose** export format (CSV, LDIF)
3. **Download** export file
4. **Use** for backup or migration

## Next Steps

- **User Management**: See [User Management](user-management.md)
- **Role Management**: See [Role Management](role-management.md)
- **Configuration**: See [Configuration](../configuration/environment-variables.md)
