<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/includes/");
include_once "web_functions.inc.php";
include_once "access_functions.inc.php";

// CRITICAL: Check for role configuration conflicts before allowing any access
// This prevents the system from operating with broken access control
if (function_exists('checkRuntimeRoleConflicts') && checkRuntimeRoleConflicts()) {
    displayMaintenanceMode();
}

// Use the enhanced access control function
// The main index should be accessible to all authenticated users
setPageAccess("user");

// After access control, redirect privileged roles to their default view
if (
    isset($VALIDATED) && $VALIDATED && (
    (isset($IS_ADMIN) && $IS_ADMIN)
    || (isset($IS_MAINTAINER) && $IS_MAINTAINER)
    || (isset($IS_ORG_ADMIN) && $IS_ORG_ADMIN)
    )
) {
    if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
        error_log('Main Index: Redirecting privileged user to role default');
    }
    header('Location: ' . getDefaultRedirectForUser());
    exit;
}

if (isset($VALIDATED) && $VALIDATED && isset($LDAP_DEBUG) && $LDAP_DEBUG) {
    error_log('Main Index: User is regular user, allowing access to main index');
}

renderHeader();

if (isset($_GET['logged_in'])) {
    ?>
    <div class="alert alert-success">
        <p class="text-center"><?php echo htmlspecialchars(t('index.logged_in_select_menu'), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <?php
}

renderFooter();
?>
