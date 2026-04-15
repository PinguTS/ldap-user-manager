# Custom Application Integration

This guide covers how to integrate custom applications with the Dex OIDC provider and LDAP backend provided by LDAP User Manager.

## Overview

Custom applications connect to Dex using the standard OpenID Connect Authorization Code flow. Dex authenticates the user against the LDAP directory and returns user attributes as JWT claims.

---

## OIDC Connection Parameters

| Parameter | Value |
|-----------|-------|
| Issuer / Provider URL | `https://id.example.org` |
| Discovery Document | `https://id.example.org/.well-known/openid-configuration` |
| Authorization Endpoint | `https://id.example.org/auth` |
| Token Endpoint | `https://id.example.org/token` |
| Userinfo Endpoint | `https://id.example.org/userinfo` |
| JWKS URI | `https://id.example.org/keys` |
| Scopes | `openid profile email groups` |

---

## Registering a Client

Add a static client to `dex/config.yaml`:

```yaml
staticClients:
  - id: myapp
    secret: your-app-client-secret-here
    redirectURIs:
      - https://app.example.org/auth/callback
    name: My Application
```

---

## Available Claims

After a successful login, the ID token contains:

| Claim | Description |
|-------|-------------|
| `sub` | Unique user identifier (stable UUID) |
| `preferred_username` | LDAP `uid` |
| `email` | User's email address |
| `given_name` | First name |
| `family_name` | Last name |
| `name` | Full name |
| `groups` | List of LDAP group names the user belongs to |

---

## PHP Integration

Use the [jumbojett/openid-connect-php](https://github.com/jumbojett/openid-connect-php) library.

```bash
composer require jumbojett/openid-connect-php
```

```php
<?php
require 'vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

$oidc = new OpenIDConnectClient(
    'https://id.example.org',
    'myapp',
    'your-app-client-secret-here'
);

$oidc->addScope(['openid', 'profile', 'email', 'groups']);
$oidc->setRedirectURL('https://app.example.org/auth/callback');
$oidc->authenticate();

$username = $oidc->requestUserInfo('preferred_username');
$email    = $oidc->requestUserInfo('email');
$groups   = $oidc->requestUserInfo('groups');
```

---

## Python Integration

Use [Authlib](https://docs.authlib.org/) with Flask.

```bash
pip install authlib flask
```

```python
from authlib.integrations.flask_client import OAuth
from flask import Flask, redirect, session, url_for

app = Flask(__name__)
app.secret_key = 'your-flask-secret-key'
oauth = OAuth(app)

oauth.register(
    name='dex',
    server_metadata_url='https://id.example.org/.well-known/openid-configuration',
    client_id='myapp',
    client_secret='your-app-client-secret-here',
    client_kwargs={'scope': 'openid profile email groups'},
)

@app.route('/login')
def login():
    redirect_uri = url_for('authorize', _external=True)
    return oauth.dex.authorize_redirect(redirect_uri)

@app.route('/auth/callback')
def authorize():
    try:
        token = oauth.dex.authorize_access_token()
        user = token.get('userinfo')
        session['user'] = user
        return redirect('/')
    except Exception as e:
        return f'Authentication failed: {e}', 400
```

---

## Node.js Integration

Use [openid-client](https://github.com/panva/node-openid-client) with Express.

```bash
npm install openid-client express express-session
```

```javascript
const { Issuer, generators } = require('openid-client');
const express = require('express');
const session = require('express-session');

const app = express();
app.use(session({ secret: 'your-session-secret', resave: false, saveUninitialized: false }));

Issuer.discover('https://id.example.org').then(issuer => {
    const client = new issuer.Client({
        client_id: 'myapp',
        client_secret: 'your-app-client-secret-here',
        redirect_uris: ['https://app.example.org/auth/callback'],
        response_types: ['code'],
    });

    app.get('/login', (req, res) => {
        const nonce = generators.nonce();
        req.session.nonce = nonce;
        const url = client.authorizationUrl({
            scope: 'openid profile email groups',
            nonce,
        });
        res.redirect(url);
    });

    app.get('/auth/callback', async (req, res) => {
        const params = client.callbackParams(req);
        const tokenSet = await client.callback(
            'https://app.example.org/auth/callback',
            params,
            { nonce: req.session.nonce }
        );
        const claims = tokenSet.claims();
        req.session.user = claims;
        res.redirect('/');
    });
});
```

---

## LDAP Direct Access

For applications that require direct LDAP queries (rather than OIDC), use the read-only LDAP account:

| Setting | Value |
|---------|-------|
| Server | `ldap://ldap:389` (internal) or `ldaps://ldap.example.org:636` (external) |
| Bind DN | `cn=readonly,dc=example,dc=org` |
| Bind Password | value of `LDAP_READONLY_USER_PASSWORD` |
| User Base DN | `ou=people,dc=example,dc=org` |
| Group Base DN | `ou=groups,dc=example,dc=org` |
| User Filter | `(objectClass=inetOrgPerson)` |

---

## Support

- [Dex Documentation](https://dexidp.io/docs/)
- [OpenID Connect Specification](https://openid.net/connect/)
- [OIDC Quick Reference](oidc-quick-reference.md)
- PHP: [jumbojett/openid-connect-php](https://github.com/jumbojett/openid-connect-php)
- Python: [Authlib](https://docs.authlib.org/)
- Node.js: [openid-client](https://github.com/panva/node-openid-client)
