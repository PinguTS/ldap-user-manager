# External Services Configuration

This directory contains configuration examples and setup guides for external services that authenticate against the local Dex OIDC provider.

## Directory Structure

```
services/
├── README.md                    # This file
├── typo3/                      # TYPO3 CMS configuration
│   ├── README.md              # TYPO3 setup guide
│   ├── composer.json          # Required extensions
│   ├── oidc-config.yaml      # OIDC configuration example
│   ├── install.sh             # Automated installation script
│   ├── testing.md             # Testing procedures
│   └── troubleshooting.md     # Troubleshooting guide
├── gitlab/                     # GitLab configuration
│   ├── README.md              # GitLab setup guide
│   ├── gitlab.rb              # OmniAuth OIDC configuration
│   └── Gemfile                # Required gems
├── nextcloud/                  # Nextcloud configuration
│   ├── README.md              # Nextcloud setup guide
│   ├── config.php             # OIDC configuration example
│   ├── occ-commands.md        # OCC command examples
│   ├── install.sh             # Automated installation script
│   ├── testing.md             # Testing procedures
│   └── troubleshooting.md     # Troubleshooting guide
├── wordpress/                  # WordPress configuration
│   ├── README.md              # WordPress setup guide
│   └── wp-config.php          # WordPress configuration with OIDC
├── joomla/                     # Joomla configuration
│   ├── README.md              # Joomla setup guide
│   └── configuration.php      # Joomla configuration with OIDC
└── custom-applications/        # Custom applications configuration
    └── README.md              # Custom application setup guide
```

## Overview

These services run on **external servers** (not in the same Docker environment) and authenticate users via the local Dex OIDC provider running at `https://id.example.org`.

## Quick Start

1. **Choose your service** from the subdirectories above
2. **Follow the README** in each service directory
3. **Configure OIDC** using the provided examples
4. **Test authentication** against your local Dex provider

## Common Configuration

All external services require:
- **OIDC Issuer**: `https://id.example.org`
- **Client ID**: As configured in Dex (`typo3`, `gitlab`, `nextcloud`)
- **Client Secret**: Generated and stored securely
- **Redirect URI**: Service-specific callback URL
- **Scopes**: `openid profile email groups`

## Support

- **Main Documentation**: [../docs/identity.md](../docs/identity.md)
- **OIDC Quick Reference**: [../docs/oidc-quick-reference.md](../docs/oidc-quick-reference.md)
- **Dex Documentation**: https://dexidp.io/docs/
