# OpenLDAP (Docker) and `pwdAccountLockedTime`

Account lock in LDAP User Manager needs the **ppolicy** module and overlay on the main database (and a default policy where your build requires it).

## osixia/openldap (env — preferred in root `docker-compose.yml`)

On **supported osixia** images, enable ppolicy with:

```bash
LDAP_BACKEND_OVERLAY_PPOLICY=true
```

Use string **`"true"`** in Docker Compose. This avoids maintaining a custom `cn=config` LDIF for ppolicy.

**Do not** combine this with the **`06-ppolicy.ldif`** bootstrap at the same time — you would add the overlay twice. If your image tag ignores this variable, use the LDIF fallback below.

## osixia/openldap (LDIF fallback)

If **`LDAP_BACKEND_OVERLAY_PPOLICY`** is not available on your tag, mount **`bootstrap/ldif/custom/06-ppolicy.ldif`** →  
`/container/service/slapd/assets/config/bootstrap/ldif/custom` and use **`--copy-service`** (first init only, empty `slapd.d`).

Overlay index **`{2}`** assumes default ordering: **`{0}`** memberof, **`{1}`** refint.

## Bitnami OpenLDAP (alternative)

- **`LDAP_CONFIGURE_PPOLICY=yes`**
- Optional: **`LDAP_PPOLICY_USE_LOCKOUT=yes`**, **`LDAP_PPOLICY_HASH_CLEARTEXT=yes`**

See the [Bitnami OpenLDAP README](https://github.com/bitnami/containers/blob/main/bitnami/openldap/README.md).
