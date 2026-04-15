# Organizations Export API

Returns the list of active member organizations from the LDAP directory. Intended for server-to-server integration with external systems such as TYPO3.

## Endpoint

- **URL:** `{SITE_BASE}/api/v1/organizations`
- **Method:** `GET`
- **Legacy URL:** `{SITE_BASE}/export/organizations.php` (same script, same behavior)

## Authentication

Authentication is **Bearer token only**. Send the shared secret in the `Authorization` header:

```http
GET /api/v1/organizations?format=json HTTP/1.1
Authorization: Bearer <EXPORT_SHARED_SECRET>
```

Do not pass the secret as a URL query parameter — it would appear in server logs, browser history, and referrer headers.

The token is validated server-side with a constant-time comparison (`hash_equals()`) to prevent timing attacks. If `EXPORT_SHARED_SECRET` is not configured on the server, the endpoint returns `503` regardless of what the caller sends.

## Request parameters

| Parameter | Required | Description |
|-----------|----------|-------------|
| `format` | No (default: `json`) | Response format: `json`, `json_typo3`, or `csv` |

## Response formats

### json (default)

```http
Content-Type: application/json
```

```json
{
  "organizations": [
    {
      "dn": "o=Example Org,ou=organizations,dc=example,dc=org",
      "o": "Example Org",
      "mail": "info@example.org",
      "telephoneNumber": "+49 123 456789",
      "facsimileTelephoneNumber": "",
      "postalAddress": "Main Street$12345$Berlin$$Germany",
      "postalAddress_street": "Main Street",
      "postalAddress_zip": "12345",
      "postalAddress_city": "Berlin",
      "postalAddress_country": "Germany",
      "labeledURI": "https://example.org",
      "description": "A member organization",
      "businessCategory": "Association",
      "memberNumber": "123",
      "memberSince": "2020-01-01",
      "memberUntil": "",
      "entryUUID": "550e8400-e29b-41d4-a716-446655440000"
    }
  ]
}
```

The `postalAddress` field holds the raw composite LDAP value (`$`-separated). The `postalAddress_*` fields are parsed components for convenience.

### json_typo3

Maps organization fields to TYPO3 `tt_address`-style column names, suitable for direct import into a TYPO3 address table.

```http
Content-Type: application/json
```

```json
{
  "export_version": "1.1",
  "export_date": "2026-04-15T10:00:00Z",
  "source": "ldap-user-manager",
  "record_type": "tt_address",
  "pid": 0,
  "records": [
    {
      "pid": 0,
      "company": "Example Org",
      "email": "info@example.org",
      "phone": "+49 123 456789",
      "fax": "",
      "address": "Main Street",
      "zip": "12345",
      "city": "Berlin",
      "country": "Germany",
      "www": "https://example.org",
      "description": "A member organization",
      "tx_orgtype": "Association",
      "tx_member_number": "123",
      "tx_member_since": "2020-01-01",
      "tx_member_until": "",
      "_meta": {
        "ldap_dn": "o=Example Org,ou=organizations,dc=example,dc=org",
        "ldap_uuid": "550e8400-e29b-41d4-a716-446655440000"
      }
    }
  ]
}
```

The `pid` value comes from the `TYPO3_EXPORT_PID` environment variable (default: `0`).

### csv

```http
Content-Type: text/csv
Content-Disposition: attachment; filename="organizations.csv"
```

Header row and one data row per organization:

```
company,email,phone,fax,address,zip,city,country,www,description,tx_orgtype,tx_member_number,tx_member_since,tx_member_until,entryUUID
Example Org,info@example.org,+49 123 456789,,Main Street,12345,Berlin,Germany,https://example.org,A member organization,Association,123,2020-01-01,,550e8400-e29b-41d4-a716-446655440000
```

## What is returned

Only organizations that meet **both** conditions are included:

- Member of the status group configured by `LDAP_GROUP_MEMBER_ORGS` (default: `memberOrganizations`)
- Not member of the disabled group configured by `LDAP_GROUP_DISABLED_ORGS` (default: `disabledOrganizations`)

## HTTP status codes

| HTTP | When |
|------|------|
| 200 | Success |
| 400 | Invalid `format` parameter |
| 401 | Missing or incorrect `Authorization: Bearer` token |
| 503 | `EXPORT_SHARED_SECRET` not configured, or LDAP unavailable |

## Example (curl)

```bash
curl -sS \
  -H 'Authorization: Bearer your-secret-here' \
  'https://your-host/example-path/api/v1/organizations?format=json'
```

```bash
# CSV download
curl -sS \
  -H 'Authorization: Bearer your-secret-here' \
  'https://your-host/example-path/api/v1/organizations?format=csv' \
  -o organizations.csv
```

## Configuration

For server-side configuration — how to generate `EXPORT_SHARED_SECRET`, set the TYPO3 page ID, and configure the LDAP status groups — see [Export Endpoint](../deployment/export-endpoint.md).
