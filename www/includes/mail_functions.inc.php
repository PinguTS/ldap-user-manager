<?php

declare(strict_types=1);

// PHPMailer loaded via Composer autoload (see config.inc.php)
//
// Email templates are loaded from files. Each file: first line = subject (optional "Subject:" prefix),
// blank line, then HTML body. Template files: new_account.html, reset_password.html.
// Override directory via EMAIL_TEMPLATES_DIR (absolute path); else default: www/templates/emails.

/**
 * Directory containing new_account.html and reset_password.html.
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
 * Load and parse a combined subject/body template from disk (fresh read; use at send time).
 *
 * @return array{subject: string, body: string}
 */
function load_parsed_combined_template_file(string $filename): array
{
    return parse_combined_email_template(
        load_email_template_file(mail_templates_directory(), $filename)
    );
}

$email_templates_dir = mail_templates_directory();

$new_account_parsed = parse_combined_email_template(
    load_email_template_file($email_templates_dir, 'new_account.html')
);
$new_account_mail_subject = $new_account_parsed['subject'];
$new_account_mail_body = $new_account_parsed['body'];

$reset_password_parsed = parse_combined_email_template(
    load_email_template_file($email_templates_dir, 'reset_password.html')
);
$reset_password_mail_subject = $reset_password_parsed['subject'];
$reset_password_mail_body = $reset_password_parsed['body'];

/**
 * Load raw content of an email template file. Filename must be one of the fixed template names.
 *
 * @param string $dir      Template directory path
 * @param string $filename Fixed filename (e.g. new_account.html, reset_password.html)
 * @return string Raw file contents
 * @throws \RuntimeException If file is missing or not readable
 */
function load_email_template_file(string $dir, string $filename): string
{
    $path = $dir . '/' . $filename;
    if (!is_file($path) || !is_readable($path)) {
        error_log("Email template missing or unreadable: {$path}");
        throw new \RuntimeException('Email template missing: ' . $filename);
    }
    $content = file_get_contents($path);
    return $content !== false ? $content : '';
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
 * - {password_set_url}, {password_reset_url}, {token_expires_minutes}
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
 * @return array{ok: bool, reason?: string, email?: string} reason: email_disabled|token_disabled|no_valid_email|send_failed
 */
function send_password_reset_email_for_ldap_user_row(array $userRow): array
{
    global $LDAP, $EMAIL_SENDING_ENABLED, $log_prefix;

    if (($EMAIL_SENDING_ENABLED ?? false) !== true) {
        return ['ok' => false, 'reason' => 'email_disabled'];
    }
    if (!function_exists('is_password_reset_link_enabled') || !is_password_reset_link_enabled()) {
        return ['ok' => false, 'reason' => 'token_disabled'];
    }

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
    $ttlMinutes = (int) ceil(get_password_reset_token_ttl_seconds() / 60);
    $vars = [
        'login' => ($login !== '' ? $login : $userMail),
        'first_name' => $first,
        'last_name' => $last,
        'password_reset_url' => $resetUrl,
        'token_expires_minutes' => (string) $ttlMinutes,
    ];
    $parsed = load_parsed_combined_template_file('reset_password.html');
    $subject = parse_mail_template((string) $parsed['subject'], $vars);
    $body = parse_mail_template((string) $parsed['body'], $vars);
    if (trim($body) === '') {
        error_log(
            "{$log_prefix}SMTP: reset email body is empty after template merge; check "
            . mail_templates_directory() . '/reset_password.html (first line = subject, following lines = body).',
            0
        );
        return ['ok' => false, 'reason' => 'send_failed'];
    }
    $sent = send_email($userMail, trim($first . ' ' . $last), $subject, $body);

    return $sent ? ['ok' => true, 'email' => $userMail] : ['ok' => false, 'reason' => 'send_failed'];
}

/**
 * @param resource|\LDAP\Connection $ldap
 * @return array{ok: bool, reason?: string, email?: string}
 */
function send_password_reset_email_for_user_dn($ldap, string $dn): array
{
    global $LDAP;
    $attrs = ['mail', 'givenName', 'sn', $LDAP['account_attribute']];
    $read = @ldap_read($ldap, $dn, '(objectClass=*)', $attrs);
    $entries = $read ? ldap_get_entries($ldap, $read) : null;
    if (!is_array($entries) || ($entries['count'] ?? 0) < 1 || !is_array($entries[0] ?? null)) {
        return ['ok' => false, 'reason' => 'no_valid_email'];
    }

    return send_password_reset_email_for_ldap_user_row($entries[0]);
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
