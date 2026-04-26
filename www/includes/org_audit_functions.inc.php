<?php

declare(strict_types=1);

/**
 * Organization audit functions for reading attribute change history.
 *
 * Primary source: OpenLDAP accesslog overlay (cn=accesslog DB).
 * Fallback: operational attributes modifiersName + modifyTimestamp present on every entry.
 *
 * Enable accesslog reading via environment variable:  LDAP_ACCESSLOG_ENABLED=true
 *
 * The accesslog DB must be configured on the LDAP server and the application bind DN
 * must have read access to cn=accesslog. See docker/openldap/README.md for setup.
 * Call sites that use a user-bound LDAP link should pass a separate admin-bound connection
 * for these queries (see manage/organizations/show — change history uses open_ldap_connection()).
 *
 * Domain layer — snake_case naming (see .cursorrules.md §1.1).
 */

/**
 * Return true when LDAP_ACCESSLOG_ENABLED=true and cn=accesslog is reachable via $conn.
 *
 * @param \LDAP\Connection|resource $conn Active LDAP connection
 */
function is_org_accesslog_available($conn): bool
{
    if (strcasecmp((string) (getenv('LDAP_ACCESSLOG_ENABLED') ?: 'false'), 'true') !== 0) {
        return false;
    }
    $result = @ldap_read($conn, 'cn=accesslog', '(objectClass=*)', ['objectClass'], 0, 1);
    return $result !== false && ldap_count_entries($conn, $result) > 0;
}

/**
 * Parse a GeneralizedTime accesslog timestamp (YYYYMMDDHHiiss.uuuuuuZ) into a Unix timestamp.
 *
 * Returns 0 on parse failure.
 */
function parse_accesslog_timestamp(string $ts): int
{
    if ($ts === '') {
        return 0;
    }
    // Strip microseconds: "20250101120000.000000Z" → "20250101120000Z"
    $normalized = (string) preg_replace('/\.\d+Z$/', 'Z', $ts);
    $dt = \DateTime::createFromFormat('YmdHis\Z', $normalized, new \DateTimeZone('UTC'));
    return $dt !== false ? (int) $dt->getTimestamp() : 0;
}

/**
 * Extract a displayable account identifier from an accesslog reqAuthzID or modifiersName value.
 *
 * reqAuthzID is typically "dn:uid=user@example.com,ou=people,dc=..." or
 * "dn:cn=admin,dc=example,dc=com".  modifiersName is the raw DN without "dn:" prefix.
 *
 * Returns the value of the first RDN (e.g. "user@example.com" or "admin").
 */
function extract_actor_display_name(string $authz_id): string
{
    if ($authz_id === '') {
        return '';
    }
    $dn = str_starts_with($authz_id, 'dn:') ? substr($authz_id, 3) : $authz_id;
    // ldap_explode_dn with with_attrib=1 strips the type prefix (uid=, cn=, …)
    $parts = @ldap_explode_dn($dn, 1);
    if ($parts !== false && isset($parts[0]) && (string) $parts[0] !== '') {
        return (string) $parts[0];
    }
    return $dn;
}

/**
 * Determine the role class of an actor by matching their DN against known role members.
 *
 * Returns 'admin', 'maintainer', or 'org_admin'.
 *
 * @param string   $actor_dn              Full DN of the actor
 * @param string[] $admin_member_dns      Lower-cased DNs in the administrators group
 * @param string[] $maintainer_member_dns Lower-cased DNs in the maintainers group
 */
function classify_actor_role(
    string $actor_dn,
    array $admin_member_dns,
    array $maintainer_member_dns
): string {
    $actor_lower = strtolower($actor_dn);
    global $LDAP;

    // Accesslog entries written via service/admin bind use LDAP_ADMIN_BIND_DN and
    // should never be classified as org_admin.
    $admin_bind_dn = strtolower((string) ($LDAP['admin_bind_dn'] ?? ''));
    if ($admin_bind_dn !== '' && $actor_lower === $admin_bind_dn) {
        return 'admin';
    }

    if (in_array($actor_lower, $admin_member_dns, true)) {
        return 'admin';
    }
    if (in_array($actor_lower, $maintainer_member_dns, true)) {
        return 'maintainer';
    }
    return 'org_admin';
}

/**
 * Fetch all member DNs from a groupOfNames entry, lower-cased for comparison.
 *
 * @param \LDAP\Connection|resource $conn
 * @param string                    $group_dn Full DN of the group entry
 * @return string[]
 */
function get_group_member_dns($conn, string $group_dn): array
{
    $result = @ldap_read($conn, $group_dn, '(objectClass=groupOfNames)', ['member'], 0, 0);
    if ($result === false || ldap_count_entries($conn, $result) === 0) {
        return [];
    }
    $entries = ldap_get_entries($conn, $result);
    if (!isset($entries[0]['member'])) {
        return [];
    }
    $count = (int) ($entries[0]['member']['count'] ?? 0);
    $members = [];
    for ($i = 0; $i < $count; $i++) {
        $members[] = strtolower((string) $entries[0]['member'][$i]);
    }
    return $members;
}

/**
 * Parse reqMod values from accesslog into a deduplicated list of attribute names.
 *
 * Each value looks like "replace: mail\nmail: new@example.com\n-".
 * Only the operation line (first line) is parsed to extract the attribute name.
 *
 * @param string[] $req_mod_values
 * @return string[]
 */
function parse_req_mod_attrs(array $req_mod_values): array
{
    $attrs = [];
    foreach ($req_mod_values as $mod) {
        $lines = explode("\n", (string) $mod);
        if (!isset($lines[0])) {
            continue;
        }
        $first = trim($lines[0]);
        if (preg_match('/^(?:replace|add|delete):\s*(.+)$/i', $first, $m)) {
            $attr = trim($m[1]);
            if ($attr !== '' && !in_array($attr, $attrs, true)) {
                $attrs[] = $attr;
            }
        }
    }
    return $attrs;
}

/**
 * Get organization attribute change history from the OpenLDAP accesslog database.
 *
 * Searches cn=accesslog for successful (reqResult=0) modify operations on $org_dn.
 * Results are returned newest first.
 *
 * Returns an empty array when the accesslog is not reachable or $org_dn has no history.
 *
 * @param \LDAP\Connection|resource $conn
 * @param string                    $org_dn Full DN of the organization entry
 * @param int                       $limit  Maximum number of entries to return
 * @return list<array{timestamp: int, actor_dn: string, actor_display: string, changed_attrs: list<string>}>
 */
function get_org_accesslog_history($conn, string $org_dn, int $limit = 20): array
{
    $safe_dn = ldap_escape($org_dn, '', LDAP_ESCAPE_FILTER);
    $filter  = '(&(reqType=modify)(reqResult=0)(reqDN=' . $safe_dn . '))';
    // Fetch slightly more than limit to allow sorting and slicing
    $result = @ldap_search(
        $conn,
        'cn=accesslog',
        $filter,
        ['reqStart', 'reqAuthzID', 'reqMod'],
        0,
        $limit * 3
    );
    if ($result === false) {
        return [];
    }

    $entries = ldap_get_entries($conn, $result);
    $count   = (int) ($entries['count'] ?? 0);
    $history = [];

    for ($i = 0; $i < $count; $i++) {
        $entry    = $entries[$i];
        $ts_raw   = (string) ($entry['reqstart'][0] ?? '');
        $authz_id = (string) ($entry['reqauthzid'][0] ?? '');

        $req_mod_vals = [];
        if (isset($entry['reqmod']) && is_array($entry['reqmod'])) {
            $mod_count = (int) ($entry['reqmod']['count'] ?? 0);
            for ($j = 0; $j < $mod_count; $j++) {
                $req_mod_vals[] = (string) $entry['reqmod'][$j];
            }
        }

        // Strip "dn:" prefix from reqAuthzID to get the plain DN
        $actor_dn = str_starts_with($authz_id, 'dn:') ? substr($authz_id, 3) : $authz_id;

        $history[] = [
            'timestamp'     => parse_accesslog_timestamp($ts_raw),
            'actor_dn'      => $actor_dn,
            'actor_display' => extract_actor_display_name($authz_id),
            'changed_attrs' => parse_req_mod_attrs($req_mod_vals),
        ];
    }

    // Sort newest first
    usort($history, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

    return array_slice($history, 0, $limit);
}

/**
 * Get the most recent change per role class (admin / maintainer / org_admin) for an org.
 *
 * Pre-fetches up to $max_entries accesslog entries and classifies each actor.
 * Stops scanning once all three role slots are filled.
 *
 * @param \LDAP\Connection|resource $conn
 * @param string                    $org_dn               Full DN of the organization entry
 * @param string[]                  $admin_member_dns     Lower-cased member DNs of the admin group
 * @param string[]                  $maintainer_member_dns Lower-cased member DNs of the maintainer group
 * @param int                       $max_entries          Maximum accesslog entries to scan per org
 * @return array<string, array{timestamp: int, actor_display: string}|null>
 */
function get_org_changes_by_role(
    $conn,
    string $org_dn,
    array $admin_member_dns,
    array $maintainer_member_dns,
    int $max_entries = 50
): array {
    $result = ['admin' => null, 'maintainer' => null, 'org_admin' => null];

    $history = get_org_accesslog_history($conn, $org_dn, $max_entries);
    foreach ($history as $entry) {
        $role = classify_actor_role($entry['actor_dn'], $admin_member_dns, $maintainer_member_dns);
        if ($result[$role] === null) {
            $result[$role] = [
                'timestamp'     => $entry['timestamp'],
                'actor_display' => $entry['actor_display'],
            ];
        }
        if ($result['admin'] !== null && $result['maintainer'] !== null && $result['org_admin'] !== null) {
            break;
        }
    }

    return $result;
}

/**
 * Get a lightweight "last modified" summary from operational attributes on the org entry.
 *
 * Used as fallback when the accesslog is not available, or for list-view performance.
 *
 * @return array{timestamp: int, actor_display: string}|null  Null when no timestamp is available
 */
function get_org_last_change_from_op_attrs(string $modify_timestamp, string $modifiers_name): ?array
{
    $ts = parse_accesslog_timestamp($modify_timestamp);
    if ($ts === 0) {
        return null;
    }
    return [
        'timestamp'     => $ts,
        'actor_display' => extract_actor_display_name($modifiers_name),
    ];
}

/**
 * Format a Unix timestamp as a human-readable relative time string (English).
 *
 * Examples: "just now", "5 minutes ago", "2 hours ago", "3 days ago"
 *
 * Returns an empty string when $timestamp is 0 or negative.
 */
function format_relative_time(int $timestamp): string
{
    if ($timestamp <= 0) {
        return '';
    }
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $mins = (int) round($diff / 60);
        return $mins === 1 ? '1 minute ago' : $mins . ' minutes ago';
    }
    if ($diff < 86400) {
        $hours = (int) round($diff / 3600);
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }
    if ($diff < 2592000) {
        $days = (int) round($diff / 86400);
        return $days === 1 ? '1 day ago' : $days . ' days ago';
    }
    if ($diff < 31536000) {
        $months = (int) round($diff / 2592000);
        return $months === 1 ? '1 month ago' : $months . ' months ago';
    }
    $years = (int) round($diff / 31536000);
    return $years === 1 ? '1 year ago' : $years . ' years ago';
}
