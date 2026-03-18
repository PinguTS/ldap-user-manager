<?php

declare(strict_types=1);

// PHPMailer loaded via Composer autoload (see config.inc.php)
//
// Email templates are loaded from files. Each file: first line = subject (optional "Subject:" prefix),
// blank line, then HTML body. Template files: new_account.html, reset_password.html.
// Override directory via EMAIL_TEMPLATES_DIR (absolute path); else default: www/templates/emails.

$email_templates_dir_env = getenv('EMAIL_TEMPLATES_DIR');
$email_templates_dir = ($email_templates_dir_env !== false && $email_templates_dir_env !== '')
    ? rtrim($email_templates_dir_env, '/')
    : dirname(__DIR__) . '/templates/emails';

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
 * Parse a combined email template (first line = subject, blank line, then body).
 * Strips optional "Subject:" prefix (case-insensitive) from the subject line.
 *
 * @param string $raw Full template file content
 * @return array{subject: string, body: string}
 */
function parse_combined_email_template(string $raw): array
{
    $parts = preg_split('/\n\s*\n/', $raw, 2);
    $subjectLine = trim($parts[0] ?? '');
    $body = trim($parts[1] ?? '');
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

    global $ORGANISATION_NAME, $SITE_PROTOCOL, $SERVER_HOSTNAME, $SERVER_PATH;

    $baseUrl = "{$SITE_PROTOCOL}{$SERVER_HOSTNAME}{$SERVER_PATH}";
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
        error_log("$log_prefix SMTP (level $level): $message");
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

    if (!$mail->Send()) {
        error_log("$log_prefix SMTP: Unable to send email: " . $mail->ErrorInfo);
        return false;
    } else {
        error_log("$log_prefix SMTP: sent an email to $recipient_email ($recipient_name)");
        return true;
    }
}
