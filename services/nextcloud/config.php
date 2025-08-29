<?php
/**
 * Nextcloud Configuration with OIDC Integration
 * This file shows the configuration structure for Nextcloud with OIDC Login app
 */

$CONFIG = array(
  // Basic Nextcloud Configuration
  'instanceid' => 'nextcloud',
  'passwordsalt' => 'your-password-salt-here',
  'secret' => 'your-secret-here',
  'trusted_domains' => array(
    0 => 'nextcloud.example.org',
  ),
  'datadirectory' => '/var/www/html/data',
  'dbtype' => 'mysql',
  'version' => '25.0.0.0',
  'overwrite.cli.url' => 'https://nextcloud.example.org',
  
  // Database Configuration (adjust for your environment)
  'dbhost' => 'localhost:3306',
  'dbname' => 'nextcloud',
  'dbuser' => 'nextcloud',
  'dbpassword' => 'your-db-password',
  
  // Redis Configuration (optional, for caching)
  'redis' => array(
    'host' => 'localhost',
    'port' => 6379,
  ),
  
  // OIDC Configuration
  'oidc_login_provider_url' => 'https://id.example.org',
  'oidc_login_client_id' => 'nextcloud',
  'oidc_login_client_secret' => 'your-nextcloud-client-secret-here',
  'oidc_login_redirect_url' => 'https://nextcloud.example.org/index.php/apps/oidc_login/oidc',
  'oidc_login_scope' => 'openid profile email groups',
  'oidc_login_auto_provision' => true,
  'oidc_login_use_email_as_uid' => true,
  'oidc_login_disable_registration' => true,
  'oidc_login_group_mapping' => true,
  'oidc_login_default_groups' => 'users',
  
  // LDAP Configuration (optional fallback)
  'ldap_host' => 'ldap-server',
  'ldap_port' => 636,
  'ldap_base' => 'dc=example,dc=com',
  'ldap_dn' => 'cn=admin,dc=example,dc=com',
  'ldap_password' => 'admin123',
  'ldap_login_filter' => '(&(objectClass=inetOrgPerson)(uid=%uid))',
  'ldap_userlist_filter' => '(objectClass=inetOrgPerson)',
  'ldap_attributes' => array(
    'uid' => 'uid',
    'mail' => 'mail',
    'cn' => 'cn',
    'givenName' => 'givenName',
    'sn' => 'sn',
  ),
  
  // Security Settings
  'updatechecker' => true,
  'updater.release.channel' => 'stable',
  'htaccess.RewriteBase' => '/',
  'memcache.local' => '\\OC\\Memcache\\APCu',
  'memcache.distributed' => '\\OC\\Memcache\\Redis',
  'memcache.locking' => '\\OC\\Memcache\\Redis',
  
  // Logging
  'log_type' => 'file',
  'log_level' => 2,
  'logfile' => '/var/log/nextcloud/nextcloud.log',
  
  // Maintenance Mode
  'maintenance' => false,
  'maintenance_window_start' => 1,
);
