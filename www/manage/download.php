<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage(['ldap']);

// Require admin access for downloads
setPageAccess("admin");
setApiResponseHeaders();

if (!isset($_GET['resource_identifier']) || !isset($_GET['attribute'])) {
    http_response_code(400);
    exit("Missing required parameters");
}

// Accept attribute name only (allowlisted characters; used to request a binary LDAP attribute)
$this_attribute = (string) $_GET['attribute'];
if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*$/', $this_attribute)) {
    http_response_code(400);
    exit("Invalid attribute name");
}

// The resource identifier may be a UUID or a pre-validated DN stored in the app.
// Resolve it server-side: accept UUID → look up DN, or accept a raw DN only when
// the current user has admin/maintainer access and the DN stays within the configured base.
$resource_identifier = (string) $_GET['resource_identifier'];

$ldap_connection = lum_ldap_data_connection();
if ($ldap_connection === false) {
    http_response_code(503);
    exit("LDAP connection failed");
}

// Attempt UUID-based resolution (preferred — prevents DN injection)
$is_uuid    = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $resource_identifier);
$this_resource = null;

if ($is_uuid) {
    $entry = ldap_get_entry_by_uuid($ldap_connection, $resource_identifier, $LDAP['base_dn']);
    if ($entry && isset($entry['dn'])) {
        $this_resource = (string) $entry['dn'];
    }
} else {
    // Fall back to treating the identifier as a raw DN, but only when it matches
    // a simple allowlist (alphanumeric, spaces, common DN punctuation).
    // The DN must also live within the configured base DN.
    if (preg_match('/^[a-zA-Z0-9 =,+\-.@_]+$/', $resource_identifier)) {
        // Verify the DN is reachable and within the allowed subtree
        $dn_check = @ldap_read($ldap_connection, $resource_identifier, '(objectClass=*)', ['dn']);
        if ($dn_check && ldap_count_entries($ldap_connection, $dn_check) === 1) {
            $this_resource = $resource_identifier;
        }
    }
}

if ($this_resource === null) {
    lum_close_ldap_if_not_manage($ldap_connection);
    http_response_code(404);
    exit("Resource not found");
}

// Authorization: global admins/maintainers may download any entry;
// org admins only entries within their own organization.
if (!currentUserIsGlobalAdmin() && !currentUserIsMaintainer()) {
    $resource_org = getUserOrganization($this_resource);
    if (!$resource_org || !currentUserIsOrgManager($resource_org)) {
        lum_close_ldap_if_not_manage($ldap_connection);
        http_response_code(403);
        exit("Access denied");
    }
}

// Fetch the requested attribute using ldap_read (base-level search on exact DN)
$ldap_search = @ldap_read($ldap_connection, $this_resource, '(objectClass=*)', [$this_attribute]);

if ($ldap_search) {
    $records = ldap_get_entries($ldap_connection, $ldap_search);
    if ($records['count'] === 1) {
        $this_record = $records[0];
        if (isset($this_record[$this_attribute][0])) {
            $data      = $this_record[$this_attribute][0];
            $finfo     = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = $finfo ? finfo_buffer($finfo, $data) : null;
            if ($finfo) {
                finfo_close($finfo);
            }
            if (!$mime_type) {
                $mime_type = 'application/octet-stream';
            }
            // Build a safe filename from the sanitised resource + attribute
            $safe_resource  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $this_resource ?? '');
            $safe_attribute = preg_replace('/[^a-zA-Z0-9._-]/', '_', $this_attribute);
            $filename       = $safe_resource . '.' . $safe_attribute;
            header("Content-Type: $mime_type");
            header("Cache-Control: no-cache, private");
            header("Content-Transfer-Encoding: Binary");
            header("Content-Disposition: attachment; filename=\"$filename\"");
            header("Content-Length: " . strlen($data));
            print $data;
        }
    }
}

lum_close_ldap_if_not_manage($ldap_connection);
