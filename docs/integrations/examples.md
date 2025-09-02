# Integration Examples

This directory contains comprehensive integration guides for connecting various platforms and applications with LDAP User Manager.

## Available Integration Guides

### Platform Integrations

- **[TYPO3 Integration](typo3.md)** - Complete guide for integrating TYPO3 with LDAP User Manager using OIDC and LDAP authentication
- **[Nextcloud Integration](nextcloud.md)** - Step-by-step instructions for connecting Nextcloud with OIDC Login App and LDAP User Backend
- **[GitLab Integration](gitlab.md)** - Comprehensive guide for GitLab integration using OmniAuth OpenID Connect and LDAP authentication
- **[WordPress Integration](wordpress.md)** - Detailed instructions for WordPress integration with OpenID Connect Plugin and LDAP authentication
- **[Joomla Integration](joomla.md)** - Complete guide for Joomla integration using OIDC Authentication Plugin and LDAP authentication

### Custom Application Integration

- **[Custom Applications](custom-applications.md)** - Guide for integrating custom applications using OIDC and LDAP client libraries
  - PHP application integration
  - Python application integration  
  - Node.js application integration
  - Complete code examples and usage patterns

### Testing and Troubleshooting

- **[Testing Integration](testing.md)** - Comprehensive testing procedures for all integrations
  - OIDC integration testing
  - LDAP integration testing
  - Performance testing
  - Security testing
  - Automated testing scripts

- **[Troubleshooting Integration Issues](troubleshooting.md)** - Complete troubleshooting guide for integration problems
  - Quick diagnosis scripts
  - Common OIDC issues
  - Common LDAP issues
  - Platform-specific problems
  - Performance issues
  - Recovery procedures

## Quick Reference

### OIDC Configuration
- **Issuer URL**: `https://id.example.org`
- **Supported Scopes**: `openid`, `profile`, `email`, `groups`
- **Token Types**: ID Token, Access Token, Refresh Token
- **Grant Types**: Authorization Code, Client Credentials, Password

### LDAP Configuration
- **Server**: `ldaps://ldap.example.com:636`
- **Base DN**: `dc=example,dc=com`
- **User DN**: `uid={username},ou=people,dc=example,dc=com`
- **Group DN**: `cn={groupname},ou=roles,dc=example,dc=com`

### Common Integration Patterns

#### OIDC Flow
1. Redirect user to OIDC provider
2. User authenticates and consents
3. Receive authorization code
4. Exchange code for tokens
5. Use tokens for API access

#### LDAP Flow
1. Bind to LDAP server
2. Search for user
3. Authenticate user credentials
4. Retrieve user attributes and groups
5. Map to application roles

## Getting Started

1. **Choose your platform** from the integration guides above
2. **Follow the step-by-step instructions** in the specific guide
3. **Test your integration** using the testing guide
4. **Troubleshoot issues** using the troubleshooting guide if needed

## Support

For integration support:
- **Documentation**: Each integration guide contains detailed instructions
- **Testing**: Use the testing guide to verify your integration
- **Troubleshooting**: Refer to the troubleshooting guide for common issues
- **Community**: [GitHub Issues](https://github.com/pinguts/ldap-user-manager/issues)
- **Professional Support**: [Contact Support](mailto:support@yourcompany.com)
