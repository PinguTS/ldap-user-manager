<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";

// Ensure CSRF token is generated early
get_csrf_token();

set_page_access("admin");

render_header("$ORGANISATION_NAME account manager");
render_submenu();

$ldap_connection = open_ldap_connection();

if (isset($_POST['delete_user'])) {
  if (!validate_csrf_token()) {
    render_alert_banner("Security validation failed. Please refresh the page and try again.", "danger");
  } else {
    $this_user = $_POST['delete_user'];
    $this_user = urldecode($this_user);

    $del_user = ldap_delete_account($ldap_connection,$this_user);

    if ($del_user) {
      render_alert_banner("User <strong>$this_user</strong> was deleted.");
    }
    else {
      render_alert_banner("User <strong>$this_user</strong> wasn't deleted.  See the logs for more information.","danger",15000);
    }
  }
}

// Get only system users (not organization users)
$people = ldap_get_system_users($ldap_connection);

?>
<div class="container">
 <form action="<?php print $THIS_MODULE_PATH; ?>/new_user.php" method="post">
  <button type="button" class="btn btn-light"><?php print count($people);?> system user<?php if (count($people) != 1) { print "s"; }?></button>  &nbsp; <button id="add_group" class="btn btn-default" type="submit">New System User</button>
 </form> 
<?php if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()): ?>
<a href="organizations.php" class="btn btn-info mb-3">Manage Organizations</a>
<a href="manage_roles.php" class="btn btn-warning mb-3">Role Management</a>
<?php endif; ?>
<div class="alert alert-info">
  <strong>System Users Only:</strong> This view shows only system-level users. Organization users are managed through their respective organization pages.
</div>
 <input class="form-control" id="search_input" type="text" placeholder="Search system users...">
 <table class="table table-striped">
  <thead>
   <tr>
     <th>Account name</th>
     <th>First name</th>
     <th>Last name</th>
     <th>Email</th>
     <th>Roles</th>
   </tr>
  </thead>
 <tbody id="userlist">
   <script>
    $(document).ready(function(){
      $("#search_input").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#userlist tr").filter(function() {
          $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
      });
    });
  </script>
<?php
foreach ($people as $account_identifier => $attribs){

  $role_membership = ldap_user_group_membership($ldap_connection,$account_identifier);
  if (!is_array($role_membership)) {
    $role_membership = [];
  }
  if (isset($people[$account_identifier]['mail'])) { $this_mail = $people[$account_identifier]['mail']; } else { $this_mail = ""; }
  
  // Use UUID for user link if available, otherwise fall back to account_identifier
  $user_uuid = isset($people[$account_identifier]['entryUUID']) ? $people[$account_identifier]['entryUUID'] : '';
  $user_link_param = $user_uuid ? 'uuid=' . urlencode($user_uuid) : 'account_identifier=' . urlencode($account_identifier);
  
  print " <tr>\n   <td><a href='{$THIS_MODULE_PATH}/show_user.php?{$user_link_param}'>" . htmlspecialchars($account_identifier) . "</a></td>\n";
  print "   <td>" . safe_user_attribute($people[$account_identifier], 'givenname') . "</td>\n";
  print "   <td>" . safe_user_attribute($people[$account_identifier], 'sn') . "</td>\n";
  print "   <td>" . htmlspecialchars($this_mail) . "</td>\n"; 
  print "   <td>" . htmlspecialchars(implode(", ", $role_membership)) . "</td>\n";
  print " </tr>\n";

}
?>
  </tbody>
 </table>
</div>
<?php

ldap_close($ldap_connection);
render_footer();
?>
