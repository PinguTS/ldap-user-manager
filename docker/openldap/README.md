# OpenLDAP (Docker) — Overlays

## Contents

- [ppolicy — `pwdAccountLockedTime`](#ppolicy)
- [accesslog — Organization change history](#accesslog)
- [User-bound `/manage` — olcAccess (ACL)](#userbind)

---

<a name="ppolicy"></a>
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

---

<a name="accesslog"></a>
# OpenLDAP accesslog — Organization change history

The accesslog overlay records every successful write operation in a separate
`cn=accesslog` database. LDAP User Manager uses this to show who last changed
an organization's attributes and when.

**Two env vars are needed — one per container:**

| Container | Variable | Purpose |
|---|---|---|
| `ldap` (OpenLDAP) | `LDAP_BACKEND_OVERLAY_ACCESSLOG=true` | Activates the overlay on the LDAP server |
| `ldap-user-manager` (PHP app) | `LDAP_ACCESSLOG_ENABLED=true` | Tells the app to query and display the history |

Both must be set to see organization change history in the UI.

---

## osixia/openldap (env — preferred)

Uncomment in `docker-compose.yml` under the `ldap` service:

```yaml
LDAP_BACKEND_OVERLAY_ACCESSLOG: "true"
```

Then set in the `ldap-user-manager` service:

```yaml
LDAP_ACCESSLOG_ENABLED: "true"
```

**Do not** mount `07-accesslog.ldif` when using this env var — that would add the overlay twice.

---

## osixia/openldap (LDIF fallback — first init only)

If your image tag does not support `LDAP_BACKEND_OVERLAY_ACCESSLOG`, mount
**`bootstrap/ldif/custom/07-accesslog.ldif`** →
`/container/service/slapd/assets/config/bootstrap/ldif/custom`
and use `--copy-service` (first init only, empty `slapd.d`).

Replace `{{ LDAP_BASE_DN }}` and `{{ LDAP_BACKEND }}` with your actual values before mounting.

Overlay index **`{3}`** assumes default ordering: **`{0}`** memberof, **`{1}`** refint, **`{2}`** ppolicy.

Before applying, create the accesslog data directory inside the container:

```bash
docker exec -it ldap-server mkdir -p /var/lib/ldap/accesslog
docker exec -it ldap-server chown openldap:openldap /var/lib/ldap/accesslog
```

---

## Enabling accesslog on an existing database (post-init)

When the LDAP server already has data and you want to add the overlay later:

1. Create the data directory inside the running container:

   ```bash
   docker exec -it ldap-server mkdir -p /var/lib/ldap/accesslog
   docker exec -it ldap-server chown openldap:openldap /var/lib/ldap/accesslog
   ```

2. Apply the following `ldapmodify` commands against `cn=config`
   (replace `<BASE_DN>` with your actual base DN, e.g. `dc=example,dc=com`):

   ```bash
   # Load the accesslog module
   ldapmodify -H ldapi:/// -Y EXTERNAL <<'EOF'
   dn: cn=module{0},cn=config
   changetype: modify
   add: olcModuleLoad
   olcModuleLoad: accesslog
   EOF

   # Create the accesslog database
   ldapadd -H ldapi:/// -Y EXTERNAL <<'EOF'
   dn: olcDatabase={2}mdb,cn=config
   objectClass: olcDatabaseConfig
   objectClass: olcMdbConfig
   olcDatabase: {2}mdb
   olcDbDirectory: /var/lib/ldap/accesslog
   olcSuffix: cn=accesslog
   olcRootDN: cn=admin,cn=accesslog
   olcDbIndex: default eq
   olcDbIndex: entryCSN,objectClass,reqEnd,reqResult,reqStart
   olcAccess: to * by dn.base="cn=admin,<BASE_DN>" manage by * none
   EOF

   # Add the accesslog overlay to the main database
   ldapadd -H ldapi:/// -Y EXTERNAL <<'EOF'
   dn: olcOverlay=accesslog,olcDatabase={1}mdb,cn=config
   objectClass: olcOverlayConfig
   objectClass: olcAccessLogConfig
   olcOverlay: accesslog
   olcAccessLogDB: cn=accesslog
   olcAccessLogOps: writes
   olcAccessLogSuccess: TRUE
   olcAccessLogPurge: 90+00:00 1+00:00
   EOF
   ```

3. Set `LDAP_ACCESSLOG_ENABLED=true` in the `ldap-user-manager` service environment
   and restart the app container.

4. Visit `/setup` (set `APP_SETUP_LOCKED=false` if setup was previously completed)
   to verify that `cn=accesslog` is accessible.

---

## Access control note

The ACL `olcAccess: to * by dn.base="cn=admin,<BASE_DN>" manage by * none`
grants only the main admin DN full access to the accesslog DB. All other
binds are denied, which is the correct security posture.

The app connects to LDAP using `LDAP_ADMIN_BIND_DN` / `LDAP_ADMIN_BIND_PWD`,
which must match the admin DN above for history queries to succeed.

---

<a name="userbind"></a>
# User-bound `/manage` — `olcAccess` (ACL)

The web UI can perform **data** operations under `/manage` as the **logged-in user** (after password login) so that OpenLDAP ACLs and accesslog `reqAuthzID` reflect real users. That only works if your directory grants each role enough `olcAccess` on the DNs the UI edits (for example org admins on their `o=…` subtree).

- **Operator reference:** `docs/ldap/userbind-acls.md` — intended rules, bootstrap LDIF location, ordering notes, and conflict checks with accesslog.
- **Verification / apply:** use the **User-bound /manage (OpenLDAP olcAccess)** card in `/setup/run_checks.php`. If rules are missing, open **Apply baseline olcAccess** (`/setup/apply_user_bind_acls.php`). This issues an idempotent `ldapmodify` against `olcAccess` on the main MDB (default `olcDatabase={1}mdb,cn=config`); set **`LDAP_OLC_MDB_DN`** in the app environment if your main database entry uses a different `{n}`.
- **Manual test (accesslog):** sign in with the HTML form as an org admin, change an organization attribute, then search `cn=accesslog` for the write: `reqAuthzID` should match the user’s DN when user bind is active; sessions without a stored password (SSO, header login) use the admin bind and will show the service account in the log for writes.
