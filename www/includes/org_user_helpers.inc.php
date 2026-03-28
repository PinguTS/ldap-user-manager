<?php

declare(strict_types=1);

/**
 * Organization-scoped user helpers (LDAP): manager groups, DN resolution, UUID handling.
 */

/**
 * Whether a string looks like an LDAP entryUUID.
 */
function org_user_identifier_is_uuid(string $userIdentifier): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userIdentifier) === 1;
}

/**
 * Base DN for users under an organization (ou=people,o=…,org_dn).
 */
function org_get_people_base_dn(string $orgName): string
{
    global $LDAP;
    $orgRdn = ldap_escape($orgName, '', LDAP_ESCAPE_DN);

    return "ou=people,o={$orgRdn}," . $LDAP['org_dn'];
}

/**
 * Member DNs of the organization admin (manager) group.
 *
 * @return array<int, string>
 */
function org_get_manager_dns(string $orgName): array
{
    global $LDAP;

    $ldapConnection = open_ldap_connection();
    if ($ldapConnection === false) {
        return [];
    }
    $orgRdn = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDn = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRdn}," . $LDAP['org_dn'];
    $result = @ldap_read($ldapConnection, $groupDn, '(objectClass=groupOfNames)', ['member']);
    if (!$result) {
        ldap_close($ldapConnection);

        return [];
    }

    $entries = ldap_get_entries($ldapConnection, $result);
    ldap_close($ldapConnection);
    $dns = [];
    if ($entries['count'] > 0 && isset($entries[0]['member'])) {
        for ($i = 0; $i < $entries[0]['member']['count']; $i++) {
            $dns[] = $entries[0]['member'][$i];
        }
    }

    return $dns;
}

/**
 * Case-insensitive DN membership check.
 */
function org_dn_in_list(string $dn, array $dns): bool
{
    foreach ($dns as $entryDn) {
        if (is_string($entryDn) && strcasecmp($dn, $entryDn) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Count org managers whose DN lives under this org’s ou=people (excludes placeholders).
 */
function org_get_scoped_manager_count(string $orgName, array $orgManagerDns): int
{
    $orgUsersBaseDn = org_get_people_base_dn($orgName);

    return count(array_filter($orgManagerDns, function ($dn) use ($orgUsersBaseDn) {
        if (!is_string($dn) || stripos($dn, 'cn=placeholder') !== false) {
            return false;
        }

        return str_ends_with(strtolower($dn), strtolower($orgUsersBaseDn));
    }));
}

/**
 * Resolve a user identifier (UUID or uid) to a DN within the org’s people OU.
 */
function org_resolve_user_dn(string $orgName, string $userIdentifier): ?string
{
    global $LDAP;

    if ($userIdentifier === '') {
        return null;
    }

    if (org_user_identifier_is_uuid($userIdentifier)) {
        $ldapConnection = open_ldap_connection();
        if ($ldapConnection === false) {
            return null;
        }
        $usersDn = org_get_people_base_dn($orgName);
        $userByUuid = ldap_get_entry_by_uuid($ldapConnection, $userIdentifier, $usersDn);
        ldap_close($ldapConnection);
        if (!$userByUuid || !isset($userByUuid['dn'])) {
            return null;
        }

        return (string) $userByUuid['dn'];
    }

    $usersDn = org_get_people_base_dn($orgName);

    return 'uid=' . ldap_escape($userIdentifier, '', LDAP_ESCAPE_DN) . ',' . $usersDn;
}

/**
 * LDAP user entry for an org user (UUID or uid), or null if not found.
 *
 * @return array<string, mixed>|null
 */
function org_resolve_user_entry(string $orgName, string $userIdentifier): ?array
{
    if ($userIdentifier === '') {
        return null;
    }

    if (org_user_identifier_is_uuid($userIdentifier)) {
        $ldapConnection = open_ldap_connection();
        if ($ldapConnection === false) {
            return null;
        }
        $usersDn = org_get_people_base_dn($orgName);
        $userByUuid = ldap_get_entry_by_uuid($ldapConnection, $userIdentifier, $usersDn);
        ldap_close($ldapConnection);

        if (!$userByUuid || !isset($userByUuid['dn'])) {
            return null;
        }

        return $userByUuid;
    }

    $ldapConnection = open_ldap_connection();
    if ($ldapConnection === false) {
        return null;
    }
    $dn = org_resolve_user_dn($orgName, $userIdentifier);
    if ($dn === null || $dn === '') {
        ldap_close($ldapConnection);

        return null;
    }
    $read = @ldap_read($ldapConnection, $dn, '(objectClass=*)');
    if (!$read) {
        ldap_close($ldapConnection);

        return null;
    }
    $entries = ldap_get_entries($ldapConnection, $read);
    ldap_close($ldapConnection);
    if (!is_array($entries) || ($entries['count'] ?? 0) < 1) {
        return null;
    }

    $row = $entries[0];

    return is_array($row) ? $row : null;
}

/**
 * Prefer LDAP uid for display/fallback messages; falls back to $fallback.
 */
function org_get_user_display(string $userDn, string $fallback): string
{
    $ldapConnection = open_ldap_connection();
    if ($ldapConnection === false) {
        return $fallback;
    }

    $read = @ldap_read($ldapConnection, $userDn, '(objectClass=*)', ['uid']);
    if (!$read) {
        ldap_close($ldapConnection);

        return $fallback;
    }

    $entries = ldap_get_entries($ldapConnection, $read);
    ldap_close($ldapConnection);

    if (is_array($entries) && ($entries['count'] ?? 0) > 0 && isset($entries[0]['uid'][0])) {
        return (string) $entries[0]['uid'][0];
    }

    return $fallback;
}
