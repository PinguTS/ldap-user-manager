<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/ldap_functions.inc.php';

final class LdapGetEntryByUuidEscapeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('ldap')) {
            self::markTestSkipped('PHP LDAP extension is not loaded');
        }
    }

    public function testLumFilterValueEscapesFilterMetacharacters(): void
    {
        self::assertSame(
            '\\28objectClass=\\2a\\29',
            lum_filter_value('(objectClass=*)')
        );
    }
}
