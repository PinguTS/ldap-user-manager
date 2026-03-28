<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once __DIR__ . '/bootstrap_setup.inc.php';

// CRITICAL: Check for role configuration conflicts before allowing setup
// This prevents setup completion with broken access control configuration
if (function_exists('checkRuntimeRoleConflicts') && checkRuntimeRoleConflicts()) {
    displayMaintenanceMode();
}

if (isset($_POST["admin_password"])) {
    $ldap_connection = open_ldap_connection();
    $user_auth = ldap_setup_auth($ldap_connection, $_POST["admin_password"]);
    ldap_close($ldap_connection);

    if ($user_auth != false) {
        setSetupCookie($user_auth);
        header("Location: " . getBaseUrl() . "setup/check/");
        exit;
    } else {
        header("Location: " . getBaseUrl() . "setup/index.php?invalid");
        exit;
    }
} else {
    renderHeader(t('setup.index.page_title', ['org' => $ORGANISATION_NAME]));

    if (isset($_GET["invalid"])) {
        ?>
        <div class="alert alert-warning">
            <p class="text-center"><?php echo htmlspecialchars(t('setup.index.wrong_password'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <?php
    }
    ?>
    <div class="container">
        <div class="card">
            <div class="card-header text-center"><?php echo htmlspecialchars(t('setup.index.password_for', ['dn' => $LDAP['admin_bind_dn']]), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="card-body text-center">
                <form class="form-inline" action='' method='post'>
                    <div class="form-group">
                        <input type='password' class="form-control" name='admin_password'>
                    </div>
                    <div class="form-group">
                        <input type='submit' class="btn btn-secondary" value="<?php echo htmlspecialchars(t('login.submit'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}
renderFooter();
?>
