# LDIF Files for LDAP User Manager

This directory contains LDIF files for setting up the LDAP directory structure and schema.

## Files

- `base.ldif` - Base directory structure and organizational units
- `system_users.ldif` - System users (administrators, maintainers)
- `example-org.ldif` - Example organization with users
- `loginPasscode.ldif` - Additional user attributes
- `userRole-schema.ldif` - Custom schema for userRole attribute

## Loading Order

**IMPORTANT**: The schema must be loaded before any data that uses it.

### 1. Load the Custom Schema (Required First)

```bash
# Load the custom schema that defines the userRole attribute
ldapadd -Y EXTERNAL -H ldapi:/// -f userRole-schema.ldif
```

### 2. Load the Base Structure

```bash
# Load the base directory structure
ldapadd -Y EXTERNAL -H ldapi:/// -f base.ldif
```

### 3. Load System Users

```bash
# Load system users (requires schema to be loaded first)
ldapadd -Y EXTERNAL -H ldapi:/// -f system_users.ldif
```

### 4. Load Example Organization (Optional)

```bash
# Load example organization and users
ldapadd -Y EXTERNAL -H ldapi:/// -f example-org.ldif
```

## Troubleshooting

If you get "attribute type undefined" errors:

1. Make sure the schema was loaded first
2. Check that the LDAP server supports dynamic schema loading
3. Verify the schema was loaded correctly: `ldapsearch -Y EXTERNAL -H ldapi:/// -b cn=schema,cn=configuration -s base`

## Alternative: Use Standard Attributes

If you cannot load custom schemas, you can modify the system to use standard attributes like `description` or `title` to store role information, but this requires code changes.
