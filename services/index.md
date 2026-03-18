# External Services OIDC Configuration Index

This directory contains comprehensive configuration guides for external services that authenticate against your local Dex OIDC provider.

## 🏗️ **Architecture Overview**

```
┌─────────────────────────────────────────────────────────────┐
│                    External Services                        │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │    TYPO3    │  │    GitLab   │  │  Nextcloud  │        │
│  │   Server    │  │   Server    │  │   Server    │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
                    ┌─────────────────┐
                    │   OIDC Auth     │
                    │   (HTTPS)       │
                    └─────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                   Local Infrastructure                      │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │    Caddy    │  │     Dex     │  │     LDAP    │        │
│  │   Reverse   │  │   OIDC      │  │   Server    │        │
│  │    Proxy    │  │  Provider   │  │             │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
└─────────────────────────────────────────────────────────────┘
```

## 📚 **Service Configuration Guides**

### [TYPO3 CMS](./typo3/)
**Authentication Method**: Causal OIDC Extension  
**Key Features**: Frontend user login, group mapping, auto-provisioning  
**Configuration**: Backend extension settings + TypoScript  
**Files**: `composer.json`, `oidc-config.yaml`

**Quick Setup**:
```bash
composer require causal/oidc
# Configure in TYPO3 backend > Admin Tools > Extensions > OIDC
```

### [GitLab](./gitlab/)
**Authentication Method**: OmniAuth OpenID Connect Strategy  
**Key Features**: Single sign-on, user auto-creation, group management  
**Configuration**: `gitlab.rb` + Gemfile  
**Files**: `gitlab.rb`, `Gemfile`

**Quick Setup**:
```bash
# Add to Gemfile
gem 'omniauth-openid-connect'
bundle install
# Configure in gitlab.rb
```

### [Nextcloud](./nextcloud/)
**Authentication Method**: OIDC Login App  
**Key Features**: File sharing, user provisioning, group sync  
**Configuration**: OCC commands + config.php  
**Files**: `config.php`, `occ-commands.md`

**Quick Setup**:
```bash
# Install OIDC Login app from app store
# Configure via OCC commands
occ config:app:set oidc_login provider-url --value="https://id.example.org"
```

### [WordPress](./wordpress/)
**Authentication Method**: OpenID Connect Plugin  
**Key Features**: Blog/CMS authentication, user provisioning, role mapping  
**Configuration**: wp-config.php + plugin settings  
**Files**: `wp-config.php`, `README.md`

**Quick Setup**:
```bash
# Install OIDC plugin from WordPress admin
# Configure in wp-config.php
define('OIDC_CLIENT_ID', 'wordpress');
```

### [Joomla](./joomla/)
**Authentication Method**: OIDC Authentication Plugin  
**Key Features**: CMS authentication, user provisioning, group mapping  
**Configuration**: configuration.php + plugin XML  
**Files**: `configuration.php`, `README.md`

**Quick Setup**:
```bash
# Install OIDC plugin from Joomla admin
# Configure in configuration.php
$oidc_client_id = 'joomla';
```

### [Custom Applications](./custom-applications/)
**Authentication Method**: OIDC/LDAP Client Libraries  
**Key Features**: Custom integration, flexible configuration  
**Configuration**: Language-specific config files  
**Files**: `README.md` with examples for PHP, Python, Node.js

**Quick Setup**:
```bash
# Choose your platform and follow the configuration examples
# Copy the relevant config files to your application
```

## 🔧 **Common Configuration Requirements**

All external services require these OIDC settings:

| Setting | Value | Description |
|---------|-------|-------------|
| **Issuer** | `https://id.example.org` | Dex OIDC provider URL |
| **Scopes** | `openid profile email groups` | Required OIDC scopes |
| **Client ID** | Service-specific | Unique identifier for each service |
| **Client Secret** | Generated | Secure secret for each service |
| **Redirect URI** | Service-specific | Callback URL after authentication |

## 🚀 **Quick Start Checklist**

### 1. **Generate Client Secrets**
```bash
# Run the setup script
./setup-oidc.sh
```

### 2. **Configure Each Service**
- [ ] **TYPO3**: Install extension, configure backend settings
- [ ] **GitLab**: Add gem, update gitlab.rb, restart services
- [ ] **Nextcloud**: Install app, run OCC commands

### 3. **Test OIDC Flow**
- [ ] Visit service login page
- [ ] Click OIDC login button
- [ ] Redirect to Dex authentication
- [ ] Login with LDAP credentials
- [ ] Return to service with valid session

### 4. **Verify User Provisioning**
- [ ] New users created automatically
- [ ] Group membership mapped correctly
- [ ] User attributes populated from LDAP

## 🔍 **Troubleshooting**

### Common Issues
- **Redirect URI Mismatch**: Ensure exact match between service and Dex
- **Discovery Endpoint Unreachable**: Check network connectivity and firewall rules
- **Client Secret Issues**: Verify secrets match in both service and Dex
- **User Not Created**: Check auto-provisioning settings

### Debug Steps
1. Test OIDC discovery: `curl -k https://id.example.org/.well-known/openid_configuration`
2. Check service logs for OIDC-related errors
3. Verify client configuration in Dex
4. Test network connectivity between external service and Dex

## 📖 **Additional Resources**

- **Main Documentation**: [../docs/identity.md](../docs/identity.md)
- **OIDC Quick Reference**: [../docs/integrations/oidc-quick-reference.md](../docs/integrations/oidc-quick-reference.md)
- **Dex Documentation**: https://dexidp.io/docs/
- **OIDC Specification**: https://openid.net/connect/

## 🛡️ **Security Considerations**

- **HTTPS Only**: All external services must run over HTTPS
- **Client Secrets**: Store securely, never commit to version control
- **Network Security**: Ensure Dex is accessible from external services
- **User Permissions**: Configure appropriate access levels for auto-created users
- **Monitoring**: Set up alerts for failed authentication attempts

## 📞 **Support**

For issues specific to each service, refer to their individual documentation in the subdirectories above. For general OIDC or Dex issues, consult the main documentation or Dex's official documentation.
