<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
session_start();

include_once "web_functions.inc.php";

renderHeader((string) $ORGANISATION_NAME . ' - ' . t('nav.request_account'));

if ($ACCOUNT_REQUESTS_ENABLED == false) {
    ?><div class='alert alert-warning'><p class='text-center'><?php echo htmlspecialchars(t('account.request.disabled'), ENT_QUOTES, 'UTF-8'); ?></p></div><?php

renderFooter();
exit(0);
}

getCsrfToken();

if ($_POST) {
    $error_messages = array();

    if (!validateCsrfToken()) {
        http_response_code(403);
        exit('CSRF validation failed');
    }

    if (! isset($_POST['validate']) or strcasecmp($_POST['validate'], $_SESSION['proof_of_humanity']) != 0) {
        array_push($error_messages, t('account.request.error.validation_mismatch'));
    }

    if (! isset($_POST['firstname']) or $_POST['firstname'] == "") {
        array_push($error_messages, t('account.request.error.first_name_required'));
    } else {
        $firstname = filter_var($_POST['firstname'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }

    if (! isset($_POST['lastname']) or $_POST['lastname'] == "") {
        array_push($error_messages, t('account.request.error.last_name_required'));
    } else {
        $lastname = filter_var($_POST['lastname'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }

    if (isset($_POST['email']) and $_POST['email'] != "") {
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    }

    if (isset($_POST['notes']) and $_POST['notes'] != "") {
        $notes = filter_var($_POST['notes'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }


    if (count($error_messages) > 0) { ?>
    <div class="alert alert-danger" role="alert">
        <?php echo htmlspecialchars(t('account.request.errors_intro'), ENT_QUOTES, 'UTF-8'); ?>
    <p>
    <ul>
        <?php
        foreach ($error_messages as $message) {
            print "<li>$message</li>\n";
        }
        ?>
    </ul>
    </div>
        <?php
    } else {
        $mail_subject = t('account.request.mail_subject', ['first_name' => $firstname, 'last_name' => $lastname, 'org' => $ORGANISATION_NAME]);

        $link_url = rtrim(lumPublicSiteBaseUrl(), '/') . "/manage/users/new/?account_request&first_name=$firstname&last_name=$lastname&email=$email";

        if (!isset($email)) {
            $email = "n/a";
        }
        if (!isset($notes)) {
            $notes = "n/a";
        }

        $mail_body = t('account.request.mail_body', [
            'org' => $ORGANISATION_NAME,
            'first_name' => $firstname,
            'last_name' => $lastname,
            'email' => $email,
            'notes' => $notes,
            'link_url' => $link_url,
        ]);

        include_once "mail_functions.inc.php";
        $sent_email = send_email(
            $ACCOUNT_REQUESTS_EMAIL,
            t('account.request.mail_heading', ['org' => $ORGANISATION_NAME]),
            $mail_subject,
            $mail_body
        );
        if ($sent_email) { ?>
       <div class="container">
         <div class="col-sm-6 offset-sm-3">
           <div class="card border-success">
             <div class="card-header bg-success text-white"><?php echo htmlspecialchars(t('account.request.success_heading'), ENT_QUOTES, 'UTF-8'); ?></div>
             <div class="card-body">
               <?php echo htmlspecialchars(t('account.request.success_message'), ENT_QUOTES, 'UTF-8'); ?>
             </div>
           </div>
         </div>
       </div>
        <?php } else { ?>
       <div class="container">
         <div class="col-sm-6 offset-sm-3">
           <div class="card border-danger">
             <div class="card-header bg-danger text-white"><?php echo htmlspecialchars(t('account.request.error_heading'), ENT_QUOTES, 'UTF-8'); ?></div>
             <div class="card-body">
               <?php echo htmlspecialchars(t('account.request.error_message'), ENT_QUOTES, 'UTF-8'); ?>
             </div>
           </div>
         </div>
       </div>
            <?php
        }
        renderFooter();
        exit(0);
    }
}
?>
<div class="container">
 <div class="col-sm-8 offset-sm-2">

  <div class="card">
    <div class="card-body">
    <?php echo htmlspecialchars(t('account.request.form_intro', ['org' => $ORGANISATION_NAME]), ENT_QUOTES, 'UTF-8'); ?>
    <br>
    <?php echo htmlspecialchars(t('account.request.form_approval_note'), ENT_QUOTES, 'UTF-8'); ?>
    </div>
   <div class="card-body text-center">

    <form class="form-horizontal" action='' method='post'>
    <?php echo csrfTokenField(); ?>

    <div class="form-group">
     <label for="firstname" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('account.request.field_first_name'), ENT_QUOTES, 'UTF-8'); ?></label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="firstname" name="firstname">
     </div>
    </div>

    <div class="form-group">
     <label for="lastname" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('account.request.field_last_name'), ENT_QUOTES, 'UTF-8'); ?></label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="lastname" name="lastname">
     </div>
    </div>

    <div class="form-group">
     <label for="email" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('account.request.field_email'), ENT_QUOTES, 'UTF-8'); ?></label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="email" name="email">
     </div>
    </div>

    <div class="form-group">
     <label for="notes" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('account.request.field_notes'), ENT_QUOTES, 'UTF-8'); ?></label>
     <div class="col-sm-6">
      <textarea class="form-control" id="notes" name="notes" rows="5"></textarea>
     </div>
    </div>

    <div class="form-group">
     <label for="validate" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('account.request.field_validation_text'), ENT_QUOTES, 'UTF-8'); ?></label>
     <div class="col-sm-6">
      <img src="human.php" alt="<?php echo htmlspecialchars(t('account.request.human_proof_alt'), ENT_QUOTES, 'UTF-8'); ?>">
      <input type="text" class="form-control" id="validate" name="validate">
     </div>
    </div>

    <div class="form-group">
     <button type="submit" class="btn btn-secondary"><?php echo htmlspecialchars(t('account.request.submit'), ENT_QUOTES, 'UTF-8'); ?></button>
    </div>

    </form>
   </div>
  </div>
 </div>
</div>

<?php
renderFooter();
