# Apache Configuration in Docker

The LDAP User Manager Docker image uses an Apache vhost configuration file (`apache/ldap-user-manager.conf`) instead of `.htaccess` for performance, security, and Docker best practices. This document explains what that configuration does and how to verify it is working.

## Why Not .htaccess?

Loading configuration from a vhost file is faster (evaluated once at startup) and more secure (users inside the container cannot modify rewrite rules or security settings). It also follows Docker conventions: configuration is immutable and baked into the image.

## What the Configuration Provides

### Clean URLs

PHP file extensions are hidden from URLs:

| Browser URL | Actual file |
|---|---|
| `/manage/users/` | `/manage/users/index.php` |
| `/manage/users/show` | `/manage/users/show.php` |
| `/setup/` | `/setup/index.php` |

URL parameters (e.g. `?uuid=...`) pass through unchanged.

### Static File Serving

CSS, JS, images, and fonts are served directly by Apache without going through PHP. They receive browser caching headers and Gzip compression.

Static file extensions excluded from PHP processing: `css`, `js`, `png`, `jpg`, `jpeg`, `gif`, `ico`, `svg`, `woff`, `woff2`, `ttf`, `eot`, `pdf`, `zip`, `txt`, `xml`, `json`.

### Security

- Access to sensitive file types (`.htaccess`, `.ini`, `.log`, `.env`, etc.) is denied.
- The `includes/` directory is blocked from direct browser access.
- Security headers are set: `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy`.

## Verifying the Configuration

Check that Apache loaded the configuration correctly:

```bash
# Inside the container
docker exec -it ldap-user-manager apache2ctl -S
docker exec -it ldap-user-manager apache2ctl -t
```

Check that required modules are loaded:

```bash
docker exec -it ldap-user-manager apache2ctl -M | grep -E "rewrite|ssl|headers|expires|deflate"
```

## Troubleshooting

**Clean URLs return 404**
- Confirm `mod_rewrite` is listed in `apache2ctl -M` output.
- Check the container logs: `docker logs ldap-user-manager`.

**Static files redirect to login page**
- The rewrite rule may be catching static extensions. Check `apache/ldap-user-manager.conf` for the exclusion pattern.

**Security headers missing**
- Confirm `mod_headers` is enabled.
- When running behind a reverse proxy (Caddy, Nginx), avoid setting the same header in both the proxy and Apache — this produces duplicate header values. Set each header in only one place.

## Configuration File Location

The Apache configuration is at `apache/ldap-user-manager.conf` in the repository root. It is copied into the Docker image at build time and included by the `entrypoint` script when it generates the Apache VirtualHost.

For bare-metal Apache or Nginx deployment (without Docker), see [Web Server Deployment](../../web-servers/README.md).
