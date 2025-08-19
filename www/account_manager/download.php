<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";

// Require admin access for downloads
set_page_access("admin");

if (!isset($_GET['resource_identifier']) or !isset($_GET['attribute'])) {
  http_response_code(400);
  exit("Missing required parameters");
}

// Validate and sanitize inputs
$this_resource = ldap_escape($_GET['resource_identifier'], "", LDAP_ESCAPE_FILTER);
$this_attribute = ldap_escape($_GET['attribute'], "", LDAP_ESCAPE_FILTER);

// Validate that resource_identifier is a proper LDAP DN
if (!preg_match('/^[a-zA-Z0-9=,+\-]+$/', $_GET['resource_identifier'])) {
  http_response_code(400);
  exit("Invalid resource identifier format");
}

// Validate that attribute is a safe attribute name
if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $_GET['attribute'])) {
  http_response_code(400);
  exit("Invalid attribute name");
}

// Additional security: ensure the resource is within allowed organizational scope
$ldap_connection = open_ldap_connection();

// Verify the DN exists and is accessible
$dn_check = ldap_read($ldap_connection, $this_resource, '(objectClass=*)', ['dn']);
if (!$dn_check || ldap_count_entries($ldap_connection, $dn_check) === 0) {
  ldap_close($ldap_connection);
  http_response_code(404);
  exit("Resource not found");
}

// Check if user has permission to access this resource
if (!currentUserIsGlobalAdmin() && !currentUserIsMaintainer()) {
  // For organization managers, check if the resource belongs to their organization
  $resource_org = getUserOrganization($this_resource);
  if (!$resource_org || !currentUserIsOrgManager($resource_org)) {
    ldap_close($ldap_connection);
    http_response_code(403);
    exit("Access denied");
  }
}

$exploded = ldap_explode_dn($this_resource,0);
$filter = $exploded[0];
$ldap_search_query="($filter)";
$ldap_search = ldap_search($ldap_connection, $this_resource, $ldap_search_query,array($this_attribute));

if ($ldap_search) {

  $records = ldap_get_entries($ldap_connection, $ldap_search);
  if ($records['count'] == 1) {
    $this_record = $records[0];
      if (isset($this_record[$this_attribute][0])) {
        // Determine MIME type from data
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_buffer($finfo, $this_record[$this_attribute][0]);
        finfo_close($finfo);
        if (!$mime_type) {
          $mime_type = 'application/octet-stream';
        }
        // Sanitize filename (allow only safe chars)
        $safe_resource = preg_replace('/[^a-zA-Z0-9._-]/', '_', $this_resource ?? '');
        $safe_attribute = preg_replace('/[^a-zA-Z0-9._-]/', '_', $this_attribute ?? '');
        $filename = $safe_resource . '.' . $safe_attribute;
        header("Content-Type: $mime_type");
        header("Cache-Control: no-cache, private");
        header("Content-Transfer-Encoding: Binary");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header("Content-Length: ". strlen($this_record[$this_attribute][0]));
        print $this_record[$this_attribute][0];
      }
  }

}

ldap_close($ldap_connection);
?>
