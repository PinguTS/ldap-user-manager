# Joomla OIDC Configuration

This guide explains how to configure Joomla on an external server to authenticate against your local Dex OIDC provider.

## Prerequisites

- Joomla installed and running
- Access to Joomla admin panel and file system
- Plugin installation capabilities
- Dex OIDC provider running at `https://id.example.org`

## Installation

### 1. Install Required Plugins

#### Option A: Via Joomla Admin
1. Go to **System** → **Manage** → **Plugins**
2. Click **Install** → **Upload Package File**
3. Upload the OIDC authentication plugin
4. Enable the plugin

#### Option B: Manual Installation
```bash
# Download plugin
wget https://github.com/joomla-plugins/authentication-oidc/archive/main.zip
unzip main.zip -d /var/www/html/plugins/authentication/
chown -R www-data:www-data /var/www/html/plugins/authentication/oidc/
```

### 2. Install LDAP Plugin (Optional)
```bash
# Install LDAP authentication plugin
wget https://github.com/joomla-plugins/authentication-ldap/archive/main.zip
unzip main.zip -d /var/www/html/plugins/authentication/
chown -R www-data:www-data /var/www/html/plugins/authentication/ldap/
```

## Configuration

### OIDC Plugin Configuration

#### Plugin XML Configuration
```xml
<!-- plugins/authentication/oidc/oidc.xml -->
<config>
    <fieldset name="basic">
        <field name="client_id" type="text" 
               label="Client ID" 
               description="OIDC Client ID"
               default="joomla" />
        
        <field name="client_secret" type="password" 
               label="Client Secret" 
               description="OIDC Client Secret"
               default="" />
        
        <field name="issuer_url" type="url" 
               label="Issuer URL" 
               description="OIDC Issuer URL"
               default="https://id.example.org" />
        
        <field name="redirect_uri" type="url" 
               label="Redirect URI" 
               description="OIDC Redirect URI"
               default="https://joomla.example.org/index.php?option=com_ajax&plugin=oidc&format=raw" />
        
        <field name="scopes" type="text" 
               label="Scopes" 
               description="OIDC Scopes"
               default="openid profile email groups" />
    </fieldset>
    
    <fieldset name="user_mapping">
        <field name="username_field" type="text" 
               label="Username Field" 
               default="preferred_username" />
        
        <field name="email_field" type="text" 
               label="Email Field" 
               default="email" />
        
        <field name="name_field" type="text" 
               label="Name Field" 
               default="name" />
        
        <field name="groups_field" type="text" 
               label="Groups Field" 
               default="groups" />
    </fieldset>
</config>
```

#### PHP Implementation
```php
<?php
// plugins/authentication/oidc/oidc.php

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Authentication\Authentication;

class PlgAuthenticationOidc extends CMSPlugin
{
    protected $autoloadLanguage = true;
    
    public function onUserAuthenticate($credentials, $options, &$response)
    {
        // OIDC Configuration
        $clientId = $this->params->get('client_id', 'joomla');
        $clientSecret = $this->params->get('client_secret', '');
        $issuerUrl = $this->params->get('issuer_url', 'https://id.example.org');
        $redirectUri = $this->params->get('redirect_uri', '');
        $scopes = $this->params->get('scopes', 'openid profile email groups');
        
        // User mapping
        $usernameField = $this->params->get('username_field', 'preferred_username');
        $emailField = $this->params->get('email_field', 'email');
        $nameField = $this->params->get('name_field', 'name');
        $groupsField = $this->params->get('groups_field', 'groups');
        
        // OIDC authentication logic
        $oidc = new OidcClient($issuerUrl, $clientId, $clientSecret);
        
        try {
            $userInfo = $oidc->authenticate($credentials['username'], $credentials['password']);
            
            if ($userInfo) {
                $response->type = 'OIDC';
                $response->status = Authentication::SUCCESS;
                $response->username = $userInfo[$usernameField];
                $response->email = $userInfo[$emailField];
                $response->fullname = $userInfo[$nameField];
                $response->groups = $userInfo[$groupsField] ?? [];
            }
        } catch (Exception $e) {
            $response->status = Authentication::FAILURE;
            $response->error_message = $e->getMessage();
        }
    }
}

class OidcClient
{
    private $issuerUrl;
    private $clientId;
    private $clientSecret;
    
    public function __construct($issuerUrl, $clientId, $clientSecret)
    {
        $this->issuerUrl = $issuerUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }
    
    public function authenticate($username, $password)
    {
        // OIDC authentication implementation
        $tokenResponse = $this->getToken($username, $password);
        
        if ($tokenResponse && isset($tokenResponse['access_token'])) {
            return $this->getUserInfo($tokenResponse['access_token']);
        }
        
        return false;
    }
    
    private function getToken($username, $password)
    {
        $data = [
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'openid profile email groups'
        ];
        
        $response = $this->makeRequest('POST', '/token', $data);
        return json_decode($response, true);
    }
    
    private function getUserInfo($accessToken)
    {
        $headers = ['Authorization: Bearer ' . $accessToken];
        $response = $this->makeRequest('GET', '/userinfo', [], $headers);
        return json_decode($response, true);
    }
    
    private function makeRequest($method, $endpoint, $data = [], $headers = [])
    {
        $ch = curl_init();
        $url = $this->issuerUrl . $endpoint;
        
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
}
```

### LDAP Plugin Configuration (Optional)

#### LDAP Settings
```php
<?php
// plugins/authentication/ldap/ldap.php

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Authentication\Authentication;

class PlgAuthenticationLdap extends CMSPlugin
{
    protected $autoloadLanguage = true;
    
    public function onUserAuthenticate($credentials, $options, &$response)
    {
        // LDAP Configuration
        $ldapHost = $this->params->get('ldap_host', 'ldap.example.com');
        $ldapPort = $this->params->get('ldap_port', 636);
        $ldapBaseDn = $this->params->get('ldap_base_dn', 'dc=example,dc=com');
        $ldapBindDn = $this->params->get('ldap_bind_dn', 'cn=admin,dc=example,dc=com');
        $ldapBindPassword = $this->params->get('ldap_bind_password', 'admin123');
        
        // LDAP authentication logic
        $ldap = new LdapClient($ldapHost, $ldapPort, $ldapBaseDn, $ldapBindDn, $ldapBindPassword);
        
        try {
            $userInfo = $ldap->authenticate($credentials['username'], $credentials['password']);
            
            if ($userInfo) {
                $response->type = 'LDAP';
                $response->status = Authentication::SUCCESS;
                $response->username = $userInfo['uid'];
                $response->email = $userInfo['mail'];
                $response->fullname = $userInfo['cn'];
                $response->groups = $userInfo['memberOf'] ?? [];
            }
        } catch (Exception $e) {
            $response->status = Authentication::FAILURE;
            $response->error_message = $e->getMessage();
        }
    }
}
```

## Testing

### 1. Verify Configuration
- Check Joomla admin panel for OIDC plugin settings
- Verify OIDC provider URL is accessible
- Ensure client secret matches Dex configuration

### 2. Test OIDC Flow
1. Visit Joomla login page
2. Click "Login with OpenID Connect" button
3. Should redirect to Dex at `https://id.example.org/auth`
4. Login with LDAP credentials
5. Should redirect back to Joomla
6. User should be logged in with proper attributes

## Troubleshooting

### Common Issues

**Error**: "OIDC plugin not found"
- **Solution**: Verify plugin is installed and enabled
- **Check**: Plugin appears in Joomla admin panel

**Error**: "Invalid OIDC configuration"
- **Solution**: Verify OIDC provider URL and client credentials
- **Check**: Network connectivity to Dex provider

**Error**: "User not created" after OIDC login
- **Solution**: Check auto-provisioning settings
- **Check**: User attribute mapping configuration

### Debug Steps

1. Check Joomla logs for OIDC-related errors
2. Verify OIDC plugin configuration in admin panel
3. Test OIDC discovery: `curl -k https://id.example.org/.well-known/openid_configuration`
4. Check user creation in Joomla admin panel

## Security Considerations

- **Client Secret**: Store securely, never commit to version control
- **HTTPS Only**: Ensure Joomla runs over HTTPS
- **User Permissions**: Configure appropriate user group assignments
- **Plugin Updates**: Keep OIDC plugin updated

## Support

- **Joomla OIDC Plugin**: https://extensions.joomla.org/extension/authentication/oidc/
- **Joomla Documentation**: https://docs.joomla.org/
- **Main Documentation**: [../../docs/identity.md](../../docs/identity.md)
- **OIDC Quick Reference**: [../../docs/oidc-quick-reference.md](../../docs/oidc-quick-reference.md)
