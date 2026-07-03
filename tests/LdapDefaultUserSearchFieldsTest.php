<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/ldap_functions.inc.php';

/**
 * Regression test for the index-gap bug: array_unique() alone preserves original keys,
 * so when the account attribute duplicates one of the hardcoded fields (e.g. the default
 * "mail") the result was not a sequential list. PHP's ldap_search()/ldap_read() read
 * attribute arrays by numeric index and silently fail (no request sent) on such a gap,
 * which made the system users list appear empty by default.
 */
final class LdapDefaultUserSearchFieldsTest extends TestCase
{
    public function testResultIsAlwaysASequentialListEvenWithDuplicates(): void
    {
        $fields = ldap_default_user_search_fields('mail', ['givenname', 'sn', 'cn', 'mail', 'description', 'dn']);

        self::assertTrue(array_is_list($fields), 'Fields array must have no index gaps for ldap_search()/ldap_read()');
        self::assertSame(['mail', 'givenname', 'sn', 'cn', 'description', 'dn'], $fields);
    }

    public function testNoDuplicatesWhenAccountAttributeIsNotAHardcodedField(): void
    {
        $fields = ldap_default_user_search_fields('uid', ['givenname', 'sn', 'cn', 'mail', 'description', 'dn']);

        self::assertTrue(array_is_list($fields));
        self::assertSame(['uid', 'givenname', 'sn', 'cn', 'mail', 'description', 'dn'], $fields);
    }
}
