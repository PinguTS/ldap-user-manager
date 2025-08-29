# GitLab Configuration for OIDC Integration
# This file shows the configuration structure for GitLab with OmniAuth OIDC

# External URL
external_url 'https://gitlab.example.org'

# GitLab Shell SSH port
gitlab_rails['gitlab_shell_ssh_port'] = 2224

# Database Configuration (adjust for your environment)
gitlab_rails['db_adapter'] = 'postgresql'
gitlab_rails['db_host'] = 'localhost'
gitlab_rails['db_port'] = 5432
gitlab_rails['db_database'] = 'gitlabhq_production'
gitlab_rails['db_username'] = 'gitlab'
gitlab_rails['db_password'] = 'your-db-password'

# Redis Configuration (adjust for your environment)
gitlab_rails['redis_host'] = 'localhost'
gitlab_rails['redis_port'] = 6379

# OIDC Configuration
gitlab_rails['omniauth_enabled'] = true
gitlab_rails['omniauth_allow_single_sign_on'] = ['openid_connect']
gitlab_rails['omniauth_block_auto_created_users'] = false
gitlab_rails['omniauth_auto_link_ldap_user'] = false

gitlab_rails['omniauth_providers'] = [
  {
    'name' => 'openid_connect',
    'label' => 'OpenID Connect',
    'args' => {
      'name' => 'openid_connect',
      'strategy_class' => 'OmniAuth::Strategies::OpenIDConnect',
      'issuer' => 'https://id.example.org',
      'client_id' => 'gitlab',
      'client_secret' => 'your-gitlab-client-secret-here',
      'discovery' => true,
      'scope' => 'openid profile email',
      'response_type' => 'code',
      'response_mode' => 'query',
      'uid_field' => 'sub',
      'client_options' => {
        'identifier' => 'gitlab',
        'secret' => 'your-gitlab-client-secret-here',
        'redirect_uri' => 'https://gitlab.example.org/users/auth/openid_connect/callback',
        'scheme' => 'https',
        'host' => 'id.example.org',
        'port' => 443,
        'authorization_endpoint' => '/auth',
        'token_endpoint' => '/token',
        'userinfo_endpoint' => '/userinfo'
      }
    }
  }
]

# Security Settings
gitlab_rails['gitlab_default_can_create_group'] = false
gitlab_rails['gitlab_username_changing_enabled'] = false
gitlab_rails['gitlab_default_projects_features_builds'] = false
gitlab_rails['gitlab_signup_enabled'] = false
gitlab_rails['gitlab_signin_enabled'] = true

# Email Configuration (optional)
# gitlab_rails['gitlab_email_enabled'] = true
# gitlab_rails['gitlab_email_from'] = 'gitlab@example.org'
# gitlab_rails['gitlab_email_display_name'] = 'GitLab'

# Logging
gitlab_rails['log_level'] = 'info'
gitlab_rails['log_format'] = 'json'

# Backup Configuration
gitlab_rails['backup_path'] = '/var/opt/gitlab/backups'
gitlab_rails['backup_keep_time'] = 604800

# Performance Settings
unicorn['worker_processes'] = 2
unicorn['worker_timeout'] = 60
