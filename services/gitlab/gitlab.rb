# /etc/gitlab/gitlab.rb — OIDC configuration snippet
# Add these settings to your existing gitlab.rb file.
# See docs/integrations/gitlab.md for the full integration guide.

gitlab_rails['omniauth_enabled'] = true
gitlab_rails['omniauth_allow_single_sign_on'] = ['openid_connect']
gitlab_rails['omniauth_sync_email_from_provider'] = 'openid_connect'
gitlab_rails['omniauth_sync_profile_from_provider'] = ['openid_connect']
gitlab_rails['omniauth_auto_sign_in_with_provider'] = 'openid_connect'
gitlab_rails['omniauth_block_auto_created_users'] = false

gitlab_rails['omniauth_providers'] = [
  {
    name: 'openid_connect',
    label: 'LDAP SSO',
    args: {
      name: 'openid_connect',
      scope: ['openid', 'profile', 'email', 'groups'],
      response_type: 'code',
      issuer: 'https://id.example.org',
      discovery: true,
      client_auth_method: 'basic',
      uid_field: 'sub',
      client_options: {
        identifier: 'gitlab',
        secret: 'your-gitlab-client-secret-here',
        redirect_uri: 'https://gitlab.example.org/users/auth/openid_connect/callback'
      }
    }
  }
]
