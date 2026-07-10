<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

if (!function_exists('renderHeader')) {
    function renderHeader(string $title, bool $menu = true): void
    {
    }

    function renderFooter(): void
    {
    }
}

putenv('APP_ENV=development');
putenv('LDAP_URI=ldap://localhost');
putenv('LDAP_BASE_DN=dc=example,dc=com');
putenv('LDAP_ADMIN_BIND_DN=cn=admin,dc=example,dc=com');
putenv('LDAP_ADMIN_BIND_PWD=xK9mP2vL7nQ4wR8sT1uY3zA');
putenv('APP_HTTP_HOST=localhost');

require_once __DIR__ . '/../www/includes/organization_functions.inc.php';

final class CountryOptionsTest extends TestCase
{
    /** @var mixed */
    private $savedAllowedCountries;

    protected function setUp(): void
    {
        global $LDAP;

        $this->savedAllowedCountries = $LDAP['org_allowed_countries'] ?? null;
    }

    protected function tearDown(): void
    {
        global $LDAP;

        $LDAP['org_allowed_countries'] = $this->savedAllowedCountries;
    }

    public function testCatalogContainsCommonTerritories(): void
    {
        $catalog = getCountryCatalog();

        foreach (['TW', 'HK', 'MO', 'PR'] as $code) {
            self::assertArrayHasKey($code, $catalog, "Expected {$code} in country catalog");
            self::assertNotSame('', $catalog[$code]);
        }
    }

    public function testCountryOptionsReturnsFullCatalogWhenAllowlistUnset(): void
    {
        global $LDAP;

        $LDAP['org_allowed_countries'] = null;

        self::assertSame(getCountryCatalog(), getCountryOptions());
    }

    public function testCountryOptionsFiltersByAllowlist(): void
    {
        global $LDAP;

        $LDAP['org_allowed_countries'] = ['DE', 'AT'];

        $options = getCountryOptions();

        self::assertCount(2, $options);
        self::assertArrayHasKey('DE', $options);
        self::assertArrayHasKey('AT', $options);
        self::assertArrayNotHasKey('FR', $options);
    }

    public function testCountryOptionsIgnoresUnknownAllowlistCodes(): void
    {
        global $LDAP;

        $LDAP['org_allowed_countries'] = ['DE', 'ZZ', 'AT'];

        $options = getCountryOptions();

        self::assertCount(2, $options);
        self::assertArrayHasKey('DE', $options);
        self::assertArrayHasKey('AT', $options);
    }

    public function testLocalizedCountryNameReturnsNonEmptyForTaiwan(): void
    {
        self::assertNotSame('', getLocalizedCountryName('TW'));
        self::assertNotSame('', getLocalizedCountryName('tw'));
    }

    public function testLocalizedCountryNameResolvesCatalogEntryOutsideAllowlist(): void
    {
        global $LDAP;

        $LDAP['org_allowed_countries'] = ['DE'];

        self::assertSame('Taiwan', getLocalizedCountryName('TW'));
    }

    public function testLocalizedCountryOptionsSortedByDisplayName(): void
    {
        global $LDAP;

        $LDAP['org_allowed_countries'] = ['DE', 'AT', 'CH'];

        $options = getLocalizedCountryOptions();
        $labels = array_values($options);
        $sorted = $labels;
        usort($sorted, static fn (string $a, string $b): int => strcasecmp($a, $b));

        self::assertSame($sorted, $labels);
    }
}
