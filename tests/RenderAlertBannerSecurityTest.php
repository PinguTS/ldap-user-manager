<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

final class RenderAlertBannerSecurityTest extends TestCase
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
            require_once __DIR__ . '/../www/includes/web_functions.inc.php';
            self::$booted = true;
        }
    }

    public function testEscapesHtmlInMessageAndAlertClass(): void
    {
        ob_start();
        renderAlertBanner('<script>alert(1)</script>', 'success"><img src=x onerror=alert(1)');
        $output = (string) ob_get_clean();

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
        self::assertStringContainsString('alert-success', $output);
        self::assertStringNotContainsString('onerror=alert', $output);
    }
}
