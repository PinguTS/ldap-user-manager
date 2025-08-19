<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";

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
 $is_admin = ldap_is_group_member($ldap_connection,$LDAP['admin_role'],$account_id);

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
  
  set_passkey_cookie($account_id,$is_admin);
  if (isset($_POST["redirect_to"])) {
   $validated_redirect = validate_redirect_url($_POST['redirect_to']);
   if ($validated_redirect !== false) {
     header("Location: //{$_SERVER['HTTP_HOST']}" . $validated_redirect);
   } else {
     // Fallback to default location if redirect is invalid
     if ($IS_ADMIN) { $default_module = "account_manager"; } else { $default_module = "change_password"; }
     header("Location: //{$_SERVER['HTTP_HOST']}{$SERVER_PATH}$default_module?logged_in");
   }
   exit;
  }
  else {
   if ($IS_ADMIN) { $default_module = "account_manager"; } else { $default_module = "change_password"; }
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
