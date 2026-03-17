<?php

/**
 * Email (SMTP) verification: test connectivity and optional authentication.
 * Used by bin/verify-email.php and setup/verify.php. Caller must load config
 * (so $SMTP is available) and Composer/PHPMailer. No file I/O in this module.
 */

declare(strict_types=1);

/**
 * Run email/SMTP verification: connect to the configured SMTP server and
 * optionally authenticate. Does not send any email. No file I/O.
 *
 * @return array{passed: bool, message: string}
 */
function run_email_verification(): array
{
    global $SMTP;

    $host = isset($SMTP['host']) ? trim((string) $SMTP['host']) : '';
    if ($host === '') {
        return ['passed' => false, 'message' => 'SMTP host not configured'];
    }

    $port = isset($SMTP['port']) ? (int) $SMTP['port'] : 25;
    $user = isset($SMTP['user']) && $SMTP['user'] !== '' && $SMTP['user'] !== null
        ? (string) $SMTP['user'] : null;
    $pass = isset($SMTP['pass']) ? (string) $SMTP['pass'] : '';
    $useTls = !empty($SMTP['tls']);
    $useSsl = !empty($SMTP['ssl']);
    $helo = isset($SMTP['helo']) && $SMTP['helo'] !== '' && $SMTP['helo'] !== null
        ? (string) $SMTP['helo'] : null;

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAutoTLS = false;

        if ($helo !== null) {
            $mail->Helo = $helo;
        }
        if ($user !== null) {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
        }
        if ($useTls) {
            $mail->SMTPSecure = 'tls';
        } elseif ($useSsl) {
            $mail->SMTPSecure = 'ssl';
        }

        $mail->smtpConnect();

        return ['passed' => true, 'message' => 'SMTP connection and authentication OK'];
    } catch (Throwable $e) {
        return [
            'passed' => false,
            'message' => 'SMTP connection or authentication failed: ' . $e->getMessage(),
        ];
    }
}
