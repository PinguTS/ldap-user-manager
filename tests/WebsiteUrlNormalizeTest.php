<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

final class WebsiteUrlNormalizeTest extends TestCase
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
            require_once __DIR__ . '/../www/includes/i18n.inc.php';
            require_once __DIR__ . '/../www/includes/web_functions.inc.php';
            self::$booted = true;
        }
    }

    public function testEmptyInputReturnsNull(): void
    {
        self::assertNull(normalizeWebsiteUrl(''));
        self::assertNull(normalizeWebsiteUrl('   '));
    }

    public function testPrependsHttpsWhenSchemeMissing(): void
    {
        self::assertSame('https://example.com', normalizeWebsiteUrl('example.com'));
        self::assertSame('https://www.example.com', normalizeWebsiteUrl('www.example.com'));
        self::assertSame('https://example.com/page', normalizeWebsiteUrl('example.com/page'));
    }

    public function testPreservesExplicitScheme(): void
    {
        self::assertSame('http://example.com', normalizeWebsiteUrl('http://example.com'));
        self::assertSame('https://example.com', normalizeWebsiteUrl('https://example.com'));
    }

    public function testRejectsUnsupportedSchemes(): void
    {
        self::assertFalse(normalizeWebsiteUrl('ftp://example.com'));
        self::assertFalse(normalizeWebsiteUrl('javascript:alert(1)'));
    }

    public function testRejectsWhitespaceAndGarbage(): void
    {
        self::assertFalse(normalizeWebsiteUrl('not a url'));
        self::assertFalse(normalizeWebsiteUrl('example .com'));
    }

    public function testIsValidWebsiteUrl(): void
    {
        self::assertTrue(isValidWebsiteUrl('https://example.com'));
        self::assertTrue(isValidWebsiteUrl('example.com'));
        self::assertFalse(isValidWebsiteUrl(''));
        self::assertFalse(isValidWebsiteUrl(null));
        self::assertFalse(isValidWebsiteUrl('ftp://example.com'));
    }

    public function testSplitWebsiteUrl(): void
    {
        self::assertSame(
            ['scheme' => 'https', 'hostPath' => ''],
            splitWebsiteUrl('')
        );
        self::assertSame(
            ['scheme' => 'https', 'hostPath' => 'example.com/path?q=1#frag'],
            splitWebsiteUrl('https://example.com/path?q=1#frag')
        );
        self::assertSame(
            ['scheme' => 'http', 'hostPath' => 'example.com:8080/foo'],
            splitWebsiteUrl('http://example.com:8080/foo')
        );
    }

    public function testApplyWebsiteUrlNormalization(): void
    {
        $orgData = ['labeledURI' => 'example.com'];
        self::assertNull(applyWebsiteUrlNormalization($orgData));
        self::assertSame('https://example.com', $orgData['labeledURI']);

        $orgData = ['labeledURI' => 'ftp://example.com'];
        self::assertNotNull(applyWebsiteUrlNormalization($orgData));

        $orgData = ['labeledURI' => ''];
        self::assertNull(applyWebsiteUrlNormalization($orgData));
        self::assertSame('', $orgData['labeledURI']);
    }
}
