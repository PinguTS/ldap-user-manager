<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

final class PostLoginRedirectTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        if (!self::$booted) {
            $_SERVER['HTTPS'] ??= 'off';
            $_SERVER['HTTP_HOST'] ??= 'localhost';
            $_SERVER['REQUEST_URI'] ??= '/manage/users/';
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
            self::$booted = true;
        }

        $this->resetAuthGlobals();
    }

    private function resetAuthGlobals(): void
    {
        global $VALIDATED, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $IS_SETUP_ADMIN;
        global $USER_ORG_NAME, $USER_ORG_UUID, $SESSION_TIMED_OUT;

        $VALIDATED = false;
        $IS_ADMIN = false;
        $IS_MAINTAINER = false;
        $IS_ORG_ADMIN = false;
        $IS_SETUP_ADMIN = false;
        $USER_ORG_NAME = null;
        $USER_ORG_UUID = null;
        $SESSION_TIMED_OUT = false;
    }

    private function setRoleGlobals(
        bool $validated,
        bool $isAdmin = false,
        bool $isMaintainer = false,
        bool $isOrgAdmin = false,
        bool $isSetupAdmin = false,
        ?string $orgName = null,
        ?string $orgUuid = null
    ): void {
        global $VALIDATED, $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $IS_SETUP_ADMIN;
        global $USER_ORG_NAME, $USER_ORG_UUID;

        $VALIDATED = $validated;
        $IS_ADMIN = $isAdmin;
        $IS_MAINTAINER = $isMaintainer;
        $IS_ORG_ADMIN = $isOrgAdmin;
        $IS_SETUP_ADMIN = $isSetupAdmin;
        $USER_ORG_NAME = $orgName;
        $USER_ORG_UUID = $orgUuid;
    }

    public function testUnauthenticatedUserRedirectIncludesReturnUrl(): void
    {
        $_SERVER['REQUEST_URI'] = '/manage/users/550e8400-e29b-41d4-a716-446655440000/';

        $url = getDefaultRedirectForUser();

        self::assertStringStartsWith(getBaseUrl() . 'login/?unauthorised&redirect_to=', $url);
        self::assertStringContainsString(
            rawurlencode(base64_encode('/manage/users/550e8400-e29b-41d4-a716-446655440000/')),
            $url
        );
    }

    public function testSystemAdminDefaultIsManageOverview(): void
    {
        $this->setRoleGlobals(true, isAdmin: true);

        self::assertSame('manage/', getRoleDefaultRedirectPath());
        self::assertSame(getBaseUrl() . 'manage/', getDefaultRedirectForUser());
    }

    public function testMaintainerDefaultIsOrganizationsOverview(): void
    {
        $this->setRoleGlobals(true, isMaintainer: true);

        self::assertSame('manage/organizations/', getRoleDefaultRedirectPath());
        self::assertSame(getBaseUrl() . 'manage/organizations/', getDefaultRedirectForUser());
    }

    public function testOrgAdminDefaultUsesUuidPath(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $this->setRoleGlobals(true, isOrgAdmin: true, orgName: 'Acme', orgUuid: $uuid);

        self::assertSame('manage/organizations/' . rawurlencode($uuid) . '/', getRoleDefaultRedirectPath());
    }

    public function testOrgAdminWithoutUuidFallsBackToLegacyOrgShowUrl(): void
    {
        $this->setRoleGlobals(true, isOrgAdmin: true, orgName: 'Acme');

        self::assertSame(
            'manage/organizations/show/index.php?org=Acme',
            getRoleDefaultRedirectPath()
        );
    }

    public function testOrgAdminWithoutOrgInfoFallsBackToPasswordChange(): void
    {
        $this->setRoleGlobals(true, isOrgAdmin: true);

        self::assertSame('password/change/', getRoleDefaultRedirectPath());
    }

    public function testRegularUserDefaultIsPasswordChange(): void
    {
        $this->setRoleGlobals(true);

        self::assertSame('password/change/', getRoleDefaultRedirectPath());
    }

    public function testValidateRedirectUrlRejectsPasswordResetAndLoginPaths(): void
    {
        foreach ([
            '/password/reset/',
            '/password/set/?token=abc',
            '/login/',
            '/login/?unauthorised',
        ] as $path) {
            self::assertFalse(
                validateRedirectUrl(base64_encode($path)),
                "expected non-restorable path to be rejected: $path"
            );
        }
    }

    public function testValidateRedirectUrlAcceptsManageDeepLink(): void
    {
        $path = '/manage/users/550e8400-e29b-41d4-a716-446655440000/';

        self::assertSame($path, validateRedirectUrl(base64_encode($path)));
    }

    /**
     * Static guard: login must honor redirect_to before role defaults via shared helper.
     */
    public function testLoginUsesSharedRoleDefaultHelper(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../www/login/index.php');

        self::assertStringContainsString('validateRedirectUrl($_POST[\'redirect_to\'])', $source);
        self::assertStringContainsString('getRoleDefaultRedirectPath()', $source);
        self::assertStringNotContainsString('manage/users/index.php', $source);
    }
}
