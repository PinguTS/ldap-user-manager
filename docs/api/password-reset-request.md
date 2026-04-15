# Password Reset Request API

Use this endpoint when integrating **forgot password** from an external system (for example TYPO3). Behavior matches the browser form at `password/reset/`: the same LDAP lookup runs for organization users and system users, and responses do not reveal whether an account exists.

## Endpoint

- **URL:** `{SITE_BASE}/api/v1/password/reset-request/`  
  Replace `{SITE_BASE}` with your deployment base URL (including any path prefix, e.g. `https://example.org/ldap-manager/`).
- **Method:** `POST`
- **Content-Type:** `application/json`

## Authentication

**No API token is required.** This endpoint is intentionally public, equivalent to the browser-based forgot-password form. Any caller can submit an email address. The application will only send a reset email if the address matches an account in the LDAP directory; no information about whether an account exists is revealed in the response.

The only access controls are rate limits (see below).

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

The following environment variables must be configured on the server for the endpoint to work. They are not sent by the API caller.

- **`PASSWORD_RESET_TOKEN_SECRET`** — Used to sign the time-limited reset link embedded in the password reset email. This is not an API access token and does not need to be sent by the caller. Generate with:
  ```bash
  openssl rand -hex 32
  ```
- **`PASSWORD_RESET_TOKEN_TTL_SECONDS`** — Optional. Lifetime of the reset link in seconds (default: **3600**, i.e. 60 minutes). The same value is used in the expiry text in the email.
- **`EMAIL_SENDING_ENABLED`** and related mail settings — Required for the reset email to be delivered.

## Example (curl)

```bash
curl -sS -X POST \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com"}' \
  'https://your-host/example-path/api/v1/password/reset-request/'
```
