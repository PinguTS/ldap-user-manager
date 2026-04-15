# OIDC Quick Reference Card

## Dex OIDC Provider Endpoints

| Endpoint | URL | Description |
|----------|-----|-------------|
| **Issuer** | `https://id.example.org` | Base URL for OIDC provider |
| **Discovery** | `https://id.example.org/.well-known/openid_configuration` | OIDC discovery document |
| **Authorization** | `https://id.example.org/auth` | User authorization endpoint |
| **Token** | `https://id.example.org/token` | Token exchange endpoint |
| **UserInfo** | `https://id.example.org/userinfo` | User information endpoint |
| **JWKS** | `https://id.example.org/keys` | JSON Web Key Set |

## OIDC Client Configuration

### LDAP User Manager (Local)
- **Client ID**: `ldap-user-manager`
- **Redirect URI**: `https://app.example.org/oidc/callback`
- **Scopes**: `openid profile email groups`

### TYPO3 (External)
- **Client ID**: `typo3`
- **Redirect URI**: `https://typo3.example.org/index.php?eID=oidc`
- **Scopes**: `openid profile email groups`

### GitLab (External)
- **Client ID**: `gitlab`
- **Redirect URI**: `https://gitlab.example.org/users/auth/openid_connect/callback`
- **Scopes**: `openid profile email groups`

### Nextcloud (External)
- **Client ID**: `nextcloud`
- **Redirect URI**: `https://nextcloud.example.org/index.php/apps/oidc_login/oidc`
- **Scopes**: `openid profile email groups`

## Token Configuration

| Token Type | Lifetime | Purpose |
|------------|----------|---------|
| **ID Token** | 15 minutes | User authentication and claims |
| **Access Token** | 15 minutes | API access (if needed) |
| **Refresh Token** | 24 hours | Token renewal |
| **Signing Keys** | 6 hours | JWT signing rotation |

## Claim Mapping

| OIDC Claim | LDAP Attribute | Description |
|-------------|----------------|-------------|
| `sub` | `uid` | Unique user identifier |
| `email` | `mail` | User email address |
| `name` | `cn` | Common name/display name |
| `given_name` | `givenName` | First name |
| `family_name` | `sn` | Last name |
| `groups` | `memberOf` | Group membership |

## Quick Test Commands

```bash
# Test OIDC discovery
curl -k https://id.example.org/.well-known/openid_configuration

# Test local services
curl -k https://app.example.org/
curl -k https://id.example.org/

# Test external services
curl -k https://typo3.example.org/
curl -k https://gitlab.example.org/
curl -k https://nextcloud.example.org/

# Check Docker services
docker-compose ps
docker-compose logs dex
```

## Common Issues & Solutions

| Issue | Check | Solution |
|-------|-------|----------|
| **Redirect URI Mismatch** | Client config vs Dex config | Ensure exact match |
| **Discovery Endpoint Unreachable** | Network connectivity | Verify firewall rules |
| **Invalid Client Credentials** | Client secret | Regenerate and update |
| **Groups Claim Missing** | LDAP group membership | Check user group assignments |
| **Certificate Trust Issues** | SSL certificates | Use valid CA-signed certs |

## Security Checklist

- [ ] TLS enforced everywhere (HTTPS only)
- [ ] LDAP network is private (not exposed to internet)
- [ ] Client secrets are secure and rotated
- [ ] Token lifetimes are appropriate (15min ID, 24h refresh)
- [ ] Monitoring and alerting configured
- [ ] Break glass accounts available
- [ ] Regular backups scheduled
- [ ] Access logs reviewed regularly

## Support Resources

- **Main Documentation**: [docs/identity.md](../identity.md)
- **Example Config Files**: [services/](../../services/)
- **Dex Documentation**: https://dexidp.io/docs/
- **OIDC Specification**: https://openid.net/connect/
