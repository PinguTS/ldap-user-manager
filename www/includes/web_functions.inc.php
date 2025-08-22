<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'ldap_functions.inc.php';
#Security level vars

$VALIDATED = FALSE;
$IS_ADMIN = FALSE;
$IS_MAINTAINER = FALSE;
$IS_SETUP_ADMIN = FALSE;
$ACCESS_LEVEL_NAME = array('account','admin');
unset($USER_ID);
$CURRENT_PAGE=htmlentities($_SERVER['PHP_SELF']);
$SENT_HEADERS = FALSE;
$SESSION_TIMED_OUT = FALSE;

$paths=explode('/',getcwd());
$THIS_MODULE=end($paths);

$GOOD_ICON = "&#9745;";
$WARN_ICON = "&#9888;";
$FAIL_ICON = "&#9940;";
$INFO_ICON = "&#8505;";

$JS_EMAIL_REGEX='/^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;';

if (isset($_SERVER['HTTPS']) and
   ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) or
   isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and
   $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
  $SITE_PROTOCOL = 'https://';
}
else {
  $SITE_PROTOCOL = 'http://';
}

include_once ("config.inc.php");    # get local settings
include_once ("modules.inc.php");   # module definitions

if (substr($SERVER_PATH, -1) != "/") { $SERVER_PATH .= "/"; }
$THIS_MODULE_PATH="{$SERVER_PATH}{$THIS_MODULE}";

$DEFAULT_COOKIE_OPTIONS = array( 
    'expires' => time()+(60 * $SESSION_TIMEOUT),
    'path' => $SERVER_PATH,
    'domain' => '',
    // Allow Secure=false if $NO_HTTPS is true (e.g., behind a proxy)
    'secure' => $NO_HTTPS ? FALSE : TRUE,
    'httponly' => TRUE,
    'samesite' => 'strict'
);

if ($REMOTE_HTTP_HEADERS_LOGIN) {
  login_via_headers();
} else {
  validate_passkey_cookie();
}


######################################################

function generate_passkey() {

 $rnd1 = mt_rand(10000000, mt_getrandmax());
 $rnd2 = mt_rand(10000000, mt_getrandmax());
 $rnd3 = mt_rand(10000000, mt_getrandmax());
 return sprintf("%0x",$rnd1) . sprintf("%0x",$rnd2) . sprintf("%0x",$rnd3);

}


######################################################

function set_passkey_cookie($user_id,$is_admin,$is_maintainer = false) {

 # Create a random value, store it locally and set it in a cookie.

 global $SESSION_TIMEOUT, $VALIDATED, $USER_ID, $IS_ADMIN, $IS_MAINTAINER, $log_prefix, $SESSION_DEBUG, $DEFAULT_COOKIE_OPTIONS;


 $passkey = generate_passkey();
 $this_time=time();
 $admin_val = 0;
 $maintainer_val = 0;

 if ($is_admin == TRUE ) {
  $admin_val = 1;
  $IS_ADMIN = TRUE;
 }
 
 if ($is_maintainer == TRUE ) {
  $maintainer_val = 1;
  $IS_MAINTAINER = TRUE;
 }
 
 // Clean up any existing session files for this user
 $filename = preg_replace('/[^a-zA-Z0-9]/','_', $user_id ?? '');
 $old_session_file = "/tmp/$filename";
 if (file_exists($old_session_file)) {
     unlink($old_session_file);
 }
 
 @ file_put_contents("/tmp/$filename","$passkey:$admin_val:$maintainer_val:$this_time");
 setcookie('orf_cookie', "$user_id:$passkey", $DEFAULT_COOKIE_OPTIONS);
 $sessto_cookie_opts = $DEFAULT_COOKIE_OPTIONS;
 $sessto_cookie_opts['expires'] = $this_time+7200;
 setcookie('sessto_cookie', $this_time+(60 * $SESSION_TIMEOUT), $sessto_cookie_opts);
 
 if ( $SESSION_DEBUG == TRUE) {  error_log("$log_prefix Session: user $user_id validated (IS_ADMIN={$IS_ADMIN}, IS_MAINTAINER={$IS_MAINTAINER}), sent orf_cookie to the browser.",0); }
 $VALIDATED = TRUE;

 // Regenerate session ID on login to prevent session fixation
 if (session_status() === PHP_SESSION_ACTIVE) {
     session_regenerate_id(true);
 }
 
 // Store user info in session for additional security
 $_SESSION['user_id'] = $user_id;
 $_SESSION['is_admin'] = $is_admin;
 $_SESSION['is_maintainer'] = $is_maintainer;
 $_SESSION['login_time'] = $this_time;
 $_SESSION['last_activity'] = $this_time;

}


######################################################

function login_via_headers() {

  global $IS_ADMIN, $USER_ID, $VALIDATED, $LDAP, $currentUserGroups;
  //['admins_group'];
  $USER_ID = $_SERVER['HTTP_REMOTE_USER'];
  $remote_groups = explode(',',$_SERVER['HTTP_REMOTE_GROUPS']);
          $IS_ADMIN = in_array($LDAP['admin_role'],$remote_groups);
  // users are always validated as we assume, that the auth server does this
  $VALIDATED = true;
  // Populate currentUserGroups from LDAP
  $ldap_connection = open_ldap_connection();
  $currentUserGroups = ldap_user_group_membership($ldap_connection, $USER_ID);
  ldap_close($ldap_connection);
}


######################################################

function validate_passkey_cookie() {

  global $SESSION_TIMEOUT, $IS_ADMIN, $IS_MAINTAINER, $USER_ID, $VALIDATED, $log_prefix, $SESSION_TIMED_OUT, $SESSION_DEBUG, $LDAP, $currentUserGroups;

  $this_time=time();
  $VALIDATED = FALSE;
  $IS_ADMIN = FALSE;
  $IS_MAINTAINER = FALSE;

  if (isset($_COOKIE['orf_cookie'])) {

    list($user_id,$c_passkey) = explode(":",$_COOKIE['orf_cookie']);
    
    // Validate user_id format
    if (!preg_match('/^[a-zA-Z0-9@._-]+$/', $user_id)) {
      if ($SESSION_DEBUG == TRUE) { error_log("$log_prefix Session: Invalid user_id format in cookie",0); }
      return;
    }
    
    $filename = preg_replace('/[^a-zA-Z0-9]/','_', $user_id ?? '');
    $session_file = @ file_get_contents("/tmp/$filename");
    if (!$session_file) {
      if ($SESSION_DEBUG == TRUE) {  error_log("$log_prefix Session: orf_cookie was sent by the client but the session file wasn't found at /tmp/$filename",0); }
    }
    else {
      $session_fields = explode(":",$session_file);
      $field_count = count($session_fields);
      
      // Handle backward compatibility for old session format (3 fields) vs new format (4 fields)
      if ($field_count === 3) {
        // Old format: passkey:admin:time
        list($f_passkey,$f_is_admin,$f_time) = $session_fields;
        $f_is_maintainer = 0; // Default to not maintainer for old sessions
      } elseif ($field_count === 4) {
        // New format: passkey:admin:maintainer:time
        list($f_passkey,$f_is_admin,$f_is_maintainer,$f_time) = $session_fields;
      } else {
        // Invalid format
        if ($SESSION_DEBUG == TRUE) { error_log("$log_prefix Session: Invalid session file format for user $user_id - expected 3 or 4 fields, got $field_count",0); }
        @unlink("/tmp/$filename");
        return;
      }
      
      // Validate session data format
      if (!is_numeric($f_time) || !is_numeric($f_is_admin) || !is_numeric($f_is_maintainer)) {
        if ($SESSION_DEBUG == TRUE) { error_log("$log_prefix Session: Invalid session file data types for user $user_id",0); }
        // Clean up corrupted session file
        @unlink("/tmp/$filename");
        return;
      }
      
      // Check if session has expired
      if ($this_time >= $f_time+(60 * $SESSION_TIMEOUT)) {
        if ($SESSION_DEBUG == TRUE) { error_log("$log_prefix Session: Session expired for user $user_id",0); }
        @unlink("/tmp/$filename");
        $SESSION_TIMED_OUT = TRUE;
        return;
      }
      
      // Validate passkey
      if (!empty($c_passkey) and $f_passkey == $c_passkey) {
        if ($f_is_admin == 1) { $IS_ADMIN = TRUE; }
        if ($f_is_maintainer == 1) { $IS_MAINTAINER = TRUE; }
        $VALIDATED = TRUE;
        $USER_ID=$user_id;
        
        // Update last activity time
        $new_session_data = "$f_passkey:$f_is_admin:$f_is_maintainer:$f_time";
        @ file_put_contents("/tmp/$filename", $new_session_data);
        
        if ($SESSION_DEBUG == TRUE) {  error_log("$log_prefix Setup session: Cookie and session file values match for user {$user_id} - VALIDATED (ADMIN = {$IS_ADMIN}, MAINTAINER = {$IS_MAINTAINER})",0); }
        set_passkey_cookie($USER_ID,$IS_ADMIN,$IS_MAINTAINER);
        // Populate currentUserGroups from LDAP
        $ldap_connection = open_ldap_connection();
        $currentUserGroups = ldap_user_group_membership($ldap_connection, $USER_ID);
        ldap_close($ldap_connection);
      }
      else {
        if ($SESSION_DEBUG == TRUE) {
          $this_error="$log_prefix Session: orf_cookie was sent by the client and the session file was found at /tmp/$filename, but";
          if (empty($c_passkey)) { $this_error .= " the cookie passkey wasn't set;"; }
          if ($c_passkey != $f_passkey) { $this_error .= " the session file passkey didn't match the cookie passkey;"; }
          $this_error.=" Cookie: {$_COOKIE['orf_cookie']} - Session file contents: $session_file";
          error_log($this_error,0);
        }
      }
    }

  }
  else {
    if ($SESSION_DEBUG == TRUE) { error_log("$log_prefix Session: orf_cookie wasn't sent by the client.",0); }
    if (isset($_COOKIE['sessto_cookie'])) {
      $this_session_timeout = $_COOKIE['sessto_cookie'];
      if ($this_time >= $this_session_timeout) {
        $SESSION_TIMED_OUT = TRUE;
        if ($SESSION_DEBUG == TRUE) { error_log("$log_prefix Session: The session had timed-out (over $SESSION_TIMEOUT mins idle).",0); }
      }
    }
  }

}


######################################################

function set_setup_cookie() {

 # Create a random value, store it locally and set it in a cookie.

 global $SESSION_TIMEOUT, $IS_SETUP_ADMIN, $log_prefix, $SESSION_DEBUG, $DEFAULT_COOKIE_OPTIONS;

 $passkey = generate_passkey();
 $this_time=time();

 $IS_SETUP_ADMIN = TRUE;

 @ file_put_contents("/tmp/ldap_setup","$passkey:$this_time");

 setcookie('setup_cookie', $passkey, $DEFAULT_COOKIE_OPTIONS);

 if ( $SESSION_DEBUG == TRUE) {  error_log("$log_prefix Setup session: sent setup_cookie to the client.",0); }

 // Regenerate session ID on setup login to prevent session fixation
 if (session_status() === PHP_SESSION_ACTIVE) {
     session_regenerate_id(true);
 }

}


######################################################

function validate_setup_cookie() {

 global $SESSION_TIMEOUT, $IS_SETUP_ADMIN, $log_prefix, $SESSION_DEBUG;

 if (isset($_COOKIE['setup_cookie'])) {

  $c_passkey = $_COOKIE['setup_cookie'];
  $session_file = file_get_contents("/tmp/ldap_setup");
  if (!$session_file) {
   $IS_SETUP_ADMIN = FALSE;
   if ( $SESSION_DEBUG == TRUE) {  error_log("$log_prefix Setup session: setup_cookie was sent by the client but the session file wasn't found at /tmp/ldap_setup",0); }
  }
  list($f_passkey,$f_time) = explode(":",$session_file);
  $this_time=time();
  if (!empty($c_passkey) and $f_passkey == $c_passkey and $this_time < $f_time+(60 * $SESSION_TIMEOUT)) {
   $IS_SETUP_ADMIN = TRUE;
   if ( $SESSION_DEBUG == TRUE) {  error_log("$log_prefix Setup session: Cookie and session file values match - VALIDATED ",0); }
   set_setup_cookie();
  }
  elseif ( $SESSION_DEBUG == TRUE) {
   $this_error="$log_prefix Setup session: setup_cookie was sent by the client and the session file was found at /tmp/ldap_setup, but";
   if (empty($c_passkey)) { $this_error .= " the cookie passkey wasn't set;"; }
   if ($c_passkey != $f_passkey) { $this_error .= " the session file passkey didn't match the cookie passkey;"; }
   $this_error += " Cookie: {$_COOKIE['setup_cookie']} - Session file contents: $session_file";
   error_log($this_error,0);
  }
 }
 elseif ( $SESSION_DEBUG == TRUE) {
   error_log("$log_prefix Session: setup_cookie wasn't sent by the client.",0);
 }

}


######################################################

function log_out($method='normal') {

 # Delete the passkey from the database and the passkey cookie

 global $USER_ID, $SERVER_PATH, $DEFAULT_COOKIE_OPTIONS;

 $this_time=time();

 $orf_cookie_opts = $DEFAULT_COOKIE_OPTIONS;
 $orf_cookie_opts['expires'] = $this_time-20000;
 $sessto_cookie_opts = $DEFAULT_COOKIE_OPTIONS;
 $sessto_cookie_opts['expires'] = $this_time-20000;

 setcookie('orf_cookie', "", $DEFAULT_COOKIE_OPTIONS);
 setcookie('sessto_cookie', "", $DEFAULT_COOKIE_OPTIONS);

 $filename = preg_replace('/[^a-zA-Z0-9]/','_', $USER_ID ?? '');
 @ unlink("/tmp/$filename");

 if ($method == 'auto') { $options = "?logged_out"; } else { $options = ""; }
 header("Location:  //{$_SERVER["HTTP_HOST"]}{$SERVER_PATH}index.php$options\n\n");

}


######################################################

function render_header($title="",$menu=TRUE) {

 global $SITE_NAME, $IS_ADMIN, $SENT_HEADERS, $SERVER_PATH, $CUSTOM_STYLES;

 if (empty($title)) { $title = $SITE_NAME; }

 # Set security headers
 set_security_headers();

 #Initialise the HTML output for the page.

 ?>
<HTML>
<HEAD>
 <TITLE><?php print "$title"; ?></TITLE>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width, initial-scale=1">
 <link rel="stylesheet" href="<?php print $SERVER_PATH; ?>bootstrap/css/bootstrap.min.css">
 <?php if ($CUSTOM_STYLES) echo '<link rel="stylesheet" href="'.$CUSTOM_STYLES.'">' ?>
 <script src="<?php print $SERVER_PATH; ?>js/jquery-3.6.0.min.js"></script>
 <script src="<?php print $SERVER_PATH; ?>bootstrap/js/bootstrap.min.js"></script>
</HEAD>
<BODY>
<?php

 if ($menu == TRUE) {
  render_menu();
 }

 if (isset($_GET['logged_in'])) {

  ?>
  <script>
    window.setTimeout(function() { $(".alert").fadeTo(500, 0).slideUp(500, function(){ $(this).remove(); }); }, 10000);
  </script>
  <div class="alert alert-success">
    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="TRUE">&times;</span></button>
    <p class="text-center">You've logged in successfully.</p>
  </div>
  <?php

 }
 $SENT_HEADERS = TRUE;

}

/**
 * Set security headers to prevent common web attacks
 */
function set_security_headers() {
    global $SECURITY_CONFIG;
    
    // Set security headers from configuration
    $headers = $SECURITY_CONFIG['security_headers'];
    
    // Prevent clickjacking
    header('X-Frame-Options: ' . $headers['x_frame_options']);
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: ' . $headers['x_content_type_options']);
    
    // Enable XSS protection
    header('X-XSS-Protection: ' . $headers['x_xss_protection']);
    
    // Referrer policy
    header('Referrer-Policy: ' . $headers['referrer_policy']);
    
    // Content Security Policy
    header('Content-Security-Policy: ' . $headers['content_security_policy']);
    
    // HSTS (only if HTTPS and enabled)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' && $headers['strict_transport_security']) {
        header('Strict-Transport-Security: ' . $headers['strict_transport_security']);
    }
}


######################################################

function render_menu() {

 #Render the navigation menu.
 #The menu is dynamically rendered the $MODULES hash

 global $SITE_NAME, $MODULES, $THIS_MODULE, $VALIDATED, $IS_ADMIN, $USER_ID, $SERVER_PATH, $CUSTOM_LOGO;

 ?>
  <nav class="navbar navbar-default">
   <div class="container-fluid">
   <div class="navbar-header"><?php
      if ($CUSTOM_LOGO) echo '<span class="navbar-brand"><img src="'.$CUSTOM_LOGO.'" class="logo" alt="logo"></span>'
     ?><a class="navbar-brand" href="./"><?php print $SITE_NAME ?></a>
   </div>
     <ul class="nav navbar-nav">
     <?php
     foreach ($MODULES as $module => $access) {

      $this_module_name=stripslashes(ucwords(preg_replace('/_/',' ', $module ?? '')));

      $show_this_module = TRUE;
      if ($VALIDATED == TRUE) {
       if ($access == 'hidden_on_login') { $show_this_module = FALSE; }
       if ($IS_ADMIN == FALSE and $access == 'admin' ){ $show_this_module = FALSE; }
      }
      else {
       if ($access != 'hidden_on_login') { $show_this_module = FALSE; }
      }
      #print "<p>$module - access is $access & show is $show_this_module</p>";
      if ($show_this_module == TRUE ) {
       if ($module == $THIS_MODULE) {
        print "<li class='active'>";
       }
       else {
        print '<li>';
       }
       print "<a href='{$SERVER_PATH}{$module}/'>$this_module_name</a></li>\n";
      }
     }
     ?>
     </ul>
     <ul class="nav navbar-nav navbar-right">
      <li><a style="color:#333"><?php if(isset($USER_ID)) { print $USER_ID; } ?></a></li>
     </ul>
   </div>
  </nav>
 <?php
}


######################################################

function render_footer() {

#Finish rendering an HTML page.

?>
 </BODY>
</HTML>
<?php

}


######################################################

function set_page_access($level) {

 global $IS_ADMIN, $IS_SETUP_ADMIN, $VALIDATED, $log_prefix, $SESSION_DEBUG, $SESSION_TIMED_OUT, $SERVER_PATH;

 #Set the security level needed to view a page.
 #This should be one of the first pieces of code
 #you call on a page.
 #Either 'setup', 'admin' or 'user'.

 if ($level == "setup") {
  if ($IS_SETUP_ADMIN == TRUE) {
   return;
  }
  else {
   header("Location: //" . $_SERVER["HTTP_HOST"] . "{$SERVER_PATH}setup/index.php?unauthorised\n\n");
   if ( $SESSION_DEBUG == TRUE) {  error_log("$log_prefix Session: UNAUTHORISED: page security level is 'setup' but IS_SETUP_ADMIN isn't TRUE",0); }
   exit(0);
  }
 }

 if ($SESSION_TIMED_OUT == TRUE) { $reason = "session_timeout"; } else { $reason = "unauthorised"; }

 if ($level == "admin") {
  if ($IS_ADMIN == TRUE and $VALIDATED == TRUE) {
   return;
  }
  else {
   header("Location: //" . $_SERVER["HTTP_HOST"] . "{$SERVER_PATH}log_in/index.php?$reason&redirect_to=" . base64_encode($_SERVER['REQUEST_URI']) . "\n\n");
   if ( $SESSION_DEBUG == TRUE) {  error_log("$log_prefix Session: no access to page ($reason): page security level is 'admin' but IS_ADMIN = '{$IS_ADMIN}' and VALIDATED = '{$VALIDATED}' (user) ",0); }
   exit(0);
  }
 }

 if ($level == "user") {
  if ($VALIDATED == TRUE){
   return;
  }
  else {
   header("Location: //" . $_SERVER["HTTP_HOST"] . "{$SERVER_PATH}log_in/index.php?$reason&redirect_to=" . base64_encode($_SERVER['REQUEST_URI']) . "\n\n");
   if ( $SESSION_DEBUG == TRUE) {  error_log("$log_prefix Session: no access to page ($reason): page security level is 'user' but VALIDATED = '{$VALIDATED}'",0); }
   exit(0);
  }
 }

}


######################################################

function is_valid_email($email) {

 return (!filter_var($email, FILTER_VALIDATE_EMAIL)) ? FALSE : TRUE;

}


######################################################

function render_js_username_check(){

 global $USERNAME_REGEX, $ENFORCE_SAFE_SYSTEM_NAMES;

 if ($ENFORCE_SAFE_SYSTEM_NAMES == TRUE) {

 print <<<EoCheckJS
<script>

 function check_entity_name_validity(name,div_id) {

  var check_regex = /$USERNAME_REGEX/;

  if (! check_regex.test(name) ) {
   document.getElementById(div_id).classList.add("has-error");
  }
  else {
   document.getElementById(div_id).classList.remove("has-error");
  }

 }

</script>

EoCheckJS;
 }
 else {
  print "<script> function check_entity_name_validity(name,div_id) {} </script>";
 }

}


######################################################

function generate_username($fn,$ln) {

  global $USERNAME_FORMAT;

  // Handle empty or null parameters
  if (empty($fn) || empty($ln)) {
    return '';
  }

  $username = $USERNAME_FORMAT;
  $username = str_replace('{first_name}',strtolower($fn), $username);
  $username = str_replace('{first_name_initial}',strtolower($fn[0]), $username);
  $username = str_replace('{last_name}',strtolower($ln), $username);
  $username = str_replace('{last_name_initial}',strtolower($ln[0]), $username);

  return $username;

}


######################################################

function render_js_username_generator($firstname_field_id,$lastname_field_id,$username_field_id,$username_div_id) {

 #Parameters are the IDs of the input fields and username name div in the account creation form.
 #The div will be set to warning if the username is invalid.

 global $USERNAME_FORMAT, $ENFORCE_SAFE_SYSTEM_NAMES;

  $remove_accents="";
  if ($ENFORCE_SAFE_SYSTEM_NAMES == TRUE) { $remove_accents = ".normalize('NFD').replace(/[\u0300-\u036f]/g, '')"; }

  print <<<EoRenderJS

<script>
 function update_username() {

  var first_name = document.getElementById('$firstname_field_id').value;
  var last_name  = document.getElementById('$lastname_field_id').value;
  var template = '$USERNAME_FORMAT';

  var actual_username = template;

  actual_username = actual_username.replace('{first_name}', first_name.toLowerCase()$remove_accents );
  actual_username = actual_username.replace('{first_name_initial}', first_name.charAt(0).toLowerCase()$remove_accents );
  actual_username = actual_username.replace('{last_name}', last_name.toLowerCase()$remove_accents );
  actual_username = actual_username.replace('{last_name_initial}', last_name.charAt(0).toLowerCase()$remove_accents );

  check_entity_name_validity(actual_username,'$username_div_id');

  document.getElementById('$username_field_id').value = actual_username;

 }

</script>

EoRenderJS;

}


######################################################

function render_js_cn_generator($firstname_field_id,$lastname_field_id,$cn_field_id,$cn_div_id) {

  global $ENFORCE_SAFE_SYSTEM_NAMES;

  if ($ENFORCE_SAFE_SYSTEM_NAMES == TRUE) {
    $gen_js = "first_name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '') + last_name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')";
  }
  else {
    $gen_js = "first_name + ' ' + last_name";
  }

  print <<<EoRenderCNJS
<script>

 var auto_cn_update = true;

 function update_cn() {

  if ( auto_cn_update == true ) {
    var first_name = document.getElementById('$firstname_field_id').value;
    var last_name  = document.getElementById('$lastname_field_id').value;
    this_cn = $gen_js;

    check_entity_name_validity(this_cn,'$cn_div_id');

    document.getElementById('$cn_field_id').value = this_cn;
  }

 }
</script>

EoRenderCNJS;

}


######################################################

function render_js_email_generator($username_field_id,$email_field_id) {

 global $EMAIL_DOMAIN;

  print <<<EoRenderEmailJS
<script>

 var auto_email_update = true;

 function update_email() {

  if ( auto_email_update == true && "$EMAIL_DOMAIN" != ""  ) {
    var username = document.getElementById('$username_field_id').value;
    document.getElementById('$email_field_id').value = username + '@' + "$EMAIL_DOMAIN";
  }

 }
</script>

EoRenderEmailJS;

}


######################################################

function render_js_homedir_generator($username_field_id,$homedir_field_id) {

  print <<<EoRenderHomedirJS
<script>

 var auto_homedir_update = true;

 function update_homedir() {

  if ( auto_homedir_update == true ) {
    var username = document.getElementById('$username_field_id').value;
    document.getElementById('$homedir_field_id').value = "/home/" + username;
  }

 }
</script>

EoRenderHomedirJS;

}

######################################################

function render_dynamic_field_js() {

?>
<script>

  function add_field_to(attribute_name,value=null) {

    var parent      = document.getElementById(attribute_name + '_input_div');
    var input_div   = document.createElement('div');

    window[attribute_name + '_count'] = (window[attribute_name + '_count'] === undefined) ? 1 : window[attribute_name + '_count'] + 1;
    var input_field_id = attribute_name + window[attribute_name + '_count'];
    var input_div_id = 'div' + '_' + input_field_id;

    input_div.className = 'input-group';
    input_div.id = input_div_id;

    parent.appendChild(input_div);

    var input_field = document.createElement('input');
        input_field.type = 'text';
        input_field.className = 'form-control';
        input_field.id = input_field_id;
        input_field.name = attribute_name + '[]';
        input_field.value = value;

    var button_span = document.createElement('span');
        button_span.className = 'input-group-btn';

    var remove_button = document.createElement('button');
        remove_button.type = 'button';
        remove_button.className = 'btn btn-default';
        remove_button.onclick = function() { var div_to_remove = document.getElementById(input_div_id); div_to_remove.innerHTML = ""; }
        remove_button.innerHTML = '-';

    input_div.appendChild(input_field);
    input_div.appendChild(button_span);
    button_span.appendChild(remove_button);

  }

</script>
<?php

}


######################################################

function render_attribute_fields($attribute,$label,$values_r,$resource_identifier,$onkeyup="",$inputtype="",$tabindex=null) {

  global $THIS_MODULE_PATH;

  ?>

     <div class="form-group" id="<?php print $attribute; ?>_div">

       <label for="<?php print $attribute; ?>" class="col-sm-3 control-label"><?php print $label; ?></label>
       <div class="col-sm-6" id="<?php print $attribute; ?>_input_div">
       <?php if($inputtype == "multipleinput") {
             ?><div class="input-group">
                  <input type="text" class="form-control" id="<?php print $attribute; ?>" name="<?php print $attribute; ?>[]" value="<?php if (isset($values_r[0])) { print $values_r[0]; } ?>">
                  <div class="input-group-btn"><button type="button" class="btn btn-default" onclick="add_field_to('<?php print $attribute; ?>')">+</i></button></div>
              </div>
            <?php
               if (isset($values_r['count']) and $values_r['count'] > 0) {
                 unset($values_r['count']);
                 $remaining_values = array_slice($values_r, 1);
                 print "<script>";
                 foreach($remaining_values as $this_value) { print "add_field_to('$attribute','$this_value');"; }
                 print "</script>";
               }
             }
             elseif ($inputtype == "binary") {
               $button_text="Browse";
               $file_button_action="disabled";
               $description="Select a file to upload";
               $mimetype="";

               if (isset($values_r[0])) {
                 $this_file_info = new finfo(FILEINFO_MIME_TYPE);
                 $mimetype = $this_file_info->buffer($values_r[0]);
                 if (strlen($mimetype) > 23) { $mimetype = substr($mimetype,0,19) . "..."; }
                 $description="Download $mimetype file (" . human_readable_filesize(strlen($values_r[0])) . ")";
                 $button_text="Replace file";
                 if ($resource_identifier != "") {
                   $this_url="//{$_SERVER['HTTP_HOST']}{$THIS_MODULE_PATH}/download.php?resource_identifier={$resource_identifier}&attribute={$attribute}";
                   $file_button_action="onclick=\"window.open('$this_url','_blank');\"";
                 }
               }
               if ($mimetype == "image/jpeg") {
                 $this_image = base64_encode($values_r[0]);
                 print "<img class='img-thumbnail' src='data:image/jpeg;base64,$this_image'>";
                 $description="";
               }
               else {
               ?>
                 <button type="button" <?php print $file_button_action; ?> class="btn btn-default" id="<?php print $attribute; ?>-file-info"><?php print $description; ?></button>
               <?php } ?>
               <label class="btn btn-default">
                 <?php print $button_text; ?><input <?php if (isset($tabindex)) { ?>tabindex="<?php print $tabindex; ?>" <?php } ?>type="file" style="display:none" onchange="$('#<?php print $attribute; ?>-file-info').text(this.files[0].name)" id="<?php print $attribute; ?>" name="<?php print $attribute; ?>">
               </label>
            <?php
            }
            else { ?>
              <input <?php if (isset($tabindex)) { ?>tabindex="<?php print $tabindex; ?>" <?php } ?>type="text" class="form-control" id="<?php print $attribute; ?>" name="<?php print $attribute; ?>" value="<?php if (isset($values_r[0])) { print $values_r[0]; } ?>" <?php if ($onkeyup != "") { print "onkeyup=\"$onkeyup\""; } ?>>
            <?php
            }
            ?>
       </div>

     </div>

  <?php
}


######################################################

function human_readable_filesize($bytes) {
  for($i = 0; ($bytes / 1024) > 0.9; $i++, $bytes /= 1024) {}
  return round($bytes, [0,0,1,2,2,3,3,4,4][$i]).['B','kB','MB','GB','TB','PB','EB','ZB','YB'][$i];
}


######################################################

function render_alert_banner($message,$alert_class="success",$timeout=4000) {

?>
    <script>window.setTimeout(function() {$(".alert").fadeTo(500, 0).slideUp(500, function(){ $(this).remove(); }); }, $<?php print $timeout; ?>);</script>
    <div class="alert alert-<?php print $alert_class; ?>" role="alert">
     <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="TRUE">&times;</span></button>
     <p class="text-center"><?php print $message; ?></p>
    </div>
<?php
}


##EoFile

// CSRF protection helpers
function get_csrf_token() {
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Refresh session timeout to prevent premature expiration
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 300) {
        // Regenerate session ID every 5 minutes for security
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
    
    // Generate new token if none exists or if it's too old (regenerate every hour for security)
    if (empty($_SESSION['csrf_token']) || 
        !isset($_SESSION['csrf_token_time']) || 
        (time() - $_SESSION['csrf_token_time']) > 3600) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    
    return $_SESSION['csrf_token'];
}
function csrf_token_field() {
    $token = get_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}
function validate_csrf_token() {
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if CSRF token exists in session
    if (empty($_SESSION['csrf_token'])) {
        error_log("CSRF validation failed: No token in session");
        return false;
    }
    
    // Check if CSRF token was posted
    if (!isset($_POST['csrf_token'])) {
        error_log("CSRF validation failed: No token posted");
        return false;
    }
    
    // Validate the token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        error_log("CSRF validation failed: Token mismatch. Session: " . substr($_SESSION['csrf_token'], 0, 8) . "... Posted: " . substr($_POST['csrf_token'], 0, 8) . "...");
        return false;
    }
    
    return true;
}

/**
 * Secure redirect validation to prevent HTTP response splitting attacks
 * @param string $redirect_url The base64 encoded redirect URL
 * @param string $base_path The base server path
 * @return string|false Validated redirect URL or false if invalid
 */
function validate_redirect_url($redirect_url, $base_path = '/') {
    // Decode the base64 URL
    $decoded = base64_decode($redirect_url, true);
    if ($decoded === false) {
        return false;
    }
    
    // Ensure the decoded URL is a string
    if (!is_string($decoded)) {
        return false;
    }
    
    // Remove any null bytes or control characters
    $decoded = preg_replace('/[\x00-\x1F\x7F]/', '', $decoded);
    
    // Check if it's a relative path (starts with /)
    if (strpos($decoded, '/') === 0) {
        // Ensure it doesn't contain directory traversal attempts
        if (strpos($decoded, '..') !== false || strpos($decoded, '//') !== false) {
            return false;
        }
        
        // Ensure it doesn't contain any potentially dangerous characters
        if (preg_match('/[<>"\']/', $decoded)) {
            return false;
        }
        
        // Limit the length to prevent excessive redirects
        if (strlen($decoded) > 200) {
            return false;
        }
        
        return $decoded;
    }
    
    // Check if it's a relative path without leading slash
    if (strpos($decoded, 'http') !== 0 && strpos($decoded, '//') !== 0) {
        // Add leading slash if missing
        $decoded = '/' . ltrim($decoded, '/');
        
        // Apply the same validation as above
        if (strpos($decoded, '..') !== false || strpos($decoded, '//') !== false) {
            return false;
        }
        
        if (preg_match('/[<>"\']/', $decoded)) {
            return false;
        }
        
        if (strlen($decoded) > 200) {
            return false;
        }
        
        return $decoded;
    }
    
    // Reject absolute URLs for security
    return false;
}

/**
 * Safe name display functions to prevent PHP warnings
 */

/**
 * Safely display a user's full name with fallbacks
 * @param array $user User data array
 * @param string $cn_key Key for common name (default: 'cn')
 * @param string $givenname_key Key for given name (default: 'givenName')
 * @param string $sn_key Key for surname (default: 'sn')
 * @return string Safe display name
 */
function safe_display_name($user, $cn_key = 'cn', $givenname_key = 'givenName', $sn_key = 'sn') {
    // Try to get the common name first
    if (isset($user[$cn_key]) && !empty($user[$cn_key])) {
        if (is_array($user[$cn_key])) {
            return htmlspecialchars($user[$cn_key][0] ?? '');
        }
        return htmlspecialchars($user[$cn_key]);
    }
    
    // Fallback: construct from given name and surname
    $givenname = '';
    $sn = '';
    
    if (isset($user[$givenname_key]) && !empty($user[$givenname_key])) {
        if (is_array($user[$givenname_key])) {
            $givenname = $user[$givenname_key][0] ?? '';
        } else {
            $givenname = $user[$givenname_key];
        }
    }
    
    if (isset($user[$sn_key]) && !empty($user[$sn_key])) {
        if (is_array($user[$sn_key])) {
            $sn = $user[$sn_key][0] ?? '';
        } else {
            $sn = $user[$sn_key];
        }
    }
    
    // Return constructed name or fallback
    if ($givenname && $sn) {
        return htmlspecialchars($givenname . ' ' . $sn);
    } elseif ($givenname) {
        return htmlspecialchars($givenname);
    } elseif ($sn) {
        return htmlspecialchars($sn);
    } else {
        return '<em>No name available</em>';
    }
}

/**
 * Safely get a single attribute value from user data
 * @param array $user User data array
 * @param string $key Attribute key
 * @param string $default Default value if attribute is missing
 * @return string Safe attribute value
 */
function safe_user_attribute($user, $key, $default = '') {
    if (!isset($user[$key]) || empty($user[$key])) {
        return $default;
    }
    
    if (is_array($user[$key])) {
        return htmlspecialchars($user[$key][0] ?? $default);
    }
    
    return htmlspecialchars($user[$key]);
}

/**
 * Rate limiting functions to prevent brute force attacks
 */

/**
 * Check if user is rate limited for login attempts
 * @param string $identifier User identifier (email/username)
 * @param int $max_attempts Maximum attempts allowed
 * @param int $time_window Time window in seconds
 * @return bool True if rate limited, false otherwise
 */
function is_rate_limited($identifier, $max_attempts = 5, $time_window = 300) {
    $rate_limit_file = "/tmp/rate_limit_" . md5($identifier);
    
    if (file_exists($rate_limit_file)) {
        $data = file_get_contents($rate_limit_file);
        $attempts = json_decode($data, true);
        
        if ($attempts && is_array($attempts)) {
            // Remove attempts outside the time window
            $current_time = time();
            $attempts = array_filter($attempts, function($timestamp) use ($current_time, $time_window) {
                return ($current_time - $timestamp) < $time_window;
            });
            
            // Check if too many attempts
            if (count($attempts) >= $max_attempts) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Record a login attempt for rate limiting
 * @param string $identifier User identifier
 * @param bool $success Whether the attempt was successful
 */
function record_login_attempt($identifier, $success = false) {
    $rate_limit_file = "/tmp/rate_limit_" . md5($identifier);
    
    if ($success) {
        // Clear rate limiting on successful login
        if (file_exists($rate_limit_file)) {
            unlink($rate_limit_file);
        }
        return;
    }
    
    $attempts = [];
    if (file_exists($rate_limit_file)) {
        $data = file_get_contents($rate_limit_file);
        $attempts = json_decode($data, true) ?: [];
    }
    
    $attempts[] = time();
    
    // Keep only last 10 attempts to prevent file bloat
    if (count($attempts) > 10) {
        $attempts = array_slice($attempts, -10);
    }
    
    file_put_contents($rate_limit_file, json_encode($attempts));
}
