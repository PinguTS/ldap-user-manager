<?php

/**
 * JSON API: request a password reset email (same behavior as POST to password/reset/).
 *
 * POST /api/v1/password/reset-request/
 * Content-Type: application/json
 * Body: {"email":"user@example.com"}
 *
 * Always returns the same generic message when the request is accepted (200) to avoid account enumeration.
 * Rate limits: per client IP and per normalized email address.
 */

declare(strict_types=1);

set_include_path('.:' . dirname(__DIR__, 4) . '/includes/');

include_once 'web_functions.inc.php';
include_once 'password_reset_functions.inc.php';
include_once 'mail_functions.inc.php';

setApiResponseHeaders();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$ipKey = 'api_pwreset_ip:' . $ip;

if (isRateLimited($ipKey, 30, 3600)) {
    http_response_code(429);
    echo json_encode([
        'error' => 'rate_limited',
        'message' => t('api.password_reset.rate_limited'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$decoded = json_decode(is_string($raw) ? $raw : '', true);
$email = '';
if (is_array($decoded) && isset($decoded['email'])) {
    $email = trim((string) $decoded['email']);
}

$genericMessage = t('password.reset.message');

if ($email !== '' && isValidEmail($email)) {
    $emailKey = 'api_pwreset_email:' . strtolower($email);
    if (isRateLimited($emailKey, 5, 3600)) {
        http_response_code(429);
        echo json_encode([
            'error' => 'rate_limited',
            'message' => t('api.password_reset.rate_limited'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

process_password_reset_request_for_email($email);

recordLoginAttempt($ipKey, false);
if ($email !== '' && isValidEmail($email)) {
    recordLoginAttempt('api_pwreset_email:' . strtolower($email), false);
}

http_response_code(200);
echo json_encode(['message' => $genericMessage], JSON_UNESCAPED_UNICODE);
