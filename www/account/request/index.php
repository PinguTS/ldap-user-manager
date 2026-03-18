<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
session_start();

include_once "web_functions.inc.php";

render_header("$ORGANISATION_NAME - request an account");

if ($ACCOUNT_REQUESTS_ENABLED == false) {
    ?><div class='alert alert-warning'><p class='text-center'>Account requesting is disabled.</p></div><?php

render_footer();
exit(0);
}

if ($_POST) {
    $error_messages = array();

    if (! isset($_POST['validate']) or strcasecmp($_POST['validate'], $_SESSION['proof_of_humanity']) != 0) {
        array_push($error_messages, "The validation text didn't match the image.");
    }

    if (! isset($_POST['firstname']) or $_POST['firstname'] == "") {
        array_push($error_messages, "You didn't enter your first name.");
    } else {
        $firstname = filter_var($_POST['firstname'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }

    if (! isset($_POST['lastname']) or $_POST['lastname'] == "") {
        array_push($error_messages, "You didn't enter your last name.");
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
    The request couldn't be sent because:
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
        $mail_subject = "$firstname $lastname has requested an account for $ORGANISATION_NAME.";

        $link_url = "{$SITE_PROTOCOL}{$SERVER_HOSTNAME}{$SERVER_PATH}manage/users/new/?account_request&first_name=$firstname&last_name=$lastname&email=$email";

        if (!isset($email)) {
            $email = "n/a";
        }
        if (!isset($notes)) {
            $notes = "n/a";
        }

        $mail_body = <<<EoT
A request for an $ORGANISATION_NAME account has been sent:
<p>
First name: <b>$firstname</b><br>
Last name: <b>$lastname</b><br>
Email: <b>$email</b><br>
Notes: <pre>$notes</pre><br>
<p>
<a href="$link_url">Create this account.</a>
EoT;

        include_once "mail_functions.inc.php";
        $sent_email = send_email($ACCOUNT_REQUESTS_EMAIL, "$ORGANISATION_NAME account requests", $mail_subject, $mail_body);
        if ($sent_email) { ?>
       <div class="container">
         <div class="col-sm-6 offset-sm-3">
           <div class="card border-success">
             <div class="card-header bg-success text-white">Thank you</div>
             <div class="card-body">
               The request was sent and the administrator will process it as soon as possible.
             </div>
           </div>
         </div>
       </div>
        <?php } else { ?>
       <div class="container">
         <div class="col-sm-6 offset-sm-3">
           <div class="card border-danger">
             <div class="card-header bg-danger text-white">Error</div>
             <div class="card-body">
               Unfortunately the account request wasn't sent because of a technical issue.
             </div>
           </div>
         </div>
       </div>
            <?php
        }
        render_footer();
        exit(0);
    }
}
?>
<div class="container">
 <div class="col-sm-8 offset-sm-2">

  <div class="card">
    <div class="card-body">
    Use this form to send a request for an account to an administrator at <?php print $ORGANISATION_NAME; ?>.
    If the administrator approves your request they'll get in touch with you to give you your new credentials.
    </div>
   <div class="card-body text-center">

    <form class="form-horizontal" action='' method='post'>

    <div class="form-group">
     <label for="firstname" class="col-sm-4 form-label">First name</label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="firstname" name="firstname">
     </div>
    </div>

    <div class="form-group">
     <label for="lastname" class="col-sm-4 form-label">Last name</label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="lastname" name="lastname">
     </div>
    </div>

    <div class="form-group">
     <label for="email" class="col-sm-4 form-label">Email</label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="email" name="email">
     </div>
    </div>

    <div class="form-group">
     <label for="notes" class="col-sm-4 form-label">Notes</label>
     <div class="col-sm-6">
      <textarea class="form-control" id="notes" name="notes" rows="5"></textarea>
     </div>
    </div>

    <div class="form-group">
     <label for="validate" class="col-sm-4 form-label">Validation text</label>
     <div class="col-sm-6">
      <img src="human.php" alt="human proof image">
      <input type="text" class="form-control" id="validate" name="validate">
     </div>
    </div>

    <div class="form-group">
     <button type="submit" class="btn btn-secondary">Send request</button>
    </div>

    </form>
   </div>
  </div>
 </div>
</div>

<?php
render_footer();
