# Quick Start Guide

This guide will get you up and running with LDAP User Manager in under 10 minutes.

## Prerequisites

Before you start, make sure you have:
- Docker and Docker Compose installed
- Basic knowledge of LDAP concepts
- A domain name (optional, but recommended for production)

## Step 1: Download and Start

```bash
# Clone the repository
git clone https://github.com/pinguts/ldap-user-manager.git
cd ldap-user-manager

# Start all services
docker-compose up -d
```

## Step 2: Verify Services

Check that all services are running:

```bash
docker-compose ps
```

You should see:
- `ldap-user-manager` (web application)
- `ldap` (LDAP server)
- `dex` (OIDC provider, if enabled)
- `caddy` (reverse proxy)

## Step 3: Complete Setup

1. **Open your browser** and go to `http://localhost:8080/setup/`
2. **Follow the setup wizard** to configure your LDAP structure
3. **Create your first admin user** when prompted
4. **Test the login** with your new admin account

## Step 4: Verify Everything Works

After setup, test these features:

- [ ] **User Management**: Create a test user
- [ ] **Organization Management**: Create a test organization
- [ ] **Role Assignment**: Assign a role to your test user
- [ ] **Self-service**: Try changing a password

## What's Next?

- **Configure OIDC**: See [OIDC Integration](../integrations/oidc.md) for external service setup
- **Customize Settings**: See [Configuration](../configuration/environment-variables.md) for advanced options
- **User Guide**: See [User Management](../user-guide/user-management.md) for daily operations

## Troubleshooting

If you encounter issues:

1. **Check service logs**: `docker-compose logs [service-name]`
2. **Verify ports**: Make sure ports 8080 and 389 are available
3. **Check setup wizard**: Ensure all required fields are completed
4. **Review configuration**: See [Troubleshooting](../deployment/troubleshooting.md) for common issues

## Production Deployment

For production use:

1. **Set up SSL certificates** (see [Docker Setup](../../DOCKER-SETUP.md))
2. **Configure environment variables** (see [Configuration](../configuration/environment-variables.md))
3. **Set up backups** (see [LDAP Backup](../ldap/backup.md))
4. **Enable monitoring** (see [Monitoring](../deployment/monitoring.md))
