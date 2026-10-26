<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

/**
 * After login ($VALIDATED), currentUserIsGlobalAdmin / currentUserIsMaintainer must
 * trust session role flags (including false) and must not open LDAP.
 */
final class SessionRoleShortCircuitTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        if (!self::$booted) {
            $_SERVER['HTTPS'] ??= 'off';
            $_SERVER['HTTP_HOST'] ??= 'localhost';
            $_SERVER['REQUEST_URI'] ??= '/';
            $_SERVER['PHP_SELF'] ??= '/index.php';
            putenv('APP_HTTP_PATH=/');
            putenv('APP_HTTP_HOST=localhost');
            putenv('LDAP_URI=ldap://localhost');
            putenv('LDAP_BASE_DN=dc=example,dc=com');
            putenv('LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com');
            putenv('LDAP_ADMIN_BIND_PWD=test');
            putenv('LDAP_ADMIN_ROLE=administrators');
            require_once __DIR__ . '/../www/includes/web_functions.inc.php';
            require_once __DIR__ . '/../www/includes/access_functions.inc.php';
            self::$booted = true;
        }

        global $VALIDATED, $IS_ADMIN, $IS_MAINTAINER, $USER_DN, $USER_ID;
        $VALIDATED = false;
        $IS_ADMIN = false;
        $IS_MAINTAINER = false;
        $USER_DN = null;
        $USER_ID = null;
    }

    public function testValidatedNonAdminReturnsFalseWithoutUserDn(): void
    {
        global $VALIDATED, $IS_ADMIN, $USER_DN, $USER_ID;

        $VALIDATED = true;
        $IS_ADMIN = false;
        // No DN / ID — LDAP fallback would fail or connect; session path must short-circuit.
        $USER_DN = null;
        $USER_ID = null;

        self::assertFalse(currentUserIsGlobalAdmin());
    }

    public function testValidatedMaintainerReturnsTrueWithoutUserDn(): void
    {
        global $VALIDATED, $IS_ADMIN, $IS_MAINTAINER, $USER_DN, $USER_ID;

        $VALIDATED = true;
        $IS_ADMIN = false;
        $IS_MAINTAINER = true;
        $USER_DN = null;
        $USER_ID = null;

        self::assertFalse(currentUserIsGlobalAdmin());
        self::assertTrue(currentUserIsMaintainer());
    }

    public function testValidatedAdminReturnsTrue(): void
    {
        global $VALIDATED, $IS_ADMIN, $USER_DN, $USER_ID;

        $VALIDATED = true;
        $IS_ADMIN = true;
        $USER_DN = null;
        $USER_ID = null;

        self::assertTrue(currentUserIsGlobalAdmin());
    }

    public function testSetupBuildRoleBasedAclSetIncludesMaintainerStatusGroups(): void
    {
        require_once __DIR__ . '/../www/includes/setup_acl_functions.inc.php';

        $rules = setupBuildRoleBasedAclSet(
            'dc=example,dc=com',
            'organizations',
            'administrators',
            'maintainers',
            'org_admin',
            'memberOrganizations',
            'disabledOrganizations'
        );

        $joined = implode("\n", $rules);
        self::assertStringContainsString(
            'to dn.exact="cn=memberOrganizations,ou=roles,dc=example,dc=com" by group/groupOfNames/member="cn=maintainers,ou=roles,dc=example,dc=com" write',
            $joined
        );
        self::assertStringContainsString(
            'to dn.exact="cn=disabledOrganizations,ou=roles,dc=example,dc=com" by group/groupOfNames/member="cn=maintainers,ou=roles,dc=example,dc=com" write',
            $joined
        );
        // Must not grant write on whole ou=roles
        self::assertStringNotContainsString(
            'to dn.subtree="ou=roles,dc=example,dc=com" by group/groupOfNames/member="cn=maintainers',
            $joined
        );
    }
}
