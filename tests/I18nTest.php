<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/i18n.inc.php';

final class I18nTest extends TestCase
{
    public function testParseAcceptLanguagePrimaryAndQ(): void
    {
        $order = lum_parse_accept_language('de-DE,de;q=0.9,en;q=0.8');
        self::assertSame(['de', 'en'], $order);
    }

    public function testParseAcceptLanguageHigherQWinsSamePrimary(): void
    {
        $order = lum_parse_accept_language('en;q=0.5, de;q=0.8');
        self::assertSame(['de', 'en'], $order);
    }

    public function testParseAcceptLanguageEmpty(): void
    {
        self::assertSame([], lum_parse_accept_language(''));
        self::assertSame([], lum_parse_accept_language('   '));
    }

    public function testPickLocaleFallsBackToEnglish(): void
    {
        self::assertSame('en', lum_i18n_pick_locale(['fr', 'ja'], ['en', 'de']));
    }

    public function testPickLocaleFirstMatch(): void
    {
        self::assertSame('de', lum_i18n_pick_locale(['de', 'en'], ['en', 'de']));
        self::assertSame('en', lum_i18n_pick_locale(['en', 'de'], ['en', 'de']));
    }

    public function testBootstrapMergesOverlayOntoEnglish(): void
    {
        $dir = sys_get_temp_dir() . '/lum_i18n_test_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700, true));
        try {
            file_put_contents(
                $dir . '/en.json',
                (string) json_encode(['a' => 'one', 'b' => 'two'], JSON_THROW_ON_ERROR)
            );
            file_put_contents(
                $dir . '/de.json',
                (string) json_encode(['b' => 'zwei'], JSON_THROW_ON_ERROR)
            );
            lum_i18n_bootstrap('de', $dir);
            self::assertSame('de', lum_current_locale());
            self::assertSame('one', t('a'));
            self::assertSame('zwei', t('b'));
        } finally {
            @unlink($dir . '/en.json');
            @unlink($dir . '/de.json');
            @rmdir($dir);
        }
    }

    public function testBootstrapEmptyAcceptUsesEnglishStrings(): void
    {
        $dir = sys_get_temp_dir() . '/lum_i18n_test_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700, true));
        try {
            file_put_contents(
                $dir . '/en.json',
                (string) json_encode(['x' => 'english'], JSON_THROW_ON_ERROR)
            );
            file_put_contents(
                $dir . '/de.json',
                (string) json_encode(['x' => 'deutsch'], JSON_THROW_ON_ERROR)
            );
            lum_i18n_bootstrap('', $dir);
            self::assertSame('en', lum_current_locale());
            self::assertSame('english', t('x'));
        } finally {
            @unlink($dir . '/en.json');
            @unlink($dir . '/de.json');
            @rmdir($dir);
        }
    }

    public function testPlaceholderReplacement(): void
    {
        $dir = sys_get_temp_dir() . '/lum_i18n_test_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700, true));
        try {
            file_put_contents(
                $dir . '/en.json',
                (string) json_encode(['greet' => 'Hello, :name'], JSON_THROW_ON_ERROR)
            );
            lum_i18n_bootstrap('', $dir);
            self::assertSame('Hello, Pat', t('greet', ['name' => 'Pat']));
        } finally {
            @unlink($dir . '/en.json');
            @rmdir($dir);
        }
    }
}
