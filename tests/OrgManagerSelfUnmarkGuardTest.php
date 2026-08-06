<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Org manager self-unmark guard must match the current user's DN case-insensitively.
 */
final class OrgManagerSelfUnmarkGuardTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        if (!self::$booted) {
            $_SERVER['HTTPS'] ??= 'off';
            $_SERVER['HTTP_HOST'] ??= 'localhost';
            $_SERVER['REQUEST_URI'] ??= '/manage/organizations/users/';
            $_SERVER['PHP_SELF'] ??= '/index.php';
            putenv('APP_HTTP_PATH=/');
            putenv('APP_HTTP_HOST=localhost');
            putenv('LDAP_URI=ldap://localhost');
            putenv('LDAP_BASE_DN=dc=example,dc=com');
            putenv('LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com');
            putenv('LDAP_ADMIN_BIND_PWD=test');
            putenv('LDAP_ADMIN_ROLE=administrators');
            require_once __DIR__ . '/../www/includes/i18n.inc.php';
            require_once __DIR__ . '/../www/includes/web_functions.inc.php';
            require_once __DIR__ . '/../www/includes/org_user_helpers.inc.php';
            self::$booted = true;
        }

        $this->resetAuthGlobals();
    }

    private function resetAuthGlobals(): void
    {
        global $USER_DN;
        $USER_DN = null;
    }

    public function testIsSelfWhenDnsMatchExactly(): void
    {
        global $USER_DN;
        $USER_DN = 'uid=alice,ou=people,o=acme,ou=organizations,dc=example,dc=com';

        self::assertTrue(org_manager_toggle_is_self('uid=alice,ou=people,o=acme,ou=organizations,dc=example,dc=com'));
    }

    public function testIsSelfWhenDnsDifferOnlyInCase(): void
    {
        global $USER_DN;
        $USER_DN = 'UID=ALICE,OU=PEOPLE,O=ACME,OU=ORGANIZATIONS,DC=EXAMPLE,DC=COM';

        self::assertTrue(org_manager_toggle_is_self('uid=alice,ou=people,o=acme,ou=organizations,dc=example,dc=com'));
    }

    public function testIsNotSelfForDifferentUser(): void
    {
        global $USER_DN;
        $USER_DN = 'uid=alice,ou=people,o=acme,ou=organizations,dc=example,dc=com';

        self::assertFalse(org_manager_toggle_is_self('uid=bob,ou=people,o=acme,ou=organizations,dc=example,dc=com'));
    }

    public function testIsNotSelfWhenCurrentUserDnUnknown(): void
    {
        global $USER_DN;
        $USER_DN = null;

        self::assertFalse(org_manager_toggle_is_self('uid=alice,ou=people,o=acme,ou=organizations,dc=example,dc=com'));
    }

    public function testHandlersContainSelfGuard(): void
    {
        $usersIndex = (string) file_get_contents(__DIR__ . '/../www/manage/organizations/users/index.php');
        $orgShow = (string) file_get_contents(__DIR__ . '/../www/manage/organizations/show/index.php');

        self::assertStringContainsString('org_manager_toggle_is_self($userDn)', $usersIndex);
        self::assertStringContainsString('org_manager_toggle_is_self($userDn)', $orgShow);
        self::assertStringContainsString('manage.org_users.msg.cannot_remove_self_manager', $usersIndex);
        self::assertStringContainsString('manage.org_users.msg.cannot_remove_self_manager', $orgShow);
    }

    public function testUiRenderersDisableSelfManagerSwitch(): void
    {
        $helpers = (string) file_get_contents(__DIR__ . '/../www/includes/user_table_helpers.inc.php');

        self::assertStringContainsString('bool $isSelf', $helpers);
        self::assertStringContainsString('<?= $isSelf ? \'disabled\' : \'\' ?>', $helpers);
        self::assertStringContainsString('manage.org_users.msg.cannot_remove_self_manager', $helpers);
    }
}
