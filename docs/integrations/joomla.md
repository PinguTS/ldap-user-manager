# Joomla Integration

This guide provides detailed integration instructions for connecting Joomla with LDAP User Manager.

## Overview

Joomla can integrate with LDAP User Manager using:
- **OIDC Authentication Plugin**: Modern OIDC-based integration
- **LDAP Authentication**: Traditional LDAP-based integration

## Joomla OIDC Plugin

### Installation

#### Via Joomla Admin
1. Go to **Extensions** → **Manage** → **Install**
2. Upload the OIDC plugin package
3. Go to **Extensions** → **Plugins**
4. Search for "OIDC Authentication"
5. Click **Enable**

#### Manual Installation
```bash
# Download and install plugin
cd /var/www/joomla/plugins/authentication
wget https://github.com/joomla/joomla-cms/releases/download/4.0.0/plg_authentication_oidc.zip
unzip plg_authentication_oidc.zip
chown -R www-data:www-data oidc
```

### Configuration

#### Plugin Configuration
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

#### Plugin Implementation
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
        $app = JFactory::getApplication();
        
        // Check if OIDC callback
        if ($app->input->get('plugin') === 'oidc') {
            $this->handleOidcCallback($response);
            return;
        }
        
        // Check if OIDC login
        if ($app->input->get('auth') === 'oidc') {
            $this->redirectToOidc();
            return;
        }
    }
    
    private function handleOidcCallback(&$response)
    {
        $code = $this->app->input->get('code');
        
        if (!$code) {
            $response->status = Authentication::STATUS_FAILURE;
            return;
        }
        
        // Exchange code for token
        $token = $this->exchangeCodeForToken($code);
        
        if (!$token) {
            $response->status = Authentication::STATUS_FAILURE;
            return;
        }
        
        // Get user info
        $userInfo = $this->getUserInfo($token);
        
        if (!$userInfo) {
            $response->status = Authentication::STATUS_FAILURE;
            return;
        }
        
        // Create or update user
        $user = $this->createOrUpdateUser($userInfo);
        
        if ($user) {
            $response->status = Authentication::STATUS_SUCCESS;
            $response->username = $user->username;
            $response->email = $user->email;
            $response->fullname = $user->name;
        } else {
            $response->status = Authentication::STATUS_FAILURE;
        }
    }
    
    private function exchangeCodeForToken($code)
    {
        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->params->get('client_id'),
            'client_secret' => $this->params->get('client_secret'),
            'code' => $code,
            'redirect_uri' => $this->params->get('redirect_uri')
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->params->get('issuer_url') . '/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        return $data['access_token'] ?? null;
    }
    
    private function getUserInfo($token)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->params->get('issuer_url') . '/userinfo');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
    
    private function createOrUpdateUser($userInfo)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        
        $username = $userInfo[$this->params->get('username_field')];
        $email = $userInfo[$this->params->get('email_field')];
        $name = $userInfo[$this->params->get('name_field')];
        
        // Check if user exists
        $query->select('id')
              ->from('#__users')
              ->where('username = ' . $db->quote($username));
        
        $db->setQuery($query);
        $userId = $db->loadResult();
        
        if ($userId) {
            // Update existing user
            $user = JFactory::getUser($userId);
            $user->email = $email;
            $user->name = $name;
            $user->save();
        } else {
            // Create new user
            $user = JFactory::getUser();
            $user->username = $username;
            $user->email = $email;
            $user->name = $name;
            $user->password = JUserHelper::hashPassword(uniqid());
            $user->groups = [2]; // Registered users group
            $user->save();
        }
        
        return $user;
    }
}
```

### Testing the Integration

#### Test OIDC Connection
```bash
# Test OIDC provider accessibility
curl -f -s https://id.example.org/.well-known/openid_configuration

# Test client configuration
curl -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=joomla&client_secret=your-joomla-client-secret-here"
```

#### Test User Authentication
1. Go to your Joomla site
2. Click the OIDC login button
3. Complete authentication on the OIDC provider
4. Verify user is logged into Joomla

## Joomla LDAP Integration

### Installation

#### Via Joomla Admin
1. Go to **Extensions** → **Manage** → **Install**
2. Upload the LDAP plugin package
3. Go to **Extensions** → **Plugins**
4. Search for "LDAP Authentication"
5. Click **Enable**

#### Manual Installation
```bash
# Download and install plugin
cd /var/www/joomla/plugins/authentication
wget https://github.com/joomla/joomla-cms/releases/download/4.0.0/plg_authentication_ldap.zip
unzip plg_authentication_ldap.zip
chown -R www-data:www-data ldap
```

### Configuration

#### LDAP Configuration
```xml
<!-- plugins/authentication/ldap/ldap.xml -->
<config>
    <fieldset name="basic">
        <field name="host" type="text" 
               label="LDAP Host" 
               description="LDAP server hostname"
               default="ldap.example.com" />
        
        <field name="port" type="text" 
               label="LDAP Port" 
               description="LDAP server port"
               default="636" />
        
        <field name="encryption" type="list" 
               label="Encryption" 
               description="LDAP encryption method"
               default="ssl">
            <option value="none">None</option>
            <option value="ssl">SSL</option>
            <option value="tls">TLS</option>
        </field>
        
        <field name="base_dn" type="text" 
               label="Base DN" 
               description="LDAP base distinguished name"
               default="dc=example,dc=com" />
        
        <field name="bind_dn" type="text" 
               label="Bind DN" 
               description="LDAP bind distinguished name"
               default="cn=admin,dc=example,dc=com" />
        
        <field name="bind_password" type="password" 
               label="Bind Password" 
               description="LDAP bind password"
               default="" />
    </fieldset>
    
    <fieldset name="user_mapping">
        <field name="user_filter" type="text" 
               label="User Filter" 
               description="LDAP user search filter"
               default="(objectClass=inetOrgPerson)" />
        
        <field name="user_base" type="text" 
               label="User Base" 
               description="LDAP user search base"
               default="ou=people,dc=example,dc=com" />
        
        <field name="username_field" type="text" 
               label="Username Field" 
               default="uid" />
        
        <field name="email_field" type="text" 
               label="Email Field" 
               default="mail" />
        
        <field name="name_field" type="text" 
               label="Name Field" 
               default="cn" />
    </fieldset>
</config>
```

#### Plugin Implementation
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
        $username = $credentials['username'];
        $password = $credentials['password'];
        
        // Connect to LDAP
        $ldap_conn = $this->connectToLdap();
        
        if (!$ldap_conn) {
            $response->status = Authentication::STATUS_FAILURE;
            return;
        }
        
        // Search for user
        $user_dn = $this->findUser($ldap_conn, $username);
        
        if (!$user_dn) {
            $response->status = Authentication::STATUS_FAILURE;
            return;
        }
        
        // Authenticate user
        if ($this->authenticateUser($ldap_conn, $user_dn, $password)) {
            // Get user info
            $user_info = $this->getUserInfo($ldap_conn, $user_dn);
            
            // Create or update user
            $user = $this->createOrUpdateUser($user_info);
            
            if ($user) {
                $response->status = Authentication::STATUS_SUCCESS;
                $response->username = $user->username;
                $response->email = $user->email;
                $response->fullname = $user->name;
            } else {
                $response->status = Authentication::STATUS_FAILURE;
            }
        } else {
            $response->status = Authentication::STATUS_FAILURE;
        }
        
        ldap_close($ldap_conn);
    }
    
    private function connectToLdap()
    {
        $host = $this->params->get('host');
        $port = $this->params->get('port');
        $encryption = $this->params->get('encryption');
        
        $ldap_uri = $host . ':' . $port;
        
        if ($encryption === 'ssl') {
            $ldap_uri = 'ldaps://' . $ldap_uri;
        } else {
            $ldap_uri = 'ldap://' . $ldap_uri;
        }
        
        $ldap_conn = ldap_connect($ldap_uri);
        
        if (!$ldap_conn) {
            return false;
        }
        
        ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap_conn, LDAP_OPT_REFERRALS, 0);
        
        // Bind with admin credentials
        $bind_dn = $this->params->get('bind_dn');
        $bind_password = $this->params->get('bind_password');
        
        if (!ldap_bind($ldap_conn, $bind_dn, $bind_password)) {
            return false;
        }
        
        return $ldap_conn;
    }
    
    private function findUser($ldap_conn, $username)
    {
        $user_base = $this->params->get('user_base');
        $user_filter = $this->params->get('user_filter');
        $username_field = $this->params->get('username_field');
        
        $filter = "(&$user_filter($username_field=$username))";
        
        $result = ldap_search($ldap_conn, $user_base, $filter);
        $entries = ldap_get_entries($ldap_conn, $result);
        
        if ($entries['count'] > 0) {
            return $entries[0]['dn'];
        }
        
        return false;
    }
    
    private function authenticateUser($ldap_conn, $user_dn, $password)
    {
        return ldap_bind($ldap_conn, $user_dn, $password);
    }
    
    private function getUserInfo($ldap_conn, $user_dn)
    {
        $result = ldap_read($ldap_conn, $user_dn, '(objectClass=*)');
        $entries = ldap_get_entries($ldap_conn, $result);
        
        if ($entries['count'] > 0) {
            return $entries[0];
        }
        
        return false;
    }
    
    private function createOrUpdateUser($user_info)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        
        $username = $user_info[$this->params->get('username_field')][0];
        $email = $user_info[$this->params->get('email_field')][0];
        $name = $user_info[$this->params->get('name_field')][0];
        
        // Check if user exists
        $query->select('id')
              ->from('#__users')
              ->where('username = ' . $db->quote($username));
        
        $db->setQuery($query);
        $userId = $db->loadResult();
        
        if ($userId) {
            // Update existing user
            $user = JFactory::getUser($userId);
            $user->email = $email;
            $user->name = $name;
            $user->save();
        } else {
            // Create new user
            $user = JFactory::getUser();
            $user->username = $username;
            $user->email = $email;
            $user->name = $name;
            $user->password = JUserHelper::hashPassword(uniqid());
            $user->groups = [2]; // Registered users group
            $user->save();
        }
        
        return $user;
    }
}
```

### Testing the Integration

#### Test LDAP Connection
```bash
# Test LDAP connectivity
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s base

# Test user search
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "ou=people,dc=example,dc=com" \
    -s sub "(objectClass=inetOrgPerson)"
```

#### Test User Authentication
1. Go to your Joomla site
2. Use LDAP credentials to log in
3. Verify user is authenticated
4. Check group membership

## User Provisioning

### Automatic User Creation
```php
<?php
// plugins/authentication/ldap/ldap.php

// Enable automatic user creation
protected $autoCreateUsers = true;

// Custom user creation hook
protected function onUserCreated($user_id, $user_data)
{
    // Set user meta
    $user = JFactory::getUser($user_id);
    $user->setParam('ldap_uid', $user_data['uid']);
    $user->setParam('ldap_dn', $user_data['dn']);
    $user->save();
    
    // Set user groups based on LDAP groups
    $groups = $this->getUserGroups($user_data);
    $user->set('groups', $groups);
    $user->save();
}

protected function getUserGroups($user_data)
{
    $group_mapping = [
        'administrators' => 8,  // Super Users
        'maintainers' => 7,      // Manager
        'org_admin' => 6,        // Administrator
        'user' => 2              // Registered
    ];
    
    $groups = [2]; // Default to Registered
    
    if (isset($user_data['memberOf'])) {
        foreach ($user_data['memberOf'] as $group) {
            if (isset($group_mapping[$group])) {
                $groups[] = $group_mapping[$group];
            }
        }
    }
    
    return array_unique($groups);
}
```

### User Synchronization
```php
<?php
// plugins/authentication/ldap/ldap.php

// Sync user data on login
protected function onUserLogin($user)
{
    if ($this->isLdapUser($user)) {
        $this->syncUserData($user);
    }
}

protected function isLdapUser($user)
{
    return $user->getParam('ldap_uid');
}

protected function syncUserData($user)
{
    $ldap_uid = $user->getParam('ldap_uid');
    $ldap_data = $this->getLdapUserData($ldap_uid);
    
    if ($ldap_data) {
        // Update user data
        $user->email = $ldap_data[$this->params->get('email_field')][0];
        $user->name = $ldap_data[$this->params->get('name_field')][0];
        $user->save();
        
        // Update last sync time
        $user->setParam('ldap_last_sync', JFactory::getDate()->toSql());
        $user->save();
    }
}

protected function getLdapUserData($uid)
{
    $ldap_conn = $this->connectToLdap();
    
    if (!$ldap_conn) {
        return false;
    }
    
    $user_base = $this->params->get('user_base');
    $username_field = $this->params->get('username_field');
    $filter = "($username_field=$uid)";
    
    $result = ldap_search($ldap_conn, $user_base, $filter);
    $entries = ldap_get_entries($ldap_conn, $result);
    
    ldap_close($ldap_conn);
    
    if ($entries['count'] > 0) {
        return $entries[0];
    }
    
    return false;
}
```

## Group Management

### LDAP Group Synchronization
```php
<?php
// plugins/authentication/ldap/ldap.php

// Sync LDAP groups to Joomla user groups
public function onAfterInitialise()
{
    $app = JFactory::getApplication();
    
    if ($app->isAdmin() && $app->input->get('option') === 'com_ajax' && 
        $app->input->get('plugin') === 'ldap' && 
        $app->input->get('group') === 'authentication' &&
        $app->input->get('format') === 'raw') {
        
        $this->syncLdapGroups();
    }
}

protected function syncLdapGroups()
{
    $ldap_groups = $this->getLdapGroups();
    
    foreach ($ldap_groups as $group) {
        $this->syncLdapGroup($group);
    }
    
    echo json_encode(['success' => true, 'message' => 'Groups synchronized']);
    $app = JFactory::getApplication();
    $app->close();
}

protected function getLdapGroups()
{
    $ldap_conn = $this->connectToLdap();
    
    if (!$ldap_conn) {
        return [];
    }
    
    $group_base = 'ou=roles,dc=example,dc=com';
    $group_filter = '(objectClass=groupOfNames)';
    
    $result = ldap_search($ldap_conn, $group_base, $group_filter);
    $entries = ldap_get_entries($ldap_conn, $result);
    
    ldap_close($ldap_conn);
    
    return $entries;
}

protected function syncLdapGroup($group)
{
    $group_name = $group['cn'][0];
    $members = $group['member'];
    
    // Create Joomla user group if it doesn't exist
    $joomla_group_id = $this->getOrCreateUserGroup($group_name);
    
    // Assign users to group
    foreach ($members as $member) {
        $uid = $this->extractUidFromDn($member);
        $user = JFactory::getUser($uid);
        
        if ($user->id) {
            $this->addUserToGroup($user->id, $joomla_group_id);
        }
    }
}

protected function getOrCreateUserGroup($group_name)
{
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    
    $query->select('id')
          ->from('#__usergroups')
          ->where('title = ' . $db->quote($group_name));
    
    $db->setQuery($query);
    $group_id = $db->loadResult();
    
    if (!$group_id) {
        // Create new user group
        $group = new stdClass();
        $group->title = $group_name;
        $group->parent_id = 1; // Root group
        
        $db->insertObject('#__usergroups', $group);
        $group_id = $db->insertid();
    }
    
    return $group_id;
}

protected function addUserToGroup($user_id, $group_id)
{
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    
    // Check if user is already in group
    $query->select('user_id')
          ->from('#__user_usergroup_map')
          ->where('user_id = ' . (int)$user_id)
          ->where('group_id = ' . (int)$group_id);
    
    $db->setQuery($query);
    $exists = $db->loadResult();
    
    if (!$exists) {
        $map = new stdClass();
        $map->user_id = $user_id;
        $map->group_id = $group_id;
        
        $db->insertObject('#__user_usergroup_map', $map);
    }
}

protected function extractUidFromDn($dn)
{
    preg_match('/uid=([^,]+)/', $dn, $matches);
    return $matches[1] ?? null;
}
```

## Troubleshooting

### Common Issues

#### OIDC Configuration Issues
```bash
# Check OIDC provider configuration
curl -v https://id.example.org/.well-known/openid_configuration

# Verify client registration
curl -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=joomla&client_secret=your-joomla-client-secret-here"
```

#### LDAP Configuration Issues
```bash
# Test LDAP connection
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "dc=example,dc=com" \
    -s base

# Check user search
ldapsearch -H ldaps://ldap.example.com:636 \
    -D "cn=admin,dc=example,dc=com" \
    -w admin123 \
    -b "ou=people,dc=example,dc=com" \
    -s sub "(uid=testuser)"
```

#### Joomla Configuration Issues
```bash
# Check Joomla logs
tail -f /var/log/joomla/error.php

# Check Joomla debug log
tail -f /var/log/joomla/debug.php

# Check plugin status
php /var/www/joomla/cli/joomla.php plugin:list --status=enabled
```

### Debug Configuration

#### Enable Debug Logging
```php
// configuration.php

// Enable Joomla debug
public $debug = true;
public $debug_lang = true;
public $log_path = '/var/log/joomla';
public $log_priorities = ['ERROR', 'WARNING', 'INFO', 'DEBUG'];
```

#### Debug Log Location
```bash
# Joomla debug logs
tail -f /var/log/joomla/debug.php

# Plugin specific logs
tail -f /var/log/joomla/ldap.log
tail -f /var/log/joomla/oidc.log
```

## Best Practices

### Security Considerations
1. **Use HTTPS**: Always use HTTPS for OIDC and LDAP connections
2. **Strong Passwords**: Use strong passwords for LDAP bind DN
3. **Certificate Validation**: Enable certificate validation for LDAPS
4. **Access Control**: Implement proper access controls in Joomla

### Performance Optimization
1. **Connection Pooling**: Configure LDAP connection pooling
2. **Caching**: Enable Joomla caching for better performance
3. **Group Mapping**: Cache group mappings to reduce LDAP queries
4. **User Sessions**: Configure appropriate session timeouts

### Maintenance
1. **Regular Updates**: Keep Joomla and plugins updated
2. **Log Monitoring**: Monitor logs for authentication issues
3. **User Management**: Regular review of user accounts and groups
4. **Backup**: Regular backup of Joomla configuration and data

## Support

For Joomla integration support:
- **Joomla Documentation**: [Joomla Documentation](https://docs.joomla.org/)
- **Plugin Documentation**: [Joomla Extensions](https://extensions.joomla.org/)
- **Community Support**: [Joomla Community](https://community.joomla.org/)
- **Professional Support**: [Joomla Services](https://www.joomla.org/services.html)
