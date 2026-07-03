<?php

/**
 * Security Configuration File
 * Centralized security settings for the LDAP User Manager
 */

declare(strict_types=1);

// Security settings
$SECURITY_CONFIG = [
    // Rate limiting
    'rate_limit' => [
        'max_attempts' => 5,
        'time_window' => 300, // 5 minutes
        'lockout_duration' => 900 // 15 minutes
    ],

    // Session security
    'session' => [
        'timeout' => 3600, // 1 hour (more reasonable for form editing)
        'regenerate_id' => true,
        'secure_cookies' => true,
        'http_only' => true,
        'same_site' => 'strict'
    ],

    // Password policy
    'password' => [
        'min_length' => 8,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special' => false,
        'max_age' => 90 * 24 * 3600, // 90 days
        'allowed_algorithms' => [
            'SSHA',
            'SHA512CRYPT',
            'SHA256CRYPT',
            'ARGON2',
        ],
        'default_algorithm' => 'SSHA'
    ],

    // File upload security
    'file_upload' => [
        'max_size' => 2 * 1024 * 1024, // 2MB
        'allowed_types' => [
            'image/jpeg', 'image/png', 'image/gif',
            'application/pdf', 'text/plain'
        ],
        'scan_virus' => false, // Enable if antivirus available
        'validate_content' => true
    ],

    // LDAP security
    'ldap' => [
        'require_tls' => true,
        'ignore_cert_errors' => false,
        'max_connections' => 10,
        'connection_timeout' => 30
    ],

    // Security headers
    'security_headers' => [
        'x_frame_options' => 'DENY',
        'x_content_type_options' => 'nosniff',
        'x_xss_protection' => '1; mode=block',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'content_security_policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none';",
        'strict_transport_security' => 'max-age=31536000; includeSubDomains; preload'
    ],

    // Audit logging
    'audit' => [
        'enabled' => true,
        'log_level' => 'INFO', // DEBUG, INFO, WARN, ERROR
        'log_file' => '/var/log/ldap_user_manager/audit.log',
        'log_events' => [
            'login_success', 'login_failure', 'password_change',
            'user_creation', 'user_deletion', 'role_change',
            'admin_actions', 'file_access'
        ]
    ]
];

// Environment-based overrides
if (getenv('APP_ENV') === 'development') {
    $SECURITY_CONFIG['ldap']['require_tls'] = false;
    $SECURITY_CONFIG['ldap']['ignore_cert_errors'] = true;
    $SECURITY_CONFIG['security_headers']['strict_transport_security'] = false;
}

if (getenv('APP_ENV') === 'test') {
    $SECURITY_CONFIG['audit']['enabled'] = false;
    $SECURITY_CONFIG['rate_limit']['max_attempts'] = 100; // Allow more attempts in testing
}

// Make config globally available
$GLOBALS['SECURITY_CONFIG'] = $SECURITY_CONFIG;

/**
 * Write an audit log entry. No-op if audit logging is disabled or no log file configured.
 *
 * @param string               $level   Log level (e.g. INFO, WARN, ERROR)
 * @param string               $message Human-readable message
 * @param array<string, mixed>  $context Optional context data (e.g. dn, uuid, by)
 * @return void
 */
function auditLog(string $level, string $message, array $context = []): void
{
    $envEnabled = getenv('AUDIT_LOG_ENABLED');
    if ($envEnabled !== false && $envEnabled !== '') {
        $enabled = (strcasecmp($envEnabled, 'TRUE') === 0 || $envEnabled === '1');
    } else {
        $enabled = ($GLOBALS['SECURITY_CONFIG']['audit']['enabled'] ?? true);
    }
    if (!$enabled) {
        return;
    }
    $logFile = getenv('AUDIT_LOG_FILE') ?: ($GLOBALS['SECURITY_CONFIG']['audit']['log_file'] ?? '');
    if ($logFile === '') {
        return;
    }
    $contextStr = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    $line = date('c') . ' [' . $level . '] ' . $message . $contextStr . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
