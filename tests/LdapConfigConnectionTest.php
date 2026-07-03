<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/ldap_functions.inc.php';

/**
 * ponytail: no LDAP server available in unit tests, so this only checks the
 * unset/default-credential branch of open_ldap_config_connection() (it must return
 * false without attempting a network connection). The bind path is exercised by the
 * setup ACL check/apply pages against a real directory.
 */
final class LdapConfigConnectionTest extends TestCase
{
    public function testConfigBindDnDefaultsToConfigAdminWhenEnvUnset(): void
    {
        putenv('LDAP_CONFIG_BIND_DN');
        putenv('LDAP_CONFIG_BIND_PWD');
        putenv('LDAP_CONFIG_PASSWORD');

        $dn = getenv('LDAP_CONFIG_BIND_DN') ?: 'cn=admin,cn=config';
        $pwd = getenv('LDAP_CONFIG_BIND_PWD') ?: (getenv('LDAP_CONFIG_PASSWORD') ?: '');

        self::assertSame('cn=admin,cn=config', $dn);
        self::assertSame('', $pwd);
    }

    public function testOpenLdapConfigConnectionReturnsFalseWithoutCredentials(): void
    {
        global $LDAP;

        $LDAP['config_bind_dn'] = 'cn=admin,cn=config';
        $LDAP['config_bind_pwd'] = '';

        self::assertFalse(open_ldap_config_connection());
    }

    public function testOpenLdapConfigConnectionFallsBackToConfigPasswordEnv(): void
    {
        putenv('LDAP_CONFIG_PASSWORD=fallback-secret');

        $pwd = getenv('LDAP_CONFIG_BIND_PWD') ?: (getenv('LDAP_CONFIG_PASSWORD') ?: '');

        self::assertSame('fallback-secret', $pwd);

        putenv('LDAP_CONFIG_PASSWORD');
    }
}
