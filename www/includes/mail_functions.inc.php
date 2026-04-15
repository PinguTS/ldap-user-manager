<?php

declare(strict_types=1);

// PHPMailer loaded via Composer autoload (see config.inc.php)
//
// Email templates are loaded from files. Each file: first line = subject (optional "Subject:" prefix),
// blank line, then HTML body. Defaults include: new_account.html, account_welcome.html,
// reset_password.html (self-service reset), reset_password_admin.html (admin-sent reset link).
// Localized: basename.<locale>.html (e.g. new_account.de.html); falls back to basename.html.
// Override directory via EMAIL_TEMPLATES_DIR (absolute path); else default: www/templates/emails.
// Outgoing locale: see email_locale.inc.php; call sites use lum_with_transactional_email_locale().
// When no locale is pushed, lum_outgoing_email_locale() falls back to installation default only (EMAIL_DEFAULT_LOCALE or en).

include_once __DIR__ . '/email_locale.inc.php';

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
 * Locale code used when resolving localized template filenames and email.ttl strings.
 * Uses the innermost lum_with_transactional_email_locale() scope when set; otherwise installation default only.
 */
function lum_outgoing_email_locale(): string
{
    $stack = $GLOBALS['lum_transactional_email_locale_stack'] ?? null;
    if (is_array($stack) && count($stack) > 0) {
        return (string) $stack[array_key_last($stack)];
    }

    return lum_installation_email_locale();
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
    $loc = lum_outgoing_email_locale();
    $tr = static function (string $key, array $replacements = []) use ($loc): string {
        if (function_exists('lum_i18n_t_for_locale')) {
            return lum_i18n_t_for_locale($loc, $key, $replacements);
        }
        if (function_exists('t')) {
            return t($key, $replacements);
        }
        $m = max(1, (int) ceil($seconds / 60));

        return $m === 1 ? '1 minute' : "{$m} minutes";
    };

    // Up to and including 60 minutes
    if ($seconds <= 3600) {
        $n = max(1, (int) ceil($seconds / 60));
        return $n === 1
            ? $tr('email.ttl.one_minute')
            : $tr('email.ttl.n_minutes', ['n' => (string) $n]);
    }

    // Greater than 60 minutes, up to and including 24 hours
    if ($seconds <= 86400) {
        $totalMin = (int) ceil($seconds / 60);
        $h = intdiv($totalMin, 60);
        $m = $totalMin % 60;
        if ($m === 0) {
            return $h === 1
                ? $tr('email.ttl.one_hour')
                : $tr('email.ttl.n_hours', ['n' => (string) $h]);
        }
        if ($h === 1) {
            return $m === 1
                ? $tr('email.ttl.one_hour_one_minute')
                : $tr('email.ttl.one_hour_n_minutes', ['m' => (string) $m]);
        }
        if ($m === 1) {
            return $tr('email.ttl.n_hours_one_minute', ['n' => (string) $h]);
        }

        return $tr('email.ttl.n_hours_n_minutes', ['n' => (string) $h, 'm' => (string) $m]);
    }

    // Greater than 24 hours
    $d = intdiv($seconds, 86400);
    $rem = $seconds % 86400;
    $h = intdiv($rem, 3600);

    if ($h === 0) {
        return $d === 1
            ? $tr('email.ttl.one_day')
            : $tr('email.ttl.n_days', ['n' => (string) $d]);
    }
    if ($d === 1) {
        return $h === 1
            ? $tr('email.ttl.one_day_one_hour')
            : $tr('email.ttl.one_day_n_hours', ['h' => (string) $h]);
    }
    if ($h === 1) {
        return $tr('email.ttl.n_days_one_hour', ['n' => (string) $d]);
    }

    return $tr('email.ttl.n_days_n_hours', ['n' => (string) $d, 'h' => (string) $h]);
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

/**
 * Wrap inner HTML content in a full email document with header, footer and optional preheader.
 *
 * @param string $inner_html  Template body (after placeholder substitution)
 * @param string $preheader   Optional inbox-preview text (hidden from body)
 */
function lum_build_email_html_document(string $inner_html, string $preheader = ''): string
{
    global $ORGANISATION_NAME, $CUSTOM_LOGO;

    $org = htmlspecialchars((string) ($ORGANISATION_NAME ?? ''), ENT_QUOTES, 'UTF-8');

    $base_url = function_exists('lumPublicSiteBaseUrl')
        ? lumPublicSiteBaseUrl()
        : (($GLOBALS['SITE_PROTOCOL'] ?? '') . ($GLOBALS['SERVER_HOSTNAME'] ?? '') . ($GLOBALS['SERVER_PATH'] ?? '/'));
    $site_url_safe = htmlspecialchars($base_url, ENT_QUOTES, 'UTF-8');

    $locale = lum_outgoing_email_locale();
    $link_html = '<a href="' . $site_url_safe . '" style="color:#888;">' . $site_url_safe . '</a>';

    $footer_auto = htmlspecialchars(
        lum_email_localized_string('email.footer.auto_message', 'This is an automatically generated message. Please do not reply to this email.', ['org' => $org]),
        ENT_QUOTES,
        'UTF-8'
    );
    $footer_auto = str_replace(':url', $link_html, $footer_auto);

    $footer_help_tpl = htmlspecialchars(
        lum_email_localized_string('email.footer.help_link', 'For help, visit :url.', ['org' => $org]),
        ENT_QUOTES,
        'UTF-8'
    );
    $footer_help_tpl = str_replace(':url', $link_html, $footer_help_tpl);

    $footer_html = $footer_auto . ' ' . str_replace(':url', $link_html, $footer_help_tpl);

    $logo_html = '';
    if (!empty($CUSTOM_LOGO)) {
        $logo_src = htmlspecialchars((string) $CUSTOM_LOGO, ENT_QUOTES, 'UTF-8');
        $logo_html = '<img src="' . $logo_src . '" alt="" style="max-height:40px;max-width:200px;margin-bottom:8px;display:block;">';
    }

    $preheader_html = '';
    if ($preheader !== '') {
        $preheader_safe = htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8');
        $preheader_html = '<span style="display:none;font-size:1px;color:#f5f5f5;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">'
            . $preheader_safe
            . '</span>';
    }

    return '<!DOCTYPE html>'
        . '<html lang="' . htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') . '">'
        . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $org . '</title></head>'
        . '<body style="margin:0;padding:0;background-color:#f5f5f5;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">'
        . $preheader_html
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f5f5f5;">'
        . '<tr><td align="center" style="padding:24px 16px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;">'
        // Header
        . '<tr><td style="padding:20px 32px;text-align:center;background-color:#ffffff;border-radius:8px 8px 0 0;border-bottom:2px solid #0d6efd;">'
        . $logo_html
        . '<span style="font-family:system-ui,-apple-system,sans-serif;font-size:18px;font-weight:600;color:#333;">' . $org . '</span>'
        . '</td></tr>'
        // Body
        . '<tr><td style="padding:28px 32px;background-color:#ffffff;font-family:system-ui,-apple-system,sans-serif;font-size:15px;line-height:1.6;color:#333;">'
        . $inner_html
        . '</td></tr>'
        // Footer
        . '<tr><td style="padding:16px 32px;background-color:#ffffff;border-radius:0 0 8px 8px;border-top:1px solid #e9ecef;">'
        . '<p style="margin:0;font-family:system-ui,-apple-system,sans-serif;font-size:12px;line-height:1.5;color:#888;">'
        . $footer_html
        . '</p></td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

/**
 * Retrieve a localized string for email content, with English fallback.
 */
function lum_email_localized_string(string $key, string $fallback, array $replacements = []): string
{
    $loc = lum_outgoing_email_locale();
    if (function_exists('lum_i18n_t_for_locale')) {
        return lum_i18n_t_for_locale($loc, $key, $replacements);
    }
    if (function_exists('t')) {
        return t($key, $replacements);
    }

    $msg = $fallback;
    if ($replacements === []) {
        return $msg;
    }
    foreach ($replacements as $name => $value) {
        $msg = str_replace(':' . (string) $name, (string) $value, $msg);
    }
    return $msg;
}

/**
 * Build localized preheader text for a transactional email.
 *
 * @param string $i18n_key  e.g. 'email.preheader.new_account'
 * @param string $fallback  English fallback text
 */
function lum_email_preheader(string $i18n_key, string $fallback): string
{
    global $ORGANISATION_NAME;
    return lum_email_localized_string($i18n_key, $fallback, ['org' => (string) ($ORGANISATION_NAME ?? '')]);
}

/**
 * Convert HTML body to a well-structured plain-text alternative.
 */
function lum_html_to_plain_text(string $html): string
{
    $text = $html;
    $text = (string) preg_replace('/<hr[^>]*>/i', "\n---\n", $text);
    $text = (string) preg_replace_callback(
        '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
        static function (array $m): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = strip_tags($m[2]);
            return ($label === $url || trim($label) === '') ? $url : $label . ' (' . $url . ')';
        },
        $text
    );
    $text = (string) preg_replace('/<\/p>\s*/i', "\n\n", $text);
    $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/[ \t]+/', ' ', $text);
    $text = trim((string) preg_replace('/\R{3,}/', "\n\n", $text));

    return $text;
}

function send_email($recipient_email, $recipient_name, $subject, $body, string $preheader = '')
{

    global $EMAIL, $SMTP, $log_prefix;

    if (trim((string) $body) === '') {
        error_log("{$log_prefix}SMTP: Refusing to send email with empty body (check email template format: first line = subject, then body).", 0);
        return false;
    }

    $wrapped_body = lum_build_email_html_document((string) $body, $preheader);

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
    $mail->Body = $wrapped_body;
    $mail->IsHTML(true);
    $mail->AltBody = lum_html_to_plain_text((string) $body);

    $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
    $mail->addCustomHeader('X-Entity-Ref-ID', bin2hex(random_bytes(16)));

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

    $visitorIsRecipient = ($trigger === 'self');
    $locale = lum_resolve_transactional_email_locale_from_ldap_user_row($userRow, $visitorIsRecipient);

    $stem = basename($templateBase, '.html');
    $templateHint = $stem . '.html';

    return lum_with_transactional_email_locale($locale, function () use (
        $userMail,
        $login,
        $first,
        $last,
        $templateBase,
        $log_prefix,
        $templateHint
    ): array {
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
                . mail_templates_directory() . '/' . $templateHint . ' (localized variants).',
                0
            );

            return ['ok' => false, 'reason' => 'send_failed'];
        }
        $preheader_key = ($templateBase === 'reset_password_admin.html')
            ? 'email.preheader.reset_password_admin'
            : 'email.preheader.reset_password';
        $preheader_fallback = ($templateBase === 'reset_password_admin.html')
            ? 'An administrator has initiated a password reset for your account.'
            : 'Use the link inside to reset your password.';
        $preheader = lum_email_preheader($preheader_key, $preheader_fallback);
        $sent = send_email($userMail, trim($first . ' ' . $last), $subject, $body, $preheader);

        return $sent ? ['ok' => true, 'email' => $userMail] : ['ok' => false, 'reason' => 'send_failed'];
    });
}

/**
 * @param resource|\LDAP\Connection $ldap
 * @param string                     $trigger 'self'|'admin'
 * @return array{ok: bool, reason?: string, email?: string}
 */
function send_password_reset_email_for_user_dn($ldap, string $dn, string $trigger = 'self'): array
{
    global $LDAP;
    $attrs = [
        'mail', 'givenName', 'sn',
        $LDAP['account_attribute'],
        'description', 'organization', 'o', 'preferredLanguage',
    ];
    $attrs = array_values(array_unique($attrs));
    $read = @ldap_read($ldap, $dn, '(objectClass=' . '*' . ')', $attrs);
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

    $preheader = lum_email_preheader('email.preheader.account_welcome', 'Your account is ready to use.');

    return send_email($recipientEmail, $recipientDisplayName, $subject, $body, $preheader);
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
