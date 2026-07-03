<?php

declare(strict_types=1);

namespace LdapUserManager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../www/includes/ldap_functions.inc.php';

/**
 * Regression: when account_attribute=mail (the default), mail is also the sort key.
 * ldap_get_system_users previously skipped the sort key when copying fields, so
 * $people[$account]['mail'] was never set and the Email column stayed blank.
 */
final class LdapMapUserRecordTest extends TestCase
{
    public function testIncludesSortKeyWhenItIsMail(): void
    {
        $record = [
            'dn' => 'mail=admin@example.com,ou=people,dc=example,dc=com',
            'mail' => ['count' => 1, 'admin@example.com'],
            'givenname' => ['count' => 1, 'System'],
            'sn' => ['count' => 1, 'Administrator'],
        ];
        $fields = ['mail', 'givenname', 'sn', 'dn'];

        $mapped = ldap_map_user_record($record, $fields, 'mail');

        self::assertSame('admin@example.com', $mapped['mail']);
        self::assertSame('System', $mapped['givenname']);
        self::assertSame('Administrator', $mapped['sn']);
        self::assertSame($record['dn'], $mapped['dn']);
    }

    public function testIncludesBothUidAndMailWhenSortKeyIsUid(): void
    {
        $record = [
            'dn' => 'uid=jsmith,ou=people,o=acme,dc=example,dc=com',
            'uid' => ['count' => 1, 'jsmith'],
            'mail' => ['count' => 1, 'jsmith@example.com'],
            'sn' => ['count' => 1, 'Smith'],
        ];
        $fields = ['uid', 'mail', 'sn'];

        $mapped = ldap_map_user_record($record, $fields, 'uid');

        self::assertSame('jsmith', $mapped['uid']);
        self::assertSame('jsmith@example.com', $mapped['mail']);
        self::assertSame('Smith', $mapped['sn']);
    }

    public function testCaseInsensitiveAttributeMatch(): void
    {
        $record = [
            'dn' => 'mail=user@example.com,ou=people,dc=example,dc=com',
            'mail' => ['count' => 1, 'user@example.com'],
            'givenName' => ['count' => 1, 'Jane'],
        ];
        $fields = ['mail', 'givenname'];

        $mapped = ldap_map_user_record($record, $fields, 'mail');

        self::assertSame('user@example.com', $mapped['mail']);
        self::assertSame('Jane', $mapped['givenname']);
    }
}
