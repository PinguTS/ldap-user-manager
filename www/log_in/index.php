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
 $account_id = ldap_auth_username($ldap_connection,$_POST["user_id"],$_POST["password"]);

 // Check if user is a administrator
 $is_admin = false;
 $is_admin = ldap_is_group_member($ldap_connection,$LDAP['admin_role'],$account_id);
 
 // Check if user is a maintainer
 $is_maintainer = false;
 if ($account_id) {
   $is_maintainer = ldap_is_group_member($ldap_connection,$LDAP['maintainer_role'],$account_id);
 }
 
 // Check if user is an organization admin (but not global admin or maintainer)
 $is_org_admin = false;
 $user_org_name = null;
 if ($account_id && !$is_admin && !$is_maintainer) {
   // Get user DN to check organization roles
   $user_search = ldap_search($ldap_connection, $LDAP['org_dn'], "({$LDAP['account_attribute']}=" . ldap_escape($_POST["user_id"], "", LDAP_ESCAPE_FILTER) . ")", ["dn", "organization"]);
   if ($user_search) {
     $user_entries = ldap_get_entries($ldap_connection, $user_search);
     if ($user_entries["count"] > 0) {
       $user_dn = $user_entries[0]["dn"];
       $user_org = null;
       
       // Try to get organization from user entry
       if (isset($user_entries[0]["organization"][0])) {
         $user_org = $user_entries[0]["organization"][0];
       } else {
         // Extract from DN if organization attribute not set
         if (preg_match('/o=([^,]+),' . preg_quote($LDAP['org_ou'], '/') . ',/', $user_dn, $matches)) {
           $user_org = $matches[1];
         }
       }
       
                if ($user_org) {
           // Check if user is org admin for this organization
           // According to LDAP structure, org admins are stored under ou=roles within each organization
           $org_admin_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['org_admin_role']})(member=$user_dn))";
           $org_roles_dn = "ou=roles,o=" . ldap_escape($user_org, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
           $org_admin_search = @ldap_search($ldap_connection, $org_roles_dn, $org_admin_filter, ["cn"]);
           if ($org_admin_search) {
             $org_admin_result = ldap_get_entries($ldap_connection, $org_admin_search);
             if ($org_admin_result["count"] > 0) {
               $is_org_admin = true;
               $user_org_name = $user_org;
             }
           }
         }
     }
   }
   
   // If not found in organizations, check system users
   if (!$is_org_admin) {
     $user_search = ldap_search($ldap_connection, $LDAP['people_dn'], "({$LDAP['account_attribute']}=" . ldap_escape($_POST["user_id"], "", LDAP_ESCAPE_FILTER) . ")", ["dn", "organization"]);
     if ($user_search) {
       $user_entries = ldap_get_entries($ldap_connection, $user_search);
       if ($user_entries["count"] > 0) {
         $user_dn = $user_entries[0]["dn"];
         $user_org = null;
         
         // Try to get organization from user entry
         if (isset($user_entries[0]["organization"][0])) {
           $user_org = $user_entries[0]["organization"][0];
         }
         
         if ($user_org) {
           // Check if user is org admin for this organization
           // According to LDAP structure, org admins are stored under ou=roles within each organization
           $org_admin_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['org_admin_role']})(member=$user_dn))";
           $org_roles_dn = "ou=roles,o=" . ldap_escape($user_org, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
           $org_admin_search = @ldap_search($ldap_connection, $org_roles_dn, $org_admin_filter, ["cn"]);
           if ($org_admin_search) {
             $org_admin_result = ldap_get_entries($ldap_connection, $org_admin_search);
             if ($org_admin_result["count"] > 0) {
               $is_org_admin = true;
               $user_org_name = $user_org;
             }
           }
         }
       }
     }
   }
 }

 // If password failed, try passcode
 if ($account_id == FALSE && isset($_POST["passcode"]) && $_POST["passcode"] !== "") {
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
         break;
       }
     }
     
     if ($passcode_found) {
       $account_id = $_POST["user_id"];
       // Optionally, set $is_admin = false; (passcode logins are not admin)
     }
   }
 }

 ldap_close($ldap_connection);

 if ($account_id != FALSE) {
  // Record successful login attempt
  record_login_attempt($_POST["user_id"], true);
  
  set_passkey_cookie($account_id,$is_admin,$is_maintainer);
  if (isset($_POST["redirect_to"])) {
   $validated_redirect = validate_redirect_url($_POST['redirect_to']);
   if ($validated_redirect !== false) {
     header("Location: //{$_SERVER['HTTP_HOST']}" . $validated_redirect);
   } else {
     // Fallback to default location if redirect is invalid
     if ($is_admin) { 
       $default_module = "account_manager"; 
     } elseif ($is_maintainer) {
       $default_module = "account_manager/organizations"; 
     } elseif ($is_org_admin && $user_org_name) {
       $default_module = "account_manager/show_organization?org=" . urlencode($user_org_name);
     } else { 
       $default_module = "change_password"; 
     }
     header("Location: //{$_SERVER['HTTP_HOST']}{$SERVER_PATH}$default_module?logged_in");
   }
   exit;
  }
  else {
   if ($is_admin) { 
     $default_module = "account_manager"; 
   } elseif ($is_maintainer) {
     $default_module = "account_manager/organizations"; 
   } elseif ($is_org_admin && $user_org_name) {
     $default_module = "account_manager/show_organization?org=" . urlencode($user_org_name);
   } else { 
     $default_module = "change_password"; 
   }
   header("Location: //{$_SERVER['HTTP_HOST']}{$SERVER_PATH}$default_module?logged_in");
   exit;
  }
 }
 else {
  // Record failed login attempt
  record_login_attempt($_POST["user_id"], false);
  
  header("Location: //{$_SERVER['HTTP_HOST']}{$THIS_MODULE_PATH}/index.php?invalid");
  exit;
 }
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
