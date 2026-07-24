<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";

setPageAccess("user");

// Generate CSRF token before any form output
getCsrfToken();

if (isset($_POST['change_password'])) {
    if (!validateCsrfToken()) {
        http_response_code(403);
        header('Location: ' . getBaseUrl() . 'password/change/?error=csrf');
        exit;
    }
    if (!$_POST['password']) {
        $not_strong_enough = 1;
    }
    if ((!is_numeric($_POST['pass_score']) or $_POST['pass_score'] < $PASSWORD_STRENGTH_MIN_SCORE) and $ACCEPT_WEAK_PASSWORDS != true) {
        $not_strong_enough = 1;
    }
    if (preg_match("/\"|'/", $_POST['password'])) {
        $invalid_chars = 1;
    }
    if ($_POST['password'] != $_POST['password_match']) {
        $mismatched = 1;
    }

    if (!isset($mismatched) and !isset($not_strong_enough) and !isset($invalid_chars)) {
        global $IS_ADMIN, $IS_MAINTAINER, $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_ORG_UUID;

        $ldap_connection = open_ldap_connection();
        ldap_change_password($ldap_connection, $USER_ID, $_POST['password']) or die("change_ldap_password() failed.");
        $user_dn = get_user_dn_from_identifier($ldap_connection, (string) $USER_ID);
        if ($user_dn) {
            $bindKeyHex = captureUserLdapCredentials($user_dn, (string) $_POST['password']);
            setPasskeyCookie(
                (string) $USER_ID,
                (bool) $IS_ADMIN,
                (bool) $IS_MAINTAINER,
                (bool) $IS_ORG_ADMIN,
                $USER_ORG_NAME,
                $USER_ORG_UUID,
                $bindKeyHex
            );
        }

        renderHeader(t('password.change.success_title', ['org' => $ORGANISATION_NAME]));
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
        renderFooter();
        exit(0);
    }
}

renderHeader(t('password.change.page_title', ['org' => $ORGANISATION_NAME]));

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

<script src="<?php print getAssetBase(); ?>js/password.min.js"></script>
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function(){
    const passwordConfig = <?php echo getPasswordStrengthConfigJs(); ?>;

    initializePasswordStrength({
        passwordFieldId: 'password',
        confirmFieldId: 'confirm',
        config: passwordConfig
    });

    const generateButton = document.getElementById('generate_password');
    if (generateButton) {
        generateButton.addEventListener('click', function() {
            generateSecurePassword({
                type: 'word',
                words: 4,
                separator: '-',
                passwordFieldId: 'password',
                confirmFieldId: 'confirm'
            });
        });
    }
});
</script>

<div class="container">
 <div class="col-md-8 offset-md-2">

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

    <form action="" method="post">
     <?php echo csrfTokenField(); ?>

     <input type="hidden" id="change_password" name="change_password">
     <input type="hidden" id="pass_score" value="0" name="pass_score">

     <script>
      function check_passwords_match() {
        if (document.getElementById('password').value != document.getElementById('confirm').value) {
            document.getElementById('password_div').classList.add("is-invalid");
            document.getElementById('confirm_div').classList.add("is-invalid");
        } else {
         document.getElementById('password_div').classList.remove("is-invalid");
         document.getElementById('confirm_div').classList.remove("is-invalid");
        }
       }
     </script>

     <div class="row g-3 text-start">
      <div class="col-md-6" id="password_div">
       <label for="password" class="form-label"><?php echo htmlspecialchars(t('password.change.new_label'), ENT_QUOTES, 'UTF-8'); ?></label>
       <input type="password" class="form-control" id="password" name="password">
       <button type="button" class="btn btn-outline-secondary mt-2" id="generate_password"><?php echo htmlspecialchars(t('password.change.generate'), ENT_QUOTES, 'UTF-8'); ?></button>
      </div>
      <div class="col-md-6" id="confirm_div">
       <label for="confirm" class="form-label"><?php echo htmlspecialchars(t('password.change.confirm_field'), ENT_QUOTES, 'UTF-8'); ?></label>
       <input type="password" class="form-control" id="confirm" name="password_match" onkeyup="check_passwords_match()">
      </div>
     </div>

     <div class="mt-3">
       <button type="submit" class="btn btn-secondary"><?php echo htmlspecialchars(t('password.change.submit'), ENT_QUOTES, 'UTF-8'); ?></button>
     </div>
    </form>

   </div>
  </div>

 </div>
</div>
<?php

renderFooter();

