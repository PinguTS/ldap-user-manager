<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";

set_page_access("user");

if (isset($_POST['change_password'])) {
 // Debug: Log what's being submitted
    error_log("Password change attempt - pass_score: " . ($_POST['pass_score'] ?? 'NOT_SET') . ", password length: " . strlen($_POST['password'] ?? ''));

    if (!$_POST['password']) {
        $not_strong_enough = 1;
    }
    if ((!is_numeric($_POST['pass_score']) or $_POST['pass_score'] < $PASSWORD_STRENGTH_MIN_SCORE) and $ACCEPT_WEAK_PASSWORDS != true) {
        $not_strong_enough = 1;
        error_log("Password rejected - pass_score: " . ($_POST['pass_score'] ?? 'NOT_SET') . ", numeric check: " . (is_numeric($_POST['pass_score'] ?? '') ? 'TRUE' : 'FALSE') . ", required score: " . $PASSWORD_STRENGTH_MIN_SCORE);
    }
    if (preg_match("/\"|'/", $_POST['password'])) {
        $invalid_chars = 1;
    }
    if ($_POST['password'] != $_POST['password_match']) {
        $mismatched = 1;
    }

    if (!isset($mismatched) and !isset($not_strong_enough) and !isset($invalid_chars)) {
        $ldap_connection = open_ldap_connection();
        ldap_change_password($ldap_connection, $USER_ID, $_POST['password']) or die("change_ldap_password() failed.");

        render_header(t('password.change.success_title', ['org' => $ORGANISATION_NAME]));
        ?>
  <div class="container">
    <div class="col-sm-6 offset-sm-3">
      <div class="card border-success">
        <div class="card-header bg-success text-white"><?php echo htmlspecialchars(t('password.change.success_heading'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="card-body">
          <?php echo htmlspecialchars(t('password.change.success_body'), ENT_QUOTES, 'UTF-8'); ?>
        </div>
      </div>
    </div>
  </div>
        <?php
        render_footer();
        exit(0);
    }
}

render_header(t('password.change.page_title', ['org' => $ORGANISATION_NAME]));

if (isset($not_strong_enough)) {  ?>
<div class="alert alert-warning">
 <p class="text-center">
   <?php echo htmlspecialchars(t('password.change.weak'), ENT_QUOTES, 'UTF-8'); ?>
    <?php if (isset($_POST['pass_score'])) : ?>
     <?php echo htmlspecialchars(t('password.change.score_help', ['score' => (string) $_POST['pass_score'], 'min' => (string) $PASSWORD_STRENGTH_MIN_SCORE]), ENT_QUOTES, 'UTF-8'); ?>
    <?php endif; ?>
 </p>
</div>
<?php }

if (isset($invalid_chars)) {  ?>
<div class="alert alert-warning">
 <p class="text-center"><?php echo htmlspecialchars(t('password.change.invalid_chars'), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php }

if (isset($mismatched)) {  ?>
<div class="alert alert-warning">
 <p class="text-center"><?php echo htmlspecialchars(t('password.change.mismatch'), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php }

?>

<script src="<?php print get_asset_base(); ?>js/password_utils.js"></script>
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function(){
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
        generateButton.textContent = <?php echo json_encode(t('password.change.generate')); ?>;
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
 <div class="col-sm-6 offset-sm-3">

  <div class="card">
   <div class="card-header text-center"><?php echo htmlspecialchars(t('password.change.card_header'), ENT_QUOTES, 'UTF-8'); ?></div>

   <ul class="list-group list-group-flush">
    <li class="list-group-item"><?php echo htmlspecialchars(t('password.change.bullet1', ['org' => $ORGANISATION_NAME]), ENT_QUOTES, 'UTF-8'); ?></li>
    <li class="list-group-item"><?php
    $lvl = (int) $PASSWORD_STRENGTH_MIN_SCORE;
    $lvl = max(0, min(4, $lvl));
    echo htmlspecialchars(t('password.change.bullet2', ['score' => (string) $PASSWORD_STRENGTH_MIN_SCORE, 'level' => t('password.strength.' . $lvl)]), ENT_QUOTES, 'UTF-8');
    ?></li>
   </ul>

   <div class="card-body text-center">
   
    <form class="form-horizontal" action='' method='post'>

     <input type='hidden' id="change_password" name="change_password">
     <input type='hidden' id="pass_score" value="0" name="pass_score">
     
     <div class="form-group" id="password_div">
      <label for="password" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('password.change.new_label'), ENT_QUOTES, 'UTF-8'); ?></label>
      <div class="col-sm-6">
       <input type="password" class="form-control" id="password" name="password">
      </div>
     </div>

     <script>
      function check_passwords_match() {

        if (document.getElementById('password').value != document.getElementById('confirm').value ) {
            document.getElementById('password_div').classList.add("is-invalid");
            document.getElementById('confirm_div').classList.add("is-invalid");
        }
        else {
         document.getElementById('password_div').classList.remove("is-invalid");
         document.getElementById('confirm_div').classList.remove("is-invalid");
        }
       }
     </script>

     <div class="form-group" id="confirm_div">
      <label for="password" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('password.change.confirm_field'), ENT_QUOTES, 'UTF-8'); ?></label>
      <div class="col-sm-6">
       <input type="password" class="form-control" id="confirm" name="password_match" onkeyup="check_passwords_match()">
      </div>
     </div>

     <div class="form-group">
       <button type="submit" class="btn btn-secondary"><?php echo htmlspecialchars(t('password.change.submit'), ENT_QUOTES, 'UTF-8'); ?></button>
     </div>
     
     <!-- Debug: Show current password strength score -->
     <div class="form-group">
       <small class="text-muted">
         <?php echo htmlspecialchars(t('password.change.debug_score'), ENT_QUOTES, 'UTF-8'); ?> <span id="debug_score">0</span>
       </small>
     </div>
    </form>

   </div>
  </div>

 </div>
</div>
<?php

render_footer();

