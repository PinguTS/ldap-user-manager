# Custom Application Integration

This guide provides detailed integration instructions for connecting custom applications with LDAP User Manager.

## Overview

Custom applications can integrate with LDAP User Manager using:
- **OIDC Client Libraries**: Modern OIDC-based integration
- **LDAP Client Libraries**: Traditional LDAP-based integration

## PHP Application Integration

### OIDC Client Implementation

#### Basic OIDC Client
```php
<?php
// oidc_client.php

class OidcClient
{
    private $issuer;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $scopes;
    
    public function __construct($config)
    {
        $this->issuer = $config['issuer'];
        $this->clientId = $config['client_id'];
        $this->clientSecret = $config['client_secret'];
        $this->redirectUri = $config['redirect_uri'];
        $this->scopes = $config['scopes'];
    }
    
    public function getAuthorizationUrl()
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scopes,
            'state' => $this->generateState()
        ];
        
        return $this->issuer . '/auth?' . http_build_query($params);
    }
    
    public function exchangeCodeForToken($code)
    {
        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri
        ];
        
        $response = $this->makeRequest('POST', '/token', $params);
        
        return json_decode($response, true);
    }
    
    public function getUserInfo($accessToken)
    {
        $headers = [
            'Authorization: Bearer ' . $accessToken
        ];
        
        $response = $this->makeRequest('GET', '/userinfo', [], $headers);
        
        return json_decode($response, true);
    }
    
    private function makeRequest($method, $endpoint, $data = [], $headers = [])
    {
        $ch = curl_init();
        
        $url = $this->issuer . $endpoint;
        
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    }
    
    private function generateState()
    {
        return bin2hex(random_bytes(16));
    }
}
```

#### Usage Example
```php
<?php
// config.php
$config = [
    'issuer' => 'https://id.example.org',
    'client_id' => 'myapp',
    'client_secret' => 'your-client-secret-here',
    'redirect_uri' => 'https://myapp.example.org/callback',
    'scopes' => 'openid profile email groups'
];

$oidc = new OidcClient($config);

// Redirect to OIDC provider
if (isset($_GET['login'])) {
    $authUrl = $oidc->getAuthorizationUrl();
    header('Location: ' . $authUrl);
    exit;
}

// Handle callback
if (isset($_GET['code'])) {
    $token = $oidc->exchangeCodeForToken($_GET['code']);
    $userInfo = $oidc->getUserInfo($token['access_token']);
    
    // Process user information
    echo "Welcome, " . $userInfo['name'] . "!";
}
?>
```

### LDAP Client Implementation

#### Basic LDAP Client
```php
<?php
// ldap_client.php

class LdapClient
{
    private $host;
    private $port;
    private $encryption;
    private $baseDn;
    private $bindDn;
    private $bindPassword;
    private $connection;
    
    public function __construct($config)
    {
        $this->host = $config['host'];
        $this->port = $config['port'];
        $this->encryption = $config['encryption'];
        $this->baseDn = $config['base_dn'];
        $this->bindDn = $config['bind_dn'];
        $this->bindPassword = $config['bind_password'];
    }
    
    public function connect()
    {
        $ldapUri = $this->host . ':' . $this->port;
        
        if ($this->encryption === 'ssl') {
            $ldapUri = 'ldaps://' . $ldapUri;
        } else {
            $ldapUri = 'ldap://' . $ldapUri;
        }
        
        $this->connection = ldap_connect($ldapUri);
        
        if (!$this->connection) {
            throw new Exception('Failed to connect to LDAP server');
        }
        
        ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($this->connection, LDAP_OPT_REFERRALS, 0);
        
        if (!ldap_bind($this->connection, $this->bindDn, $this->bindPassword)) {
            throw new Exception('Failed to bind to LDAP server');
        }
        
        return true;
    }
    
    public function authenticateUser($username, $password)
    {
        $userDn = $this->findUser($username);
        
        if (!$userDn) {
            return false;
        }
        
        return ldap_bind($this->connection, $userDn, $password);
    }
    
    public function findUser($username)
    {
        $filter = "(uid=$username)";
        $result = ldap_search($this->connection, $this->baseDn, $filter);
        $entries = ldap_get_entries($this->connection, $result);
        
        if ($entries['count'] > 0) {
            return $entries[0]['dn'];
        }
        
        return false;
    }
    
    public function getUserInfo($username)
    {
        $filter = "(uid=$username)";
        $result = ldap_search($this->connection, $this->baseDn, $filter);
        $entries = ldap_get_entries($this->connection, $result);
        
        if ($entries['count'] > 0) {
            return $entries[0];
        }
        
        return false;
    }
    
    public function getUserGroups($username)
    {
        $userInfo = $this->getUserInfo($username);
        
        if (!$userInfo || !isset($userInfo['memberof'])) {
            return [];
        }
        
        $groups = [];
        foreach ($userInfo['memberof'] as $group) {
            $groups[] = $this->extractGroupName($group);
        }
        
        return $groups;
    }
    
    private function extractGroupName($groupDn)
    {
        preg_match('/cn=([^,]+)/', $groupDn, $matches);
        return $matches[1] ?? $groupDn;
    }
    
    public function close()
    {
        if ($this->connection) {
            ldap_close($this->connection);
        }
    }
}
```

#### Usage Example
```php
<?php
// config.php
$config = [
    'host' => 'ldap.example.com',
    'port' => 636,
    'encryption' => 'ssl',
    'base_dn' => 'dc=example,dc=com',
    'bind_dn' => 'cn=admin,dc=example,dc=com',
    'bind_password' => 'admin123'
];

$ldap = new LdapClient($config);

try {
    $ldap->connect();
    
    if ($ldap->authenticateUser($_POST['username'], $_POST['password'])) {
        $userInfo = $ldap->getUserInfo($_POST['username']);
        $groups = $ldap->getUserGroups($_POST['username']);
        
        echo "Welcome, " . $userInfo['cn'][0] . "!";
        echo "Groups: " . implode(', ', $groups);
    } else {
        echo "Authentication failed";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
} finally {
    $ldap->close();
}
?>
```

## Python Application Integration

### OIDC Client Implementation

#### Basic OIDC Client
```python
# oidc_client.py

import requests
import secrets
import json
from urllib.parse import urlencode

class OidcClient:
    def __init__(self, config):
        self.issuer = config['issuer']
        self.client_id = config['client_id']
        self.client_secret = config['client_secret']
        self.redirect_uri = config['redirect_uri']
        self.scopes = config['scopes']
    
    def get_authorization_url(self):
        params = {
            'response_type': 'code',
            'client_id': self.client_id,
            'redirect_uri': self.redirect_uri,
            'scope': self.scopes,
            'state': secrets.token_hex(16)
        }
        
        return f"{self.issuer}/auth?{urlencode(params)}"
    
    def exchange_code_for_token(self, code):
        data = {
            'grant_type': 'authorization_code',
            'client_id': self.client_id,
            'client_secret': self.client_secret,
            'code': code,
            'redirect_uri': self.redirect_uri
        }
        
        response = requests.post(f"{self.issuer}/token", data=data)
        return response.json()
    
    def get_user_info(self, access_token):
        headers = {
            'Authorization': f'Bearer {access_token}'
        }
        
        response = requests.get(f"{self.issuer}/userinfo", headers=headers)
        return response.json()
```

#### Flask Example
```python
# app.py

from flask import Flask, request, redirect, session
from oidc_client import OidcClient

app = Flask(__name__)
app.secret_key = 'your-secret-key'

config = {
    'issuer': 'https://id.example.org',
    'client_id': 'myapp',
    'client_secret': 'your-client-secret-here',
    'redirect_uri': 'https://myapp.example.org/callback',
    'scopes': 'openid profile email groups'
}

oidc = OidcClient(config)

@app.route('/login')
def login():
    auth_url = oidc.get_authorization_url()
    return redirect(auth_url)

@app.route('/callback')
def callback():
    code = request.args.get('code')
    token = oidc.exchange_code_for_token(code)
    user_info = oidc.get_user_info(token['access_token'])
    
    session['user'] = user_info
    return f"Welcome, {user_info['name']}!"

if __name__ == '__main__':
    app.run(debug=True)
```

### LDAP Client Implementation

#### Basic LDAP Client
```python
# ldap_client.py

import ldap
from ldap.filter import escape_filter_chars

class LdapClient:
    def __init__(self, config):
        self.host = config['host']
        self.port = config['port']
        self.encryption = config['encryption']
        self.base_dn = config['base_dn']
        self.bind_dn = config['bind_dn']
        self.bind_password = config['bind_password']
        self.connection = None
    
    def connect(self):
        ldap_uri = f"{self.host}:{self.port}"
        
        if self.encryption == 'ssl':
            ldap_uri = f"ldaps://{ldap_uri}"
        else:
            ldap_uri = f"ldap://{ldap_uri}"
        
        self.connection = ldap.initialize(ldap_uri)
        self.connection.set_option(ldap.OPT_PROTOCOL_VERSION, 3)
        self.connection.set_option(ldap.OPT_REFERRALS, 0)
        
        self.connection.simple_bind_s(self.bind_dn, self.bind_password)
    
    def authenticate_user(self, username, password):
        user_dn = self.find_user(username)
        
        if not user_dn:
            return False
        
        try:
            self.connection.simple_bind_s(user_dn, password)
            return True
        except ldap.INVALID_CREDENTIALS:
            return False
    
    def find_user(self, username):
        filter_str = f"(uid={escape_filter_chars(username)})"
        
        try:
            result = self.connection.search_s(
                self.base_dn,
                ldap.SCOPE_SUBTREE,
                filter_str
            )
            
            if result:
                return result[0][0]
        except ldap.LDAPError:
            pass
        
        return None
    
    def get_user_info(self, username):
        filter_str = f"(uid={escape_filter_chars(username)})"
        
        try:
            result = self.connection.search_s(
                self.base_dn,
                ldap.SCOPE_SUBTREE,
                filter_str
            )
            
            if result:
                return result[0][1]
        except ldap.LDAPError:
            pass
        
        return None
    
    def get_user_groups(self, username):
        user_info = self.get_user_info(username)
        
        if not user_info or 'memberOf' not in user_info:
            return []
        
        groups = []
        for group_dn in user_info['memberOf']:
            group_name = self.extract_group_name(group_dn.decode('utf-8'))
            groups.append(group_name)
        
        return groups
    
    def extract_group_name(self, group_dn):
        import re
        match = re.search(r'cn=([^,]+)', group_dn)
        return match.group(1) if match else group_dn
    
    def close(self):
        if self.connection:
            self.connection.unbind_s()
```

#### Flask Example
```python
# app.py

from flask import Flask, request, render_template
from ldap_client import LdapClient

app = Flask(__name__)

config = {
    'host': 'ldap.example.com',
    'port': 636,
    'encryption': 'ssl',
    'base_dn': 'dc=example,dc=com',
    'bind_dn': 'cn=admin,dc=example,dc=com',
    'bind_password': 'admin123'
}

ldap_client = LdapClient(config)

@app.route('/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        username = request.form['username']
        password = request.form['password']
        
        try {
            ldap_client.connect()
            
            if ldap_client.authenticate_user(username, password):
                user_info = ldap_client.get_user_info(username)
                groups = ldap_client.get_user_groups(username)
                
                return f"Welcome, {user_info['cn'][0].decode('utf-8')}! Groups: {', '.join(groups)}"
            else:
                return "Authentication failed"
        except Exception as e:
            return f"Error: {str(e)}"
        finally:
            ldap_client.close()
    
    return render_template('login.html')

if __name__ == '__main__':
    app.run(debug=True)
```

## Node.js Application Integration

### OIDC Client Implementation

#### Basic OIDC Client
```javascript
// oidc_client.js

const axios = require('axios');
const crypto = require('crypto');

class OidcClient {
    constructor(config) {
        this.issuer = config.issuer;
        this.clientId = config.client_id;
        this.clientSecret = config.client_secret;
        this.redirectUri = config.redirect_uri;
        this.scopes = config.scopes;
    }
    
    getAuthorizationUrl() {
        const params = new URLSearchParams({
            response_type: 'code',
            client_id: this.clientId,
            redirect_uri: this.redirectUri,
            scope: this.scopes,
            state: crypto.randomBytes(16).toString('hex')
        });
        
        return `${this.issuer}/auth?${params.toString()}`;
    }
    
    async exchangeCodeForToken(code) {
        const data = new URLSearchParams({
            grant_type: 'authorization_code',
            client_id: this.clientId,
            client_secret: this.clientSecret,
            code: code,
            redirect_uri: this.redirectUri
        });
        
        const response = await axios.post(`${this.issuer}/token`, data, {
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            }
        });
        
        return response.data;
    }
    
    async getUserInfo(accessToken) {
        const response = await axios.get(`${this.issuer}/userinfo`, {
            headers: {
                'Authorization': `Bearer ${accessToken}`
            }
        });
        
        return response.data;
    }
}

module.exports = OidcClient;
```

#### Express.js Example
```javascript
// app.js

const express = require('express');
const session = require('express-session');
const OidcClient = require('./oidc_client');

const app = express();

app.use(session({
    secret: 'your-secret-key',
    resave: false,
    saveUninitialized: true
}));

const config = {
    issuer: 'https://id.example.org',
    client_id: 'myapp',
    client_secret: 'your-client-secret-here',
    redirect_uri: 'https://myapp.example.org/callback',
    scopes: 'openid profile email groups'
};

const oidc = new OidcClient(config);

app.get('/login', (req, res) => {
    const authUrl = oidc.getAuthorizationUrl();
    res.redirect(authUrl);
});

app.get('/callback', async (req, res) => {
    try {
        const code = req.query.code;
        const token = await oidc.exchangeCodeForToken(code);
        const userInfo = await oidc.getUserInfo(token.access_token);
        
        req.session.user = userInfo;
        res.send(`Welcome, ${userInfo.name}!`);
    } catch (error) {
        res.status(500).send('Authentication failed');
    }
});

app.listen(3000, () => {
    console.log('Server running on port 3000');
});
```

### LDAP Client Implementation

#### Basic LDAP Client
```javascript
// ldap_client.js

const ldap = require('ldapjs');

class LdapClient {
    constructor(config) {
        this.host = config.host;
        this.port = config.port;
        this.encryption = config.encryption;
        this.baseDn = config.base_dn;
        this.bindDn = config.bind_dn;
        this.bindPassword = config.bind_password;
        this.client = null;
    }
    
    async connect() {
        const ldapUri = `${this.host}:${this.port}`;
        const url = this.encryption === 'ssl' ? `ldaps://${ldapUri}` : `ldap://${ldapUri}`;
        
        this.client = ldap.createClient({ url });
        
        return new Promise((resolve, reject) => {
            this.client.bind(this.bindDn, this.bindPassword, (err) => {
                if (err) {
                    reject(err);
                } else {
                    resolve();
                }
            });
        });
    }
    
    async authenticateUser(username, password) {
        const userDn = await this.findUser(username);
        
        if (!userDn) {
            return false;
        }
        
        return new Promise((resolve) => {
            this.client.bind(userDn, password, (err) => {
                resolve(!err);
            });
        });
    }
    
    async findUser(username) {
        const filter = `(uid=${username})`;
        
        return new Promise((resolve, reject) => {
            this.client.search(this.baseDn, {
                scope: 'sub',
                filter: filter
            }, (err, res) => {
                if (err) {
                    reject(err);
                    return;
                }
                
                res.on('searchEntry', (entry) => {
                    resolve(entry.objectName);
                });
                
                res.on('end', () => {
                    resolve(null);
                });
            });
        });
    }
    
    async getUserInfo(username) {
        const filter = `(uid=${username})`;
        
        return new Promise((resolve, reject) => {
            this.client.search(this.baseDn, {
                scope: 'sub',
                filter: filter
            }, (err, res) => {
                if (err) {
                    reject(err);
                    return;
                }
                
                res.on('searchEntry', (entry) => {
                    resolve(entry.object);
                });
                
                res.on('end', () => {
                    resolve(null);
                });
            });
        });
    }
    
    async getUserGroups(username) {
        const userInfo = await this.getUserInfo(username);
        
        if (!userInfo || !userInfo.memberOf) {
            return [];
        }
        
        return userInfo.memberOf.map(groupDn => this.extractGroupName(groupDn));
    }
    
    extractGroupName(groupDn) {
        const match = groupDn.match(/cn=([^,]+)/);
        return match ? match[1] : groupDn;
    }
    
    close() {
        if (this.client) {
            this.client.unbind();
        }
    }
}

module.exports = LdapClient;
```

#### Express.js Example
```javascript
// app.js

const express = require('express');
const LdapClient = require('./ldap_client');

const app = express();

app.use(express.urlencoded({ extended: true }));

const config = {
    host: 'ldap.example.com',
    port: 636,
    encryption: 'ssl',
    base_dn: 'dc=example,dc=com',
    bind_dn: 'cn=admin,dc=example,dc=com',
    bind_password: 'admin123'
};

const ldapClient = new LdapClient(config);

app.post('/login', async (req, res) => {
    const { username, password } = req.body;
    
    try {
        await ldapClient.connect();
        
        const authenticated = await ldapClient.authenticateUser(username, password);
        
        if (authenticated) {
            const userInfo = await ldapClient.getUserInfo(username);
            const groups = await ldapClient.getUserGroups(username);
            
            res.send(`Welcome, ${userInfo.cn}! Groups: ${groups.join(', ')}`);
        } else {
            res.send('Authentication failed');
        }
    } catch (error) {
        res.status(500).send(`Error: ${error.message}`);
    } finally {
        ldapClient.close();
    }
});

app.listen(3000, () => {
    console.log('Server running on port 3000');
});
```

## Testing Integration

### Test Scripts

#### OIDC Flow Test
```bash
#!/bin/bash
# test_oidc_integration.sh

echo "Testing OIDC Integration..."

# Test OIDC provider accessibility
echo "1. Testing OIDC provider accessibility..."
if curl -f -s https://id.example.org/.well-known/openid_configuration > /dev/null; then
    echo "✅ OIDC provider is accessible"
else
    echo "❌ OIDC provider is not accessible"
    exit 1
fi

# Test client configuration
echo "2. Testing client configuration..."
if curl -f -s -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=test-client&client_secret=test-secret" > /dev/null; then
    echo "✅ Client configuration is working"
else
    echo "❌ Client configuration failed"
fi

# Test user authentication
echo "3. Testing user authentication..."
USER_TOKEN=$(curl -s -X POST https://id.example.org/token \
    -d "grant_type=password&username=admin@example.com&password=admin123&client_id=test-client&client_secret=test-secret" \
    | jq -r '.access_token')

if [ "$USER_TOKEN" != "null" ] && [ "$USER_TOKEN" != "" ]; then
    echo "✅ User authentication is working"
    
    # Test user info endpoint
    USER_INFO=$(curl -s -H "Authorization: Bearer $USER_TOKEN" \
        https://id.example.org/userinfo | jq -r '.name')
    
    if [ "$USER_INFO" != "null" ] && [ "$USER_INFO" != "" ]; then
        echo "✅ User info endpoint is working"
    else
        echo "❌ User info endpoint failed"
    fi
else
    echo "❌ User authentication failed"
fi

echo "OIDC integration test completed!"
```

#### LDAP Integration Test
```bash
#!/bin/bash
# test_ldap_integration.sh

echo "Testing LDAP Integration..."

# Test LDAP connectivity
echo "1. Testing LDAP connectivity..."
if ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s base > /dev/null 2>&1; then
    echo "✅ LDAP connectivity is working"
else
    echo "❌ LDAP connectivity failed"
    exit 1
fi

# Test user search
echo "2. Testing user search..."
USER_COUNT=$(ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s sub "(objectClass=inetOrgPerson)" | grep -c "^dn:")

if [ "$USER_COUNT" -gt 0 ]; then
    echo "✅ User search is working (found $USER_COUNT users)"
else
    echo "❌ User search failed"
fi

# Test group search
echo "3. Testing group search..."
GROUP_COUNT=$(ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s sub "(objectClass=groupOfNames)" | grep -c "^dn:")

if [ "$GROUP_COUNT" -gt 0 ]; then
    echo "✅ Group search is working (found $GROUP_COUNT groups)"
else
    echo "❌ Group search failed"
fi

echo "LDAP integration test completed!"
```

## Troubleshooting

### Common Issues

#### OIDC Configuration Issues
```bash
# Check OIDC provider configuration
curl -v https://id.example.org/.well-known/openid_configuration

# Verify client registration
curl -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=your-client-id&client_secret=your-client-secret"
```

#### LDAP Configuration Issues
```bash
# Test LDAP connection
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s base

# Check LDAP schema
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "cn=schema,cn=configuration,dc=example,dc=com" \
    -s sub "(objectClass=attributeSchema)"
```

#### Network Connectivity Issues
```bash
# Test network connectivity
ping ldap.example.com
telnet ldap.example.com 636
telnet id.example.org 443

# Check DNS resolution
nslookup ldap.example.com
nslookup id.example.org
```

## Best Practices

### Security Considerations
1. **Use HTTPS**: Always use HTTPS for OIDC and LDAP connections
2. **Strong Passwords**: Use strong passwords for LDAP bind DN
3. **Certificate Validation**: Enable certificate validation for LDAPS
4. **Access Control**: Implement proper access controls in your application

### Performance Optimization
1. **Connection Pooling**: Configure LDAP connection pooling
2. **Caching**: Implement caching for user and group data
3. **Error Handling**: Implement proper error handling and retry logic
4. **Resource Management**: Properly close connections and clean up resources

### Maintenance
1. **Regular Updates**: Keep your application and dependencies updated
2. **Log Monitoring**: Monitor logs for authentication issues
3. **User Management**: Regular review of user accounts and groups
4. **Backup**: Regular backup of your application configuration and data

## Support

For custom application integration support:
- **OIDC Documentation**: [OpenID Connect](https://openid.net/connect/)
- **LDAP Documentation**: [LDAP RFC](https://tools.ietf.org/html/rfc4511)
- **Community Support**: [Stack Overflow](https://stackoverflow.com/)
- **Professional Support**: [Consult with your development team](mailto:dev@yourcompany.com)
