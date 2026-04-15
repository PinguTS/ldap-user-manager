# GitLab Integration

This guide explains how to configure GitLab to authenticate users via the Dex OIDC provider bundled with LDAP User Manager.

## Overview

GitLab supports OmniAuth, which includes native support for OpenID Connect. Users signing in via Dex are authenticated against your LDAP directory.

---

## Prerequisites

- GitLab installed (omnibus or source)
- Dex OIDC provider accessible at `https://id.example.org`
- A client secret configured in Dex for the `gitlab` client

---

## 1. Install the OmniAuth Provider Gem

If using **GitLab Omnibus**, the `omniauth-openid-connect` gem is included. No installation step is required.

If using a **source installation**, add the gem to your Gemfile:

```
gem 'omniauth-openid-connect'
```

Then run:

```bash
bundle install
```

---

## 2. Configure gitlab.rb

Add the following OmniAuth block to `/etc/gitlab/gitlab.rb`:

```ruby
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
```

An example `gitlab.rb` snippet is available at [`services/gitlab/gitlab.rb`](../../services/gitlab/gitlab.rb).

---

## 3. Apply the Configuration

```bash
sudo gitlab-ctl reconfigure
```

---

## 4. Test the Integration

1. Navigate to `https://gitlab.example.org`
2. Click **Sign in with LDAP SSO**
3. Verify you are redirected to `https://id.example.org/auth`
4. Log in with your LDAP credentials
5. Confirm you are redirected back to GitLab and signed in

```bash
# Verify the Dex discovery endpoint is reachable from the GitLab server
curl -s https://id.example.org/.well-known/openid-configuration | jq .issuer

# Check GitLab production logs for OmniAuth activity
sudo tail -f /var/log/gitlab/gitlab-rails/production.log
```

---

## 5. Group Sync

GitLab can synchronize LDAP groups using its built-in LDAP group sync feature (GitLab Premium). For OIDC-based group mapping, use the `groups` claim from Dex and configure GitLab's OmniAuth group sync accordingly.

Dex provides the `groups` claim when the scope `groups` is requested. The claim contains the LDAP group names the user belongs to.

---

## 6. Troubleshooting

**"Invalid redirect URI"**
The `redirect_uri` in `gitlab.rb` must exactly match the URI registered in Dex `config.yaml` under `staticClients.redirectURIs`.

**"Could not authenticate you from OpenidConnect"**
- Verify the `issuer` URL is correct and reachable from the GitLab server
- Check that the `identifier` (client ID) and `secret` match the Dex configuration
- Review production logs: `sudo gitlab-ctl tail`

**User created but cannot log in**
Check `gitlab_rails['omniauth_block_auto_created_users']` — set to `false` to allow auto-provisioned users to log in immediately.

**Verify Dex client configuration:**
```yaml
# dex/config.yaml
staticClients:
  - id: gitlab
    secret: your-gitlab-client-secret-here
    redirectURIs:
      - https://gitlab.example.org/users/auth/openid_connect/callback
```

---

## Security Considerations

- Store the client secret securely; do not commit it to version control
- Use HTTPS for both GitLab and the Dex provider
- Review GitLab's sign-in restrictions after enabling OmniAuth

## Support

- [GitLab OmniAuth Documentation](https://docs.gitlab.com/ee/integration/omniauth.html)
- [GitLab OpenID Connect](https://docs.gitlab.com/ee/administration/auth/oidc.html)
- [Dex Documentation](https://dexidp.io/docs/)
- [OIDC Quick Reference](oidc-quick-reference.md)
