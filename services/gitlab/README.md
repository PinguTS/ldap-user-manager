# GitLab OIDC Configuration

This guide explains how to configure GitLab on an external server to authenticate against your local Dex OIDC provider using OmniAuth.

## Prerequisites

- GitLab CE/EE installed and running
- Access to GitLab configuration files
- Ruby and Bundler available
- Dex OIDC provider running at `https://id.example.org`

## Installation

### 1. Install OmniAuth OpenID Connect Strategy

Add to your `Gemfile`:

```ruby
gem 'omniauth-openid-connect'
```

Then install:

```bash
bundle install
```

### 2. Restart GitLab

```bash
# Reload configuration
gitlab-ctl reconfigure

# Restart services
gitlab-ctl restart
```

## Configuration

### GitLab Configuration File

Update your `gitlab.rb` file with the following OIDC configuration:

```ruby
# Enable OmniAuth
gitlab_rails['omniauth_enabled'] = true
gitlab_rails['omniauth_allow_single_sign_on'] = ['openid_connect']
gitlab_rails['omniauth_block_auto_created_users'] = false
gitlab_rails['omniauth_auto_link_ldap_user'] = false

# OIDC Provider Configuration
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
```

### Environment Variables

Alternatively, you can use environment variables:

```bash
export GITLAB_OMNIAUTH_PROVIDERS='[{"name":"openid_connect","label":"OpenID Connect","args":{"name":"openid_connect","strategy_class":"OmniAuth::Strategies::OpenIDConnect","issuer":"https://id.example.org","client_id":"gitlab","client_secret":"your-secret","discovery":true,"scope":"openid profile email","uid_field":"sub"}}]'
```

## User Mapping

GitLab will map OIDC claims to user attributes:

- **Username**: `sub` (OIDC subject identifier)
- **Email**: `email` claim
- **Name**: `name` claim
- **Auto-provisioning**: Enabled for new users

## Testing

### 1. Verify Configuration
- Check GitLab logs for OmniAuth configuration
- Verify OIDC provider is listed in GitLab login page
- Ensure client secret matches Dex configuration

### 2. Test OIDC Flow
1. Visit GitLab login page
2. Click "OpenID Connect" button
3. Should redirect to Dex at `https://id.example.org/auth`
4. Login with LDAP credentials
5. Should redirect back to GitLab
6. User should be logged in with proper attributes

## Troubleshooting

### Common Issues

**Error**: "OmniAuth error" in GitLab
- **Solution**: Verify OIDC client configuration in gitlab.rb
- **Check**: Client secret matches Dex configuration

**Error**: "User not created" after OIDC login
- **Solution**: Check `omniauth_block_auto_created_users` setting
- **Check**: User attribute mapping configuration

**Error**: "Invalid OIDC configuration"
- **Solution**: Verify issuer URL and discovery endpoint
- **Check**: Network connectivity to Dex provider

### Debug Steps

1. Check GitLab logs: `gitlab-ctl tail`
2. Verify OmniAuth configuration: `gitlab-ctl show-config`
3. Test OIDC discovery: `curl -k https://id.example.org/.well-known/openid_configuration`
4. Check user creation in GitLab admin panel

## Security Considerations

- **Client Secret**: Store securely, never commit to version control
- **HTTPS Only**: Ensure GitLab runs over HTTPS
- **User Permissions**: Configure appropriate user access levels
- **Auto-creation**: Review auto-created user permissions

## Support

- **GitLab OmniAuth Documentation**: https://docs.gitlab.com/ee/integration/omniauth.html
- **Main Documentation**: [../../docs/identity.md](../../docs/identity.md)
- **OIDC Quick Reference**: [../../docs/integrations/oidc-quick-reference.md](../../docs/integrations/oidc-quick-reference.md)
