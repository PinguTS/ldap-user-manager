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
        set_setup_cookie($user_auth);
        header("Location: " . get_base_url() . "setup/run_checks.php");
        exit;
    } else {
        header("Location: " . get_base_url() . "setup/index.php?invalid");
        exit;
    }
} else {
    render_header("$ORGANISATION_NAME account manager setup - log in");

    if (isset($_GET["invalid"])) {
        ?>
        <div class="alert alert-warning">
            <p class="text-center">The password was incorrect.</p>
        </div>
        <?php
    }
    ?>
    <div class="container">
        <div class="card">
            <div class="card-header text-center">Password for <?php print $LDAP['admin_bind_dn']; ?></div>
            <div class="card-body text-center">
                <form class="form-inline" action='' method='post'>
                    <div class="form-group">
                        <input type='password' class="form-control" name='admin_password'>
                    </div>
                    <div class="form-group">
                        <input type='submit' class="btn btn-secondary" value='Log in'>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}
render_footer();
?>
