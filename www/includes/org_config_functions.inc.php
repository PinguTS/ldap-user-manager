<?php

declare(strict_types=1);

include_once __DIR__ . '/ldap_functions.inc.php';
include_once __DIR__ . '/config.inc.php';

/**
 * Per-organization configuration storage without schema extensions.
 *
 * Layout:
 * - ou=config,o=<Org>,<org_dn>
 * - cn=userLimit,ou=config,o=<Org>,<org_dn> (objectClass: applicationProcess)
 *   - description: integer as string (e.g. "50")
 */

function ldap_org_config_ou_dn(string $orgName): string
{
    global $LDAP;

    $orgRdn = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    return 'ou=config,o=' . $orgRdn . ',' . $LDAP['org_dn'];
}

function ldap_org_user_limit_dn(string $orgName): string
{
    return 'cn=userLimit,' . ldap_org_config_ou_dn($orgName);
}

function ldap_org_ensure_config_ou($ldap, string $orgName): bool
{
    $ouDn = ldap_org_config_ou_dn($orgName);
    $exists = @ldap_read($ldap, $ouDn, '(objectClass=*)', ['dn']);
    if ($exists !== false) {
        return true;
    }

    $entry = [
        'objectClass' => ['top', 'organizationalUnit'],
        'ou' => 'config',
        'description' => 'Organization configuration entries',
    ];

    return @ldap_add($ldap, $ouDn, $entry);
}

/**
 * Get the configured max allowed user accounts for an organization.
 *
 * @return int|null Null means unlimited / not set
 */
function ldap_org_get_user_limit($ldap, string $orgName): ?int
{
    $limitDn = ldap_org_user_limit_dn($orgName);
    $read = @ldap_read($ldap, $limitDn, '(objectClass=*)', ['description']);
    if ($read === false) {
        return null;
    }

    $entries = ldap_get_entries($ldap, $read);
    if (!is_array($entries) || ($entries['count'] ?? 0) < 1) {
        return null;
    }

    $raw = (string) (($entries[0]['description'][0] ?? '') ?: '');
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (!ctype_digit($raw)) {
        return null;
    }

    $val = (int) $raw;
    return $val > 0 ? $val : null;
}

/**
 * Set the max allowed user accounts for an organization.
 *
 * @param int|null $limit Null (or <=0) will remove the setting (unlimited)
 */
function ldap_org_set_user_limit($ldap, string $orgName, ?int $limit): bool
{
    $limit = ($limit !== null && $limit > 0) ? $limit : null;

    if (!ldap_org_ensure_config_ou($ldap, $orgName)) {
        return false;
    }

    $limitDn = ldap_org_user_limit_dn($orgName);

    if ($limit === null) {
        // Remove entry if present; treat "not found" as success.
        $exists = @ldap_read($ldap, $limitDn, '(objectClass=*)', ['dn']);
        if ($exists === false) {
            return true;
        }
        return @ldap_delete($ldap, $limitDn);
    }

    $exists = @ldap_read($ldap, $limitDn, '(objectClass=*)', ['dn']);
    if ($exists === false) {
        $entry = [
            'objectClass' => ['top', 'applicationProcess'],
            'cn' => 'userLimit',
            'description' => (string) $limit,
        ];
        return @ldap_add($ldap, $limitDn, $entry);
    }

    return @ldap_modify($ldap, $limitDn, ['description' => (string) $limit]);
}

/**
 * Count user accounts in an organization.
 */
function ldap_org_count_users($ldap, string $orgName): int
{
    global $LDAP;

    $usersDn = 'ou=people,o=' . ldap_escape($orgName, '', LDAP_ESCAPE_DN) . ',' . $LDAP['org_dn'];
    $search = @ldap_search($ldap, $usersDn, '(objectClass=inetOrgPerson)', ['dn']);
    if ($search === false) {
        return 0;
    }
    $count = ldap_count_entries($ldap, $search);
    return is_int($count) && $count > 0 ? $count : 0;
}
