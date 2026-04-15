<?php
/**
 * Joomla configuration.php — OIDC configuration snippet
 * Add these properties to your existing JConfig class.
 * See docs/integrations/joomla.md for the full integration guide.
 */

class JConfig {
    public $oidc_provider_url     = 'https://id.example.org';
    public $oidc_client_id        = 'joomla';
    public $oidc_client_secret    = 'your-joomla-client-secret-here';
    public $oidc_scope            = 'openid profile email groups';
    public $oidc_redirect_uri     = 'https://joomla.example.org/index.php?option=com_users&task=user.oidccallback';
    public $oidc_enable_auto_user = true;
    public $oidc_default_group    = 2;
    public $oidc_group_claim      = 'groups';
}
