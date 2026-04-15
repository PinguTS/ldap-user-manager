# Compliance Considerations

This page outlines how LDAP User Manager can support your organization's compliance posture. It does **not** certify compliance with any standard — compliance depends on your entire deployment, policies, and processes, not on a single application.

## GDPR / Data Protection

LDAP User Manager stores user personal data (name, email, organization affiliation) in your own LDAP directory, which you control and host. Key points:

- **Data minimization**: Only the fields you configure as required are collected.
- **Data subject access**: Administrators can export or view any user's data through the management UI.
- **Data deletion**: Deleting a user account removes all data from LDAP. Audit log entries may persist depending on your `AUDIT_LOG_FILE` retention configuration.
- **Data portability**: The export endpoint (`/export/organizations.php`) provides structured JSON output. Direct LDAP access (`ldapsearch`, `slapcat`) provides full data export.
- **Audit trail**: Enable `AUDIT_LOG_ENABLED=TRUE` to log all administrative actions.

You are responsible for: defining a legal basis for processing, establishing a retention policy, configuring backup encryption, and managing access to the LDAP server and audit logs.

## Access Control

The application enforces role-based access control with four levels:

- **System Administrator** — full access to all functions.
- **System Maintainer** — can manage organizations and their users, not system users or roles.
- **Organization Administrator** — can manage only their own organization.
- **User** — self-service only (change own password, view own profile).

Follow the principle of least privilege: assign users the lowest role that meets their need.

## Audit Logging

Set the following to enable audit logging:

```bash
AUDIT_LOG_ENABLED=TRUE
AUDIT_LOG_FILE=/var/log/ldap_user_manager/audit.log
```

The log records administrative actions (user creation, modification, deletion, login events). Ensure the log file is stored on a volume with appropriate retention and access controls.

## Network and Transport Security

For production deployments:

- Use LDAPS (`ldaps://`) or STARTTLS for LDAP connections.
- Terminate HTTPS at the reverse proxy (Caddy, Nginx, or Apache with TLS).
- Restrict LDAP ports (389/636) to internal network traffic only.
- Use Docker networks to isolate the LDAP container from public interfaces.

See [Security Best Practices](best-practices.md) for concrete configuration steps.

## Password Security

Configure the password policy to meet your organization's requirements:

```bash
PASSWORD_STRENGTH_MIN_SCORE=2        # 0-4 scale; 2 = fair, 3 = good
PASSWORD_STRENGTH_MIN_LENGTH=12
PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
PASSWORD_STRENGTH_REQUIRE_SYMBOLS=TRUE
```

Password hashing algorithm is set via `PASSWORD_HASH` (default: `SSHA`). Passwords are stored in the LDAP directory.

## Backup and Recovery

Regular backups of LDAP data are essential for compliance with most data protection frameworks. See [LDAP Backup](../ldap/backup.md) for backup and restore procedures.

Store backup files encrypted and off-site. Document and test your recovery procedure periodically.
