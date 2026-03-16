<?php

/**
 * Shared setup verification logic: same checks as the web wizard (OUs, system
 * users via role groups, role groups). Used by verify.php and by the CLI
 * verify-and-lock-setup script. Caller must load config and ldap_functions first.
 */

declare(strict_types=1);

/**
 * Determine existence of a "system user" by role-group membership.
 *
 * @param resource|\LDAP\Connection $ldap_connection
 * @param string                   $roles_dn
 * @param string                   $role_cn
 * @return array{0:bool,1:string|null,2:array<string, mixed>|null} [exists, memberDn, memberAttrs]
 */
function getFirstRoleMemberUser($ldap_connection, string $roles_dn, string $role_cn): array
{
    $role_cn_escaped = ldap_escape($role_cn, "", LDAP_ESCAPE_FILTER);
    $group_filter = "(&(objectclass=groupOfNames)(cn={$role_cn_escaped}))";
    $group_search = @ldap_search($ldap_connection, $roles_dn, $group_filter, ['member'], 0, 1);
    if (!$group_search || ldap_count_entries($ldap_connection, $group_search) === 0) {
        return [false, null, null];
    }

    $group_entries = ldap_get_entries($ldap_connection, $group_search);
    if (!isset($group_entries[0]['member']) || $group_entries[0]['member']['count'] < 1) {
        return [false, null, null];
    }

    $member_dn = (string) $group_entries[0]['member'][0];
    $user_read = @ldap_read($ldap_connection, $member_dn, "(objectClass=*)", ['uid', 'cn', 'mail']);
    if (!$user_read || ldap_count_entries($ldap_connection, $user_read) === 0) {
        return [false, $member_dn, null];
    }

    $user_entries = ldap_get_entries($ldap_connection, $user_read);
    $first_entry = $user_entries[0] ?? null;
    if (!is_array($first_entry)) {
        return [true, $member_dn, null];
    }

    /** @var array<string, mixed> $first_entry */
    return [true, $member_dn, $first_entry];
}

/**
 * Run setup verification checks (OUs, admin/maintainer users, role groups).
 * Does not perform Test 4/5 display logic; pass/fail is from missing_components only.
 *
 * @param resource|\LDAP\Connection $ldap_connection
 * @return array{passed:bool,missing_components:array,ou_results:array<string,bool>,admin_exists:bool,admin_member_dn:string|null,admin_attrs:array<string,mixed>|null,maintainer_exists:bool,maintainer_member_dn:string|null,maintainer_attrs:array<string,mixed>|null,admin_role_cn_escaped:string,maintainer_role_cn_escaped:string,admin_group:array|null,maintainer_group:array|null}
 */
function run_setup_verification($ldap_connection): array
{
    global $LDAP;

    $missing_components = [];
    $ou_results = [];
    $ou_tests = [
        $LDAP['org_dn'] => "Organizations OU",
        "ou=people,{$LDAP['base_dn']}" => "People OU",
        $LDAP['roles_dn'] => "Roles OU",
    ];
    foreach ($ou_tests as $ou_dn => $ou_name) {
        $ou_search = ldap_read($ldap_connection, $ou_dn, "(objectClass=*)", ["dn"]);
        $ok = $ou_search && ldap_count_entries($ldap_connection, $ou_search) > 0;
        $ou_results[$ou_name] = $ok;
        if (!$ok) {
            $missing_components[] = 'ou';
        }
    }

    [$admin_exists, $admin_member_dn, $admin_attrs] = getFirstRoleMemberUser(
        $ldap_connection,
        $LDAP['roles_dn'],
        $LDAP['admin_role']
    );
    if (!$admin_exists) {
        $missing_components[] = 'user';
    }

    [$maintainer_exists, $maintainer_member_dn, $maintainer_attrs] = getFirstRoleMemberUser(
        $ldap_connection,
        $LDAP['roles_dn'],
        $LDAP['maintainer_role']
    );

    $admin_role_cn_escaped = ldap_escape($LDAP['admin_role'], "", LDAP_ESCAPE_FILTER);
    $maintainer_role_cn_escaped = ldap_escape($LDAP['maintainer_role'], "", LDAP_ESCAPE_FILTER);
    $role_filters = [
        'administrators' => "(&(objectclass=groupOfNames)(cn={$admin_role_cn_escaped}))",
        'maintainers' => "(&(objectclass=groupOfNames)(cn={$maintainer_role_cn_escaped}))",
    ];
    $admin_group = null;
    $maintainer_group = null;
    foreach ($role_filters as $key => $filter) {
        $role_search = ldap_search($ldap_connection, $LDAP['roles_dn'], $filter);
        $ok = $role_search && ldap_count_entries($ldap_connection, $role_search) > 0;
        if (!$ok) {
            $missing_components[] = 'role';
        }
        if ($key === 'administrators' && $role_search && ldap_count_entries($ldap_connection, $role_search) > 0) {
            $entries = ldap_get_entries($ldap_connection, $role_search);
            $admin_group = is_array($entries[0] ?? null) ? $entries[0] : null;
        }
        if ($key === 'maintainers' && $role_search && ldap_count_entries($ldap_connection, $role_search) > 0) {
            $entries = ldap_get_entries($ldap_connection, $role_search);
            $maintainer_group = is_array($entries[0] ?? null) ? $entries[0] : null;
        }
    }

    return [
        'passed' => empty($missing_components),
        'missing_components' => $missing_components,
        'ou_results' => $ou_results,
        'admin_exists' => $admin_exists,
        'admin_member_dn' => $admin_member_dn,
        'admin_attrs' => $admin_attrs,
        'maintainer_exists' => $maintainer_exists,
        'maintainer_member_dn' => $maintainer_member_dn,
        'maintainer_attrs' => $maintainer_attrs,
        'admin_role_cn_escaped' => $admin_role_cn_escaped,
        'maintainer_role_cn_escaped' => $maintainer_role_cn_escaped,
        'admin_group' => $admin_group,
        'maintainer_group' => $maintainer_group,
    ];
}
