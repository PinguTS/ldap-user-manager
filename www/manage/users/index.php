<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once dirname(__DIR__) . "/module_functions.inc.php";

// Ensure CSRF token is generated early
get_csrf_token();

set_page_access(["admin", "maintainer"]);

render_header("$ORGANISATION_NAME - System User Management");
render_submenu();

$ldap_connection = open_ldap_connection();

if (isset($_POST['delete_user'])) {
  if (!validate_csrf_token()) {
    render_alert_banner("Security validation failed. Please refresh the page and try again.", "danger");
  } else {
    $this_user = $_POST['delete_user'];
    $this_user = urldecode($this_user);

    // Check if user can delete this user
    $can_delete = false;
    $delete_reason = '';
    
    // Prevent self-deletion
    if ($this_user === $USER_ID) {
      render_alert_banner("You cannot delete your own account.", "danger");
    }
    // Check role-based permissions
    elseif (currentUserIsGlobalAdmin()) {
      $can_delete = true;
    }
    elseif (currentUserIsMaintainer()) {
      // Get the target user's role membership
      $target_user_dn = get_user_dn_from_identifier($ldap_connection, $this_user);
      if ($target_user_dn) {
        $role_membership = ldap_user_group_membership($ldap_connection, $target_user_dn);
        if (is_array($role_membership) && !in_array($LDAP['admin_role'], $role_membership)) {
          $can_delete = true;
        } else {
                          $delete_reason = $LDAP['error_messages']['maintainer_cannot_delete_admin'];
        }
      }
    }
    
    if ($can_delete) {
      $del_user = ldap_delete_account($ldap_connection, $this_user);
      if ($del_user) {
        render_alert_banner("User <strong>$this_user</strong> was deleted.");
      } else {
        render_alert_banner("User <strong>$this_user</strong> wasn't deleted. See the logs for more information.", "danger", 15000);
      }
    } else {
      render_alert_banner("Permission denied: $delete_reason", "danger");
    }
  }
}

// Get only system users (not organization users)
$people = ldap_get_system_users($ldap_connection);

?>
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>System User Management</h2>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <?php if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()): ?>
                    <a href="/manage/users/new.php" class="btn btn-success">
                        <i class="glyphicon glyphicon-plus"></i> New System User
                    </a>
                    <?php endif; ?>
                    <span class="badge badge-info"><?php print count($people);?> system user<?php if (count($people) != 1) { print "s"; }?></span>
                </div>
                <div class="col-md-6 text-right">
                    <?php if (currentUserIsGlobalAdmin()): ?>
                    <a href="/manage/organizations/" class="btn btn-info">Manage Organizations</a>
                    <a href="/manage/roles/" class="btn btn-warning">Role Management</a>
                    <?php elseif (currentUserIsMaintainer()): ?>
                    <a href="/manage/organizations/" class="btn btn-info">Manage Organizations</a>
                    <?php elseif (currentUserIsOrgAdmin()): ?>
                    <a href="/manage/organizations/" class="btn btn-info">Manage Organizations</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="alert alert-info">
                <strong>System Users Only:</strong> This view shows only system-level users. Organization users are managed through their respective organization pages.
            </div>
            
            <div class="form-group">
                <input class="form-control" id="search_input" type="text" placeholder="Search system users...">
            </div>
            
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Account name</th>
                        <th>First name</th>
                        <th>Last name</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="userlist">
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
                        
                        print " <tr>\n";
                        print "   <td><a href='/manage/users/show.php?{$user_link_param}'>" . htmlspecialchars($account_identifier) . "</a></td>\n";
                        print "   <td>" . safe_user_attribute($people[$account_identifier], 'givenname') . "</td>\n";
                        print "   <td>" . safe_user_attribute($people[$account_identifier], 'sn') . "</td>\n";
                        print "   <td>" . htmlspecialchars($this_mail) . "</td>\n"; 
                        print "   <td>" . htmlspecialchars(implode(", ", $role_membership)) . "</td>\n";
                        print "   <td>";
                        print "     <a href='/manage/users/show.php?{$user_link_param}' class='btn btn-xs btn-info'>View</a>";
                        
                        // Check if current user can delete this user
                        $can_delete = false;
                        $delete_reason = '';
                        
                        // Prevent self-deletion
                        if ($account_identifier === $USER_ID) {
                            $delete_reason = 'Cannot delete yourself';
                        }
                        // Check role-based permissions
                        elseif (currentUserIsGlobalAdmin()) {
                            $can_delete = true;
                        }
                                                  elseif (currentUserIsMaintainer()) {
          // Maintainers can only delete maintainer users, not admins
          // IMPORTANT: Check global admin role independently, regardless of role value conflicts
          $ldap = open_ldap_connection();
          $admin_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_group_name']})(member=$user_dn))";
          $ldap_search = @ldap_search($ldap, $LDAP['roles_dn'], $admin_role_filter, ['cn']);
          if ($ldap_search) {
            $result = ldap_get_entries($ldap, $ldap_search);
            ldap_close($ldap);
            if ($result['count'] > 0) {
              $can_delete = false;
              $delete_reason = $LDAP['error_messages']['maintainer_cannot_delete_admin'];
            }
          } else {
            ldap_close($ldap);
          }
        }
                        
                        if ($can_delete) {
                            print "     <button type='button' class='btn btn-xs btn-danger' onclick='confirmDelete(\"" . htmlspecialchars($account_identifier) . "\")'>Delete</button>";
                        } else {
                            print "     <button type='button' class='btn btn-xs btn-danger' disabled title='" . htmlspecialchars($delete_reason) . "'>Delete</button>";
                        }
                        
                        print "   </td>";
                        print " </tr>\n";

                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">Confirm User Deletion</h4>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the user "<span id="deleteUserName"></span>"?</p>
                <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <form method="post" action="" style="display: inline;">
                    <?php echo csrf_token_field(); ?>
                    <input type="hidden" name="delete_user" id="deleteUserInput">
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $("#search_input").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#userlist tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
});

function confirmDelete(userName) {
    document.getElementById('deleteUserName').textContent = userName;
    document.getElementById('deleteUserInput').value = userName;
    $('#deleteModal').modal('show');
}
</script>

<?php
render_footer();
?>
