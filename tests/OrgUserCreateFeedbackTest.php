<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Org user create must report LDAP and SMTP outcomes independently.
 */
final class OrgUserCreateFeedbackTest extends TestCase
{
    private static function addPhpSource(): string
    {
        return (string) file_get_contents(__DIR__ . '/../www/manage/organizations/users/add.php');
    }

    public function testLdapCreateIsEvaluatedBeforeEmailNotification(): void
    {
        $source = self::addPhpSource();
        $ldapPos = strpos($source, '$ldapCreated = ldap_new_account');
        $emailPos = strpos($source, 'emailAttempted');

        self::assertNotFalse($ldapPos, 'expected explicit ldapCreated assignment');
        self::assertNotFalse($emailPos, 'expected explicit emailAttempted guard');
        self::assertLessThan($emailPos, $ldapPos, 'LDAP create must run before email notification');
    }

    public function testLdapConnectionClosedBeforeEmailPhase(): void
    {
        $source = self::addPhpSource();
        $closePos = strpos($source, 'lum_close_ldap_if_not_manage($ldap_connection);');
        $emailPos = strpos($source, '$emailAttempted');

        self::assertNotFalse($closePos);
        self::assertNotFalse($emailPos);
        self::assertLessThan($emailPos, $closePos, 'LDAP connection should close before email work');
    }

    public function testEmailFailuresUseDedicatedMessageNotLdapCreateFailed(): void
    {
        $source = self::addPhpSource();

        self::assertStringContainsString("t('manage.org_users.add.msg.email_send_failed')", $source);
        self::assertStringContainsString('catch (Throwable $emailException)', $source);
        self::assertStringNotContainsString(
            "t('manage.org_users.add.msg.create_failed')",
            (string) preg_replace('/renderAlertBanner\(t\(\'manage\.org_users\.add\.msg\.create_failed\'\)[\s\S]*$/', '', $source),
            'create_failed must not appear in the LDAP-success/email branch'
        );
    }

    public function testLdapFailureBannerOnlyOnNonCreatedAccount(): void
    {
        $source = self::addPhpSource();

        self::assertMatchesRegularExpression(
            '/if \(\$ldapCreated\) \{[\s\S]*setFlash\([\s\S]*exit\(0\);[\s\S]*\}[\s\S]*renderAlertBanner\(t\(\'manage\.org_users\.add\.msg\.create_failed\'\)/',
            $source
        );
    }

    public function testOrgAdminRoleUsesExplicitAddUserToOrgAdminCall(): void
    {
        $source = self::addPhpSource();

        self::assertStringContainsString('$user_role === $LDAP[\'org_admin_role\']', $source);
        self::assertStringContainsString('addUserToOrgAdmin($org_name, $userEntryDn)', $source);
        self::assertStringContainsString('!$orgAdminAdd[0]', $source);
    }
}
