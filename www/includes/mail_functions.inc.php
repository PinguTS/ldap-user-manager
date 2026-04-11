<?php

declare(strict_types=1);

// PHPMailer loaded via Composer autoload (see config.inc.php)
//
// Email templates are loaded from files. Each file: first line = subject (optional "Subject:" prefix),
// blank line, then HTML body. Defaults include: new_account.html, account_welcome.html,
// reset_password.html (self-service reset), reset_password_admin.html (admin-sent reset link).
// Localized: basename.<locale>.html (e.g. new_account.de.html); falls back to basename.html.
// Override directory via EMAIL_TEMPLATES_DIR (absolute path); else default: www/templates/emails.
// Outgoing locale: EMAIL_DEFAULT_LOCALE (if set and valid), else lum_current_locale(), else en.

/**
 * Directory containing transactional *.html templates (new_account, account_welcome, reset_password, etc.).
 */
function mail_templates_directory(): string
{
    $env = getenv('EMAIL_TEMPLATES_DIR');
    if ($env !== false && $env !== '') {
        return rtrim($env, '/');
    }
    return dirname(__DIR__) . '/templates/emails';
}

/**
 * Locale code used when resolving localized template filenames (not necessarily equal to UI locale if EMAIL_DEFAULT_LOCALE is set).
 */
function lum_outgoing_email_locale(): string
{
    global $log_prefix;
    $prefix = is_string($log_prefix ?? null) ? $log_prefix : '';

    $dir = __DIR__ . '/../locales';
    $available = function_exists('lum_i18n_discover_locales')
        ? lum_i18n_discover_locales($dir)
        : ['en'];

    $envRaw = getenv('EMAIL_DEFAULT_LOCALE');
    if ($envRaw !== false && $envRaw !== '') {
        $cand = function_exists('lum_i18n_normalize_locale')
            ? lum_i18n_normalize_locale(trim($envRaw))
            : strtolower(trim($envRaw));
        if ($cand !== '' && function_exists('lum_i18n_is_available_locale')
            && lum_i18n_is_available_locale($cand, $available)) {
            return $cand;
        }
        error_log("{$prefix}EMAIL_DEFAULT_LOCALE ignored (unknown or invalid): " . trim($envRaw), 0);
    }

    if (function_exists('lum_current_locale')) {
        $cur = lum_current_locale();
        if (function_exists('lum_i18n_is_available_locale') && lum_i18n_is_available_locale($cur, $available)) {
            return $cur;
        }
    }

    return 'en';
}

/**
 * @return string|null Raw file contents, or null if missing/unreadable
 */
function lum_try_read_email_template_file(string $dir, string $filename): ?string
{
    $path = $dir . '/' . $filename;
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $content = file_get_contents($path);
    return $content !== false ? $content : null;
}

/**
 * Resolve which template file to load: localized stem.locale.html, optional stem.en.html, or stem.html.
 *
 * @param string $baseFilename e.g. new_account.html or new_account
 */
function lum_resolve_localized_email_filename(string $dir, string $baseFilename): string
{
    global $log_prefix;
    $prefix = is_string($log_prefix ?? null) ? $log_prefix : '';

    $base = strtolower($baseFilename);
    if (!str_ends_with($base, '.html')) {
        $baseFilename .= '.html';
    }
    $stem = basename($baseFilename, '.html');
    $locale = lum_outgoing_email_locale();

    if ($locale === 'en') {
        $enExplicit = $stem . '.en.html';
        if (lum_try_read_email_template_file($dir, $enExplicit) !== null) {
            return $enExplicit;
        }
        return $stem . '.html';
    }

    $localized = $stem . '.' . $locale . '.html';
    if (lum_try_read_email_template_file($dir, $localized) !== null) {
        return $localized;
    }

    error_log("{$prefix}Email template fallback: {$localized} not found, using {$stem}.html", 0);
    return $stem . '.html';
}

/**
 * Load and parse a combined subject/body template using localized filename resolution (fresh read).
 *
 * @param string $baseFilename e.g. new_account.html
 * @return array{subject: string, body: string}
 */
function lum_load_parsed_combined_transactional_template(string $baseFilename): array
{
    $dir = mail_templates_directory();
    $resolved = lum_resolve_localized_email_filename($dir, $baseFilename);
    $raw = lum_try_read_email_template_file($dir, $resolved);
    if ($raw === null) {
        error_log('Email template missing or unreadable: ' . $dir . '/' . $resolved, 0);
        throw new \RuntimeException('Email template missing: ' . $resolved);
    }

    return parse_combined_email_template($raw);
}

/**
 * Load and parse a combined subject/body template from disk (localized). Prefer lum_load_parsed_combined_transactional_template().
 *
 * @return array{subject: string, body: string}
 */
function load_parsed_combined_template_file(string $filename): array
{
    return lum_load_parsed_combined_transactional_template($filename);
}

/**
 * token_expires_minutes and token_expires_human for password set/reset emails (uses PASSWORD_RESET_TOKEN_TTL_SECONDS).
 *
 * @return array{token_expires_minutes: string, token_expires_human: string}
 */
function lum_password_action_token_expiry_mail_vars(): array
{
    $secs = function_exists('get_password_reset_token_ttl_seconds')
        ? get_password_reset_token_ttl_seconds()
        : 3600;

    return [
        'token_expires_minutes' => (string) max(1, (int) ceil($secs / 60)),
        'token_expires_human' => lum_format_password_action_token_ttl_human($secs),
    ];
}

/**
 * Human-readable duration for email copy (matches token TTL). Uses t() keys email.ttl.*.
 */
function lum_format_password_action_token_ttl_human(int $seconds): string
{
    $seconds = max(1, $seconds);

    if (!function_exists('t')) {
        $m = max(1, (int) ceil($seconds / 60));
        return $m === 1 ? '1 minute' : "{$m} minutes";
    }

    // Up to and including 60 minutes
    if ($seconds <= 3600) {
        $n = max(1, (int) ceil($seconds / 60));
        return $n === 1
            ? t('email.ttl.one_minute')
            : t('email.ttl.n_minutes', ['n' => (string) $n]);
    }

    // Greater than 60 minutes, up to and including 24 hours
    if ($seconds <= 86400) {
        $totalMin = (int) ceil($seconds / 60);
        $h = intdiv($totalMin, 60);
        $m = $totalMin % 60;
        if ($m === 0) {
            return $h === 1
                ? t('email.ttl.one_hour')
                : t('email.ttl.n_hours', ['n' => (string) $h]);
        }
        if ($h === 1) {
            return $m === 1
                ? t('email.ttl.one_hour_one_minute')
                : t('email.ttl.one_hour_n_minutes', ['m' => (string) $m]);
        }
        if ($m === 1) {
            return t('email.ttl.n_hours_one_minute', ['n' => (string) $h]);
        }

        return t('email.ttl.n_hours_n_minutes', ['n' => (string) $h, 'm' => (string) $m]);
    }

    // Greater than 24 hours
    $d = intdiv($seconds, 86400);
    $rem = $seconds % 86400;
    $h = intdiv($rem, 3600);

    if ($h === 0) {
        return $d === 1
            ? t('email.ttl.one_day')
            : t('email.ttl.n_days', ['n' => (string) $d]);
    }
    if ($d === 1) {
        return $h === 1
            ? t('email.ttl.one_day_one_hour')
            : t('email.ttl.one_day_n_hours', ['h' => (string) $h]);
    }
    if ($h === 1) {
        return t('email.ttl.n_days_one_hour', ['n' => (string) $d]);
    }

    return t('email.ttl.n_days_n_hours', ['n' => (string) $d, 'h' => (string) $h]);
}

/**
 * Load raw content of an email template file.
 *
 * @param string $dir      Template directory path
 * @param string $filename Fixed filename (e.g. new_account.html, reset_password.html)
 * @return string Raw file contents
 * @throws \RuntimeException If file is missing or not readable
 */
function load_email_template_file(string $dir, string $filename): string
{
    $raw = lum_try_read_email_template_file($dir, $filename);
    if ($raw === null) {
        error_log("Email template missing or unreadable: {$dir}/{$filename}");
        throw new \RuntimeException('Email template missing: ' . $filename);
    }

    return $raw;
}

/**
 * Parse a combined email template (first line = subject, then body).
 * Strips optional "Subject:" prefix (case-insensitive) from the subject line.
 * After the first line, leading blank lines are skipped so a single newline
 * between subject and body works (preg_split on double-newline alone missed that and yielded an empty body).
 *
 * @param string $raw Full template file content
 * @return array{subject: string, body: string}
 */
function parse_combined_email_template(string $raw): array
{
    $norm = str_replace(["\r\n", "\r"], "\n", $raw);
    if (str_starts_with($norm, "\xEF\xBB\xBF")) {
        $norm = substr($norm, 3);
    }
    $lines = explode("\n", $norm);
    $subjectLine = trim((string) ($lines[0] ?? ''));
    $rest = array_slice($lines, 1);
    while (count($rest) > 0 && trim((string) $rest[0]) === '') {
        array_shift($rest);
    }
    $body = trim(implode("\n", $rest));
    if (preg_match('/^Subject:\s*(.*)$/i', $subjectLine, $m)) {
        $subjectLine = trim($m[1]);
    }
    return ['subject' => $subjectLine, 'body' => $body];
}

/**
 * Replace placeholders in email templates.
 *
 * Supported placeholders:
 * - {login}, {first_name}, {last_name}
 * - {organisation}, {site_url}, {change_password_url}
 * - {password_set_url}, {password_reset_url}, {token_expires_minutes}, {token_expires_human}
 *
 * @param string $template
 * @param array<string, string> $vars
 */
function parse_mail_template(string $template, array $vars): string
{

    global $ORGANISATION_NAME;

    $baseUrl = function_exists('lumPublicSiteBaseUrl')
        ? lumPublicSiteBaseUrl()
        : (($GLOBALS['SITE_PROTOCOL'] ?? '') . ($GLOBALS['SERVER_HOSTNAME'] ?? '') . ($GLOBALS['SERVER_PATH'] ?? '/'));
    $defaults = [
        'organisation' => (string) $ORGANISATION_NAME,
        'site_url' => $baseUrl,
        'change_password_url' => $baseUrl . 'password/change',
    ];

    $vars = array_merge($defaults, $vars);
    foreach ($vars as $key => $value) {
        $template = str_replace('{' . $key . '}', (string) $value, $template);
    }

    return (string) $template;
}

/**
 * Backwards-compatible wrapper for legacy templates that included {password}.
 *
 * @deprecated Use parse_mail_template() with explicit vars instead.
 */
function parse_mail_text($template, $password, $login, $first_name, $last_name)
{
    return parse_mail_template((string) $template, [
        'password' => (string) $password,
        'login' => (string) $login,
        'first_name' => (string) $first_name,
        'last_name' => (string) $last_name,
    ]);
}

function send_email($recipient_email, $recipient_name, $subject, $body)
{

    global $EMAIL, $SMTP, $log_prefix;

    $mail = new PHPMailer\PHPMailer\PHPMailer();
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();

    $mail->SMTPDebug = $SMTP['debug_level'];
    $mail->Debugoutput = function ($message, $level) {
        global $log_prefix;
        error_log("{$log_prefix}SMTP (level $level): $message", 0);
    };

    $mail->Host = $SMTP['host'];
    $mail->Port = $SMTP['port'];

    if (isset($SMTP['helo'])) {
        $mail->Helo = $SMTP['helo'];
    }

    if (isset($SMTP['user'])) {
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP['user'];
        $mail->Password = $SMTP['pass'];
    }

    if ($SMTP['tls'] == true) {
        $mail->SMTPSecure = 'tls';
    }
    if ($SMTP['ssl'] == true) {
        $mail->SMTPSecure = 'ssl';
    }

    $mail->SMTPAutoTLS = false;
    $mail->setFrom($EMAIL['from_address'], $EMAIL['from_name']);
    $mail->addAddress($recipient_email, $recipient_name);
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->IsHTML(true);

    $plain = html_entity_decode(strip_tags((string) $body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/[ \t]+/', ' ', $plain) ?? $plain;
    $plain = trim(preg_replace('/\R{3,}/', "\n\n", $plain) ?? $plain);
    $mail->AltBody = $plain;

    if (trim((string) $body) === '') {
        error_log("{$log_prefix}SMTP: Refusing to send email with empty body (check email template format: first line = subject, then body).", 0);
        return false;
    }

    if (!$mail->Send()) {
        error_log("{$log_prefix}SMTP: Unable to send email: " . $mail->ErrorInfo, 0);
        return false;
    }

    error_log("{$log_prefix}SMTP: sent an email to $recipient_email ($recipient_name)", 0);
    return true;
}

/**
 * Send a password-reset email using attributes from one ldap_get_entries() user row.
 *
 * Requires password_reset_functions.inc.php (token helpers) and web_functions (isValidEmail).
 *
 * @param array<string, mixed> $userRow
 * @param string               $trigger  'self' (forgot-password / public flow) or 'admin' (manager sent link)
 * @return array{ok: bool, reason?: string, email?: string} reason: email_disabled|token_disabled|no_valid_email|send_failed
 */
function send_password_reset_email_for_ldap_user_row(array $userRow, string $trigger = 'self'): array
{
    global $LDAP, $EMAIL_SENDING_ENABLED, $log_prefix;

    if (($EMAIL_SENDING_ENABLED ?? false) !== true) {
        return ['ok' => false, 'reason' => 'email_disabled'];
    }
    if (!function_exists('is_password_reset_link_enabled') || !is_password_reset_link_enabled()) {
        return ['ok' => false, 'reason' => 'token_disabled'];
    }

    $trigger = ($trigger === 'admin') ? 'admin' : 'self';
    $templateBase = ($trigger === 'admin') ? 'reset_password_admin.html' : 'reset_password.html';

    $userMail = (string) ($userRow['mail'][0] ?? '');
    $acctKey = strtolower((string) ($LDAP['account_attribute'] ?? 'mail'));
    $login = (string) ($userRow[$acctKey][0] ?? '');
    if ($login === '') {
        $login = $userMail;
    }
    $first = (string) ($userRow['givenname'][0] ?? $userRow['givenName'][0] ?? '');
    $last = (string) ($userRow['sn'][0] ?? '');

    if ($userMail === '' || !function_exists('isValidEmail') || !isValidEmail($userMail)) {
        return ['ok' => false, 'reason' => 'no_valid_email'];
    }

    $payload = build_password_action_payload($login !== '' ? $login : $userMail, 'reset');
    $token = create_password_action_token($payload);
    $resetUrl = build_password_action_url($token);
    $vars = array_merge(lum_password_action_token_expiry_mail_vars(), [
        'login' => ($login !== '' ? $login : $userMail),
        'first_name' => $first,
        'last_name' => $last,
        'password_reset_url' => $resetUrl,
    ]);
    $parsed = lum_load_parsed_combined_transactional_template($templateBase);
    $subject = parse_mail_template((string) $parsed['subject'], $vars);
    $body = parse_mail_template((string) $parsed['body'], $vars);
    if (trim($body) === '') {
        error_log(
            "{$log_prefix}SMTP: reset email body is empty after template merge; check "
            . mail_templates_directory() . '/' . basename($templateBase, '.html') . '*.html (first line = subject, following lines = body).',
            0
        );
        return ['ok' => false, 'reason' => 'send_failed'];
    }
    $sent = send_email($userMail, trim($first . ' ' . $last), $subject, $body);

    return $sent ? ['ok' => true, 'email' => $userMail] : ['ok' => false, 'reason' => 'send_failed'];
}

/**
 * @param resource|\LDAP\Connection $ldap
 * @param string                     $trigger 'self'|'admin'
 * @return array{ok: bool, reason?: string, email?: string}
 */
function send_password_reset_email_for_user_dn($ldap, string $dn, string $trigger = 'self'): array
{
    global $LDAP;
    $attrs = ['mail', 'givenName', 'sn', $LDAP['account_attribute']];
    $read = @ldap_read($ldap, $dn, '(objectClass=*)', $attrs);
    $entries = $read ? ldap_get_entries($ldap, $read) : null;
    if (!is_array($entries) || ($entries['count'] ?? 0) < 1 || !is_array($entries[0] ?? null)) {
        return ['ok' => false, 'reason' => 'no_valid_email'];
    }

    return send_password_reset_email_for_ldap_user_row($entries[0], $trigger);
}

/**
 * Send account_welcome.html after an administrator created the account and set the password (no link, no password in email).
 *
 * @param array<string, string> $vars login, first_name, last_name (optional empty)
 * @return bool True if SMTP accepted the message
 */
function lum_send_account_welcome_email(string $recipientEmail, string $recipientDisplayName, array $vars): bool
{
    global $EMAIL_SENDING_ENABLED;
    if (($EMAIL_SENDING_ENABLED ?? false) !== true) {
        return false;
    }
    if ($recipientEmail === '' || !function_exists('isValidEmail') || !isValidEmail($recipientEmail)) {
        return false;
    }

    $parsed = lum_load_parsed_combined_transactional_template('account_welcome.html');
    $subject = parse_mail_template((string) $parsed['subject'], $vars);
    $body = parse_mail_template((string) $parsed['body'], $vars);
    if (trim($body) === '') {
        return false;
    }

    return send_email($recipientEmail, $recipientDisplayName, $subject, $body);
}

/**
 * Self-service forgot-password: if the address is valid, LDAP has a matching user, and mail/tokens are enabled, send the reset email.
 * No-op when preconditions fail (same semantics as the public reset form; safe for enumeration-resistant callers).
 */
function process_password_reset_request_for_email(string $email): void
{
    $email = trim($email);
    if ($email === '' || !function_exists('isValidEmail') || !isValidEmail($email)) {
        return;
    }
    global $EMAIL_SENDING_ENABLED;
    if (($EMAIL_SENDING_ENABLED ?? false) !== true) {
        return;
    }
    if (!function_exists('is_password_reset_link_enabled') || !is_password_reset_link_enabled()) {
        return;
    }
    if (!function_exists('open_ldap_connection') || !function_exists('ldap_find_user_entry_by_account_identifier')) {
        return;
    }
    $ldap = open_ldap_connection();
    if ($ldap === false) {
        return;
    }
    global $LDAP;
    $attrs = ['mail', 'givenName', 'sn', $LDAP['account_attribute'] ?? 'mail'];
    $user = ldap_find_user_entry_by_account_identifier($ldap, $email, $attrs);
    ldap_close($ldap);
    if (is_array($user)) {
        send_password_reset_email_for_ldap_user_row($user);
    }
}
