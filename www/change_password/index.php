<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";

set_page_access("user");

if (isset($_POST['change_password'])) {

 // Debug: Log what's being submitted
 error_log("Password change attempt - pass_score: " . ($_POST['pass_score'] ?? 'NOT_SET') . ", password length: " . strlen($_POST['password'] ?? ''));

 if (!$_POST['password']) { $not_strong_enough = 1; }
 if ((!is_numeric($_POST['pass_score']) or $_POST['pass_score'] < $PASSWORD_STRENGTH_MIN_SCORE) and $ACCEPT_WEAK_PASSWORDS != TRUE) { 
   $not_strong_enough = 1; 
   error_log("Password rejected - pass_score: " . ($_POST['pass_score'] ?? 'NOT_SET') . ", numeric check: " . (is_numeric($_POST['pass_score'] ?? '') ? 'TRUE' : 'FALSE') . ", required score: " . $PASSWORD_STRENGTH_MIN_SCORE);
 }
 if (preg_match("/\"|'/",$_POST['password'])) { $invalid_chars = 1; }
 if ($_POST['password'] != $_POST['password_match']) { $mismatched = 1; }

 if (!isset($mismatched) and !isset($not_strong_enough) and !isset($invalid_chars) ) {

  $ldap_connection = open_ldap_connection();
  ldap_change_password($ldap_connection,$USER_ID,$_POST['password']) or die("change_ldap_password() failed.");

  render_header("$ORGANISATION_NAME account manager - password changed");
  ?>
  <div class="container">
    <div class="col-sm-6 col-sm-offset-3">
      <div class="panel panel-success">
        <div class="panel-heading">Success</div>
        <div class="panel-body">
          Your password has been updated.
        </div>
      </div>
    </div>
  </div>
  <?php
  render_footer();
  exit(0);
 }

}

render_header("Change your $ORGANISATION_NAME password");

if (isset($not_strong_enough)) {  ?>
<div class="alert alert-warning">
 <p class="text-center">
   The password wasn't strong enough. 
   <?php if (isset($_POST['pass_score'])): ?>
     Current strength score: <?php echo htmlspecialchars($_POST['pass_score']); ?> 
     (0=Very Weak, 1=Weak, 2=Fair, 3=Good, 4=Strong). 
     A score of <?php echo $PASSWORD_STRENGTH_MIN_SCORE; ?> or higher is required.
   <?php endif; ?>
 </p>
</div>
<?php }

if (isset($invalid_chars)) {  ?>
<div class="alert alert-warning">
 <p class="text-center">The password contained invalid characters.</p>
</div>
<?php }

if (isset($mismatched)) {  ?>
<div class="alert alert-warning">
 <p class="text-center">The passwords didn't match.</p>
</div>
<?php }

?>

<script src="<?php print $SERVER_PATH; ?>js/zxcvbn.min.js"></script>
<script src="<?php print $SERVER_PATH; ?>js/password_utils.min.js"></script>
<script type="text/javascript">
$(document).ready(function(){
    // Get password strength configuration from server
    const passwordConfig = <?php echo get_password_strength_config_js(); ?>;
    
    // Initialize unified password strength checking with dynamic config
    initializePasswordStrength({
        passwordFieldId: 'password',
        confirmFieldId: 'confirm',
        config: passwordConfig
    });
    
    // Add password generation button
    const passwordField = document.getElementById('password');
    if (passwordField) {
        const generateButton = document.createElement('button');
        generateButton.type = 'button';
        generateButton.className = 'btn btn-info btn-sm ml-2';
        generateButton.textContent = 'Generate Password';
        generateButton.onclick = () => generateSecurePassword({
            type: 'word',
            words: 4,
            separator: ' ',
            passwordFieldId: 'password',
            confirmFieldId: 'confirm'
        });
        
        // Insert after password field
        passwordField.parentNode.insertBefore(generateButton, passwordField.nextSibling);
    }
});
</script>

<div class="container">
 <div class="col-sm-6 col-sm-offset-3">

  <div class="panel panel-default">
   <div class="panel-heading text-center">Change your password</div>

   <ul class="list-group">
    <li class="list-group-item">Use this form to change your <?php print $ORGANISATION_NAME; ?> password. When you start typing your new password the gauge at the bottom will show its security strength.
    Enter your password again in the <b>confirm</b> field. If the passwords don't match then both fields will be bordered with red.</li>
    <li class="list-group-item"><strong>Password Strength Requirement:</strong> Your password must achieve a strength score of <?php echo $PASSWORD_STRENGTH_MIN_SCORE; ?> (<?php echo $PASSWORD_STRENGTH_MIN_SCORE == 0 ? 'Very Weak' : ($PASSWORD_STRENGTH_MIN_SCORE == 1 ? 'Weak' : ($PASSWORD_STRENGTH_MIN_SCORE == 2 ? 'Fair' : ($PASSWORD_STRENGTH_MIN_SCORE == 3 ? 'Good' : 'Strong'))); ?>) or higher. The strength meter below will show your password's current score as you type.</li>
   </ul>

   <div class="panel-body text-center">
   
    <form class="form-horizontal" action='' method='post'>

     <input type='hidden' id="change_password" name="change_password">
     <input type='hidden' id="pass_score" value="0" name="pass_score">
     
     <div class="form-group" id="password_div">
      <label for="password" class="col-sm-4 control-label">Password</label>
      <div class="col-sm-6">
       <input type="password" class="form-control" id="password" name="password">
      </div>
     </div>

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
     </script>

     <div class="form-group" id="confirm_div">
      <label for="password" class="col-sm-4 control-label">Confirm</label>
      <div class="col-sm-6">
       <input type="password" class="form-control" id="confirm" name="password_match" onkeyup="check_passwords_match()">
      </div>
     </div>

     <div class="form-group">
       <button type="submit" class="btn btn-default">Change password</button>
     </div>
     
     <!-- Debug: Show current password strength score -->
     <div class="form-group">
       <small class="text-muted">
         Password strength score: <span id="debug_score">0</span> (0=Very Weak, 1=Weak, 2=Fair, 3=Good, 4=Strong)
       </small>
     </div>
    </form>

   </div>
  </div>

 </div>
</div>
<?php

render_footer();

?>

