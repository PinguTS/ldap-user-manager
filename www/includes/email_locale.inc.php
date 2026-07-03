<?php

/**
 * Recipient-based locale for transactional HTML emails (not the admin's UI language).
 *
 * Precedence: user LDAP locale attr → org LDAP locale attr → country map →
 * EMAIL_DEFAULT_LOCALE → (visitor session locale only if visitor is recipient) → en.
 *
 * System/service accounts (see EMAIL_SYSTEM_ACCOUNT_ROLES): EMAIL_DEFAULT_LOCALE → en only.
 */

declare(strict_types=1);

include_once __DIR__ . '/i18n.inc.php';
include_once __DIR__ . '/config.inc.php';

/**
 * @return list<string>
 */
function lum_email_transactional_available_locales(): array
{
    return lum_i18n_discover_locales(__DIR__ . '/../locales');
}

/**
 * EMAIL_DEFAULT_LOCALE when set and valid, else empty string.
 */
function lum_email_valid_default_locale_from_env(): string
{
    global $log_prefix;

    $envRaw = getenv('EMAIL_DEFAULT_LOCALE');
    if ($envRaw === false || trim($envRaw) === '') {
        return '';
    }
    $available = lum_email_transactional_available_locales();
    $cand = lum_i18n_normalize_locale(trim($envRaw));
    if ($cand !== '' && lum_i18n_is_available_locale($cand, $available)) {
        return $cand;
    }

    $prefix = is_string($log_prefix ?? null) ? $log_prefix : '';
    error_log("{$prefix}EMAIL_DEFAULT_LOCALE ignored (unknown or invalid): " . trim($envRaw), 0);

    return '';
}

/**
 * Installation default for system-account email: EMAIL_DEFAULT_LOCALE if valid, else en.
 */
function lum_installation_email_locale(): string
{
    $fromEnv = lum_email_valid_default_locale_from_env();

    return $fromEnv !== '' ? $fromEnv : 'en';
}

/**
 * LDAP attribute on users for preferred locale (e.g. preferredLanguage). Empty string = disabled.
 */
function lum_email_user_locale_ldap_attr(): string
{
    $v = getenv('EMAIL_USER_LOCALE_LDAP_ATTR');
    if ($v === false) {
        return 'preferredLanguage';
    }

    return trim($v);
}

/**
 * LDAP attribute on organization entries for default email locale. Empty = not used.
 */
function lum_email_org_locale_ldap_attr(): string
{
    $v = getenv('EMAIL_ORG_LOCALE_LDAP_ATTR');
    if ($v === false || trim($v) === '') {
        return '';
    }

    return trim($v);
}

/**
 * Roles (LDAP description value) that receive installation-locale email only.
 * When unset, defaults to global admin role name and maintainer.
 *
 * @return list<string>
 */
function lum_email_system_account_role_list(): array
{
    global $LDAP;
    $raw = getenv('EMAIL_SYSTEM_ACCOUNT_ROLES');
    if ($raw !== false && trim($raw) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    return array_values(array_filter([
        (string) ($LDAP['admin_role'] ?? ''),
        (string) ($LDAP['maintainer_role'] ?? ''),
    ]));
}

function lum_email_is_system_account_role(string $role): bool
{
    $role = strtolower(trim($role));
    if ($role === '') {
        return false;
    }
    foreach (lum_email_system_account_role_list() as $r) {
        if (strtolower(trim((string) $r)) === $role) {
            return true;
        }
    }

    return false;
}

/**
 * Parse EMAIL_COUNTRY_LOCALE_MAP: JSON object {"DE":"de"} or comma-separated DE=de,FR=fr.
 *
 * @return array<string, string> uppercase ISO country => locale
 */
function lum_email_parse_country_locale_map(): array
{
    $raw = getenv('EMAIL_COUNTRY_LOCALE_MAP');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $raw = trim($raw);
    if ($raw !== '' && $raw[0] === '{') {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $cc = strtoupper(trim($k));
                if (preg_match('/^[A-Z]{2}$/', $cc) !== 1) {
                    continue;
                }
                $loc = lum_i18n_normalize_locale(trim($v));
                if ($loc !== '') {
                    $out[$cc] = $loc;
                }
            }
        }

        return $out;
    }

    $out = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^([A-Za-z]{2})\s*[=:]\s*([a-z]{2}(-[a-z0-9]+)?)$/i', $part, $m)) {
            $cc = strtoupper($m[1]);
            $loc = lum_i18n_normalize_locale($m[2]);
            if ($loc !== '') {
                $out[$cc] = $loc;
            }
        }
    }

    return $out;
}

function lum_email_country_to_locale(string $countryIso2Upper): string
{
    $cc = strtoupper(trim($countryIso2Upper));
    if (preg_match('/^[A-Z]{2}$/', $cc) !== 1) {
        return '';
    }
    $map = lum_email_parse_country_locale_map();

    return isset($map[$cc]) ? lum_i18n_normalize_locale($map[$cc]) : '';
}

/**
 * Country segment from postalAddress: forms use Street$ZIP$City$State$Country (5 parts);
 * legacy may use Street$ZIP$City$Country (4 parts).
 */
function lum_org_country_from_postal_address(string $postal): string
{
    if ($postal === '') {
        return '';
    }
    $parts = explode('$', $postal);
    $n = count($parts);
    if ($n >= 5) {
        return strtoupper(trim($parts[4]));
    }
    if ($n >= 4) {
        return strtoupper(trim($parts[3]));
    }

    return '';
}

/**
 * @param array<string, mixed> $row ldap_get_entries single row
 */
function lum_ldap_row_attr_first(array $row, string $attr): string
{
    $lk = strtolower($attr);
    if (isset($row[$lk]) && is_array($row[$lk]) && isset($row[$lk][0])) {
        return trim((string) $row[$lk][0]);
    }

    return '';
}

/**
 * @param array<string, mixed> $row ldap_get_entries single row
 */
function lum_ldap_user_description_role(array $row): string
{
    return lum_ldap_row_attr_first($row, 'description');
}

/**
 * @param array<string, mixed> $row ldap_get_entries single row
 */
function lum_ldap_row_first_org_name(array $row): string
{
    foreach (['organization', 'o'] as $attr) {
        $v = lum_ldap_row_attr_first($row, $attr);
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

/**
 * @param resource|\LDAP\Connection $ldap
 * @return array{default_locale: string, country: string}
 */
function lum_ldap_fetch_org_email_hints($ldap, string $orgName): array
{
    global $LDAP;
    $orgName = trim($orgName);
    if ($orgName === '') {
        return ['default_locale' => '', 'country' => ''];
    }

    $orgLocaleAttr = lum_email_org_locale_ldap_attr();
    $attrs = ['postaladdress'];
    if ($orgLocaleAttr !== '') {
        $attrs[] = $orgLocaleAttr;
    }
    $attrs = array_values(array_unique($attrs));

    $filter = '(&(objectClass=organization)(o=' . ldap_escape($orgName, '', LDAP_ESCAPE_FILTER) . '))';
    $search = @ldap_search($ldap, $LDAP['org_dn'], $filter, $attrs);
    if (!$search) {
        return ['default_locale' => '', 'country' => ''];
    }
    $entries = ldap_get_entries($ldap, $search);
    if (!is_array($entries) || ($entries['count'] ?? 0) < 1 || !is_array($entries[0] ?? null)) {
        return ['default_locale' => '', 'country' => ''];
    }
    $e = $entries[0];
    $localeRaw = $orgLocaleAttr !== '' ? lum_ldap_row_attr_first($e, $orgLocaleAttr) : '';
    $localeNorm = $localeRaw !== '' ? lum_i18n_normalize_locale($localeRaw) : '';
    $postal = lum_ldap_row_attr_first($e, 'postaladdress');

    return [
        'default_locale' => $localeNorm,
        'country' => lum_org_country_from_postal_address($postal),
    ];
}

/**
 * @param array<string, mixed> $userRow ldap_get_entries single row
 */
function lum_resolve_transactional_email_locale_from_ldap_user_row(array $userRow, bool $visitorIsRecipient): string
{
    $role = lum_ldap_user_description_role($userRow);
    if (lum_email_is_system_account_role($role)) {
        return lum_installation_email_locale();
    }

    $available = lum_email_transactional_available_locales();

    $userAttr = lum_email_user_locale_ldap_attr();
    if ($userAttr !== '') {
        $prefRaw = lum_ldap_row_attr_first($userRow, $userAttr);
        $pref = lum_i18n_normalize_locale($prefRaw);
        if ($pref !== '' && lum_i18n_is_available_locale($pref, $available)) {
            return $pref;
        }
    }

    $orgName = lum_ldap_row_first_org_name($userRow);
    $orgDefault = '';
    $country = '';
    if ($orgName !== '' && function_exists('open_ldap_connection')) {
        $ldap = open_ldap_connection();
        if ($ldap !== false) {
            $hints = lum_ldap_fetch_org_email_hints($ldap, $orgName);
            ldap_close($ldap);
            $orgDefault = $hints['default_locale'];
            $country = $hints['country'];
        }
    }

    return lum_resolve_email_locale_chain_tail($orgDefault, $country, $visitorIsRecipient, $available);
}

/**
 * New org user (no LDAP row yet): org hints only + tail.
 */
function lum_resolve_transactional_email_locale_for_new_org_user(string $orgName, string $userRole): string
{
    if (lum_email_is_system_account_role($userRole)) {
        return lum_installation_email_locale();
    }

    $available = lum_email_transactional_available_locales();
    $orgDefault = '';
    $country = '';
    if (trim($orgName) !== '' && function_exists('open_ldap_connection')) {
        $ldap = open_ldap_connection();
        if ($ldap !== false) {
            $hints = lum_ldap_fetch_org_email_hints($ldap, $orgName);
            ldap_close($ldap);
            $orgDefault = $hints['default_locale'];
            $country = $hints['country'];
        }
    }

    return lum_resolve_email_locale_chain_tail($orgDefault, $country, false, $available);
}

/**
 * System user invite (manage/users/new): always installation locale for those roles.
 */
function lum_resolve_transactional_email_locale_for_system_user_invite(string $userRole): string
{
    if (lum_email_is_system_account_role($userRole)) {
        return lum_installation_email_locale();
    }

    return lum_resolve_email_locale_chain_tail('', '', false, lum_email_transactional_available_locales());
}

/**
 * Org default locale (normalized) and ISO country; then map, env, visitor, en.
 *
 * @param list<string>|null $available
 */
function lum_resolve_email_locale_chain_tail(
    string $orgDefaultLocaleNorm,
    string $countryIso2,
    bool $visitorIsRecipient,
    ?array $available = null
): string {
    $available ??= lum_email_transactional_available_locales();

    if ($orgDefaultLocaleNorm !== '' && lum_i18n_is_available_locale($orgDefaultLocaleNorm, $available)) {
        return $orgDefaultLocaleNorm;
    }

    $mapped = lum_email_country_to_locale($countryIso2);
    if ($mapped !== '' && lum_i18n_is_available_locale($mapped, $available)) {
        return $mapped;
    }

    $envLoc = lum_email_valid_default_locale_from_env();
    if ($envLoc !== '') {
        return $envLoc;
    }

    if ($visitorIsRecipient && function_exists('lum_current_locale')) {
        $cur = lum_current_locale();
        if (lum_i18n_is_available_locale($cur, $available)) {
            return $cur;
        }
    }

    return 'en';
}

function lum_push_transactional_email_locale(string $locale): void
{
    if (!isset($GLOBALS['lum_transactional_email_locale_stack']) || !is_array($GLOBALS['lum_transactional_email_locale_stack'])) {
        $GLOBALS['lum_transactional_email_locale_stack'] = [];
    }
    $available = lum_email_transactional_available_locales();
    $normalized = lum_i18n_normalize_locale($locale);
    if ($normalized === '' || !lum_i18n_is_available_locale($normalized, $available)) {
        $normalized = 'en';
    }
    $GLOBALS['lum_transactional_email_locale_stack'][] = $normalized;
}

function lum_pop_transactional_email_locale(): void
{
    $stack = &$GLOBALS['lum_transactional_email_locale_stack'];
    if (is_array($stack) && count($stack) > 0) {
        array_pop($stack);
    }
}

/**
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function lum_with_transactional_email_locale(string $locale, callable $callback)
{
    lum_push_transactional_email_locale($locale);
    try {
        return $callback();
    } finally {
        lum_pop_transactional_email_locale();
    }
}
