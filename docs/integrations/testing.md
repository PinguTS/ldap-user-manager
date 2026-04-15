# Integration Testing

This guide describes how to verify that your OIDC integration is working correctly. It covers testing the Dex provider endpoints and manually walking through the Authorization Code flow.

---

## 1. Verify the Dex Discovery Endpoint

The discovery document exposes all OIDC endpoints. Confirm it is reachable and returns the expected issuer:

```bash
curl -s https://id.example.org/.well-known/openid-configuration | jq '{issuer, authorization_endpoint, token_endpoint, userinfo_endpoint, jwks_uri}'
```

Expected output:

```json
{
  "issuer": "https://id.example.org",
  "authorization_endpoint": "https://id.example.org/auth",
  "token_endpoint": "https://id.example.org/token",
  "userinfo_endpoint": "https://id.example.org/userinfo",
  "jwks_uri": "https://id.example.org/keys"
}
```

If the endpoint is unreachable, check that the Dex container is running:

```bash
docker compose ps dex
docker compose logs dex
```

---

## 2. Verify LDAP Connectivity

Confirm Dex can reach the LDAP server by testing connectivity directly:

```bash
# From inside the Dex container or the Docker network
docker compose exec dex sh -c \
  "nc -zv ldap 389 && echo 'LDAP reachable'"

# From the host using ldapsearch
ldapsearch -H ldap://localhost:389 \
  -D "cn=admin,dc=example,dc=org" \
  -w "$LDAP_ADMIN_PASSWORD" \
  -b "dc=example,dc=org" \
  "(objectClass=inetOrgPerson)" uid mail
```

---

## 3. Test the Authorization Code Flow

Dex uses the Authorization Code flow. There is no direct token API to call; the login must be initiated from a browser.

### Step 1: Construct the Authorization URL

Build the URL manually using your client's parameters:

```
https://id.example.org/auth
  ?client_id=myapp
  &redirect_uri=https://app.example.org/auth/callback
  &response_type=code
  &scope=openid+profile+email+groups
  &state=test123
  &nonce=nonce456
```

### Step 2: Open in a Browser

Open the URL above in a browser. You should be redirected to the Dex login page.

### Step 3: Log In with LDAP Credentials

Enter a valid LDAP username and password. On success, Dex redirects to your `redirect_uri` with a `code` parameter:

```
https://app.example.org/auth/callback?code=AUTHORIZATION_CODE&state=test123
```

If the redirect does not occur, check the Dex logs:

```bash
docker compose logs dex
```

### Step 4: Verify Callback Handling

Confirm that your application handles the callback correctly, exchanges the code for tokens, and establishes a user session.

---

## 4. Verify Token Content

If your application exposes a debug endpoint or you have access to the ID token, verify it contains the expected claims:

Expected claims from Dex with the `openid profile email groups` scope:

| Claim | Example Value |
|-------|---------------|
| `sub` | `CgRqb2huEgZsb2NhbA` |
| `iss` | `https://id.example.org` |
| `preferred_username` | `jdoe` |
| `email` | `jdoe@example.org` |
| `given_name` | `John` |
| `family_name` | `Doe` |
| `name` | `John Doe` |
| `groups` | `["developers", "staff"]` |

You can decode an ID token (without verifying the signature) to inspect claims:

```bash
# Replace TOKEN with the base64-encoded payload portion of the JWT
echo 'TOKEN' | cut -d. -f2 | base64 -d 2>/dev/null | jq .
```

---

## 5. Test Per-Application Login

After verifying Dex directly, test each integrated application end-to-end:

| Application | Login URL |
|-------------|-----------|
| TYPO3 | `https://typo3.example.org/` → click OIDC login button |
| GitLab | `https://gitlab.example.org/users/sign_in` → "Sign in with LDAP SSO" |
| Nextcloud | `https://nextcloud.example.org/login` → "Log in with LDAP SSO" |
| WordPress | `https://wordpress.example.org/wp-login.php` → "Login with SSO" |

For each application:
1. Initiate the login
2. Confirm redirect to `https://id.example.org/auth`
3. Log in and confirm redirect back to the application
4. Verify user attributes (display name, email, group memberships)

---

## 6. Troubleshooting Login Issues

**Login page does not redirect to Dex**
- Verify the OIDC plugin or module is enabled in the application
- Check that the issuer URL is configured correctly

**"Invalid client" or "Unauthorized client"**
- Verify the `client_id` and `client_secret` match the values in `dex/config.yaml`

**"Redirect URI not allowed"**
- The `redirect_uri` sent by the application must exactly match a URI in `dex/config.yaml` under `staticClients.redirectURIs`

**"User attributes missing"**
- Verify the requested scopes include `profile email groups`
- Check the Dex LDAP connector attribute mapping in `dex/config.yaml`

See [troubleshooting.md](troubleshooting.md) for additional guidance.
