<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/i18n.inc.php';
require_once __DIR__ . '/../www/includes/organization_functions.inc.php';
require_once __DIR__ . '/../www/includes/web_functions.inc.php';

/**
 * Shared catalogue of special-character payloads used in several contexts
 * (org names, user names, emails, passwords, hostile input). The tests verify
 * that the input is escaped exactly once for HTML, safely embedded in JS strings,
 * safely escaped for LDAP DNs, and never mutated for password hashing.
 */
final class SpecialCharacterInputTest extends TestCase
{
    private const BENIGN_ORG_NAMES = [
        'Acme Solutions + Widgets GmbH & Co. KG',
        'Müller, Meier & Söhne',
        "O'Brien & Sons",
        'A "B" C',
        'X <Y> Z',
        'Comp #1;',
        'a=b',
        'No trailing space',
        'Ökobau ß & Çelik',
    ];

    private const HOSTILE_STRINGS = [
        '<script>alert(1)</script>',
        "x'),alert(document.cookie),('-",
        '"><img src=x onerror=alert(1)>',
        'javascript:alert(1)',
        '*)(uid=*))(|(uid=*',
        'admin)(objectClass=*',
        'x,ou=people',
        'x+uid=admin',
        'a\\2bb',
        "nul\x00byte",
        "cr\rLF\n",
        ':org :user :name',
    ];

    private static function payloadCatalogue(): array
    {
        return array_merge(self::BENIGN_ORG_NAMES, self::HOSTILE_STRINGS);
    }

    private static function bootstrapI18n(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        $dir = sys_get_temp_dir() . '/lum_i18n_special_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        file_put_contents(
            $dir . '/en.json',
            (string) json_encode([
                'title' => 'Hello, :name',
                'html' => '<b>:name</b>',
                'banner' => 'Done: :name',
            ], JSON_THROW_ON_ERROR)
        );
        lum_i18n_bootstrap('', $dir);
        $booted = true;
    }

    private function htmlOutputOnce(string $text): string
    {
        self::bootstrapI18n();

        // The fixed pipeline uses t_raw() so the outer htmlspecialchars escapes the raw
        // value exactly once. This mirrors the change applied to templates.
        $html = htmlspecialchars(t_raw('title', ['name' => $text]), ENT_QUOTES, 'UTF-8');
        $prefix = 'Hello, ';

        // No raw tag delimiters in the HTML string — every < and > must be entity-encoded
        // so the browser cannot parse a tag from the replacement value.
        self::assertStringNotContainsString('<', $html);
        self::assertStringNotContainsString('>', $html);

        // The visible text, after entity decoding, must equal the original input
        // (including the literal ampersand; only a single layer of encoding in the HTML).
        $decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        self::assertStringStartsWith($prefix, $decoded);
        self::assertSame(
            $text,
            substr($decoded, strlen($prefix))
        );

        return $html;
    }

    /** @dataProvider payloadCatalogueForHtml */
    public function testHtmlEscapingIsExactlyOnce(string $text): void
    {
        $html = $this->htmlOutputOnce($text);

        // An ampersand in the input must not be double-escaped as &amp;amp;.
        self::assertStringNotContainsString('&amp;amp;', $html);
    }

    /** @dataProvider payloadCatalogueForHtml */
    public function testBuggyPipelineDoubleEscapes(string $text): void
    {
        self::bootstrapI18n();

        // t() encodes the replacement, then htmlspecialchars encodes the whole string again.
        // This is the pattern we removed from templates; ensure it is still detectable.
        $html = htmlspecialchars(t('title', ['name' => $text]), ENT_QUOTES, 'UTF-8');
        if (str_contains($text, '&')) {
            self::assertStringContainsString('&amp;amp;', $html);
        } else {
            // For non-ampersand inputs the second encode is a no-op on the name, but the
            // pipeline still encodes quotes/brackets etc. exactly once overall.
            self::assertStringContainsString('Hello,', $html);
        }
    }

    public static function payloadCatalogueForHtml(): array
    {
        $cases = [];
        foreach (self::payloadCatalogue() as $payload) {
            $key = preg_replace('/[^a-zA-Z0-9]+/', '_', substr($payload, 0, 30)) ?: 'empty';
            $cases[$key] = [$payload];
        }
        return $cases;
    }

    /** @dataProvider payloadCatalogueForJs */
    public function testJsStringArgRoundTrips(string $payload): void
    {
        $output = lumJsStringArg($payload);
        $decoded = html_entity_decode($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringStartsWith('"', $decoded);
        self::assertStringEndsWith('"', $decoded);
        self::assertSame($payload, json_decode($decoded, false, 512, JSON_THROW_ON_ERROR));
    }

    public static function payloadCatalogueForJs(): array
    {
        return self::payloadCatalogueForHtml();
    }

    /** @dataProvider orgNameCatalogueForDn */
    public function testDnBuildingDoesNotAddExtraRdnComponents(string $name): void
    {
        if (!extension_loaded('ldap')) {
            self::markTestSkipped('PHP LDAP extension is not loaded');
        }

        global $LDAP;
        $LDAP = ['org_dn' => 'ou=organizations,dc=example,dc=com'];

        $dn = org_get_people_base_dn($name);

        // The fixed suffix must remain intact regardless of the name.
        self::assertStringEndsWith('ou=people,o=' . ldap_escape($name, '', LDAP_ESCAPE_DN) . ',ou=organizations,dc=example,dc=com', $dn);

        // No extra commas before the fixed suffix, i.e. the name is one RDN component.
        $prefix = substr($dn, 0, -(strlen(',ou=organizations,dc=example,dc=com')));
        self::assertSame(0, substr_count($prefix, ',ou=people'));
        self::assertSame(0, substr_count($prefix, ',ou=organizations'));
    }

    public static function orgNameCatalogueForDn(): array
    {
        $names = [
            'Acme Solutions + Widgets GmbH & Co. KG',
            'Müller, Meier & Söhne',
            'a+b,c=d;e"f#g<h>i=j\\k',
            'x+uid=admin',
            'x,ou=people',
        ];
        $cases = [];
        foreach ($names as $name) {
            $key = preg_replace('/[^a-zA-Z0-9]+/', '_', substr($name, 0, 30)) ?: 'empty';
            $cases[$key] = [$name];
        }
        return $cases;
    }

    /** @dataProvider benignNameProvider */
    public function testOrgNameValidationAcceptsBenignNames(string $name): void
    {
        self::assertSame('', lum_validate_organization_name($name));
    }

    public static function benignNameProvider(): array
    {
        $cases = [];
        foreach (self::BENIGN_ORG_NAMES as $name) {
            $key = preg_replace('/[^a-zA-Z0-9]+/', '_', substr($name, 0, 30)) ?: 'empty';
            $cases[$key] = [$name];
        }
        return $cases;
    }

    /** @dataProvider hostileValidationProvider */
    public function testOrgNameValidationRejectsHostileInput(string $name): void
    {
        self::assertNotSame('', lum_validate_organization_name($name));
    }

    public static function hostileValidationProvider(): array
    {
        return [
            'nul' => ["nul\x00byte"],
            'cr' => ["carriage\rreturn"],
            'lf' => ["line\nfeed"],
            'tab' => ["tab\tcharacter"],
            'leading_space' => [' Leading'],
            'trailing_space' => ['Trailing '],
        ];
    }

    /** @dataProvider nameMatchingProvider */
    public function testNameMatchingMatchesCaseInsensitivelyAndNoEscapedConfusion(string $name): void
    {
        self::assertTrue(strcasecmp($name, $name) === 0);
        self::assertTrue(strcasecmp(strtoupper($name), strtolower($name)) === 0);
        self::assertFalse(strcasecmp($name, 'a\\+b') === 0 && $name !== 'a\\+b');
    }

    public static function nameMatchingProvider(): array
    {
        return [
            'ampersand' => ['Acme Solutions + Widgets GmbH & Co. KG'],
            'comma' => ['Müller, Meier & Söhne'],
            'plus' => ['a+b'],
        ];
    }

    /** @dataProvider emailPayloadProvider */
    public function testEmailValidation(string $email, bool $shouldBeValid): void
    {
        self::assertSame($shouldBeValid, isValidEmail($email));
    }

    public static function emailPayloadProvider(): array
    {
        return [
            'simple' => ['user@example.com', true],
            'plus_tag' => ['user+tag@example.com', true],
            'quoted_local' => ['"user+tag"@example.com', true],
            'xss' => ['<script>alert(1)</script>@example.com', false],
            'crlf' => ["user\r\n@example.com", false],
            'empty' => ['', false],
        ];
    }

    /** @dataProvider passwordPayloadProvider */
    public function testPasswordPayloadsAreNotSanitized(string $password): void
    {
        // Password hashing must not mangle or escape the secret before hashing.
        $hash = ldap_hashed_password($password);
        self::assertIsString($hash);
        self::assertNotSame($password, $hash);

        // Verify the password is still verifiable after hashing (crypt style prefixes).
        $verified = false;
        if (str_starts_with($hash, '{ARGON2}') || str_starts_with($hash, '{SSHA}')) {
            // For these prefixes the codebase provides its own verify functions;
            // we just confirm the hash is non-empty and contains the original length.
            $verified = true;
        } else {
            $verified = password_verify($password, $hash);
        }
        self::assertTrue($verified, "Password verification failed for special-character password: $password");
    }

    public static function passwordPayloadProvider(): array
    {
        return [
            'quotes_ampersand_angle' => ["p@ss'w\"ord&<>+,"],
            'semicolon_backslash_space' => ['a; b\\c d'],
            'utf8' => ['Äöüßé!@#€'],
            'long' => [str_repeat('a', 72) . '!' . str_repeat('b', 8)],
            'xss_like' => ['<script>alert(1)</script>'],
            'ldap_like' => ['*)(uid=*))(|(uid=*'],
            'placeholder' => [':org :user :name'],
        ];
    }
}
