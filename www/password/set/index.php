<?php

declare(strict_types=1);

set_include_path('.:' . __DIR__ . '/../../includes/');

include_once 'web_functions.inc.php';
include_once 'ldap_functions.inc.php';
include_once 'password_reset_functions.inc.php';

setPageAccess('hidden_on_login');

$token = (string) ($_GET['token'] ?? '');
$payload = ($token !== '') ? verify_password_action_token($token) : null;

if ($payload === null) {
    renderHeader(t('password.set.page_title'));
    echo "<div class='container'><div class='alert alert-warning'>" . htmlspecialchars(t('password.set.invalid_link'), ENT_QUOTES, 'UTF-8') . "</div></div>";
    renderFooter();
    exit(0);
}

$accountIdentifier = $payload['sub'];
$purpose = $payload['purpose'];

$errors = [];
$success = false;

getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
    if (!validateCsrfToken()) {
        http_response_code(403);
        exit('CSRF validation failed');
    }
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
            // Mark the token consumed so it cannot be replayed.
            markPasswordTokenConsumed($token);
            $success = true;
        } else {
            $errors[] = t('password.set.fail_admin');
        }
    }
}

renderHeader(t('password.set.page_title'));
?>

<div class="container">
    <div class="col-sm-6 offset-sm-3">
        <div class="card">
            <div class="card-header text-center">
                <?php echo htmlspecialchars($purpose === 'reset' ? t('password.set.card_reset') : t('password.set.card_set'), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="card-body">
                <?php if ($success) : ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars(t('password.set.success'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars(getBaseUrl() . 'login/'); ?>"><?php echo htmlspecialchars(t('password.set.go_login'), ENT_QUOTES, 'UTF-8'); ?></a>
                <?php else : ?>
                    <?php if (!empty($errors)) : ?>
                        <div class="alert alert-warning">
                            <?php echo htmlspecialchars(implode(' ', $errors)); ?>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted">
                        <?php echo htmlspecialchars(t('password.set.account_label'), ENT_QUOTES, 'UTF-8'); ?> <strong><?php echo htmlspecialchars($accountIdentifier); ?></strong>
                    </p>

                    <form class="form-horizontal" action="" method="post">
                        <?php echo csrfTokenField(); ?>
                        <input type="hidden" name="set_password" value="1">
                        <input type="hidden" id="pass_score" value="0" name="pass_score">

                        <div class="form-group" id="password_div">
                            <label for="password" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('password.set.new_pw'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="col-sm-6">
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>

                        <div class="form-group" id="confirm_div">
                            <label for="confirm" class="col-sm-4 form-label"><?php echo htmlspecialchars(t('password.set.confirm'), ENT_QUOTES, 'UTF-8'); ?></label>
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

<script src="<?php print getAssetBase(); ?>js/password_utils.js"></script>
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function(){
    const passwordConfig = <?php echo getPasswordStrengthConfigJs(); ?>;
    initializePasswordStrength({
        passwordFieldId: 'password',
        confirmFieldId: 'confirm',
        config: passwordConfig
    });
});
</script>

<?php
renderFooter();

