# User-bound `/manage` — optional `olcAccess` fragment

`ldap-user-manager` can bind to LDAP as the logged-in user for `/manage` (see project docs). The
OpenLDAP default **cn=admin** can always manage the DIT; other binds need explicit `olcAccess` in
`cn=config`.

- **New installs / automation:** use the setup wizard
  `www/setup/apply_user_bind_acls.php` (admin bind) or the helper
  `setupApplyUserBindAclsToMdb()` in `www/includes/setup_acl_functions.inc.php`.
- **Index numbers:** the helper uses `ldap_mod_add` to append new `olcAccess` values; you do not
  need to choose `{0}`, `{1}`, … by hand.
- **Production:** add maintainer- and org-admin–specific `olcAccess` rules (e.g. `group/`
  or `set`) — the bundled rules are a baseline for **read** and **self** `userPassword` only.
- **Override database DN:** if your main mdb is not `olcDatabase={1}mdb`, set
  `LDAP_OLC_MDB_DN` in the app environment to match `cn=config`.

Do not double-apply the same `olcAccess` string; the app skips duplicates by value match.
