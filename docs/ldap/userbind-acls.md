# OpenLDAP olcAccess Rules for User-Bound `/manage`

This document explains the `olcAccess` rules that ldap-user-manager expects on your
OpenLDAP server and how to verify and apply them.

---

## Background: app roles vs. LDAP identities

ldap-user-manager defines three roles that control what a user can do in the web UI:

| App role | LDAP group |
|---|---|
| System administrator | `cn={LDAP_ADMIN_ROLE},ou=roles,{LDAP_BASE_DN}` |
| System maintainer | `cn={LDAP_MAINTAINER_ROLE},ou=roles,{LDAP_BASE_DN}` |
| Organisation administrator | `cn={LDAP_ORG_ADMIN_ROLE},ou=roles,o={org},ou={LDAP_ORG_OU},{LDAP_BASE_DN}` |

These roles are **`groupOfNames` entries** — they are not native LDAP ACL subjects.
OpenLDAP's `olcAccess` evaluates rules against the **bind DN** and optionally against
group membership via `by group`. Without explicit `by group` rules, OpenLDAP only knows
whether a request comes from `self`, another authenticated DN (`by users`), or anonymous.

### What each role may do (app-level)

| Operation | Admin | Maintainer | Org admin |
|---|---|---|---|
| Create / delete system users | ✔ | ✔ (non-admin only¹) | — |
| Disable / activate system users | ✔ | ✔ (non-admin only¹) | — |
| Manage organisations (create, disable, delete) | ✔ | ✔ | — |
| Manage org users (add, remove, disable) | ✔ | ✔ | ✔ (own org) |
| Assign system roles (admin / maintainer) | ✔ | — | — |

¹ Protection of admin-role users from maintainer modification is enforced at the **PHP
application layer** (`canMaintainerDisableUser()`, `currentUserCanDeleteUser()`). OpenLDAP
ACLs cannot condition access on the *target entry's* group membership, so this restriction
cannot be expressed as an `olcAccess` rule. Maintainers receive write on the system people OU
at the LDAP level; the app prevents them from acting on admin-role users.

### What stays on admin bind

Some operations intentionally continue to use the app service account (`LDAP_ADMIN_BIND_DN`)
regardless of ACLs, because they run in contexts where no user session is available or
where reliability is paramount:

- **Login pipeline** — no session yet; the app must search and authenticate
- **Password reset e-mails** — need to read any user's `mail` attribute
- **Setup / verification pages** — modifying `cn=config` requires elevated access
- **Organisation listing and UUID resolution** — done with admin bind for reliability
  (user-bind ACL variations across installations make these fragile otherwise)
- **System users listing** (`/manage/users/`) — same reliability argument; listing uses admin
  bind so administrators and maintainers always see all users regardless of ACL state
- **Organisation detail reads** — org roles, recent users, member/disabled status, user limit,
  and org user listings all use admin bind; writes (disable/enable/delete/update) still use
  user-bind via separate per-action connections

The user-bind path handles **data write operations** on `/manage` when session credentials exist.

---

## Step 1 — Baseline ACLs (required)

At minimum you need two rules that allow every authenticated user to:

1. Write their own `userPassword` and `shadowLastChange`.
2. Read the entire base subtree (needed for login and role resolution).

```ldif
# Passwords: self write + anonymous auth passthrough
to attrs=userPassword,shadowLastChange
    by self write
    by anonymous auth
    by * break

# Authenticated read on the whole tree
to dn.subtree="dc=example,dc=com"
    by users read
    by * none
```

> If the organisations container (`ou=organizations,dc=example,dc=com`) is a separate
> child of the base DN, a specificity-helper rule on that OU may also be needed:
>
> ```ldif
> to dn.subtree="ou=organizations,dc=example,dc=com"
>     by users read
>     by * break
> ```
>
> The setup helper in `/setup/apply_user_bind_acls.php` adds this automatically when it detects
> that `LDAP_ORG_OU` is a direct child of `LDAP_BASE_DN`.

---

## Step 2 — Role-based write ACLs (recommended)

Without role-based ACLs, the app must use the admin bind for every write. With them, users
can bind as themselves and OpenLDAP enforces write permissions based on their group membership.

Rules 1–6 use `by * break` so that subsequent rules continue to be evaluated. Rule 7 uses
`by * none` (terminal deny for non-authenticated access).

### Complete rule set (with explanations)

```text
# 1. Password attributes — self write, anonymous auth passthrough
to attrs=userPassword,shadowLastChange
    by self write
    by anonymous auth
    by * break

# 2. System administrators — full manage on the entire tree
#    cn=admin,<base> is the OpenLDAP rootDN and bypasses ACLs entirely;
#    no explicit grant is needed for it here.
to dn.subtree="dc=example,dc=com"
    by group/groupOfNames/member="cn=administrators,ou=roles,dc=example,dc=com" manage
    by * break

# 3. System maintainers — write on the entire organisations subtree
#    (org entries, per-org role groups, org users under ou=people,o=Org,...)
to dn.subtree="ou=organizations,dc=example,dc=com"
    by group/groupOfNames/member="cn=maintainers,ou=roles,dc=example,dc=com" write
    by * break

# 4. System maintainers — write on the system people OU
#    Needed for creating, deleting, and disabling system user accounts.
#    ou=roles is NOT covered; maintainers cannot assign system roles via LDAP.
#    Protection of admin-role users from maintainer modification is enforced at the
#    PHP application layer — OpenLDAP ACLs cannot restrict by target group membership.
to dn.subtree="ou=people,dc=example,dc=com"
    by group/groupOfNames/member="cn=maintainers,ou=roles,dc=example,dc=com" write
    by * break

# 5. Org admins — write on their specific org subtree
#    dn.regex captures the org name in $2; group.expand resolves the per-org group DN
to dn.regex="^(.+,)?o=([^,]+),ou=organizations,dc=example,dc=com$"
    by group/groupOfNames/member.expand="cn=org_admin,ou=roles,o=$2,ou=organizations,dc=example,dc=com" write
    by * break

# 6. Self write on own entry
to dn.subtree="dc=example,dc=com"
    by self write
    by * break

# 7. Authenticated read — fallback for all other access (terminal deny for anonymous)
to dn.subtree="dc=example,dc=com"
    by users read
    by * none
```

### Key concepts

| Concept | Explanation |
|---|---|
| `by group/groupOfNames/member="..."` | Access is granted when the bind DN is listed as a `member` attribute in the named `groupOfNames` entry. |
| `by group/groupOfNames/member.expand="..."` | Same, but the group DN is computed from a back-reference (`$2`) captured by the `dn.regex` pattern. Requires OpenLDAP 2.3+. |
| `dn.regex` | Matches target DNs against a POSIX extended regular expression. Special characters in the base DN (e.g. `.`) must be escaped as `\\.` inside the regex literal. |
| `by * break` | If the rule did not grant/deny access, pass control to the next `olcAccess` rule. Without `break`, a non-match would silently deny. |
| ACL order | OpenLDAP evaluates `olcAccess` in order; more specific rules (e.g. `attrs=`) should come first. The `{N}` prefix added by slapd is auto-assigned; do not rely on it. |
| rootDN bypass | `cn=admin,<base>` is the OpenLDAP `olcRootDN`; it bypasses all ACLs regardless of what the rules say. No explicit ACL grant is needed for the service account used by the app. |

### Why maintainers have write on `ou=people` but not `ou=roles`

The tree structure separates system users from role groups:

```
dc=example,dc=com
├── ou=people           ← system user accounts ($LDAP['people_dn'])
├── ou=organizations    ← organisations and their users / roles
└── ou=roles            ← global role groups (administrators, maintainers)
```

Maintainers need write on `ou=people` to create, delete, and modify system user accounts.
They do **not** receive write on `ou=roles`, so they cannot promote a user to administrator
or add themselves to the maintainers group by manipulating LDAP directly.

The additional protection — that maintainers cannot act on users who *are* administrators —
is enforced at the PHP layer. OpenLDAP cannot evaluate "deny if the target entry is a member
of group X" as an ACL condition; it can only evaluate the *requesting* user's group membership.

---

## Applying the rules

> **Important:** if your current `olcAccess` configuration already has rules ending with
> `by * none` (the default osixia/openldap rules), those rules block any rules appended
> after them — LUM rules added at the end are never evaluated. The recommended approach is
> to **replace** the entire `olcAccess` attribute rather than appending.
>
> The setup helper at `/setup/apply_user_bind_acls.php` detects this situation and uses
> `replace:` automatically. The LDIF examples below also use `replace:`.

### Option A — EXTERNAL auth inside the LDAP container (recommended for osixia/openldap)

Replace all your current values (preserving the UNIX-socket EXTERNAL rule):

```bash
docker exec -it ldap-server sh -lc 'ldapmodify -Y EXTERNAL -H ldapi:/// <<EOF
dn: olcDatabase={1}mdb,cn=config
changetype: modify
replace: olcAccess
olcAccess: to * by dn.exact=gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth manage by * break
olcAccess: to attrs=userPassword,shadowLastChange by self write by anonymous auth by * break
olcAccess: to dn.subtree="dc=example,dc=com" by group/groupOfNames/member="cn=administrators,ou=roles,dc=example,dc=com" manage by * break
olcAccess: to dn.subtree="ou=organizations,dc=example,dc=com" by group/groupOfNames/member="cn=maintainers,ou=roles,dc=example,dc=com" write by * break
olcAccess: to dn.subtree="ou=people,dc=example,dc=com" by group/groupOfNames/member="cn=maintainers,ou=roles,dc=example,dc=com" write by * break
olcAccess: to dn.regex="^(.+,)?o=([^,]+),ou=organizations,dc=example,dc=com$" by group/groupOfNames/member.expand="cn=org_admin,ou=roles,o=$2,ou=organizations,dc=example,dc=com" write by * break
olcAccess: to dn.subtree="dc=example,dc=com" by self write by * break
olcAccess: to dn.subtree="dc=example,dc=com" by users read by * none
EOF'
```

The setup page at `/setup/apply_user_bind_acls.php` shows the same commands **with values
filled from your environment variables** and provides a one-click apply button.

### Option B — config admin over LDAPS

```bash
ldapmodify -x -H ldaps://ldap-server:636 \
  -D "cn=admin,cn=config" -w "<LDAP_CONFIG_PASSWORD>" <<EOF
# ... same LDIF body as Option A ...
EOF
```

---

## Verification

After applying, verify as each role using `ldapsearch` bound as the respective user:

### As a system maintainer (should see all organisations)

```bash
ldapsearch -x -H ldaps://ldap-server:636 \
  -D "uid=maintainer,ou=people,dc=example,dc=com" -W \
  -b "ou=organizations,dc=example,dc=com" \
  "(objectClass=organization)" dn o
```

Expected: all organisation entries are returned.

### As a system maintainer (should see all system users)

```bash
ldapsearch -x -H ldaps://ldap-server:636 \
  -D "uid=maintainer,ou=people,dc=example,dc=com" -W \
  -b "ou=people,dc=example,dc=com" \
  "(objectClass=inetOrgPerson)" dn uid
```

Expected: all system user entries are returned.

### As an org admin (should see their org and its subtree)

```bash
ldapsearch -x -H ldaps://ldap-server:636 \
  -D "uid=orgadmin,ou=people,o=MyOrg,ou=organizations,dc=example,dc=com" -W \
  -b "o=MyOrg,ou=organizations,dc=example,dc=com" \
  "(objectClass=*)" dn
```

Expected: all entries inside `o=MyOrg` are returned.

### As an org admin (should NOT see other orgs)

```bash
ldapsearch -x -H ldaps://ldap-server:636 \
  -D "uid=orgadmin,ou=people,o=MyOrg,ou=organizations,dc=example,dc=com" -W \
  -b "o=OtherOrg,ou=organizations,dc=example,dc=com" \
  "(objectClass=*)" dn
```

Expected: no results (or "No such object").

### Check current olcAccess rules (inside the LDAP container)

```bash
docker exec -i ldap-server sh -lc \
  "ldapsearch -Y EXTERNAL -H ldapi:/// -LLL -b 'olcDatabase={1}mdb,cn=config' olcAccess"
```

---

## Considerations

- **ACL order is critical.** OpenLDAP stops at the first rule that grants or denies.
  `by * break` passes control to the next rule, so put more specific rules first.
- **`dn.regex` escaping.** Dots in base DNs must be escaped as `\\.` inside the regex literal.
  The setup helper does this automatically (PHP `str_replace('.', '\\.', $baseDn)`).
  The bootstrap LDIF (`08-lum-userbind-acls.ldif`) uses `{{ LDAP_BASE_DN }}` directly — for
  the vast majority of installations (`dc=example,dc=com`) no dots appear in DC values and
  no escaping is needed. If your base DN contains literal dots (e.g. `dc=my.domain,dc=com`),
  manually apply the ACLs via the setup helper instead of relying on the bootstrap LDIF.

- **Bootstrap LDIF atomicity.** The osixia/openldap entrypoint applies bootstrap LDIFs as a
  single LDAP operation. If any unknown template variable remains unsubstituted the entire LDIF
  fails silently. Earlier versions of this file used `{{ LDAP_BASE_DN_ESCAPED }}`, which is not
  a variable known to the entrypoint, causing the complete LDIF to be skipped and no LUM ACLs
  to be added. The current version uses `{{ LDAP_BASE_DN }}` only. If you bootstrapped your
  container before this fix you must apply the ACLs manually via `/setup/apply_user_bind_acls.php`.

- **Legacy blocking rules.** The default osixia/openldap configuration includes rules ending
  with `by * none`. These terminate the ACL chain, making any LUM rules appended after them
  unreachable. The setup helper detects this and uses `replace: olcAccess` instead of
  individual `add:` operations, removing the legacy rules and installing the correct set.

- **OpenLDAP version.** `group.expand` (rule 5) requires OpenLDAP 2.3 or later.
  All modern distributions and the osixia/openldap image meet this requirement.

- **Idempotency.** The setup helper's "Apply" button checks each rule for exact string
  equality (after stripping the `{N}` prefix added by slapd) and only adds missing rules.
  It is safe to run multiple times.

- **`LDAP_OLC_MDB_DN`.** If your main database is not `olcDatabase={1}mdb,cn=config`
  (e.g. you have a second database), set this environment variable on the app service.
