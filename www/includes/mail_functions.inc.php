<?php

declare(strict_types=1);

// PHPMailer loaded via Composer autoload (see config.inc.php)

// Default email templates (link-only; no passwords in email)

$new_account_mail_subject = (getenv('NEW_ACCOUNT_EMAIL_SUBJECT') ? getenv('NEW_ACCOUNT_EMAIL_SUBJECT') : 'Your {organisation} account is ready.');
$new_account_mail_body = getenv('NEW_ACCOUNT_EMAIL_BODY') ?: <<<EoNA
You've been set up with an account for {organisation}.
<p>
Login: {login}
<p>
To set your password, open this link (valid for {token_expires_minutes} minutes):
<p>
<a href="{password_set_url}">{password_set_url}</a>
EoNA;

$reset_password_mail_subject = (getenv('RESET_PASSWORD_EMAIL_SUBJECT') ? getenv('RESET_PASSWORD_EMAIL_SUBJECT') : 'Reset your {organisation} password.');
$reset_password_mail_body = getenv('RESET_PASSWORD_EMAIL_BODY') ?: <<<EoRP
A password reset was requested for your {organisation} account.
<p>
To set a new password, open this link (valid for {token_expires_minutes} minutes):
<p>
<a href="{password_reset_url}">{password_reset_url}</a>
EoRP;


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
        'change_password_url' => $baseUrl . 'change_password',
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
