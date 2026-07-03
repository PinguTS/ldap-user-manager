<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

// email_locale.inc.php pulls in config.inc.php, which exits fatally if these are unset.
putenv('LDAP_URI=ldap://localhost:389');
putenv('LDAP_BASE_DN=dc=example,dc=com');
putenv('LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com');
putenv('LDAP_ADMIN_BIND_PWD=test');
putenv('APP_HTTP_HOST=localhost');

require_once __DIR__ . '/../www/includes/email_locale.inc.php';

/**
 * Regression test for the maintainer role-name mismatch: the new-user form and
 * createUserAccount() used the literal string 'maintainer', while the actual LDAP
 * group/role is $LDAP['maintainer_role'] (default 'maintainers'), so a maintainer was
 * never placed in the correct group and was never recognized as a maintainer. Fixed by
 * using $LDAP['maintainer_role'] everywhere instead of a hardcoded literal.
 */
final class MaintainerRoleConsistencyTest extends TestCase
{
    public function testEmailSystemAccountRoleListUsesConfiguredMaintainerRole(): void
    {
        global $LDAP;

        putenv('EMAIL_SYSTEM_ACCOUNT_ROLES');
        $LDAP['admin_role'] = 'administrators';
        $LDAP['maintainer_role'] = 'custom_maintainers';

        $roles = lum_email_system_account_role_list();

        self::assertContains('custom_maintainers', $roles);
        self::assertNotContains('maintainer', $roles, 'must not fall back to the hardcoded literal role name');
    }

    /**
     * Static guard: neither file may reintroduce the literal 'maintainer' role value —
     * both must derive it from $LDAP['maintainer_role'] so the form value always matches
     * the group createUserAccount()/createSystemUser() write to and access_functions.inc.php
     * reads from.
     */
    public function testSourceDoesNotHardcodeMaintainerLiteral(): void
    {
        foreach (['new.php' => '../www/manage/users/new.php', 'organization_functions.inc.php' => '../www/includes/organization_functions.inc.php'] as $label => $relPath) {
            $source = (string) file_get_contents(__DIR__ . '/' . $relPath);
            self::assertStringNotContainsString("'maintainer'", $source, "$label must not hardcode the literal role name 'maintainer'");
        }
    }
}
