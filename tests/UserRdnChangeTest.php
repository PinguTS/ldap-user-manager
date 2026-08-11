<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/ldap_functions.inc.php';

/**
 * Tests for lum_pending_rdn_change(), the helper that decides whether an
 * attribute update would change the naming (RDN) attribute of a user DN.
 */
final class UserRdnChangeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('ldap')) {
            self::markTestSkipped('PHP LDAP extension is not loaded');
        }
    }

    public function testDetectsRdnChange(): void
    {
        $dn = 'mail=old@example.com,ou=people,dc=example,dc=com';

        $change = lum_pending_rdn_change($dn, ['mail' => 'new@example.com']);

        self::assertNotNull($change);
        self::assertSame('mail', $change['attr']);
        self::assertSame('new@example.com', $change['new_value']);
        self::assertSame('mail=new@example.com', $change['new_rdn']);
        self::assertSame('ou=people,dc=example,dc=com', $change['parent_dn']);
    }

    public function testReturnsNullWhenRdnAttributeUnchanged(): void
    {
        $dn = 'mail=old@example.com,ou=people,dc=example,dc=com';

        self::assertNull(lum_pending_rdn_change($dn, ['mail' => 'old@example.com']));
    }

    public function testReturnsNullWhenRdnAttributeNotInModifications(): void
    {
        $dn = 'uid=jdoe,ou=people,dc=example,dc=com';

        self::assertNull(lum_pending_rdn_change($dn, ['cn' => 'John Doe']));
    }

    public function testEscapesSpecialCharactersInNewRdnValue(): void
    {
        $dn = 'mail=old@example.com,ou=people,dc=example,dc=com';
        $new = 'a+b@c=,d.example.com';

        $change = lum_pending_rdn_change($dn, ['mail' => $new]);

        self::assertNotNull($change);
        self::assertStringContainsString('mail=', $change['new_rdn']);
        self::assertStringNotContainsString(',', $change['new_rdn']);
        self::assertSame($change['parent_dn'], 'ou=people,dc=example,dc=com');
    }

    public function testWorksForOrganizationUserDn(): void
    {
        $dn = 'mail=old@example.com,ou=people,o=ACME,ou=organizations,dc=example,dc=com';

        $change = lum_pending_rdn_change($dn, ['mail' => 'new@example.com']);

        self::assertNotNull($change);
        self::assertSame('mail=new@example.com', $change['new_rdn']);
        self::assertSame('ou=people,o=ACME,ou=organizations,dc=example,dc=com', $change['parent_dn']);
    }

    public function testReturnsNullForEmptyNewValue(): void
    {
        $dn = 'mail=old@example.com,ou=people,dc=example,dc=com';

        self::assertNull(lum_pending_rdn_change($dn, ['mail' => '']));
    }

    public function testCaseInsensitiveAttributeMatch(): void
    {
        $dn = 'mail=old@example.com,ou=people,dc=example,dc=com';

        $change = lum_pending_rdn_change($dn, ['MAIL' => 'new@example.com']);

        self::assertNotNull($change);
        self::assertSame('mail', $change['attr']);
    }
}
