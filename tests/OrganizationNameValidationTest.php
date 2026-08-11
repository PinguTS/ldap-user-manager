<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/i18n.inc.php';
require_once __DIR__ . '/../www/includes/organization_functions.inc.php';

/**
 * Tests for lum_validate_organization_name(), the trust-boundary validation
 * for organization names. Must allow real company names while rejecting content
 * that causes DN or UX issues.
 */
final class OrganizationNameValidationTest extends TestCase
{
    /** @dataProvider validNameProvider */
    public function testValidNamesAreAccepted(string $name): void
    {
        self::assertSame('', lum_validate_organization_name($name));
    }

    public static function validNameProvider(): array
    {
        return [
            'plain' => ['Example Inc'],
            'ampersand' => ['Acme Solutions + Widgets GmbH & Co. KG'],
            'comma' => ['Müller, Meier & Söhne'],
            'apostrophe' => ["O'Brien & Sons"],
            'double_quotes' => ['A "B" C'],
            'dn_specials' => ['a+b,c=d;e"f#g<h>i=j\\k'],
            'hash' => ['Comp #1'],
            'semicolon' => ['A; B'],
            'equals' => ['a=b'],
            'trailing_content' => ['Name with trailing content'],
            'utf8_no_leading_space' => ['Ökobau ß & Çelik'],
            'quoted' => ['"Quoted" Name'],
            'plus' => ['Schmitt + Sohn'],
        ];
    }

    /** @dataProvider invalidNameProvider */
    public function testInvalidNamesAreRejected(string $name, string $expectedKey): void
    {
        $error = lum_validate_organization_name($name);
        self::assertNotSame('', $error);
        self::assertSame($expectedKey, $error);
    }

    public static function invalidNameProvider(): array
    {
        return [
            'empty' => ['', 'manage.orgs.add.msg.required_org_name_missing'],
            'only_whitespace' => ['   ', 'manage.orgs.add.msg.invalid_org_name_whitespace'],
            'leading_space' => [' Leading', 'manage.orgs.add.msg.invalid_org_name_whitespace'],
            'trailing_space' => ['Trailing ', 'manage.orgs.add.msg.invalid_org_name_whitespace'],
            'nul' => ["nul\x00byte", 'manage.orgs.add.msg.invalid_org_name_control_chars'],
            'cr' => ["carriage\rreturn", 'manage.orgs.add.msg.invalid_org_name_control_chars'],
            'lf' => ["line\nfeed", 'manage.orgs.add.msg.invalid_org_name_control_chars'],
            'tab' => ["tab\tcharacter", 'manage.orgs.add.msg.invalid_org_name_control_chars'],
            'form_feed' => ["form\x0cfeed", 'manage.orgs.add.msg.invalid_org_name_control_chars'],
            'backspace' => ["back\x08space", 'manage.orgs.add.msg.invalid_org_name_control_chars'],
            'del' => ["del\x7fchar", 'manage.orgs.add.msg.invalid_org_name_control_chars'],
            'too_long' => [str_repeat('a', 129), 'manage.orgs.add.msg.invalid_org_name_too_long'],
        ];
    }

    public function testBoundaryLengthIsAccepted(): void
    {
        self::assertSame('', lum_validate_organization_name(str_repeat('a', 128)));
    }
}
