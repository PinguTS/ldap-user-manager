<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once dirname(__DIR__) . "/module_functions.inc.php";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ldap_connection = open_ldap_connection();
    
    switch ($_POST['action']) {
        case 'lock_user':
            if (isset($_POST['user_identifier']) && currentUserCanDisableUser($_POST['user_identifier'])) {
                $user_identifier = trim($_POST['user_identifier']);
                $user_dn = get_user_dn_from_identifier($ldap_connection, $user_identifier);
                
                if ($user_dn && ldap_lock_user_account($ldap_connection, $user_dn)) {
                    $message = "User '$user_identifier' has been locked successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Failed to lock user '$user_identifier'. Please check the logs for details.";
                    $message_type = 'danger';
                }
            } else {
                $message = "Permission denied or invalid user identifier.";
                $message_type = 'danger';
            }
            break;
            
        case 'unlock_user':
            if (isset($_POST['user_identifier']) && currentUserCanEnableUser($_POST['user_identifier'])) {
                $user_identifier = trim($_POST['user_identifier']);
                $user_dn = get_user_dn_from_identifier($ldap_connection, $user_identifier);
                
                if ($user_dn && ldap_unlock_user_account($ldap_connection, $user_dn)) {
                    $message = "User '$user_identifier' has been unlocked successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Failed to unlock user '$user_identifier'. Please check the logs for details.";
                    $message_type = 'danger';
                }
            } else {
                $message = "Permission denied or invalid user identifier.";
                $message_type = 'danger';
            }
            break;
            
        case 'delete_user':
            // Existing delete logic
            if (isset($_POST['user_identifier'])) {
                $this_user = urldecode($_POST['user_identifier']);
                
                // Check if this is a UUID or account identifier
                $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $this_user);
                
                if ($is_uuid) {
                    // Convert UUID to account identifier for delete operation
                    $user_entry = ldap_get_entry_by_uuid($ldap_connection, $this_user, $LDAP['people_dn']);
                    if (!$user_entry || !isset($user_entry['uid'][0])) {
                        render_alert_banner("User not found with UUID: $this_user", "danger");
                        return;
                    }
                    $this_user = $user_entry['uid'][0];
                }

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
            break;
    }
    
    ldap_close($ldap_connection);
}

// Ensure CSRF token is generated early
get_csrf_token();

set_page_access(["admin", "maintainer"]);

render_header("$ORGANISATION_NAME - System User Management");
render_submenu();

$ldap_connection = open_ldap_connection();

// Get only system users (not organization users)
$people = ldap_get_system_users($ldap_connection);

?>
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>System User Management</h2>
            
            <?php if (isset($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible" role="alert">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
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
                <input class="form-control" id="user_search_input" type="text" placeholder="Search system users...">
            </div>
            
            <table class="table table-striped" id="user_table">
                <thead>
                    <tr>
                        <th>Account ID</th>
                        <th>Given Name</th>
                        <th>Surname</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="userlist">
                    <?php
                    foreach ($people as $account_identifier => $attribs){

                        // Get user DN for role checking - use DN from user data if available
                        $user_dn = isset($attribs['dn']) ? $attribs['dn'] : get_user_dn_from_identifier($ldap_connection, $account_identifier);
                        
                        $role_membership = ldap_user_group_membership($ldap_connection, $user_dn);
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
                        
                        // Display lock status
                        $user_dn_for_status = get_user_dn_from_identifier($ldap_connection, $account_identifier);
                        if ($user_dn_for_status) {
                            if (ldap_user_is_locked($ldap_connection, $user_dn_for_status)) {
                                print '<span class="badge badge-danger">Locked</span>';
                            } else {
                                print '<span class="badge badge-success">Active</span>';
                            }
                        } else {
                            print '<span class="badge badge-secondary">Unknown</span>';
                        }
                        
                        print "</td>\n";
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
                            if ($user_dn) {
                                // Use LDAP_ESCAPE_FILTER if available, otherwise use 0 (PHP < 7.3 compatibility)
                                $escape_flag = defined('LDAP_ESCAPE_FILTER') ? LDAP_ESCAPE_FILTER : 0;
                                $admin_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_group_name']})(member=" . ldap_escape($user_dn, "", $escape_flag) . "))";
                                $ldap_search = @ldap_search($ldap_connection, $LDAP['roles_dn'], $admin_role_filter, ['cn']);
                                if ($ldap_search) {
                                    $result = ldap_get_entries($ldap_connection, $ldap_search);
                                    if ($result['count'] > 0) {
                                        $can_delete = false;
                                        $delete_reason = $LDAP['error_messages']['maintainer_cannot_delete_admin'];
                                    } else {
                                        $can_delete = true;
                                    }
                                } else {
                                    $can_delete = true;
                                }
                            }
                        }
                        
                        if ($can_delete) {
                            // Use UUID for delete if available, otherwise use account_identifier
                            $delete_param = $user_uuid ? $user_uuid : $account_identifier;
                            print "     <button type='button' class='btn btn-xs btn-danger' onclick='confirmDelete(\"" . htmlspecialchars($delete_param) . "\", \"" . htmlspecialchars($account_identifier) . "\")'>Delete</button>";
                        } else {
                            print "     <button type='button' class='btn btn-xs btn-danger' disabled title='" . htmlspecialchars($delete_reason) . "'>Delete</button>";
                        }
                        
                        // Add lock/unlock functionality
                        if (currentUserCanDisableUser($account_identifier)) {
                            $user_dn_for_lock = get_user_dn_from_identifier($ldap_connection, $account_identifier);
                            if ($user_dn_for_lock) {
                                if (ldap_user_is_locked($ldap_connection, $user_dn_for_lock)) {
                                    print "     <button type='button' class='btn btn-xs btn-success' onclick='confirmUnlockUser(\"" . htmlspecialchars($account_identifier) . "\")'>Unlock</button>";
                                } else {
                                    print "     <button type='button' class='btn btn-xs btn-warning' onclick='confirmLockUser(\"" . htmlspecialchars($account_identifier) . "\")'>Lock</button>";
                                }
                            }
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

<!-- Delete User Modal -->
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
                <p class="text-danger"><strong>Warning:</strong> This action cannot be undone and will remove all associated data.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <form method="post" action="" style="display: inline;">
                    <?php echo csrf_token_field(); ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_identifier" id="deleteUserInput">
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Lock User Modal -->
<div class="modal fade" id="lockUserModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">Confirm User Lock</h4>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to lock the user "<span id="lockUserName"></span>"?</p>
                <p class="text-warning"><strong>Warning:</strong> This will disable the user account. The user will not be able to log in until the account is unlocked.</p>
                <p><strong>Note:</strong> This action is reversible. You can unlock the user at any time.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <form method="post" action="" style="display: inline;">
                    <?php echo csrf_token_field(); ?>
                    <input type="hidden" name="action" value="lock_user">
                    <input type="hidden" name="user_identifier" id="lockUserInput">
                    <button type="submit" class="btn btn-warning">Lock User</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Unlock User Modal -->
<div class="modal fade" id="unlockUserModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">Confirm User Unlock</h4>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to unlock the user "<span id="unlockUserName"></span>"?</p>
                <p class="text-success"><strong>Effect:</strong> This will re-enable the user account. The user will be able to log in again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <form method="post" action="" style="display: inline;">
                    <?php echo csrf_token_field(); ?>
                    <input type="hidden" name="action" value="unlock_user">
                    <input type="hidden" name="user_identifier" id="unlockUserInput">
                    <button type="submit" class="btn btn-success">Unlock User</button>
                </form>
            </div>
        </div>
    </div>
</div>

    <script src="/js/jquery-3.6.0.min.js"></script>
    <script src="/js/user_management.min.js"></script>
    <script>
        // Initialize common user management page functionality
        document.addEventListener('DOMContentLoaded', function() {
            initializeUserManagementPage({
                searchInputId: 'user_search_input',
                tableId: 'user_table',
                messageId: 'msgbox'
            });
        });
        
        // User lock/unlock functions
        function confirmLockUser(userIdentifier) {
            document.getElementById('lockUserName').textContent = userIdentifier;
            document.getElementById('lockUserInput').value = userIdentifier;
            $('#lockUserModal').modal('show');
        }
        
        function confirmUnlockUser(userIdentifier) {
            document.getElementById('unlockUserName').textContent = userIdentifier;
            document.getElementById('unlockUserInput').value = userIdentifier;
            $('#unlockUserModal').modal('show');
        }
        
        function confirmDelete(userIdentifier, displayName) {
            document.getElementById('deleteUserName').textContent = displayName;
            document.getElementById('deleteUserInput').value = userIdentifier;
            $('#deleteModal').modal('show');
        }
    </script>

<?php
render_footer();
?>
