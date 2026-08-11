<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Navbar falls back to $USER_ID (entryUUID) when lum_display_name is empty.
 * setPasskeyCookie must not wipe a known display name (e.g. after password change).
 */
final class SessionDisplayNameTest extends TestCase
{
    private static bool $booted = false;

    /** @var list<string> */
    private array $sessionFiles = [];

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
            require_once __DIR__ . '/../www/includes/i18n.inc.php';
            require_once __DIR__ . '/../www/includes/web_functions.inc.php';
            self::$booted = true;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        global $SESSION_SAVE_PATH, $SESSION_TIMEOUT, $USER_DISPLAY_NAME;
        $SESSION_SAVE_PATH = sys_get_temp_dir();
        $SESSION_TIMEOUT = 10;
        $USER_DISPLAY_NAME = null;

        unset(
            $_SESSION['lum_user_id'],
            $_SESSION['lum_is_admin'],
            $_SESSION['lum_is_maintainer'],
            $_SESSION['lum_is_org_admin'],
            $_SESSION['lum_org_name'],
            $_SESSION['lum_org_uuid'],
            $_SESSION['lum_display_name'],
            $_SESSION['lum_expiry']
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->sessionFiles as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
        $this->sessionFiles = [];
        unset($_SESSION['lum_display_name']);
        $GLOBALS['USER_DISPLAY_NAME'] = null;
    }

    public function testSetLumSessionAuthStoresDisplayName(): void
    {
        setLumSessionAuth('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', false, false, false, null, null, 'Alice Example');

        self::assertSame('Alice Example', $_SESSION['lum_display_name']);
    }

    public function testSetPasskeyCookiePreservesExistingSessionDisplayName(): void
    {
        $_SESSION['lum_display_name'] = 'Alice Example';
        $userId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        setPasskeyCookie($userId, false, false, false, null, null, null);
        $this->sessionFiles[] = getSessionFilePath(hash('sha256', $userId));

        self::assertSame('Alice Example', $_SESSION['lum_display_name']);
    }

    public function testSetPasskeyCookieUsesGlobalDisplayNameWhenSessionEmpty(): void
    {
        $GLOBALS['USER_DISPLAY_NAME'] = 'From Global';
        $userId = '11111111-2222-3333-4444-555555555555';

        setPasskeyCookie($userId, false, false, false, null, null, null);
        $this->sessionFiles[] = getSessionFilePath(hash('sha256', $userId));

        self::assertSame('From Global', $_SESSION['lum_display_name']);
    }
}
