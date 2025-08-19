<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";
include_once "organization_functions.inc.php";

$attribute_map = $LDAP['default_attribute_map'];
if (isset($LDAP['account_additional_attributes'])) { $attribute_map = ldap_complete_attribute_map($attribute_map,$LDAP['account_additional_attributes']); }

if (! array_key_exists($LDAP['account_attribute'], $attribute_map)) {
  $attribute_r = array_merge($attribute_map, array($LDAP['account_attribute'] => array("label" => "Account UID")));
}

if ( isset($_POST['setup_admin_account']) ) {

  $admin_setup = TRUE;

  validate_setup_cookie();
  set_page_access("setup");

  $completed_action="{$SERVER_PATH}log_in";
  $page_title="New administrator account";

  render_header("$ORGANISATION_NAME account manager - setup administrator account", FALSE);

}
else {
  set_page_access("admin");

  $completed_action="{$THIS_MODULE_PATH}/";
  $page_title="New account";
  $admin_setup = FALSE;

  render_header("$ORGANISATION_NAME account manager");
  render_submenu();
}

$invalid_password = FALSE;
$mismatched_passwords = FALSE;
$invalid_username = FALSE;
$weak_password = FALSE;
$invalid_email = FALSE;
$disabled_email_tickbox = TRUE;
$invalid_cn = FALSE;
$invalid_givenname = FALSE;
$invalid_sn = FALSE;
$invalid_account_identifier = FALSE;
$invalid_organization = FALSE;
$invalid_user_role = FALSE;
$account_attribute = $LDAP['account_attribute'];

$new_account_r = array();

// Get available organizations for selection
$available_organizations = [];
if (!$admin_setup) {
    $available_organizations = listOrganizations();
}

// Get available user roles
$available_user_roles = ['user', 'org_admin'];

foreach ($attribute_map as $attribute => $attr_r) {

  if (isset($_FILES[$attribute]['size']) and $_FILES[$attribute]['size'] > 0) {
    // File upload validation
    global $FILE_UPLOAD_MAX_SIZE, $FILE_UPLOAD_ALLOWED_MIME_TYPES;
    $max_file_size = $FILE_UPLOAD_MAX_SIZE;
    $allowed_mime_types = $FILE_UPLOAD_ALLOWED_MIME_TYPES;
    $file_size = $_FILES[$attribute]['size'];
    $file_tmp = $_FILES[$attribute]['tmp_name'];
    $file_error = $_FILES[$attribute]['error'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_tmp);
    finfo_close($finfo);
    if ($file_error !== UPLOAD_ERR_OK) {
      render_alert_banner('File upload error for ' . htmlspecialchars($attribute) . '.', 'danger', 10000);
      continue;
    }
    if ($file_size > $max_file_size) {
      render_alert_banner('File for ' . htmlspecialchars($attribute) . ' is too large (max 2MB).', 'danger', 10000);
      continue;
    }
    if (!in_array($mime_type, $allowed_mime_types)) {
      render_alert_banner('Invalid file type for ' . htmlspecialchars($attribute) . '. Allowed: images, PDF, text.', 'danger', 10000);
      continue;
    }
    $this_attribute = array();
    $this_attribute['count'] = 1;
    $this_attribute[0] = file_get_contents($file_tmp);
    $$attribute = $this_attribute;
    $new_account_r[$attribute] = $this_attribute;
    unset($new_account_r[$attribute]['count']);

  }

  if (isset($_POST[$attribute])) {

    $this_attribute = array();

    if (is_array($_POST[$attribute]) and count($_POST[$attribute]) > 0) {
      foreach($_POST[$attribute] as $key => $value) {
        if ($value != "") { $this_attribute[$key] = filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS); }
      }
      if (count($this_attribute) > 0) {
        $this_attribute['count'] = count($this_attribute);
        $$attribute = $this_attribute;
      }
    }
    elseif ($_POST[$attribute] != "") {
      $this_attribute['count'] = 1;
      $this_attribute[0] = filter_var($_POST[$attribute], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
      $$attribute = $this_attribute;
    }

  }

  if (!isset($$attribute) and isset($attr_r['default'])) {
    $$attribute['count'] = 1;
    $$attribute[0] = $attr_r['default'];
  }

  if (isset($$attribute)) {
    $new_account_r[$attribute] = $$attribute;
    unset($new_account_r[$attribute]['count']);
  }

}

##

if (isset($_GET['account_request'])) {

  $givenname[0]=filter_var($_GET['first_name'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  $new_account_r['givenname'] = $givenname[0];

  $sn[0]=filter_var($_GET['last_name'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  $new_account_r['sn'] = $sn[0];

  $mail[0]=filter_var($_GET['email'], FILTER_SANITIZE_EMAIL);
  if ($mail[0] == "") {
    if (isset($EMAIL_DOMAIN)) {
      $mail[0] = $uid . "@" . $EMAIL_DOMAIN;
      $disabled_email_tickbox = FALSE;
    }
  }
  else {
    $disabled_email_tickbox = FALSE;
  }
  $new_account_r['mail'] = $mail;
  unset($new_account_r['mail']['count']);

}


if (isset($_GET['account_request']) or isset($_POST['create_account'])) {

  if (!isset($uid[0])) {
    $uid[0] = generate_username($givenname[0],$sn[0]);
    $new_account_r['uid'] = $uid;
    unset($new_account_r['uid']['count']);
  }

  if (!isset($cn[0])) {
    if ($ENFORCE_SAFE_SYSTEM_NAMES == TRUE) {
      $cn[0] = $givenname[0] . $sn[0];
    }
    else {
      $cn[0] = $givenname[0] . " " . $sn[0];
    }
    $new_account_r['cn'] = $cn;
    unset($new_account_r['cn']['count']);
  }

}


if (isset($_POST['create_account'])) {

 $password  = $_POST['password'];
 $new_account_r['password'][0] = $password;
 $account_identifier = $new_account_r[$account_attribute][0];
 $this_cn=$cn[0];
 $this_mail=$mail[0];
 $this_givenname=$givenname[0];
 $this_sn=$sn[0];
 $this_password=$password[0];
 $this_organization = isset($organization[0]) ? $organization[0] : '';
 $this_user_role = isset($description[0]) ? $description[0] : 'user';

 if (!isset($this_cn) or $this_cn == "") { $invalid_cn = TRUE; }
 if ((!isset($account_identifier) or $account_identifier == "") and $invalid_cn != TRUE) { $invalid_account_identifier = TRUE; }
 if (!isset($this_givenname) or $this_givenname == "") { $invalid_givenname = TRUE; }
 if (!isset($this_sn) or $this_sn == "") { $invalid_sn = TRUE; }
 if ((!is_numeric($_POST['pass_score']) or $_POST['pass_score'] < 3) and $ACCEPT_WEAK_PASSWORDS != TRUE) { $weak_password = TRUE; }
 if (isset($this_mail) and !is_valid_email($this_mail)) { $invalid_email = TRUE; }
 if (preg_match("/\"|'/",$password)) { $invalid_password = TRUE; }
 if ($password != $_POST['password_match']) { $mismatched_passwords = TRUE; }
 if ($ENFORCE_SAFE_SYSTEM_NAMES == TRUE and !preg_match("/$USERNAME_REGEX/",$account_identifier)) { $invalid_account_identifier = TRUE; }
 
 // Validate organization selection (only for non-admin setup)
 if (!$admin_setup) {
   if (empty($this_organization)) {
     $invalid_organization = TRUE;
   } else {
     // Check if organization exists
     $org_exists = false;
     foreach ($available_organizations as $org) {
       if ($org['name'] === $this_organization) {
         $org_exists = true;
         break;
       }
     }
     if (!$org_exists) {
       $invalid_organization = TRUE;
     }
   }
 }
 
 // Validate user role
 if (!in_array($this_user_role, $available_user_roles)) {
   $invalid_user_role = TRUE;
 }
 
 if (isset($_POST['send_email']) and isset($mail) and $EMAIL_SENDING_ENABLED == TRUE) { $send_user_email = TRUE; }

 if (     isset($this_givenname)
      and isset($this_sn)
      and isset($this_password)
      and !$mismatched_passwords
      and !$weak_password
      and !$invalid_password
      and !$invalid_account_identifier
      and !$invalid_cn
      and !$invalid_email
      and !$invalid_organization
      and !$invalid_user_role) {

  $ldap_connection = open_ldap_connection();
  
  // For admin setup, create in people, otherwise in organization
  if ($admin_setup) {
    $new_account = ldap_new_account($ldap_connection, $new_account_r);
  } else {
    // Add organization to the account data
    $new_account_r['organization'] = [$this_organization];
    $new_account_r['description'] = [$this_user_role];
    $new_account = ldap_new_account($ldap_connection, $new_account_r);
  }

  if ($new_account) {

    $creation_message = "The account was created.";

    if (isset($send_user_email) and $send_user_email == TRUE) {

      include_once "mail_functions.inc.php";

      $mail_body = parse_mail_text($new_account_mail_body, $password, $account_identifier, $this_givenname, $this_sn);
      $mail_subject = parse_mail_text($new_account_mail_subject, $password, $account_identifier, $this_givenname, $this_sn);

      $sent_email = send_email($this_mail,"$this_givenname $this_sn",$mail_subject,$mail_body);
      $creation_message = "The account was created";
      if ($sent_email) {
        $creation_message .= " and an email sent to $this_mail.";
      }
      else {
        $creation_message .= " but unfortunately the email wasn't sent.<br>More information will be available in the logs.";
      }
    }

    if ($admin_setup == TRUE) {
              $member_add = ldap_add_member_to_group($ldap_connection, $LDAP['admin_role'], $account_identifier);
      if (!$member_add) { ?>
       <div class="alert alert-warning">
        <p class="text-center"><?php print htmlspecialchars($creation_message); ?> Unfortunately adding it to the admin group failed.</p>
       </div>
       <?php
      }
     #Tidy up empty uniquemember entries left over from the setup wizard
     $USER_ID="tmp_admin";
             ldap_delete_member_from_group($ldap_connection, $LDAP['admin_role'], "");
     if (isset($DEFAULT_USER_GROUP)) { ldap_delete_member_from_group($ldap_connection, $DEFAULT_USER_GROUP, ""); }
    } else {
      // Add user to organization admin role if selected
      if ($this_user_role === 'org_admin') {
        $org_admin_add = addUserToOrgAdmin($this_organization, $new_account);
        if (!$org_admin_add) {
          $creation_message .= " Warning: Failed to add user to organization admin role.";
        }
      }
    }

   ?>
   <div class="alert alert-success">
   <p class="text-center"><?php print htmlspecialchars($creation_message); ?></p>
   </div>
   <form action='<?php print $completed_action; ?>'>
    <p align="center">
     <input type='submit' class="btn btn-success" value='Finished'>
    </p>
   </form>
   <?php
   render_footer();
   exit(0);
  }
  else {
  ?>
    <div class="alert alert-warning">
     <p class="text-center">Failed to create the account:</p>
     <pre>
     <?php
       print ldap_error($ldap_connection) . "\n";
       ldap_get_option($ldap_connection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $detailed_err);
       print $detailed_err;
     ?>
     </pre>
    </div>
    <?php

   render_footer();
   exit(0);

  }

 }

}

$errors="";
if ($invalid_cn) { $errors.="<li>The Common Name is required</li>\n"; }
if ($invalid_givenname) { $errors.="<li>First Name is required</li>\n"; }
if ($invalid_sn) { $errors.="<li>Last Name is required</li>\n"; }
if ($invalid_account_identifier) {  $errors.="<li>The account identifier (" . $attribute_map[$account_attribute]['label'] . ") is invalid.</li>\n"; }
if ($weak_password) { $errors.="<li>The password is too weak</li>\n"; }
if ($invalid_password) { $errors.="<li>The password contained invalid characters</li>\n"; }
if ($invalid_email) { $errors.="<li>The email address is invalid</li>\n"; }
if ($mismatched_passwords) { $errors.="<li>The passwords are mismatched</li>\n"; }
if ($invalid_username) { $errors.="<li>The username is invalid</li>\n"; }
if ($invalid_organization) { $errors.="<li>Please select a valid organization</li>\n"; }
if ($invalid_user_role) { $errors.="<li>Please select a valid user role</li>\n"; }

if ($errors != "") { ?>
<div class="alert alert-warning">
 <p class="text-align: center">
 There were issues creating the account:
 <ul>
 <?php print strip_tags($errors, '<li>'); ?>
 </ul>
 </p>
</div>
<?php
}

render_js_username_check();
render_js_username_generator('givenname','sn','uid','uid_div');
render_js_cn_generator('givenname','sn','cn','cn_div');
render_js_email_generator('uid','mail');
render_js_homedir_generator('uid','homedirectory');

$tabindex=1;

?>
<script src="<?php print $SERVER_PATH; ?>js/zxcvbn.min.js"></script>
<script type="text/javascript" src="<?php print $SERVER_PATH; ?>js/zxcvbn-bootstrap-strength-meter.js"></script>
<script type="text/javascript">
 $(document).ready(function(){
   $("#StrengthProgressBar").zxcvbnProgressBar({ passwordInput: "#password" });
 });
</script>
<script type="text/javascript" src="<?php print $SERVER_PATH; ?>js/generate_passphrase.js"></script>
<script type="text/javascript" src="<?php print $SERVER_PATH; ?>js/wordlist.js"></script>
<script>

 function check_passwords_match() {

   if (document.getElementById('password').value != document.getElementById('confirm').value ) {
       document.getElementById('password_div').classList.add("has-error");
       document.getElementById('confirm_div').classList.add("has-error");
   }
   else {
    document.getElementById('password_div').classList.remove("has-error");
    document.getElementById('confirm_div').classList.remove("has-error");
   }
  }

 function random_password() {

  generatePassword(4,'-','password','confirm');
  $("#StrengthProgressBar").zxcvbnProgressBar({ passwordInput: "#password" });
 }

 function back_to_hidden(passwordField,confirmField) {

  var passwordField = document.getElementById(passwordField).type = 'password';
  var confirmField = document.getElementById(confirmField).type = 'password';

 }


</script>
<script>

 function check_email_validity(mail) {

  var check_regex = <?php print $JS_EMAIL_REGEX; ?>

  if (! check_regex.test(mail) ) {
   document.getElementById("mail_div").classList.add("has-error");
   <?php if ($EMAIL_SENDING_ENABLED == TRUE) { ?>document.getElementById("send_email_checkbox").disabled = true;<?php } ?>
  }
  else {
   document.getElementById("mail_div").classList.remove("has-error");
   <?php if ($EMAIL_SENDING_ENABLED == TRUE) { ?>document.getElementById("send_email_checkbox").disabled = false;<?php } ?>
  }

 }

</script>

<?php render_dynamic_field_js(); ?>

<div class="container">
 <div class="col-sm-8 col-md-offset-2">

  <div class="panel panel-default">
   <div class="panel-heading text-center"><?php print htmlspecialchars($page_title); ?></div>
   <div class="panel-body text-center">

    <form class="form-horizontal" action="" enctype="multipart/form-data" method="post">

     <?php if ($admin_setup == TRUE) { ?><input type="hidden" name="setup_admin_account" value="true"><?php } ?>
     <input type="hidden" name="create_account">
     <input type="hidden" id="pass_score" value="0" name="pass_score">
     <?= csrf_token_field() ?>

     <?php
       foreach ($attribute_map as $attribute => $attr_r) {
         $label = $attr_r['label'];
         if (isset($attr_r['onkeyup'])) { $onkeyup = $attr_r['onkeyup']; } else { $onkeyup = ""; }
         if ($attribute == $LDAP['account_attribute']) { $label = "<strong>$label</strong><sup>&ast;</sup>"; }
         if (isset($attr_r['required']) and $attr_r['required'] == TRUE) { $label = "<strong>$label</strong><sup>&ast;</sup>"; }
         if (isset($$attribute)) { $these_values=$$attribute; } else { $these_values = array(); }
         if (isset($attr_r['inputtype'])) { $inputtype = $attr_r['inputtype']; } else { $inputtype = ""; }
         
         // Special handling for organization and description fields
         if ($attribute === 'organization' && !$admin_setup) {
           echo '<div class="form-group" id="organization_div">';
           echo '<label for="organization" class="col-sm-3 control-label"><strong>Organization</strong><sup>&ast;</sup></label>';
           echo '<div class="col-sm-6">';
           echo '<select class="form-control" name="organization" id="organization" required>';
           echo '<option value="">Select an organization...</option>';
           foreach ($available_organizations as $org) {
             $selected = (isset($these_values[0]) && $these_values[0] === $org['name']) ? 'selected' : '';
             echo '<option value="' . htmlspecialchars($org['name']) . '" ' . $selected . '>' . htmlspecialchars($org['name']) . '</option>';
           }
           echo '</select>';
           echo '</div>';
           echo '</div>';
         } elseif ($attribute === 'description' && !$admin_setup) {
           echo '<div class="form-group" id="description_div">';
           echo '<label for="description" class="col-sm-3 control-label">User Role</label>';
           echo '<div class="col-sm-6">';
           echo '<select class="form-control" name="description" id="description">';
           foreach ($available_user_roles as $role) {
             $selected = (isset($these_values[0]) && $these_values[0] === $role) ? 'selected' : '';
             echo '<option value="' . htmlspecialchars($role) . '" ' . $selected . '>' . htmlspecialchars(ucfirst(str_replace('_', ' ', $role))) . '</option>';
           }
           echo '</select>';
           echo '</div>';
           echo '</div>';
         } else {
           render_attribute_fields($attribute,$label,$these_values,"",$onkeyup,$inputtype,$tabindex);
         }
         $tabindex++;
       }
     ?>

     <div class="form-group" id="password_div">
      <label for="password" class="col-sm-3 control-label">Password</label>
      <div class="col-sm-6">
       <input tabindex="<?php print $tabindex+1; ?>" type="text" class="form-control" id="password" name="password" onkeyup="back_to_hidden('password','confirm');">
      </div>
      <div class="col-sm-1">
       <input tabindex="<?php print $tabindex+2; ?>" type="button" class="btn btn-sm" id="password_generator" onclick="random_password();" value="Generate password">
      </div>
     </div>

     <div class="form-group" id="confirm_div">
      <label for="confirm" class="col-sm-3 control-label">Confirm</label>
      <div class="col-sm-6">
       <input tabindex="<?php print $tabindex+3; ?>" type="password" class="form-control" id="confirm" name="password_match" onkeyup="check_passwords_match()">
      </div>
     </div>

<?php  if ($EMAIL_SENDING_ENABLED == TRUE and $admin_setup != TRUE) { ?>
      <div class="form-group" id="send_email_div">
       <label for="send_email" class="col-sm-3 control-label"> </label>
       <div class="col-sm-6">
        <input tabindex="<?php print $tabindex+4; ?>" type="checkbox" class="form-check-input" id="send_email_checkbox" name="send_email" <?php if ($disabled_email_tickbox == TRUE) { print "disabled"; } ?>>  Email these credentials to the user?
       </div>
      </div>
<?php } ?>

     <div class="form-group">
       <button tabindex="<?php print $tabindex+5; ?>" type="submit" class="btn btn-warning">Create account</button>
     </div>

    </form>

    <div class="progress">
     <div id="StrengthProgressBar" class="progress-bar"></div>
    </div>

    <div><sup>&ast;</sup>The account identifier</div>

   </div>
  </div>

 </div>
</div>
<?php



render_footer();

?>
