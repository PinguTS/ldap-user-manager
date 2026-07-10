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

    public function testLocalePlaceholdersMatchEnglish(): void
    {
        $dir = __DIR__ . '/../www/locales';
        $en = json_decode((string) file_get_contents($dir . '/en.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($en);

        $pattern = '/:([a-zA-Z_][a-zA-Z0-9_]*)/';
        $extract = static function (string $value) use ($pattern): array {
            preg_match_all($pattern, $value, $matches);
            $names = $matches[1];
            sort($names);

            return $names;
        };

        foreach (glob($dir . '/*.json') ?: [] as $path) {
            if (str_ends_with($path, '/en.json')) {
                continue;
            }
            $locale = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($locale);
            $code = basename($path, '.json');

            foreach ($en as $key => $enValue) {
                self::assertArrayHasKey($key, $locale, $code . ' missing key ' . $key);
                self::assertSame(
                    $extract((string) $enValue),
                    $extract((string) $locale[$key]),
                    $code . ' placeholder mismatch for ' . $key
                );
            }
        }
    }

    public function testResolveLocalePrefersExplicitOverride(): void
    {
        $available = ['en', 'de', 'fr'];
        $resolved = lum_i18n_resolve_locale($available, 'fr', 'de', 'en-US,en;q=0.9');
        self::assertSame('fr', $resolved);
    }

    public function testResolveLocaleFallsBackToPersistedPreference(): void
    {
        $available = ['en', 'de', 'fr'];
        $resolved = lum_i18n_resolve_locale($available, null, 'de', 'fr-CH,fr;q=0.8');
        self::assertSame('de', $resolved);
    }

    public function testResolveLocaleFallsBackToAcceptLanguage(): void
    {
        $available = ['en', 'de', 'fr'];
        $resolved = lum_i18n_resolve_locale($available, null, null, 'fr-CH,fr;q=0.8,en;q=0.6');
        self::assertSame('fr', $resolved);
    }

    public function testResolveLocaleInvalidValuesUseFallback(): void
    {
        $available = ['en', 'de'];
        $resolved = lum_i18n_resolve_locale($available, '../../etc/passwd', 'xx', 'de-DE,de;q=0.9');
        self::assertSame('de', $resolved);
    }

    public function testLocaleOptionsUseAvailableLocalesOnly(): void
    {
        $options = lum_i18n_locale_options(['en', 'de']);
        self::assertCount(2, $options);
        self::assertSame('en', $options[0]['code']);
        self::assertSame('English', $options[0]['native']);
        self::assertSame('en.svg', $options[0]['flag']);
        self::assertSame('de', $options[1]['code']);
        self::assertSame('Deutsch', $options[1]['native']);
        self::assertSame('de.svg', $options[1]['flag']);
    }

    public function testApplyI18nFieldLabelsTranslatesOrgAndAttributeMaps(): void
    {
        global $LDAP;

        $dir = sys_get_temp_dir() . '/lum_i18n_test_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($dir, 0700, true));
        try {
            file_put_contents(
                $dir . '/en.json',
                (string) json_encode([
                    'manage.fields.org_name' => 'Org Name EN',
                    'manage.fields.fax_number' => 'Fax EN',
                    'manage.common.first_name' => 'First EN',
                    'manage.common.display_name' => 'Display EN',
                    'manage.roles.label.admin' => 'Admin EN',
                    'manage.users.msg.cannot_delete_self' => 'Self delete EN',
                ], JSON_THROW_ON_ERROR)
            );
            lum_i18n_bootstrap('', $dir);

            $LDAP = [
                'account_attribute' => 'mail',
                'org_field_labels' => [
                    'org_name' => 'Organization Name',
                    'org_fax' => 'Fax number',
                ],
                'default_attribute_map' => [
                    'givenname' => ['label' => 'First name'],
                    'cn' => ['label' => 'Common name'],
                ],
                'role_display_labels' => [
                    'admin_role' => 'System Administrator',
                ],
                'error_messages' => [
                    'cannot_delete_self' => 'You cannot delete your own account',
                ],
            ];

            lum_apply_i18n_field_labels();

            self::assertSame('Org Name EN', $LDAP['org_field_labels']['org_name']);
            self::assertSame('Fax EN', $LDAP['org_field_labels']['org_fax']);
            self::assertSame('First EN', $LDAP['default_attribute_map']['givenname']['label']);
            self::assertSame('Display EN', $LDAP['default_attribute_map']['cn']['label']);
            self::assertSame('Admin EN', $LDAP['role_display_labels']['admin_role']);
            self::assertSame('Self delete EN', $LDAP['error_messages']['cannot_delete_self']);
        } finally {
            @unlink($dir . '/en.json');
            @rmdir($dir);
        }
    }
}
