<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";

// Handle login POST before any output
if (isset($_POST["user_id"]) and (isset($_POST["password"]) || isset($_POST["passcode"]))) {

  // Check rate limiting before attempting authentication
  if (is_rate_limited($_POST["user_id"])) {
    http_response_code(429); // Too Many Requests
    header("Location: //{$_SERVER['HTTP_HOST']}{$THIS_MODULE_PATH}/index.php?rate_limited");
    exit;
  }

  $ldap_connection = open_ldap_connection();
  $user_dn = ldap_auth_username($ldap_connection,$_POST["user_id"],$_POST["password"]);

  // If password failed, try passcode
  if ($user_dn == FALSE && isset($_POST["passcode"]) && $_POST["passcode"] !== "") {
    // Search for user DN across all organizations and system users
    $user_search = ldap_search($ldap_connection, $LDAP['org_dn'], "({$LDAP['account_attribute']}=" . ldap_escape($_POST["user_id"], "", LDAP_ESCAPE_FILTER) . ")", ["dn", "userPassword"]);
    $user_entries = ldap_get_entries($ldap_connection, $user_search);
    
    // If not found in organizations, search in system users
    if ($user_entries["count"] == 0) {
      $user_search = ldap_search($ldap_connection, $LDAP['people_dn'], "({$LDAP['account_attribute']}=" . ldap_escape($_POST["user_id"], "", LDAP_ESCAPE_FILTER) . ")", ["dn", "userPassword"]);
      $user_entries = ldap_get_entries($ldap_connection, $user_search);
    }
    
    if ($user_entries["count"] > 0 && isset($user_entries[0]["userpassword"])) {
      // Check all userPassword values for passcode match
      $passcode_found = false;
      foreach ($user_entries[0]["userpassword"] as $index => $stored_hash) {
        if ($index === "count") continue; // Skip the count field
        
        // Skip if this looks like a regular password (not a passcode format)
        if (strpos($stored_hash, '{') === 0 && !preg_match('/^\{ARGON2\}|\{SSHA\}|\{CRYPT\}|\{SMD5\}|\{MD5\}|\{SHA\}/', $stored_hash)) {
          continue; // Skip non-passcode hash formats
        }
        
        // Verify passcode using LDAP-compatible hashing
        if (verify_ldap_passcode($_POST["passcode"], $stored_hash)) {
          $passcode_found = true;
          $user_dn = $user_entries[0]["dn"];
          break;
        }
      }
      
      if (!$passcode_found) {
        $user_dn = FALSE;
      }
    }
  }

  if (!$user_dn) {
    // Record failed login attempt
    record_login_attempt($_POST["user_id"], false);

    ldap_close($ldap_connection);

    // If we get here, the login failed
    header("Location: //{$_SERVER['HTTP_HOST']}{$THIS_MODULE_PATH}/index.php?invalid");
    exit;
  }

  // Get user UUID for internal use
  $user_uuid = ldap_user_get_uuid($ldap_connection, $user_dn);
  if (!$user_uuid) {
    // Fallback: extract username from DN for backward compatibility
    if (preg_match('/uid=([^,]+),/', $user_dn, $matches)) {
      $username = $matches[1];
    } else {
      $username = $_POST["user_id"];
    }
  } else {
    $username = $user_uuid;
  }

  // Check if user is a administrator
  $is_admin = false;
  $is_admin = ldap_is_group_member($ldap_connection, $LDAP['roles_dn'], $LDAP['admin_role'], $user_dn);

  // Check if user is a maintainer
  $is_maintainer = false;
  $is_maintainer = ldap_is_group_member($ldap_connection, $LDAP['roles_dn'], $LDAP['maintainer_role'], $user_dn);
 
  // Get user organization information first
  $user_org_name = null;
  $user_org_name = ldap_user_get_organization($ldap_connection, $user_dn);
  
  if ($LDAP_DEBUG) {
    error_log("Login: User DN: $user_dn");
    error_log("Login: Extracted organization name: " . ($user_org_name ?: 'NULL'));
  }

  // Check if user is an organization admin (but not global admin or maintainer)
  $is_org_admin = false;
  if ($user_org_name && !$is_admin && !$is_maintainer) {
    // Search for org admin role within the user's specific organization
    $org_roles_dn = "ou=roles,o=" . ldap_escape($user_org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $org_admin_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['org_admin_role']})(member=$user_dn))";
    
    if ($LDAP_DEBUG) {
      error_log("Login: Checking org admin role in: $org_roles_dn");
      error_log("Login: Using filter: $org_admin_filter");
    }
    
    $org_admin_search = @ldap_search($ldap_connection, $org_roles_dn, $org_admin_filter, ['cn']);
    if ($org_admin_search) {
      $org_admin_result = ldap_get_entries($ldap_connection, $org_admin_search);
      $is_org_admin = ($org_admin_result['count'] > 0);
      if ($LDAP_DEBUG) {
        error_log("Login: Org admin search result: " . $org_admin_result['count'] . " entries");
      }
    } else {
      if ($LDAP_DEBUG) {
        error_log("Login: Org admin search failed: " . ldap_error($ldap_connection));
      }
    }
  }

  // Get organization UUID for redirects (only if we have an organization name)
  $org_uuid = null;
  if ($user_org_name) {
    $org_uuid = ldap_organization_get_uuid($ldap_connection, $user_org_name);
    if ($LDAP_DEBUG) {
      error_log("Login: Organization '$user_org_name' UUID lookup result: " . ($org_uuid ?: 'FAILED'));
    }
  }
  
  ldap_close($ldap_connection);

  // Record successful login attempt
  record_login_attempt($_POST["user_id"], true);
  
  // Use UUID for cookie data when available, fallback to username
  $cookie_user_id = $user_uuid ?: $_POST["user_id"];
  set_passkey_cookie($cookie_user_id, $is_admin, $is_maintainer, $is_org_admin, $user_org_name, $org_uuid);
  
  if (isset($_POST["redirect_to"])) {
    $validated_redirect = validate_redirect_url($_POST['redirect_to']);
    if ($validated_redirect !== false) {
      header("Location: //{$_SERVER['HTTP_HOST']}" . $validated_redirect);
    }
    exit;
  }
  
  if ($is_admin) { 
    $default_module = "account_manager/index.php"; 
  } elseif ($is_maintainer) {
    $default_module = "account_manager/organizations.php"; 
  } elseif ($is_org_admin && $user_org_name && $org_uuid) {
    // Use UUID-based URL for better security
    $default_module = "account_manager/show_organization.php?uuid=" . urlencode($org_uuid);
  } elseif ($is_org_admin && $user_org_name) {
    // Fallback to name-based URL if UUID not available
    $default_module = "account_manager/show_organization.php?org=" . urlencode($user_org_name);
  } else { 
    $default_module = "change_password/index.php"; 
  }
  
  if ($LDAP_DEBUG) {
    error_log("Login: Redirecting to: $default_module");
    error_log("Login: User roles - Admin: " . ($is_admin ? 'YES' : 'NO') . ", Maintainer: " . ($is_maintainer ? 'YES' : 'NO') . ", Org Admin: " . ($is_org_admin ? 'YES' : 'NO'));
    error_log("Login: Organization info - Name: " . ($user_org_name ?: 'NULL') . ", UUID: " . ($org_uuid ?: 'NULL'));
    error_log("Login: SERVER_PATH: '$SERVER_PATH'");
    error_log("Login: HTTP_HOST: '{$_SERVER['HTTP_HOST']}'");
  }
  
  // Ensure the redirect URL is properly constructed
  // Check if default_module already has query parameters
  if (strpos($default_module, '?') !== false) {
    // Already has query parameters, use & to append logged_in
    $redirect_url = "//{$_SERVER['HTTP_HOST']}{$SERVER_PATH}$default_module&logged_in";
    if ($LDAP_DEBUG) {
      error_log("Login: Using & separator for logged_in (module has existing query params)");
    }
  } else {
    // No query parameters, use ? to start query string
    $redirect_url = "//{$_SERVER['HTTP_HOST']}{$SERVER_PATH}$default_module?logged_in";
    if ($LDAP_DEBUG) {
      error_log("Login: Using ? separator for logged_in (module has no query params)");
    }
  }
  
  // Ensure SERVER_PATH is properly set and ends with /
  if (empty($SERVER_PATH) || $SERVER_PATH === '/') {
    $SERVER_PATH = '/';
  } elseif (substr($SERVER_PATH, -1) !== '/') {
    $SERVER_PATH .= '/';
  }
  
  // Reconstruct URL with validated SERVER_PATH
  if (strpos($default_module, '?') !== false) {
    $redirect_url = "//{$_SERVER['HTTP_HOST']}{$SERVER_PATH}$default_module&logged_in";
  } else {
    $redirect_url = "//{$_SERVER['HTTP_HOST']}{$SERVER_PATH}$default_module?logged_in";
  }
  
  // Validate the final URL
  if (!filter_var($redirect_url, FILTER_VALIDATE_URL)) {
    error_log("Login: ERROR - Invalid redirect URL generated: $redirect_url");
    // Fallback to a safe URL
    $redirect_url = "//{$_SERVER['HTTP_HOST']}{$SERVER_PATH}change_password/index.php?logged_in";
    error_log("Login: Using fallback URL: $redirect_url");
  }
  
  if ($LDAP_DEBUG) {
    error_log("Login: Final redirect URL: $redirect_url");
    error_log("Login: SERVER_PATH after validation: '$SERVER_PATH'");
  }
  
  header("Location: $redirect_url");
  exit;
  
}

// Only render HTML after all possible headers are sent

if (isset($_GET["unauthorised"])) { $display_unauth = TRUE; }
if (isset($_GET["session_timeout"])) { $display_logged_out = TRUE; }
if (isset($_GET["redirect_to"])) { $redirect_to = $_GET["redirect_to"]; }

render_header("$ORGANISATION_NAME account manager - log in");

?>
<div class="container">
 <div class="col-sm-8 col-sm-offset-2">

  <div class="panel panel-default">
   <div class="panel-heading text-center">Log in</div>
   <div class="panel-body text-center">

   <?php if (isset($_GET['logged_out'])) { ?>
   <div class="alert alert-warning">
   <p class="text-center">You've been automatically logged out because you've been inactive for
   <?php print $SESSION_TIMEOUT; ?> minutes. Click on the 'Log in' link to get back into the system.</p>
   </div>
   <?php } ?>

   <?php if (isset($display_unauth)) { ?>
   <div class="alert alert-warning">
    Please log in to continue
   </div>
   <?php } ?>

   <?php if (isset($display_logged_out)) { ?>
   <div class="alert alert-warning">
    You were logged out because your session expired. Log in again to continue.
   </div>
   <?php } ?>

   <?php if (isset($_GET["invalid"])) { ?>
   <div class="alert alert-warning">
    The username and/or password are unrecognised.
   </div>
   <?php } ?>

   <?php if (isset($_GET["rate_limited"])) { ?>
   <div class="alert alert-danger">
    Too many login attempts. Please wait 5 minutes before trying again.
   </div>
   <?php } ?>

   <form class="form-horizontal" action='' method='post'>
    <?php if (isset($redirect_to) and ($redirect_to != "")) { ?><input type="hidden" name="redirect_to" value="<?php print htmlspecialchars($redirect_to); ?>"><?php } ?>

    <div class="form-group">
     <label for="username" class="col-sm-4 control-label"><?php print $SITE_LOGIN_FIELD_LABEL; ?></label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="user_id" name="user_id">
     </div>
    </div>

    <div class="form-group">
     <label for="password" class="col-sm-4 control-label">Password</label>
     <div class="col-sm-6">
      <input type="password" class="form-control" id="confirm" name="password">
     </div>
    </div>

    <div class="form-group">
     <label for="passcode" class="col-sm-4 control-label">Passcode (optional)</label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="passcode" name="passcode">
     </div>
    </div>

    <div class="form-group">
     <button type="submit" class="btn btn-default">Log in</button>
    </div>

   </form>
  </div>
 </div>
</div>
<?php
render_footer();
?>
