<?php

declare(strict_types=1);

/**
 * OpenLDAP olcAccess checks and optional remedial add for user-bound /manage.
 *
 * Provides two sets of ACLs:
 *   - Baseline: self password write + authenticated read (broad, safe starting point)
 *   - Role-based: group-scoped write for administrators, maintainers, and per-org admins
 *
 * Used from /setup only (admin bind). See docs/ldap/userbind-acls.md
 */

/**
 * DIT database entry in cn=config (default is typical for a single mdb in osixia/openldap).
 */
function setupOlcMdbDn(): string
{
    $fromEnv = trim((string) (getenv('LDAP_OLC_MDB_DN') ?: ''));
    if ($fromEnv !== '') {
        return $fromEnv;
    }

    return 'olcDatabase={1}mdb,cn=config';
}

/**
 * @param resource|\LDAP\Connection $conn
 * @return list<string>
 */
function setupReadMdbOlcAccess($conn, string $mdbDn): array
{
    $res = @ldap_read($conn, $mdbDn, '(objectClass=*)', ['olcAccess'], 0, 0);
    if ($res === false) {
        return [];
    }
    $ents = ldap_get_entries($conn, $res);
    if (empty($ents[0])) {
        return [];
    }
    $e     = $ents[0];
    $lines = [];
    $n     = (int) ($e['olcaccess']['count'] ?? 0);
    for ($i = 0; $i < $n; $i++) {
        if (isset($e['olcaccess'][$i])) {
            $lines[] = (string) $e['olcaccess'][$i];
        }
    }

    return $lines;
}

/**
 * Heuristic: suggest ACLs that allow self-password update and broad read for authenticated clients.
 * Production still needs maintainer/org_admin write rules — see documentation.
 */
function setupOlcSuggestsUserBindSupport(array $olcAccessLines): bool
{
    $joined = strtolower(implode("\n", $olcAccessLines));
    if (str_contains($joined, 'by self') && (str_contains($joined, 'by users read') || str_contains($joined, 'by * read') || str_contains($joined, ' by users '))) {
        return true;
    }
    if (str_contains($joined, ' by users ') && (str_contains($joined, 'write') || str_contains($joined, 'manage'))) {
        return true;
    }

    return false;
}

/**
 * When org_dn is a proper subordinate of base_dn, an explicit olcAccess on that DN can help
 * (some default MDB rules bind less clearly to a subtree; slapd is order / specificity sensitive).
 */
function setupShouldAddExplicitOrgSubtreeUserRead(string $baseDn, string $orgDn): bool
{
    if ($orgDn === '' || $baseDn === '') {
        return false;
    }
    if (strcasecmp($orgDn, $baseDn) === 0) {
        return false;
    }
    if (str_ends_with($orgDn, ',' . $baseDn)) {
        return true;
    }

    return false;
}

/**
 * Idempotent: only adds olcAccess values not already present (string match).
 *
 * @param resource|\LDAP\Connection $conn
 * @param string|null                 $orgDn  Optional: $LDAP['org_dn']; adds explicit subtree=org_dn if under base
 */
function setupApplyUserBindAclsToMdb($conn, string $mdbDn, string $baseDn, ?string $orgDn = null): bool
{
    $existing = setupReadMdbOlcAccess($conn, $mdbDn);
    $bd         = $baseDn;
    $toAdd      = [
        'to attrs=userPassword,shadowLastChange by self write by anonymous auth by * break',
        'to dn.subtree="' . $bd . '" by users read by * break',
    ];
    if ($orgDn !== null && $orgDn !== '' && setupShouldAddExplicitOrgSubtreeUserRead($bd, $orgDn)) {
        $toAdd[] = 'to dn.subtree="' . $orgDn . '" by users read by * break';
    }
    $newVals = [];
    foreach ($toAdd as $cand) {
        $found = false;
        foreach ($existing as $e) {
            if (trim($e) === trim($cand)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $newVals[] = $cand;
        }
    }
    if ($newVals === []) {
        return true;
    }
    if (!@ldap_mod_add($conn, $mdbDn, ['olcAccess' => $newVals])) {
        if (function_exists('auditLog')) {
            $err = is_resource($conn) || (is_object($conn) && $conn instanceof \LDAP\Connection) ? ldap_error($conn) : 'unknown';
            auditLog('WARN', 'setupApplyUserBindAclsToMdb: ldap_mod_add failed', [
                'mdbDn'  => $mdbDn,
                'error'  => $err,
            ]);
        }

        return false;
    }
    if (function_exists('auditLog')) {
        auditLog('INFO', 'setupApplyUserBindAclsToMdb: added olcAccess value(s) for read / self userPassword', [
            'mdbDn'  => $mdbDn,
            'count'  => (string) count($newVals),
        ]);
    }

    return true;
}

/**
 * @param resource|\LDAP\Connection $conn
 * @return array{ok: bool, detail: string, line_count: int, recommended_apply: bool}
 */
function setupVerifyUserBindAcls($conn): array
{
    $mdb   = setupOlcMdbDn();
    $lines = setupReadMdbOlcAccess($conn, $mdb);
    $lc    = count($lines);
    if ($lc === 0) {
        $r = @ldap_read($conn, $mdb, '(objectClass=*)', ['dn'], 0, 1);
        if ($r === false) {
            return [
                'ok'                 => false,
                'detail'             => 'Cannot read ' . $mdb . ' with LDAP_ADMIN_BIND_DN. ACLs may already be applied, but this setup check cannot verify cn=config read access with the current app bind. Set LDAP_OLC_MDB_DN if needed and verify with ldapi EXTERNAL inside the LDAP container.',
                'line_count'         => 0,
                'recommended_apply'  => true,
            ];
        }
    }
    $heur = $lc > 0 && setupOlcSuggestsUserBindSupport($lines);

    return [
        'ok'                 => $heur,
        'detail'             => $heur
            ? 'olcAccess lines present and heuristic match for user read + self (does not prove an end-user can search org_dn; see docs/ldap/userbind-acls.md).'
            : 'Could not confirm olcAccess for authenticated read / self; apply recommended rules or add manually in cn=config (see docs/ldap/userbind-acls.md).',
        'line_count'         => $lc,
        'recommended_apply'  => !$heur,
    ];
}

// ---------------------------------------------------------------------------
// Role-based ACL functions
// ---------------------------------------------------------------------------

/**
 * Build the full set of role-based olcAccess rules for this installation.
 *
 * Rules (in order):
 *   1. userPassword / shadowLastChange — self write, anonymous auth passthrough (by * break)
 *   2. Global admins (administrators group) — manage on entire tree (by * break)
 *   3. System maintainers — write on the organisations subtree (by * break)
 *   4. System maintainers — write on the system people OU (ou=people,base) for creating /
 *      deleting / disabling system users (by * break).
 *      Note: protection of admin-role users from maintainer modification is enforced at the
 *      PHP application layer (canMaintainerDisableUser / currentUserCanDeleteUser), because
 *      OpenLDAP ACLs cannot condition access on the *target entry's* group membership.
 *   5. Org admins per org — write on their specific org subtree via dn.regex + group.expand
 *      (by * break)
 *   6. Self write on own entry (by * break)
 *   7. Authenticated read — fallback for all other access, terminal deny for anonymous
 *
 * @return list<string>
 */
function setupBuildRoleBasedAclSet(
    string $baseDn,
    string $orgOu,
    string $adminRole,
    string $maintainerRole,
    string $orgAdminRole
): array {
    $rolesDn     = 'ou=roles,' . $baseDn;
    $orgBaseDn   = 'ou=' . $orgOu . ',' . $baseDn;
    // System users always live at ou=people,<base_dn> (see config.inc.php $LDAP['people_dn'])
    $peopleDn    = 'ou=people,' . $baseDn;
    // Base DN dots must be escaped for use inside a dn.regex literal
    $escapedBase = str_replace('.', '\\.', $baseDn);

    return [
        // 1. Password self-write + anonymous auth passthrough
        'to attrs=userPassword,shadowLastChange by self write by anonymous auth by * break',
        // 2. Global administrators: full manage on the whole tree
        'to dn.subtree="' . $baseDn . '" by group/groupOfNames/member="cn=' . $adminRole . ',' . $rolesDn . '" manage by * break',
        // 3. System maintainers: write on the organisations subtree (orgs, org roles, org users)
        'to dn.subtree="' . $orgBaseDn . '" by group/groupOfNames/member="cn=' . $maintainerRole . ',' . $rolesDn . '" write by * break',
        // 4. System maintainers: write on the system people OU (create / delete / modify system users)
        //    Admin-user protection is enforced at the PHP layer; LDAP ACLs cannot restrict by
        //    the target entry's group membership.
        'to dn.subtree="' . $peopleDn . '" by group/groupOfNames/member="cn=' . $maintainerRole . ',' . $rolesDn . '" write by * break',
        // 5. Org admins: write on their own org subtree (dn.regex captures org name in $2; group.expand resolves per-org group)
        'to dn.regex="^(.+,)?o=([^,]+),ou=' . $orgOu . ',' . $escapedBase . '$" by group/groupOfNames/member.expand="cn=' . $orgAdminRole . ',ou=roles,o=$2,ou=' . $orgOu . ',' . $baseDn . '" write by * break',
        // 6. Self write on own entry
        'to dn.subtree="' . $baseDn . '" by self write by * break',
        // 7. Authenticated read for all remaining access — terminal deny for anonymous
        'to dn.subtree="' . $baseDn . '" by users read by * none',
    ];
}

/**
 * Returns true when any existing olcAccess rule ends with "by * none".
 *
 * Such rules terminate the ACL chain and silently block any LUM rules appended after them.
 * This is the hallmark of default osixia/openldap installations that have not yet been
 * migrated to the LUM role-based ACL set.
 */
function setupDetectsLegacyBlockingRules(array $existing): bool
{
    foreach ($existing as $e) {
        $normalized = strtolower(trim((string) preg_replace('/^\{\d+\}/', '', $e)));
        if (str_ends_with($normalized, 'by * none')) {
            return true;
        }
    }

    return false;
}

/**
 * Find the UNIX-socket EXTERNAL manage rule among the existing olcAccess values.
 *
 * This rule is added automatically by osixia/openldap (or slapd) and must be preserved
 * when replacing all olcAccess values, otherwise root access via ldapi:/// is lost.
 *
 * Returns the value without the {N} ordering prefix, or null if not found.
 */
function setupFindExternalManageRule(array $existing): ?string
{
    foreach ($existing as $e) {
        $normalized = (string) preg_replace('/^\{\d+\}/', '', trim($e));
        if (
            stripos($normalized, 'cn=external,cn=auth') !== false
            && stripos($normalized, 'manage') !== false
        ) {
            return $normalized;
        }
    }

    return null;
}

/**
 * Returns true if all required LUM rules are present AND appear after the EXTERNAL rule
 * and before any terminal "by * none" rule (i.e., they are actually reachable).
 */
function setupRoleBasedAclsAreReachable(array $existing, array $required): bool
{
    // Strip ordering prefixes from all existing values
    $stripped = array_map(
        static fn(string $e): string => (string) preg_replace('/^\{\d+\}/', '', trim($e)),
        $existing
    );

    // Find the index of the first terminal "by * none" rule (other than the last LUM rule itself)
    $lastLumRule = trim($required[count($required) - 1]);
    $firstBlockIdx = null;
    foreach ($stripped as $idx => $s) {
        if (str_ends_with(strtolower($s), 'by * none') && $s !== $lastLumRule) {
            $firstBlockIdx = $idx;
            break;
        }
    }

    foreach ($required as $rule) {
        $ruleNorm = trim($rule);
        $found    = false;
        foreach ($stripped as $idx => $s) {
            if ($s === $ruleNorm) {
                // Ensure this rule appears BEFORE any legacy blocking rule
                if ($firstBlockIdx !== null && $idx > $firstBlockIdx) {
                    return false; // Rule exists but is after a blocking rule — unreachable
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            return false;
        }
    }

    return true;
}

/**
 * Check which role-based ACL rules are present and which are missing.
 *
 * @return array{
 *   present: list<string>,
 *   missing: list<string>,
 *   all_present: bool,
 *   reachable: bool,
 *   has_legacy_blocking: bool,
 *   can_read_config: bool,
 *   line_count: int
 * }
 */
function setupVerifyRoleBasedAcls($conn): array
{
    global $LDAP;

    $baseDn         = (string) ($LDAP['base_dn'] ?? '');
    $orgOu          = (string) ($LDAP['org_ou'] ?? 'organizations');
    $adminRole      = (string) ($LDAP['admin_role'] ?? 'administrators');
    $maintainerRole = (string) ($LDAP['maintainer_role'] ?? 'maintainers');
    $orgAdminRole   = (string) ($LDAP['org_admin_role'] ?? 'org_admin');

    $mdb      = setupOlcMdbDn();
    $existing = setupReadMdbOlcAccess($conn, $mdb);
    $lc       = count($existing);

    $canRead = $lc > 0 || (@ldap_read($conn, $mdb, '(objectClass=*)', ['dn'], 0, 1) !== false);

    if ($baseDn === '') {
        return [
            'present'             => [],
            'missing'             => [],
            'all_present'         => false,
            'reachable'           => false,
            'has_legacy_blocking' => false,
            'can_read_config'     => $canRead,
            'line_count'          => $lc,
        ];
    }

    $required = setupBuildRoleBasedAclSet($baseDn, $orgOu, $adminRole, $maintainerRole, $orgAdminRole);
    $present  = [];
    $missing  = [];

    foreach ($required as $rule) {
        $found = false;
        foreach ($existing as $e) {
            // Strip slapd numeric ordering prefix e.g. "{3}" before comparing
            $normalized = preg_replace('/^\{\d+\}/', '', trim($e));
            if ($normalized === trim($rule)) {
                $found = true;
                break;
            }
        }
        if ($found) {
            $present[] = $rule;
        } else {
            $missing[] = $rule;
        }
    }

    $hasLegacyBlocking = setupDetectsLegacyBlockingRules($existing);
    $reachable         = $missing === [] && setupRoleBasedAclsAreReachable($existing, $required);

    return [
        'present'             => $present,
        'missing'             => $missing,
        'all_present'         => $missing === [],
        'reachable'           => $reachable,
        'has_legacy_blocking' => $hasLegacyBlocking,
        'can_read_config'     => $canRead,
        'line_count'          => $lc,
    ];
}

/**
 * Idempotent: apply the complete role-based olcAccess rule set.
 *
 * Strategy:
 *   - If all rules are already present and reachable (not blocked by legacy rules): no-op.
 *   - If legacy blocking rules are detected OR some rules already exist in a partial/wrong
 *     state: use replace: olcAccess with [EXTERNAL rule] + [6 LUM rules] atomically.
 *   - If no blocking rules and no partial state: add only the missing rules.
 *
 * @param resource|\LDAP\Connection $conn
 * @return bool true when all rules already correct or successfully applied; false on LDAP error
 */
function setupApplyRoleBasedAclsToMdb($conn, string $mdbDn): bool
{
    global $LDAP;

    $baseDn         = (string) ($LDAP['base_dn'] ?? '');
    $orgOu          = (string) ($LDAP['org_ou'] ?? 'organizations');
    $adminRole      = (string) ($LDAP['admin_role'] ?? 'administrators');
    $maintainerRole = (string) ($LDAP['maintainer_role'] ?? 'maintainers');
    $orgAdminRole   = (string) ($LDAP['org_admin_role'] ?? 'org_admin');

    if ($baseDn === '') {
        return false;
    }

    $required = setupBuildRoleBasedAclSet($baseDn, $orgOu, $adminRole, $maintainerRole, $orgAdminRole);
    $existing = setupReadMdbOlcAccess($conn, $mdbDn);

    // Determine which required rules are currently missing
    $missing = [];
    foreach ($required as $rule) {
        $found = false;
        foreach ($existing as $e) {
            $normalized = preg_replace('/^\{\d+\}/', '', trim($e));
            if ($normalized === trim($rule)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missing[] = $rule;
        }
    }

    $hasLegacyBlocking = setupDetectsLegacyBlockingRules($existing);
    $hasPartialRules   = $missing !== [] && count($missing) < count($required);
    $allReachable      = $missing === [] && setupRoleBasedAclsAreReachable($existing, $required);

    // Nothing to do
    if ($allReachable) {
        return true;
    }

    if ($hasLegacyBlocking || $hasPartialRules) {
        // Blocking rules or a partial state means a simple add would either fail (duplicate) or
        // produce unreachable rules. Replace the entire olcAccess attribute atomically:
        //   [EXTERNAL manage rule (preserved from current config)] + [6 LUM rules]
        $externalRule = setupFindExternalManageRule($existing);
        $replacement  = $externalRule !== null ? [$externalRule] : [];
        foreach ($required as $rule) {
            $replacement[] = $rule;
        }

        if (!@ldap_mod_replace($conn, $mdbDn, ['olcAccess' => $replacement])) {
            if (function_exists('auditLog')) {
                $err = is_resource($conn) || (is_object($conn) && $conn instanceof \LDAP\Connection) ? ldap_error($conn) : 'unknown';
                auditLog('WARN', 'setupApplyRoleBasedAclsToMdb: ldap_mod_replace failed', [
                    'mdbDn' => $mdbDn,
                    'error' => $err,
                ]);
            }

            return false;
        }

        if (function_exists('auditLog')) {
            auditLog('INFO', 'setupApplyRoleBasedAclsToMdb: replaced olcAccess with role-based ACL set (legacy blocking rules removed)', [
                'mdbDn'              => $mdbDn,
                'had_external_rule'  => $externalRule !== null ? 'yes' : 'no',
                'rules_count'        => (string) count($replacement),
            ]);
        }

        return true;
    }

    // Clean state: no blocking rules, no partial state — just add the missing ones
    if (!@ldap_mod_add($conn, $mdbDn, ['olcAccess' => $missing])) {
        if (function_exists('auditLog')) {
            $err = is_resource($conn) || (is_object($conn) && $conn instanceof \LDAP\Connection) ? ldap_error($conn) : 'unknown';
            auditLog('WARN', 'setupApplyRoleBasedAclsToMdb: ldap_mod_add failed', [
                'mdbDn' => $mdbDn,
                'error' => $err,
            ]);
        }

        return false;
    }

    if (function_exists('auditLog')) {
        auditLog('INFO', 'setupApplyRoleBasedAclsToMdb: added role-based olcAccess rule(s)', [
            'mdbDn' => $mdbDn,
            'count' => (string) count($missing),
        ]);
    }

    return true;
}

/**
 * Build a complete ldapmodify LDIF block for the role-based ACL rules.
 *
 * Uses "replace: olcAccess" (not multiple "add:") to handle two common failure cases:
 *   1. Some LUM rules were already added — "add" would fail with "value already exists".
 *   2. Legacy osixia rules ending with "by * none" block appended rules from being reached.
 *
 * The LDIF preserves the standard osixia/openldap UNIX-socket EXTERNAL manage rule.
 * If your instance has a customised rule at position {0}, adjust the first olcAccess line.
 *
 * @return string ready-to-paste LDIF content
 */
function setupBuildRoleBasedAclLdif(string $mdbDn): string
{
    global $LDAP;

    $baseDn         = (string) ($LDAP['base_dn'] ?? '');
    $orgOu          = (string) ($LDAP['org_ou'] ?? 'organizations');
    $adminRole      = (string) ($LDAP['admin_role'] ?? 'administrators');
    $maintainerRole = (string) ($LDAP['maintainer_role'] ?? 'maintainers');
    $orgAdminRole   = (string) ($LDAP['org_admin_role'] ?? 'org_admin');

    if ($baseDn === '') {
        return '# LDAP_BASE_DN is not set — cannot generate LDIF.';
    }

    $rules = setupBuildRoleBasedAclSet($baseDn, $orgOu, $adminRole, $maintainerRole, $orgAdminRole);

    $lines   = [];
    $lines[] = '# This LDIF replaces ALL existing olcAccess rules with the LUM role-based set.';
    $lines[] = '# The first olcAccess line preserves the standard osixia/openldap UNIX-socket';
    $lines[] = '# EXTERNAL manage rule. If your instance uses a different rule at {0}, adjust it.';
    $lines[] = '#';
    $lines[] = '# cn=admin,' . $baseDn . ' is the OpenLDAP rootDN and bypasses ACLs entirely;';
    $lines[] = '# it does not need an explicit grant in these rules.';
    $lines[] = 'dn: ' . $mdbDn;
    $lines[] = 'changetype: modify';
    $lines[] = 'replace: olcAccess';
    $lines[] = 'olcAccess: to * by dn.exact=gidNumber=0+uidNumber=0,cn=peercred,cn=external,cn=auth manage by * break';

    foreach ($rules as $rule) {
        $lines[] = 'olcAccess: ' . $rule;
    }

    return implode("\n", $lines) . "\n";
}
