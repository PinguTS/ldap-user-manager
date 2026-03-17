# Member Organizations Export Endpoint

This document describes the export endpoint used by external systems (e.g. TYPO3) to fetch member organization data. It is **not** a management UI page; it lives at a top-level path and is called server-to-server.

## Endpoint

**URL**: `GET /export/organizations.php`

**Location**: `www/export/organizations.php`

## Authentication

Authentication is **Bearer token only**. The shared secret must be sent in the `Authorization` header. **Do not** pass the secret as a URL query parameter (it would appear in logs, browser history, and referrers).

```http
GET /export/organizations.php?format=json_typo3 HTTP/1.1
Authorization: Bearer <EXPORT_SHARED_SECRET>
```

### Environment variable

- **`EXPORT_SHARED_SECRET`** – Required for the endpoint to respond. Generate with:
  ```bash
  openssl rand -hex 32
  ```
  Minimum 32 characters. If empty or unset, the endpoint returns **503** (Export not configured).

### Security

- The endpoint validates the secret with `hash_equals()` to avoid timing attacks.
- No web session or `requireRole()` is used; the Bearer secret is the sole authentication.
- Use HTTPS and consider rate-limiting by IP at the reverse proxy (e.g. Caddy).

## Request parameters

| Parameter | Required | Description |
|----------|----------|-------------|
| `format` | No (default: `json`) | One of: `json`, `json_typo3`, `csv` |

## Supported formats

| `format`   | Content-Type        | Description |
|------------|---------------------|-------------|
| `json`    | `application/json`  | Full JSON of all exported (member, non-disabled) organizations |
| `json_typo3` | `application/json` | Same data mapped to TYPO3 `tt_address`-style columns |
| `csv`     | `text/csv`          | CSV with header row |

## What is exported

Only organizations that are **members** (in the status group configured by `LDAP_GROUP_MEMBER_ORGS`) and **not disabled** (not in `LDAP_GROUP_DISABLED_ORGS`) are included.

Attributes exported include: `o`, `mail`, `postalAddress`, `telephoneNumber`, `facsimileTelephoneNumber`, `labeledURI`, `description`, `businessCategory`, `memberNumber`, `memberSince`, `entryUUID`.

## Related configuration

- **`EXPORT_SHARED_SECRET`** – Shared secret for Bearer auth (see above).
- **`TYPO3_EXPORT_PID`** – Page ID used in TYPO3-oriented export (default: `0`).
- **`LDAP_GROUP_MEMBER_ORGS`** – CN of the “member organizations” status group (default: `memberOrganizations`).
- **`LDAP_GROUP_DISABLED_ORGS`** – CN of the “disabled organizations” status group (default: `disabledOrganizations`).

See [Environment Variables](../configuration/environment-variables.md) and [LDAP Structure](../ldap-structure.md) for full details.

## HTTP status codes

- **200** – Success; response body is JSON or CSV per `format`.
- **400** – Invalid `format` parameter.
- **401** – Missing or invalid `Authorization: Bearer` token.
- **503** – Export not configured (e.g. empty `EXPORT_SHARED_SECRET` or LDAP not configured) or LDAP unavailable.
