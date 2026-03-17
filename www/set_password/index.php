<?php

declare(strict_types=1);

set_include_path('.:' . __DIR__ . '/../includes/');

include_once 'web_functions.inc.php';
include_once 'ldap_functions.inc.php';
include_once 'password_reset_functions.inc.php';

set_page_access('hidden_on_login');

$token = (string) ($_GET['token'] ?? '');
$payload = ($token !== '') ? verify_password_action_token($token) : null;

if ($payload === null) {
    render_header('Set password');
    echo "<div class='container'><div class='alert alert-warning'>Invalid or expired link.</div></div>";
    render_footer();
    exit(0);
}

$accountIdentifier = $payload['sub'];
$purpose = $payload['purpose'];

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_match'] ?? '');
    $passScore = isset($_POST['pass_score']) && is_numeric($_POST['pass_score']) ? (int) $_POST['pass_score'] : null;

    $validation = validate_password_submission($password, $confirm, $passScore);
    if (!$validation['ok']) {
        $errors = $validation['errors'];
    } else {
        $ldap = open_ldap_connection();
        $changed = ldap_change_password($ldap, $accountIdentifier, $password);
        ldap_close($ldap);

        if ($changed) {
            $success = true;
        } else {
            $errors[] = 'Failed to set password. Please contact an administrator.';
        }
    }
}

render_header('Set password');
?>

<div class="container">
    <div class="col-sm-6 offset-sm-3">
        <div class="card">
            <div class="card-header text-center">
                <?php echo ($purpose === 'reset') ? 'Reset your password' : 'Set your password'; ?>
            </div>
            <div class="card-body">
                <?php if ($success) : ?>
                    <div class="alert alert-success">
                        Your password has been updated. You can now log in.
                    </div>
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars(get_base_url() . 'log_in/'); ?>">Go to login</a>
                <?php else : ?>
                    <?php if (!empty($errors)) : ?>
                        <div class="alert alert-warning">
                            <?php echo htmlspecialchars(implode(' ', $errors)); ?>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted">
                        Account: <strong><?php echo htmlspecialchars($accountIdentifier); ?></strong>
                    </p>

                    <form class="form-horizontal" action="" method="post">
                        <input type="hidden" name="set_password" value="1">
                        <input type="hidden" id="pass_score" value="0" name="pass_score">

                        <div class="form-group" id="password_div">
                            <label for="password" class="col-sm-4 form-label">New Password</label>
                            <div class="col-sm-6">
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>

                        <div class="form-group" id="confirm_div">
                            <label for="confirm" class="col-sm-4 form-label">Confirm</label>
                            <div class="col-sm-6">
                                <input type="password" class="form-control" id="confirm" name="password_match" required>
                            </div>
                        </div>

                        <div class="form-group mt-2">
                            <button type="submit" class="btn btn-secondary">Set password</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="<?php print get_asset_base(); ?>js/password_utils.js"></script>
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function(){
    const passwordConfig = <?php echo get_password_strength_config_js(); ?>;
    initializePasswordStrength({
        passwordFieldId: 'password',
        confirmFieldId: 'confirm',
        config: passwordConfig
    });
});
</script>

<?php
render_footer();

