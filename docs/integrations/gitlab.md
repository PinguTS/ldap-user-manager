# GitLab Integration

This guide provides detailed integration instructions for connecting GitLab with LDAP User Manager.

## Overview

GitLab can integrate with LDAP User Manager using:
- **OmniAuth OpenID Connect**: Modern OIDC-based integration
- **LDAP Authentication**: Traditional LDAP-based integration

## GitLab OmniAuth OpenID Connect

### Installation

#### Edit GitLab Configuration
```bash
# Edit /etc/gitlab/gitlab.rb
sudo nano /etc/gitlab/gitlab.rb
```

### Configuration

#### Configuration Files

##### Gemfile
```ruby
source 'https://rubygems.org'

# GitLab dependencies
gem 'rails', '~> 6.1.0'
gem 'omniauth', '~> 2.0.0'

# OIDC Integration
gem 'omniauth-openid-connect'

# Other GitLab gems
gem 'gitlab', '~> 4.0'
gem 'gitlab-lab', '~> 1.0'
gem 'gitlab-workhorse', '~> 8.0'
gem 'gitlab-shell', '~> 13.0'
gem 'gitlab-elasticsearch-indexer', '~> 2.0'
gem 'gitlab-pages', '~> 1.0'
gem 'gitlab-runner', '~> 13.0'
gem 'gitlab-sidekiq', '~> 6.0'
gem 'gitlab-unicorn', '~> 5.0'
gem 'gitlab-puma', '~> 4.0'
gem 'gitlab-nginx', '~> 1.0'
gem 'gitlab-prometheus', '~> 1.0'
gem 'gitlab-grafana', '~> 1.0'
gem 'gitlab-postgresql', '~> 12.0'
gem 'gitlab-redis', '~> 6.0'
gem 'gitlab-gitaly', '~> 14.0'
gem 'gitlab-mailroom', '~> 0.0'
gem 'gitlab-mattermost', '~> 5.0'
gem 'gitlab-registry', '~> 2.0'
gem 'gitlab-pages', '~> 1.0'
gem 'gitlab-runner', '~> 13.0'
gem 'gitlab-sidekiq', '~> 6.0'
gem 'gitlab-unicorn', '~> 5.0'
gem 'gitlab-puma', '~> 4.0'
gem 'gitlab-nginx', '~> 1.0'
gem 'gitlab-prometheus', '~> 1.0'
gem 'gitlab-grafana', '~> 1.0'
gem 'gitlab-postgresql', '~> 12.0'
gem 'gitlab-redis', '~> 6.0'
gem 'gitlab-gitaly', '~> 14.0'
gem 'gitlab-mailroom', '~> 0.0'
gem 'gitlab-mattermost', '~> 5.0'
gem 'gitlab-registry', '~> 2.0'
```

##### gitlab.rb
```ruby
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
```

#### GitLab Configuration (`/etc/gitlab/gitlab.rb`)
```ruby
# GitLab configuration for OIDC integration

# External URL
external_url 'https://gitlab.example.org'

# LDAP configuration
gitlab_rails['ldap_enabled'] = true
gitlab_rails['ldap_servers'] = {
  'main' => {
    'label' => 'LDAP',
    'host' => 'ldap.example.com',
    'port' => 636,
    'uid' => 'uid',
    'encryption' => 'ssl',
    'verify_certificates' => true,
    'bind_dn' => 'cn=admin,dc=example,dc=com',
    'password' => 'admin123',
    'active_directory' => false,
    'allow_username_or_email_login' => true,
    'block_auto_created_users' => false,
    'base' => 'dc=example,dc=com',
    'user_filter' => '(objectClass=inetOrgPerson)',
    'attributes' => {
      'username' => ['uid', 'userid', 'sAMAccountName'],
      'email' => ['mail', 'email', 'userPrincipalName'],
      'name' => 'cn',
      'first_name' => 'givenName',
      'last_name' => 'sn'
    }
  }
}

# OIDC configuration
gitlab_rails['omniauth_enabled'] = true
gitlab_rails['omniauth_allow_single_sign_on'] = ['openid_connect']
gitlab_rails['omniauth_block_auto_created_users'] = false
gitlab_rails['omniauth_auto_link_ldap_user'] = true
gitlab_rails['omniauth_providers'] = [
  {
    'name' => 'openid_connect',
    'label' => 'SSO Login',
    'args' => {
      'name' => 'openid_connect',
      'scope' => ['openid', 'profile', 'email', 'groups'],
      'response_type' => 'code',
      'issuer' => 'https://id.example.org',
      'client_auth_method' => 'client_secret_post',
      'discovery' => true,
      'uid_field' => 'preferred_username',
      'client_options' => {
        'identifier' => 'gitlab',
        'secret' => 'your-gitlab-client-secret-here',
        'redirect_uri' => 'https://gitlab.example.org/users/auth/openid_connect/callback'
      }
    }
  }
]

# Apply configuration
sudo gitlab-ctl reconfigure
```

#### Advanced LDAP Configuration
```ruby
# Additional LDAP settings
gitlab_rails['ldap_servers']['main']['additional'] = {
  'user_search_base' => 'ou=people,dc=example,dc=com',
  'user_search_filter' => '(objectClass=inetOrgPerson)',
  'group_search_base' => 'ou=roles,dc=example,dc=com',
  'group_search_filter' => '(objectClass=groupOfNames)',
  'group_member_attribute' => 'member',
  'group_member_format' => 'uid=%{username},ou=people,dc=example,dc=com',
  'admin_group' => 'administrators',
  'sync_ssh_keys' => 'sshPublicKey',
  'sync_timeout' => 5,
  'sync_retry_limit' => 3,
  'sync_retry_delay' => 1,
  'sync_retry_backoff' => 2
}
```

### Testing the Integration

#### Test OIDC Connection
```bash
# Test OIDC provider accessibility
curl -f -s https://id.example.org/.well-known/openid_configuration

# Test client configuration
curl -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=gitlab&client_secret=your-gitlab-client-secret-here"
```

#### Test User Authentication
1. Go to your GitLab instance
2. Click the SSO login button
3. Complete authentication on the OIDC provider
4. Verify user is logged into GitLab

## GitLab LDAP Integration

### Configuration

#### Basic LDAP Configuration
```ruby
# /etc/gitlab/gitlab.rb

# Enable LDAP
gitlab_rails['ldap_enabled'] = true

# LDAP server configuration
gitlab_rails['ldap_servers'] = {
  'main' => {
    'label' => 'LDAP',
    'host' => 'ldap.example.com',
    'port' => 636,
    'uid' => 'uid',
    'encryption' => 'ssl',
    'verify_certificates' => true,
    'bind_dn' => 'cn=admin,dc=example,dc=com',
    'password' => 'admin123',
    'active_directory' => false,
    'allow_username_or_email_login' => true,
    'block_auto_created_users' => false,
    'base' => 'dc=example,dc=com',
    'user_filter' => '(objectClass=inetOrgPerson)',
    'attributes' => {
      'username' => ['uid', 'userid', 'sAMAccountName'],
      'email' => ['mail', 'email', 'userPrincipalName'],
      'name' => 'cn',
      'first_name' => 'givenName',
      'last_name' => 'sn'
    }
  }
}
```

#### Advanced LDAP Configuration
```ruby
# Advanced LDAP settings
gitlab_rails['ldap_servers']['main']['advanced'] = {
  'user_search_base' => 'ou=people,dc=example,dc=com',
  'user_search_filter' => '(objectClass=inetOrgPerson)',
  'group_search_base' => 'ou=roles,dc=example,dc=com',
  'group_search_filter' => '(objectClass=groupOfNames)',
  'group_member_attribute' => 'member',
  'group_member_format' => 'uid=%{username},ou=people,dc=example,dc=com',
  'admin_group' => 'administrators',
  'sync_ssh_keys' => 'sshPublicKey',
  'sync_timeout' => 5,
  'sync_retry_limit' => 3,
  'sync_retry_delay' => 1,
  'sync_retry_backoff' => 2,
  'sync_attributes' => {
    'username' => 'uid',
    'email' => 'mail',
    'name' => 'cn',
    'first_name' => 'givenName',
    'last_name' => 'sn',
    'ssh_public_key' => 'sshPublicKey'
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
1. Go to your GitLab instance
2. Use LDAP credentials to log in
3. Verify user is authenticated
4. Check group membership

## User Provisioning

### Automatic User Creation
```ruby
# /etc/gitlab/gitlab.rb

# Allow automatic user creation
gitlab_rails['ldap_servers']['main']['block_auto_created_users'] = false

# Auto-link LDAP users
gitlab_rails['omniauth_auto_link_ldap_user'] = true
```

### User Provisioning Hook
```ruby
# /opt/gitlab/embedded/service/gitlab-rails/lib/gitlab/ldap/access.rb

module Gitlab
  module LDAP
    class Access
      def self.open(user)
        access = new(user)
        yield access
      ensure
        access&.close
      end

      def initialize(user)
        @user = user
        @adapter = Gitlab::LDAP::Adapter.new(provider)
      end

      def allowed?
        return true unless ldap_user

        if user.ldap_user?
          return true if ldap_user.active?
        else
          return true if Gitlab::LDAP::Person.find_by_uid_and_provider(user.username, provider)
        end

        false
      end

      def ldap_user
        @ldap_user ||= Gitlab::LDAP::Person.find_by_uid_and_provider(user.username, provider)
      end

      private

      attr_reader :user, :adapter

      def provider
        @provider ||= Gitlab::LDAP::Config.providers.first
      end
    end
  end
end
```

## Group Management

### LDAP Group Synchronization
```ruby
# /etc/gitlab/gitlab.rb

# LDAP group sync configuration
gitlab_rails['ldap_group_sync_enabled'] = true
gitlab_rails['ldap_group_sync_interval'] = 3600

gitlab_rails['ldap_servers']['main']['group_sync'] = {
  'enabled' => true,
  'group_base' => 'ou=roles,dc=example,dc=com',
  'group_filter' => '(objectClass=groupOfNames)',
  'group_member_attribute' => 'member',
  'group_member_format' => 'uid=%{username},ou=people,dc=example,dc=com',
  'admin_group' => 'administrators',
  'external_group' => 'external_users'
}
```

### Group Provisioning Script
```ruby
# /opt/gitlab/embedded/service/gitlab-rails/lib/gitlab/ldap/group_sync.rb

module Gitlab
  module LDAP
    class GroupSync
      def self.sync_groups
        new.sync_all_groups
      end

      def sync_all_groups
        ldap_groups.each do |ldap_group|
          sync_group(ldap_group)
        end
      end

      private

      def sync_group(ldap_group)
        group_name = ldap_group['cn']
        gitlab_group = Group.find_by_name(group_name)

        if gitlab_group.nil?
          gitlab_group = Group.create!(
            name: group_name,
            path: group_name.downcase,
            visibility_level: Gitlab::VisibilityLevel::PRIVATE
          )
        end

        sync_group_members(gitlab_group, ldap_group)
      end

      def sync_group_members(gitlab_group, ldap_group)
        member_dns = ldap_group['member'] || []
        
        member_dns.each do |member_dn|
          username = extract_username_from_dn(member_dn)
          user = User.find_by_username(username)
          
          if user && !gitlab_group.users.include?(user)
            gitlab_group.add_user(user, Gitlab::Access::DEVELOPER)
          end
        end
      end

      def extract_username_from_dn(dn)
        # Extract username from DN like "uid=user1,ou=people,dc=example,dc=com"
        match = dn.match(/uid=([^,]+)/)
        match ? match[1] : nil
      end

      def ldap_groups
        @ldap_groups ||= Gitlab::LDAP::Adapter.new(provider).groups
      end

      def provider
        @provider ||= Gitlab::LDAP::Config.providers.first
      end
    end
  end
end
```

## Troubleshooting

### Common Issues

#### OIDC Configuration Issues
```bash
# Check OIDC provider configuration
curl -v https://id.example.org/.well-known/openid_configuration

# Verify client registration
curl -X POST https://id.example.org/token \
    -d "grant_type=client_credentials&client_id=gitlab&client_secret=your-gitlab-client-secret-here"
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

#### GitLab Configuration Issues
```bash
# Check GitLab status
sudo gitlab-ctl status

# Check GitLab logs
sudo gitlab-ctl tail

# Reconfigure GitLab
sudo gitlab-ctl reconfigure

# Restart GitLab
sudo gitlab-ctl restart
```

### Debug Configuration

#### Enable Debug Logging
```ruby
# /etc/gitlab/gitlab.rb

# Enable debug logging
gitlab_rails['log_level'] = 'debug'
gitlab_rails['ldap_debug'] = true
```

#### Debug Log Location
```bash
# GitLab logs
sudo gitlab-ctl tail

# LDAP specific logs
sudo gitlab-ctl tail gitlab-rails | grep LDAP

# OIDC specific logs
sudo gitlab-ctl tail gitlab-rails | grep OmniAuth
```

## Best Practices

### Security Considerations
1. **Use HTTPS**: Always use HTTPS for OIDC and LDAP connections
2. **Strong Passwords**: Use strong passwords for LDAP bind DN
3. **Certificate Validation**: Enable certificate validation for LDAPS
4. **Access Control**: Implement proper access controls in GitLab

### Performance Optimization
1. **Connection Pooling**: Configure LDAP connection pooling
2. **Caching**: Enable GitLab caching for better performance
3. **Group Mapping**: Cache group mappings to reduce LDAP queries
4. **User Sessions**: Configure appropriate session timeouts

### Maintenance
1. **Regular Updates**: Keep GitLab updated
2. **Log Monitoring**: Monitor logs for authentication issues
3. **User Management**: Regular review of user accounts and groups
4. **Backup**: Regular backup of GitLab configuration and data

## Support

For GitLab integration support:
- **GitLab Documentation**: [GitLab Documentation](https://docs.gitlab.com/)
- **LDAP Documentation**: [GitLab LDAP](https://docs.gitlab.com/ee/administration/auth/ldap/)
- **OIDC Documentation**: [GitLab OIDC](https://docs.gitlab.com/ee/administration/auth/oidc/)
- **Community Support**: [GitLab Community](https://forum.gitlab.com/)
- **Professional Support**: [GitLab Support](https://about.gitlab.com/support/)
