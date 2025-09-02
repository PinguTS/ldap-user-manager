<?php
/**
 * Joomla Configuration with OIDC Integration
 */

class JConfig {
    // Database Configuration
    public $dbtype = 'mysqli';
    public $host = 'localhost';
    public $user = 'joomla';
    public $password = 'your-db-password';
    public $db = 'joomla';
    public $dbprefix = 'jos_';
    
    // Site Configuration
    public $live_site = 'https://joomla.example.org';
    public $secret = 'your-secret-here';
    public $gzip = false;
    public $error_reporting = 'default';
    public $helpurl = 'https://help.joomla.org/proxy?keyref=Help{major}{minor}:{keyref}&lang={langcode}';
    public $ftp_host = '';
    public $ftp_port = '';
    public $ftp_user = '';
    public $ftp_pass = '';
    public $ftp_root = '';
    public $ftp_enable = false;
    public $offset = 'UTC';
    public $mailonline = true;
    public $mailer = 'mail';
    public $mailfrom = 'admin@joomla.example.org';
    public $fromname = 'Joomla';
    public $sendmail = '/usr/sbin/sendmail';
    public $smtpauth = false;
    public $smtpuser = '';
    public $smtppass = '';
    public $smtphost = 'localhost';
    public $smtpsecure = 'none';
    public $smtpport = 25;
    public $caching = 0;
    public $cache_handler = 'file';
    public $cachetime = 15;
    public $cache_platformprefix = false;
    public $MetaDesc = 'Joomla site with OIDC integration';
    public $MetaKeys = '';
    public $MetaTitle = 1;
    public $MetaAuthor = 1;
    public $MetaVersion = 0;
    public $robots = '';
    public $sef = 1;
    public $sef_rewrite = 0;
    public $sef_suffix = 0;
    public $unicodeslugs = 0;
    public $feed_limit = 10;
    public $feed_email = 'none';
    public $log_path = '/var/www/html/administrator/logs';
    public $tmp_path = '/var/www/html/tmp';
    public $lifetime = 15;
    public $session_handler = 'database';
    public $shared_session = false;
    public $session_metadata = true;
    
    // OIDC Configuration
    public $oidc_client_id = 'joomla';
    public $oidc_client_secret = 'your-joomla-client-secret-here';
    public $oidc_issuer_url = 'https://id.example.org';
    public $oidc_redirect_uri = 'https://joomla.example.org/index.php?option=com_ajax&plugin=oidc&format=raw';
    public $oidc_scopes = 'openid profile email groups';
    
    // LDAP Configuration (optional)
    public $ldap_host = 'ldap.example.com';
    public $ldap_port = 636;
    public $ldap_base_dn = 'dc=example,dc=com';
    public $ldap_bind_dn = 'cn=admin,dc=example,dc=com';
    public $ldap_bind_password = 'admin123';
}
