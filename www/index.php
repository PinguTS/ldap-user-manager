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
set_page_access("user");

// After access control, check if user should be redirected to their default view
// This ensures users don't stay on the empty main index page
if (isset($VALIDATED) && $VALIDATED) {
    // User is authenticated, check if they should be redirected
    if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
        error_log("Main Index: User is validated, checking role for redirect");
        error_log("Main Index: User roles - Admin: " . (isset($IS_ADMIN) && $IS_ADMIN ? 'YES' : 'NO') . ", Maintainer: " . (isset($IS_MAINTAINER) && $IS_MAINTAINER ? 'YES' : 'NO') . ", Org Admin: " . (isset($IS_ORG_ADMIN) && $IS_ORG_ADMIN ? 'YES' : 'NO'));
    }
    
    if (isset($IS_ADMIN) && $IS_ADMIN) {
        // Global admin, redirect to account manager
        if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
            error_log("Main Index: Redirecting global admin to manage/users/");
        }
        header("Location: manage/users/");
        exit;
    } elseif (isset($IS_MAINTAINER) && $IS_MAINTAINER) {
        // Maintainer, redirect to organizations page
        if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
            error_log("Main Index: Redirecting maintainer to manage/organizations/");
        }
        header("Location: manage/organizations/");
        exit;
    } elseif (isset($IS_ORG_ADMIN) && $IS_ORG_ADMIN) {
        // Organization admin, redirect to their organization page
        $org_name = currentUserGetOrgName();
        $org_uuid = currentUserGetOrgUuid();
        
        if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
            error_log("Main Index: Redirecting org admin '$org_name' to organization page");
        }
        
        if ($org_uuid) {
            header("Location: manage/organizations/show/index.php?uuid=" . urlencode($org_uuid));
        } elseif ($org_name) {
            header("Location: manage/organizations/show/index.php?org=" . urlencode($org_name));
        } else {
            header("Location: change_password/");
        }
        exit;
    }
    
    if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
        error_log("Main Index: User is regular user, allowing access to main index");
    }
    // Regular users can stay on the main index
}

render_header();

if (isset($_GET['logged_in'])) {
    ?>
    <div class="alert alert-success">
        <p class="text-center">You're logged in. Select from the menu above.</p>
    </div>
    <?php
}

render_footer();
?>
