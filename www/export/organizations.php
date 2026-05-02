<?php

declare(strict_types=1);

// Must be the very first check – before any LDAP or output operations.
$secret = getenv('EXPORT_SHARED_SECRET');
if ($secret === false || $secret === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Export not configured');
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$provided = '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $provided = substr($authHeader, 7);
}

if (!hash_equals($secret, $provided)) {
    include_once __DIR__ . '/../includes/security_config.inc.php';
    if (function_exists('auditLog')) {
        auditLog('WARN', 'Organization membership export: invalid secret', [
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="ldap-user-manager-export"');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Unauthorized');
}

set_include_path(__DIR__ . '/../includes');
include_once __DIR__ . '/../includes/web_functions.inc.php';
include_once __DIR__ . '/../includes/config.inc.php';
setApiResponseHeaders();
include_once __DIR__ . '/../includes/ldap_functions.inc.php';
include_once __DIR__ . '/../includes/organization_functions.inc.php';

$format = $_GET['format'] ?? 'json';
$allowedFormats = ['json', 'json_typo3', 'csv'];
if (!in_array($format, $allowedFormats, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Invalid format');
}

$baseDn = $LDAP['base_dn'] ?? '';
$orgDn = $LDAP['org_dn'] ?? '';
if ($baseDn === '' || $orgDn === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Export not configured');
}

$memberGroupCn = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
$disabledGroupCn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';
$typo3Pid = (int) (getenv('TYPO3_EXPORT_PID') ?: '0');

$ldap = open_ldap_connection();
if (!$ldap) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Export unavailable');
}

$attrs = [
    'o', 'mail', 'postalAddress', 'telephoneNumber', 'facsimileTelephoneNumber',
    'labeledURI', 'description', 'businessCategory', 'documentIdentifier',
    'entryUUID',
];
$search = @ldap_list($ldap, $orgDn, '(objectClass=organization)', $attrs, 0, 0);
if ($search === false) {
    ldap_close($ldap);
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Export unavailable');
}

$entries = ldap_get_entries($ldap, $search);
if (is_object($search)) {
    ldap_free_result($search);
}

$records = [];
for ($i = 0; $i < (int) $entries['count']; $i++) {
    $org = $entries[$i];
    $orgDnEntry = $org['dn'];
    if (!is_in_status_group($ldap, $orgDnEntry, $memberGroupCn, $baseDn)) {
        continue;
    }
    if (is_in_status_group($ldap, $orgDnEntry, $disabledGroupCn, $baseDn)) {
        continue;
    }
    $postalAddress = $org['postaladdress'][0] ?? '';
    $parsed = parsePostalAddress($postalAddress);
    $docIdentifiers = $org['documentidentifier'] ?? [];
    $decodedMembership = parseDocumentIdentifierMembership(is_array($docIdentifiers) ? $docIdentifiers : [$docIdentifiers]);
    $records[] = [
        'dn' => $orgDnEntry,
        'o' => $org['o'][0] ?? '',
        'mail' => $org['mail'][0] ?? '',
        'telephoneNumber' => $org['telephonenumber'][0] ?? '',
        'facsimileTelephoneNumber' => $org['facsimiletelephonenumber'][0] ?? '',
        'postalAddress' => $postalAddress,
        'postalAddress_street' => $parsed['street'],
        'postalAddress_zip' => $parsed['zip'],
        'postalAddress_city' => $parsed['city'],
        'postalAddress_country' => $parsed['country'],
        'labeledURI' => $org['labeleduri'][0] ?? '',
        'description' => $org['description'][0] ?? '',
        'businessCategory' => $org['businesscategory'][0] ?? '',
        'memberNumber' => $decodedMembership['memberNumber'],
        'memberSince' => $decodedMembership['memberSince'],
        'memberUntil' => $decodedMembership['memberUntil'],
        'entryUUID' => $org['entryuuid'][0] ?? '',
    ];
}
ldap_close($ldap);

if (function_exists('auditLog')) {
    auditLog('INFO', 'Organization membership export generated', [
        'format' => $format,
        'count' => count($records),
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['organizations' => $records], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($format === 'json_typo3') {
    $typo3Records = [];
    foreach ($records as $r) {
        $typo3Records[] = [
            'pid' => $typo3Pid,
            'company' => $r['o'],
            'email' => $r['mail'],
            'phone' => $r['telephoneNumber'],
            'fax' => $r['facsimileTelephoneNumber'],
            'address' => $r['postalAddress_street'],
            'zip' => $r['postalAddress_zip'],
            'city' => $r['postalAddress_city'],
            'country' => $r['postalAddress_country'],
            'www' => $r['labeledURI'],
            'description' => $r['description'],
            'tx_orgtype' => $r['businessCategory'],
            'tx_member_number' => $r['memberNumber'],
            'tx_member_since' => $r['memberSince'],
            'tx_member_until' => $r['memberUntil'],
            '_meta' => [
                'ldap_dn' => $r['dn'],
                'ldap_uuid' => $r['entryUUID'],
            ],
        ];
    }
    $payload = [
        'export_version' => '1.1',
        'export_date' => gmdate('Y-m-d\TH:i:s\Z'),
        'source' => 'ldap-user-manager',
        'record_type' => 'tt_address',
        'pid' => $typo3Pid,
        'records' => $typo3Records,
    ];
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="organizations.csv"');
$out = fopen('php://output', 'w');
if ($out !== false) {
    fputcsv($out, [
        'company', 'email', 'phone', 'fax', 'address', 'zip', 'city', 'country',
        'www', 'description', 'tx_orgtype', 'tx_member_number', 'tx_member_since', 'tx_member_until', 'entryUUID',
    ]);
    foreach ($records as $r) {
        fputcsv($out, [
            $r['o'], $r['mail'], $r['telephoneNumber'], $r['facsimileTelephoneNumber'],
            $r['postalAddress_street'], $r['postalAddress_zip'], $r['postalAddress_city'], $r['postalAddress_country'],
            $r['labeledURI'], $r['description'], $r['businessCategory'],
            $r['memberNumber'], $r['memberSince'], $r['memberUntil'], $r['entryUUID'],
        ]);
    }
    fclose($out);
}
