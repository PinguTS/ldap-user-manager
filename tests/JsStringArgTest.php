<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/web_functions.inc.php';

/**
 * Tests for lumJsStringArg(), which encodes a string as a JavaScript literal
 * inside an HTML attribute. The output must be a valid JSON string after the
 * browser entity-decodes the attribute, and it must not break out of the JS
 * string context regardless of the input.
 */
final class JsStringArgTest extends TestCase
{
    private function assertJsStringArgRoundTrips(string $input): void
    {
        $output = lumJsStringArg($input);

        // After HTML entity decoding, the content must be a valid JSON string.
        $decoded = html_entity_decode($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertStringStartsWith('"', $decoded);
        self::assertStringEndsWith('"', $decoded);

        $roundTripped = json_decode($decoded, false, 512, JSON_THROW_ON_ERROR);
        self::assertSame($input, $roundTripped);

        // No raw HTML-attribute delimiters that would let the value escape the attribute.
        self::assertStringNotContainsString('"', $output);
        self::assertStringNotContainsString("'", $output);
        self::assertStringNotContainsString('<', $output);
        self::assertStringNotContainsString('>', $output);
    }

    public function testPlainString(): void
    {
        $this->assertJsStringArgRoundTrips('plain');
    }

    public function testEmptyString(): void
    {
        $this->assertJsStringArgRoundTrips('');
    }

    public function testAmpersand(): void
    {
        $this->assertJsStringArgRoundTrips('A & B');
    }

    public function testDoubleQuotes(): void
    {
        $this->assertJsStringArgRoundTrips('say "hello"');
    }

    public function testApostrophe(): void
    {
        $this->assertJsStringArgRoundTrips("O'Brien");
    }

    public function testBackslash(): void
    {
        $this->assertJsStringArgRoundTrips('a\\b');
    }

    public function testXssPayloadScriptTag(): void
    {
        $this->assertJsStringArgRoundTrips('<script>alert(1)</script>');
    }

    public function testXssPayloadBreakout(): void
    {
        $this->assertJsStringArgRoundTrips("x'),alert(document.cookie),('");
    }

    public function testXssPayloadImgOnerror(): void
    {
        $this->assertJsStringArgRoundTrips('"><img src=x onerror=alert(1)>');
    }

    public function testLdapFilterInjection(): void
    {
        $this->assertJsStringArgRoundTrips('*)(uid=*))(|(uid=*');
    }

    public function testDnSpecialCharacters(): void
    {
        $this->assertJsStringArgRoundTrips('a+b,c=d;e"f#g<h>i=j\\k');
    }

    public function testUtf8(): void
    {
        $this->assertJsStringArgRoundTrips('Ökobau ß & Çelik GmbH');
    }

    public function testNulByte(): void
    {
        $this->assertJsStringArgRoundTrips("contains\x00nul");
    }

    public function testPlaceholderCollision(): void
    {
        $this->assertJsStringArgRoundTrips(':org :user :name');
    }
}
