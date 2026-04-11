# Password reset request API

Use this endpoint when integrating **forgot password** from an external system (for example TYPO3). Behavior matches the browser form at `password/reset/`: the same LDAP lookup runs for organization users and system users, and responses do not reveal whether an account exists.

## Endpoint

- **URL:** `{SITE_BASE}/api/v1/password/reset-request/`  
  Replace `{SITE_BASE}` with your deployment base URL (including any path prefix, e.g. `https://example.org/ldap-manager/`).
- **Method:** `POST`
- **Content-Type:** `application/json`

## Request body

```json
{
  "email": "user@example.com"
}
```

The value must be a syntactically valid email address. The directory is searched using the configured LDAP account attribute (often `mail`).

## Successful response

- **HTTP 200**
- **Body:**

```json
{
  "message": "…"
}
```

The `message` is the same generic text as on the self-service page (`password.reset.message` in locales). It is returned whether or not a matching account was found or an email was sent, to reduce account enumeration.

## Error responses

| HTTP | `error` field | When |
|------|----------------|------|
| 405 | `method_not_allowed` | Not `POST` |
| 429 | `rate_limited` | Too many requests from this client IP or for this email address |

Rate limits (defaults): **30 requests per hour per IP**, and **5 per hour per normalized email** (only counted when the email format is valid).

## After the request

If the account exists and outbound mail is configured, the user receives an email with a link to **`password/set/`** on this application. The user completes the new password there; no further API call is required for the typical flow.

## Prerequisites

- `PASSWORD_RESET_TOKEN_SECRET` set (token signing).
- Optional: `PASSWORD_RESET_TOKEN_TTL_SECONDS` — link lifetime in seconds (default **3600**, i.e. 60 minutes). Same value is used for the expiry text in the email.
- Email sending verified / enabled as for the rest of the app (`EMAIL_SENDING_ENABLED`).

## Example (curl)

```bash
curl -sS -X POST \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com"}' \
  'https://your-host/example-path/api/v1/password/reset-request/'
```
