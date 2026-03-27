<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ldap_connection = open_ldap_connection();

    switch ($_POST['action']) {
        case 'disable_user':
            if (isset($_POST['user_identifier']) && currentUserCanDisableUser($_POST['user_identifier'])) {
                $user_identifier = trim($_POST['user_identifier']);

                // Get user DN from the user data we already have
                $user_dn = null;
                if (isset($people[$user_identifier]['dn'])) {
                    $user_dn = $people[$user_identifier]['dn'];
                } else {
                    // Fallback to searching if not found in current data
                    $user_dn = get_user_dn_from_identifier($ldap_connection, $user_identifier);
                }

                if ($user_dn && ldap_disable_user_account($ldap_connection, $user_dn)) {
                    $message = t('manage.users.msg.deactivate_ok', ['user' => $user_identifier]);
                    $message_type = 'success';
                } else {
                    // Get the actual LDAP error for debugging
                    $ldap_error = ldap_error($ldap_connection);
                    $message = t('manage.users.msg.deactivate_fail', ['user' => $user_identifier, 'error' => $ldap_error]);
                    $message_type = 'danger';

                    // Log additional debugging information
                    error_log("Disable user failed - User: $user_identifier, DN: $user_dn, LDAP Error: $ldap_error");
                }
            } else {
                $message = t('manage.users.msg.permission_denied_invalid_user');
                $message_type = 'danger';
            }
            break;

        case 'enable_user':
            if (isset($_POST['user_identifier']) && currentUserCanEnableUser($_POST['user_identifier'])) {
                $user_identifier = trim($_POST['user_identifier']);

                // Get user DN from the user data we already have
                $user_dn = null;
                if (isset($people[$user_identifier]['dn'])) {
                    $user_dn = $people[$user_identifier]['dn'];
                } else {
                    // Fallback to searching if not found in current data
                    $user_dn = get_user_dn_from_identifier($ldap_connection, $user_identifier);
                }

                if ($user_dn && ldap_enable_user_account($ldap_connection, $user_dn)) {
                    $message = t('manage.users.msg.activate_ok', ['user' => $user_identifier]);
                    $message_type = 'success';
                } else {
                    // Get the actual LDAP error for debugging
                    $ldap_error = ldap_error($ldap_connection);
                    $message = t('manage.users.msg.activate_fail', ['user' => $user_identifier, 'error' => $ldap_error]);
                    $message_type = 'danger';

                    // Log additional debugging information
                    error_log("Enable user failed - User: $user_identifier, DN: $user_dn, LDAP Error: $ldap_error");
                }
            } else {
                $message = t('manage.users.msg.permission_denied_invalid_user');
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
                        render_alert_banner(t('manage.users.msg.user_not_found'), "danger");
                        return;
                    }
                    $this_user = $user_entry['uid'][0];
                }

                // Check if user can delete this user
                $can_delete = false;
                $delete_reason = '';

                // Prevent self-deletion
                if ($this_user === $USER_ID) {
                    render_alert_banner(t('manage.users.msg.cannot_delete_self'), "danger");
                }
                // Check role-based permissions
            } elseif (currentUserIsGlobalAdmin()) {
                $can_delete = true;
            } elseif (currentUserIsMaintainer()) {
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
                    render_alert_banner(t('manage.users.msg.delete_ok', ['user' => $this_user]));
                } else {
                    render_alert_banner(t('manage.users.msg.delete_fail'), "danger", 15000);
                }
            } else {
                render_alert_banner(t('manage.users.msg.permission_denied_reason', ['reason' => $delete_reason]), "danger");
            }
            break;
        } // phpcs:ignore Generic.WhiteSpace.ScopeIndent.IncorrectExact,Squiz.WhiteSpace.ScopeClosingBrace.Indent -- switch at 8 spaces
    ldap_close($ldap_connection);
}

// Ensure CSRF token is generated early
get_csrf_token();

set_page_access(["admin", "maintainer"]);

$orgName = (string) ($ORGANISATION_NAME ?? 'System');
render_header(t('manage.users.page_title', ['org' => $orgName]));
render_submenu();

$ldap_connection = open_ldap_connection();
if ($ldap_connection === false) {
    $message = t('manage.users.msg.ldap_unavailable');
    $message_type = 'danger';
    $people = [];
} else {
    // Get only system users (not organization users)
    $people = ldap_get_system_users($ldap_connection);
}

?>
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2><?php echo htmlspecialchars(t('manage.users.heading'), ENT_QUOTES, 'UTF-8'); ?></h2>
            
            <?php if (isset($message)) : ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></button>
                </div>
            <?php endif; ?>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <?php if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) : ?>
                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/users/new/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success">
                        <i class="bi bi-plus-lg"></i> <?php echo htmlspecialchars(t('manage.users.new_system_user'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <?php endif; ?>
                    <?php $systemUserCount = count($people); ?>
                    <span class="badge bg-info">
                        <?php
                        echo htmlspecialchars(
                            $systemUserCount === 1
                                ? t('manage.users.system_user_count_one', ['count' => (string) $systemUserCount])
                                : t('manage.users.system_user_count_many', ['count' => (string) $systemUserCount]),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        ?>
                    </span>
                </div>
                <div class="col-md-6 text-end">
                    <?php if (currentUserIsGlobalAdmin()) : ?>
                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info"><?php echo htmlspecialchars(t('manage.dashboard.manage_orgs'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/roles/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-warning"><?php echo htmlspecialchars(t('manage.submenu.role_management'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php elseif (currentUserIsMaintainer()) : ?>
                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info"><?php echo htmlspecialchars(t('manage.dashboard.manage_orgs'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php elseif (currentUserIsOrgAdmin()) : ?>
                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info"><?php echo htmlspecialchars(t('manage.dashboard.manage_orgs'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="alert alert-info">
                <strong><?php echo htmlspecialchars(t('manage.users.system_users_only_title'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php echo htmlspecialchars(t('manage.users.system_users_only_body'), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            
            <div class="form-group">
                <input class="form-control" id="user_search_input" type="text" placeholder="<?php echo htmlspecialchars(t('manage.users.search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            
            <table class="table table-striped" id="user_table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('manage.common.account_id'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.first_name'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.last_name'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.email'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.roles'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.status'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.actions'), ENT_QUOTES, 'UTF-8'); ?></th>
                    </tr>
                </thead>
                <tbody id="userlist">
                    <?php
                    foreach ($people as $account_identifier => $attribs) {
                        // Get user DN for role checking - use DN from user data if available
                        $user_dn = isset($attribs['dn']) ? $attribs['dn'] : get_user_dn_from_identifier($ldap_connection, $account_identifier);

                        $role_membership = ldap_user_group_membership($ldap_connection, $user_dn);
                        if (!is_array($role_membership)) {
                            $role_membership = [];
                        }

                        if (isset($people[$account_identifier]['mail'])) {
                            $this_mail = $people[$account_identifier]['mail'];
                        } else {
                            $this_mail = "";
                        }

                        // Canonical routing is UUID-only.
                        $user_uuid = isset($people[$account_identifier]['entryUUID']) ? (string) $people[$account_identifier]['entryUUID'] : '';
                        $user_href = $user_uuid !== '' ? '/manage/users/' . urlencode($user_uuid) . '/' : '';

                        print " <tr>\n";
                        if ($user_href !== '') {
                            print "   <td><a href='{$user_href}'>" . htmlspecialchars($account_identifier) . "</a></td>\n";
                        } else {
                            print "   <td>" . htmlspecialchars($account_identifier) . "</td>\n";
                        }
                        print "   <td>" . safe_user_attribute($people[$account_identifier], 'givenname') . "</td>\n";
                        print "   <td>" . safe_user_attribute($people[$account_identifier], 'sn') . "</td>\n";
                        print "   <td>" . htmlspecialchars($this_mail) . "</td>\n";
                        print "   <td>" . htmlspecialchars(implode(", ", $role_membership)) . "</td>\n";
                        print "   <td>";

                        $user_dn_for_status = get_user_dn_from_identifier($ldap_connection, $account_identifier);
                        if ($user_dn_for_status) {
                            $is_disabled = ldap_user_is_disabled($ldap_connection, $user_dn_for_status);
                            $is_individually_disabled = ldap_user_is_individually_disabled($ldap_connection, $user_dn_for_status);
                            $user_org_name = get_organization_from_user_dn($user_dn_for_status);
                            $is_user_org_disabled = ($user_org_name !== false && $user_org_name !== '' && ldap_organization_is_disabled($ldap_connection, $user_org_name));
                            if ($is_disabled) {
                                if ($is_individually_disabled && $is_user_org_disabled) {
                                    $badge_title = t('manage.org_users.deactivate_reason.both');
                                } elseif ($is_individually_disabled) {
                                    $badge_title = t('manage.org_users.deactivate_reason.individual');
                                } elseif ($is_user_org_disabled) {
                                    $badge_title = t('manage.org_users.deactivate_reason.org');
                                } else {
                                    $badge_title = t('manage.common.inactive');
                                }
                                print '<span class="badge bg-danger" title="' . htmlspecialchars($badge_title, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('manage.common.inactive'), ENT_QUOTES, 'UTF-8') . '</span>';
                            } else {
                                print '<span class="badge bg-success">' . htmlspecialchars(t('manage.common.active'), ENT_QUOTES, 'UTF-8') . '</span>';
                            }
                        } else {
                            print '<span class="badge bg-secondary">' . htmlspecialchars(t('manage.common.unknown'), ENT_QUOTES, 'UTF-8') . '</span>';
                        }

                        print "</td>\n";
                        print "   <td>";
                        print "     <span class='d-inline-flex align-items-center flex-wrap gap-1'>";
                        print "       <span class='btn-group btn-group-sm' role='group' aria-label='" . htmlspecialchars(t('manage.common.user_actions_aria'), ENT_QUOTES, 'UTF-8') . "'>";
                        if ($user_href !== '') {
                            print "         <a href='{$user_href}' class='btn btn-sm btn-info'>" . htmlspecialchars(t('manage.common.view'), ENT_QUOTES, 'UTF-8') . "</a>";
                        }

                        // Check if current user can delete this user
                        $can_delete = false;
                        $delete_reason = '';

                        // Prevent self-deletion
                        if ($account_identifier === $USER_ID) {
                            $delete_reason = t('manage.users.msg.cannot_delete_self');
                        } elseif (currentUserIsGlobalAdmin()) {
                            $can_delete = true;
                        } elseif (currentUserIsMaintainer()) {
                        // Maintainers can only delete maintainer users, not admins
                            if ($user_dn) {
                                // Use LDAP_ESCAPE_FILTER if available, otherwise use 0 (PHP < 7.3 compatibility)
                                $escape_flag = defined('LDAP_ESCAPE_FILTER') ? LDAP_ESCAPE_FILTER : 0;
                                $admin_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_role']})(member=" . ldap_escape($user_dn, "", $escape_flag) . "))";
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

                        if (currentUserCanDisableUser($account_identifier)) {
                            if ($user_dn_for_status) {
                                $activate_tooltip = $is_user_org_disabled ? ' title=\'' . htmlspecialchars(t('manage.org_users.activate_tooltip_org_disabled'), ENT_QUOTES, 'UTF-8') . '\'' : '';
                                $deactivate_tooltip = $is_user_org_disabled ? ' title=\'' . htmlspecialchars(t('manage.org_users.deactivate_tooltip_org_disabled'), ENT_QUOTES, 'UTF-8') . '\'' : '';
                                if ($is_individually_disabled) {
                                    print "         <button type='button' class='btn btn-sm btn-success'" . $activate_tooltip . " onclick='confirmEnableUser(\"" . htmlspecialchars($account_identifier) . "\")'>" . htmlspecialchars(t('manage.common.activate'), ENT_QUOTES, 'UTF-8') . "</button>";
                                } else {
                                    print "         <button type='button' class='btn btn-sm btn-warning'" . $deactivate_tooltip . " onclick='confirmDisableUser(\"" . htmlspecialchars($account_identifier) . "\")'>" . htmlspecialchars(t('manage.common.deactivate'), ENT_QUOTES, 'UTF-8') . "</button>";
                                }
                            }
                        }

                        print "       </span>"; // btn-group
                        print "       <span class='ms-2 ps-2 border-start'>";
                        if ($can_delete) {
                            // Use UUID for deletes (canonical).
                            $delete_param = $user_uuid;
                            print "         <button type='button' class='btn btn-sm btn-danger' onclick='confirmDelete(\"" . htmlspecialchars($delete_param) . "\", \"" . htmlspecialchars($account_identifier) . "\")'>" . htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8') . "</button>";
                        } else {
                            print "         <button type='button' class='btn btn-sm btn-danger' disabled title='" . htmlspecialchars($delete_reason) . "'>" . htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8') . "</button>";
                        }
                        print "       </span>"; // delete separator
                        print "     </span>"; // d-inline-flex
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
<?php
render_confirm_modal(
    'deleteModal',
    t('manage.users.index.modal.delete_title'),
    t('manage.users.index.modal.delete_body'),
    [
        ['name' => 'action', 'value' => 'delete_user'],
        ['name' => 'user_identifier', 'id' => 'deleteUserInput'],
    ],
    t('manage.users.index.modal.delete_submit'),
    'btn-danger'
);
render_confirm_modal(
    'disableUserModal',
    t('manage.users.index.modal.deactivate_title'),
    t('manage.users.index.modal.deactivate_body'),
    [
        ['name' => 'action', 'value' => 'disable_user'],
        ['name' => 'user_identifier', 'id' => 'disableUserInput'],
    ],
    t('manage.users.index.modal.deactivate_submit'),
    'btn-warning'
);
render_confirm_modal(
    'enableUserModal',
    t('manage.users.index.modal.activate_title'),
    t('manage.users.index.modal.activate_body'),
    [
        ['name' => 'action', 'value' => 'enable_user'],
        ['name' => 'user_identifier', 'id' => 'enableUserInput'],
    ],
    t('manage.users.index.modal.activate_submit'),
    'btn-success'
);
?>

    <script src="<?php print get_asset_base(); ?>js/modals.js"></script>
    <script>
        // Initialize common user management page functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize search functionality
            const searchInput = document.getElementById('user_search_input');
            const userTable = document.getElementById('user_table');
            
            if (searchInput && userTable) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = userTable.querySelectorAll('tbody tr');
                    
                    rows.forEach(function(row) {
                        const text = row.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });

        function confirmDisableUser(userIdentifier) {
            confirmAction('disableUserModal', { disableUserName: userIdentifier, disableUserInput: userIdentifier });
        }
        function confirmEnableUser(userIdentifier) {
            confirmAction('enableUserModal', { enableUserName: userIdentifier, enableUserInput: userIdentifier });
        }
        function confirmDelete(userIdentifier, displayName) {
            confirmAction('deleteModal', { deleteUserName: displayName, deleteUserInput: userIdentifier });
        }
    </script>

<?php
render_footer();
?>
