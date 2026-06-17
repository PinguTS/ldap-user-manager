<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

final class FlashTest extends TestCase
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

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SESSION['lum_flash']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['lum_flash']);
    }

    public function testSetFlashStoresPayload(): void
    {
        setFlash('created', 'success', 5000);

        self::assertSame(
            ['message' => 'created', 'type' => 'success', 'timeout' => 5000],
            $_SESSION['lum_flash']
        );
    }

    public function testRenderFlashConsumesAndClears(): void
    {
        setFlash('done', 'warning', 3000);

        ob_start();
        renderFlash();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('done', $output);
        self::assertStringContainsString('alert-warning', $output);
        self::assertArrayNotHasKey('lum_flash', $_SESSION);
    }

    public function testRenderFlashIsNoOpWhenEmpty(): void
    {
        ob_start();
        renderFlash();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }
}
