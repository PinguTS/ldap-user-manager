<?php

set_include_path( ".:" . __DIR__ . "/includes/");
include_once "web_functions.inc.php";
include_once "access_functions.inc.php";

// Add debugging to see what's happening
if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
  error_log("Main Index: Page accessed");
  error_log("Main Index: Session validation status: " . (isset($VALIDATED) ? 'VALIDATED' : 'NOT VALIDATED'));
  if (isset($USER_ID)) {
    error_log("Main Index: User ID: $USER_ID");
  }
  if (isset($USER_DN)) {
    error_log("Main Index: User DN: $USER_DN");
  }
  
  // Check if access control functions are available
  if (function_exists('currentUserIsOrgAdmin')) {
    error_log("Main Index: currentUserIsOrgAdmin function is available");
  } else {
    error_log("Main Index: ERROR - currentUserIsOrgAdmin function is NOT available");
  }
  
  if (function_exists('currentUserIsGlobalAdmin')) {
    error_log("Main Index: currentUserIsGlobalAdmin function is available");
  } else {
    error_log("Main Index: ERROR - currentUserIsGlobalAdmin function is NOT available");
  }
  
  if (function_exists('currentUserIsMaintainer')) {
    error_log("Main Index: currentUserIsMaintainer function is available");
  } else {
    error_log("Main Index: ERROR - currentUserIsMaintainer function is NOT available");
  }
}

// Check if user is an organization admin (but not global admin or maintainer)
// If so, redirect them to their organization page since the main index
// doesn't provide any useful functionality for organization admins
if (function_exists('currentUserIsOrgAdmin') && function_exists('currentUserIsGlobalAdmin') && function_exists('currentUserIsMaintainer')) {
  if (currentUserIsOrgAdmin() && !currentUserIsGlobalAdmin() && !currentUserIsMaintainer()) {
    $org_name = currentUserGetOrgName();
    $org_uuid = currentUserGetOrgUuid();
    
    if ($org_uuid) {
      // Use UUID-based URL for better security
      $redirect_url = "account_manager/show_organization.php?uuid=" . urlencode($org_uuid);
    } elseif ($org_name) {
      // Fallback to name-based URL if UUID not available
      $redirect_url = "account_manager/show_organization.php?org=" . urlencode($org_name);
    } else {
      // If we can't determine the organization, redirect to change password
      $redirect_url = "change_password/index.php";
    }
    
    // Log the redirect for debugging
    if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
      error_log("Main Index: Redirecting org admin '$org_name' to: $redirect_url");
      error_log("Main Index: User roles - Admin: " . (currentUserIsGlobalAdmin() ? 'YES' : 'NO') . ", Maintainer: " . (currentUserIsMaintainer() ? 'YES' : 'NO') . ", Org Admin: " . (currentUserIsOrgAdmin() ? 'YES' : 'NO'));
    }
    
    // Perform the redirect
    header("Location: $redirect_url");
    exit;
  }
} else {
  if (isset($LDAP_DEBUG) && $LDAP_DEBUG) {
    error_log("Main Index: WARNING - Access control functions not available, skipping redirect logic");
  }
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
