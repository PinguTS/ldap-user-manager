<?php

/**
 * JSON-based i18n: Accept-Language resolution, merge en + locale overlay, t().
 */

declare(strict_types=1);

$GLOBALS['lum_i18n_messages'] = [];
$GLOBALS['lum_i18n_locale'] = 'en';

/**
 * Native display names and flag asset filenames by locale code.
 *
 * @return array<string, array{native: string, flag: string}>
 */
function lum_i18n_locale_catalog(): array
{
    return [
        'da' => ['native' => 'Dansk', 'flag' => 'da.svg'],
        'de' => ['native' => 'Deutsch', 'flag' => 'de.svg'],
        'en' => ['native' => 'English', 'flag' => 'en.svg'],
        'es' => ['native' => 'Español', 'flag' => 'es.svg'],
        'fr' => ['native' => 'Français', 'flag' => 'fr.svg'],
        'hi' => ['native' => 'हिन्दी', 'flag' => 'hi.svg'],
        'it' => ['native' => 'Italiano', 'flag' => 'it.svg'],
        'ja' => ['native' => '日本語', 'flag' => 'ja.svg'],
        'ko' => ['native' => '한국어', 'flag' => 'ko.svg'],
        'nb' => ['native' => 'Norsk bokmål', 'flag' => 'nb.svg'],
        'nl' => ['native' => 'Nederlands', 'flag' => 'nl.svg'],
        'no' => ['native' => 'Norsk', 'flag' => 'no.svg'],
        'sv' => ['native' => 'Svenska', 'flag' => 'sv.svg'],
        'zh' => ['native' => '中文', 'flag' => 'zh.svg'],
    ];
}

/**
 * Parse Accept-Language into primary language codes, highest q first, each code once.
 *
 * @return list<string> e.g. ['de', 'en']
 */
function lum_parse_accept_language(string $header): array
{
    $header = trim($header);
    if ($header === '') {
        return [];
    }

    /** @var array<string, float> $byPrimary max q per primary tag */
    $byPrimary = [];

    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $pieces = preg_split('/\s*;\s*/', $part) ?: [];
        $range = strtolower(trim((string) ($pieces[0] ?? '')));
        if ($range === '' || $range === '*') {
            continue;
        }
        $q = 1.0;
        for ($i = 1; $i < count($pieces); $i++) {
            if (preg_match('/^q\s*=\s*([0-9.]+)/i', (string) $pieces[$i], $m)) {
                $q = (float) $m[1];
                if ($q > 1.0) {
                    $q = 1.0;
                }
                if ($q < 0.0) {
                    $q = 0.0;
                }
                break;
            }
        }
        $primary = explode('-', str_replace('_', '-', $range), 2)[0];
        if ($primary === '') {
            continue;
        }
        if (!isset($byPrimary[$primary]) || $q > $byPrimary[$primary]) {
            $byPrimary[$primary] = $q;
        }
    }

    arsort($byPrimary, SORT_NUMERIC);
    return array_keys($byPrimary);
}

/**
 * Locale JSON filenames under $dir (basename without .json).
 *
 * @return list<string>
 */
function lum_i18n_discover_locales(string $dir): array
{
    if (!is_dir($dir)) {
        return ['en'];
    }
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $base = basename($path, '.json');
        if ($base !== '' && preg_match('/^[a-z]{2}(-[a-z0-9]+)?$/i', $base)) {
            $out[] = strtolower($base);
        }
    }
    $out = array_values(array_unique($out));
    sort($out);

    return in_array('en', $out, true) ? $out : array_merge(['en'], $out);
}

function lum_i18n_normalize_locale(string $raw): string
{
    $normalized = strtolower(trim($raw));
    $normalized = str_replace('_', '-', $normalized);
    if (!preg_match('/^[a-z]{2}(-[a-z0-9]+)?$/', $normalized)) {
        return '';
    }
    return $normalized;
}

/**
 * @param list<string> $available
 */
function lum_i18n_is_available_locale(string $code, array $available): bool
{
    return in_array(lum_i18n_normalize_locale($code), $available, true);
}

/**
 * First preferred language that exists in $available; otherwise English if present.
 *
 * @param list<string> $preferredOrder
 * @param list<string> $available
 */
function lum_i18n_pick_locale(array $preferredOrder, array $available): string
{
    $set = array_flip($available);
    foreach ($preferredOrder as $code) {
        $code = strtolower($code);
        if (isset($set[$code])) {
            return $code;
        }
    }

    return isset($set['en']) ? 'en' : (string) ($available[0] ?? 'en');
}

/**
 * Resolve locale by precedence:
 * explicit override -> persisted preference -> Accept-Language -> English fallback.
 *
 * @param list<string> $available
 */
function lum_i18n_resolve_locale(
    array $available,
    ?string $explicitLocaleOverride = null,
    ?string $persistedLocalePreference = null,
    ?string $acceptLanguageHeader = null
): string {
    $explicit = lum_i18n_normalize_locale((string) $explicitLocaleOverride);
    if ($explicit !== '' && lum_i18n_is_available_locale($explicit, $available)) {
        return $explicit;
    }

    $persisted = lum_i18n_normalize_locale((string) $persistedLocalePreference);
    if ($persisted !== '' && lum_i18n_is_available_locale($persisted, $available)) {
        return $persisted;
    }

    $preferred = lum_parse_accept_language((string) $acceptLanguageHeader);
    return lum_i18n_pick_locale($preferred, $available);
}

/**
 * @return array<string, string>
 */
function lum_i18n_load_json_file(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    $flat = [];
    foreach ($data as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $flat[$k] = $v;
        }
    }

    return $flat;
}

/**
 * Initialize messages from locales dir using Accept-Language (or override for tests).
 *
 * @param string|null $localesDirectory Absolute path; default www/locales. Tests may pass a temp dir.
 */
function lum_i18n_bootstrap(
    ?string $acceptLanguageOverride = null,
    ?string $localesDirectory = null,
    ?string $explicitLocaleOverride = null,
    ?string $persistedLocalePreference = null
): void {
    global $lum_i18n_messages, $lum_i18n_locale;

    $dir = $localesDirectory ?? (__DIR__ . '/../locales');
    $available = lum_i18n_discover_locales($dir);
    $header = $acceptLanguageOverride ?? (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $chosen = lum_i18n_resolve_locale($available, $explicitLocaleOverride, $persistedLocalePreference, $header);

    $enPath = $dir . '/en.json';
    $messages = lum_i18n_load_json_file($enPath);
    if ($chosen !== 'en') {
        $overlay = lum_i18n_load_json_file($dir . '/' . $chosen . '.json');
        foreach ($overlay as $k => $v) {
            $messages[$k] = $v;
        }
    }

    $lum_i18n_messages = $messages;
    $lum_i18n_locale = $chosen;
}

/**
 * Locale options for chooser UI.
 *
 * @param list<string>|null $availableLocales
 * @return list<array{code: string, native: string, flag: string}>
 */
function lum_i18n_locale_options(?array $availableLocales = null): array
{
    $dir = __DIR__ . '/../locales';
    $available = $availableLocales ?? lum_i18n_discover_locales($dir);
    $catalog = lum_i18n_locale_catalog();
    $options = [];
    foreach ($available as $code) {
        $meta = $catalog[$code] ?? ['native' => strtoupper($code), 'flag' => $code . '.svg'];
        $options[] = [
            'code' => $code,
            'native' => $meta['native'],
            'flag' => $meta['flag'],
        ];
    }

    return $options;
}

/**
 * Current UI locale (BCP 47 primary or full if we only had 2-letter files).
 */
function lum_current_locale(): string
{
    global $lum_i18n_locale;

    return is_string($lum_i18n_locale) ? $lum_i18n_locale : 'en';
}

/**
 * Translate key; optional :placeholder replacement from $replacements.
 */
function t(string $key, array $replacements = []): string
{
    global $lum_i18n_messages;

    $msg = $lum_i18n_messages[$key] ?? $key;
    if ($replacements === []) {
        return $msg;
    }
    foreach ($replacements as $name => $value) {
        $msg = str_replace(':' . (string) $name, (string) $value, $msg);
    }

    return $msg;
}

/**
 * Load merged en + locale messages for transactional email (does not change global UI locale).
 *
 * @return array<string, string>
 */
function lum_i18n_messages_for_locale(string $locale): array
{
    $dir = __DIR__ . '/../locales';
    $available = lum_i18n_discover_locales($dir);
    $code = lum_i18n_normalize_locale($locale);
    if ($code === '' || !lum_i18n_is_available_locale($code, $available)) {
        $code = 'en';
    }

    $messages = lum_i18n_load_json_file($dir . '/en.json');
    if ($code !== 'en') {
        foreach (lum_i18n_load_json_file($dir . '/' . $code . '.json') as $k => $v) {
            $messages[$k] = $v;
        }
    }

    return $messages;
}

/**
 * @param array<string, string> $messages
 */
function lum_i18n_translate_from_messages(array $messages, string $key, array $replacements = []): string
{
    $msg = $messages[$key] ?? $key;
    if ($replacements === []) {
        return $msg;
    }
    foreach ($replacements as $name => $value) {
        $msg = str_replace(':' . (string) $name, (string) $value, $msg);
    }

    return $msg;
}

/**
 * Translate a key using a specific locale without mutating global i18n state.
 */
function lum_i18n_t_for_locale(string $locale, string $key, array $replacements = []): string
{
    static $cache = [];

    $code = lum_i18n_normalize_locale($locale);
    if ($code === '') {
        $code = 'en';
    }
    if (!isset($cache[$code])) {
        $cache[$code] = lum_i18n_messages_for_locale($code);
    }

    return lum_i18n_translate_from_messages($cache[$code], $key, $replacements);
}
