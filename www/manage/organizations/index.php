<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap', 'organization']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ldap_connection = open_ldap_connection();

    switch ($_POST['action']) {
        case 'lock_organization':
            if (isset($_POST['org_name']) && currentUserCanDisableOrganization($_POST['org_name'])) {
                $org_name = trim($_POST['org_name']);
                if (ldap_lock_organization($ldap_connection, $org_name)) {
                    $message = "Organization '$org_name' has been locked successfully. All users in this organization are now disabled.";
                    $message_type = 'success';
                } else {
                    $message = "Failed to lock organization '$org_name'. Please check the logs for details.";
                    $message_type = 'danger';
                }
            } else {
                $message = "Permission denied or invalid organization name.";
                $message_type = 'danger';
            }
            break;

        case 'unlock_organization':
            if (isset($_POST['org_name']) && currentUserCanEnableOrganization($_POST['org_name'])) {
                $org_name = trim($_POST['org_name']);
                if (ldap_unlock_organization($ldap_connection, $org_name)) {
                    $message = "Organization '$org_name' has been unlocked successfully. All users in this organization are now enabled.";
                    $message_type = 'success';
                } else {
                    $message = "Failed to unlock organization '$org_name'. Please check the logs for details.";
                    $message_type = 'danger';
                }
            } else {
                $message = "Permission denied or invalid organization name.";
                $message_type = 'danger';
            }
            break;

        case 'delete_organization':
            // Existing delete logic
            if (isset($_POST['org_name']) && currentUserCanDeleteOrganization($_POST['org_name'])) {
                $org_name = trim($_POST['org_name']);
                $org_uuid = isset($_POST['org_uuid']) ? trim($_POST['org_uuid']) : '';

                if (ldap_delete_organization($ldap_connection, $org_name, $org_uuid)) {
                    $message = "Organization '$org_name' has been deleted successfully.";
                    $message_type = 'success';
                } else {
                    $message = "Failed to delete organization '$org_name'. Please check the logs for details.";
                    $message_type = 'danger';
                }
            } else {
                $message = "Permission denied or invalid organization name.";
                $message_type = 'danger';
            }
            break;
    }

    ldap_close($ldap_connection);
}

// Use the enhanced access control function
set_page_access(["admin", "maintainer", "org_admin"]);

// Get user's access level for UI customization
$is_global_admin = currentUserIsGlobalAdmin();
$is_maintainer = currentUserIsMaintainer();
$user_organizations = [];

// If user is an organization admin, get their organizations
if (!$is_global_admin && !$is_maintainer) {
    // For organization admins, we need to find which organizations they manage
    // This is a bit complex since we need to search through all organizations
    $all_orgs = listOrganizations();
    foreach ($all_orgs as $org) {
        if (currentUserIsOrgManager($org['name'])) {
            $user_organizations[] = $org['name'];
        }
    }
}

render_header(($ORGANISATION_NAME ?? 'System') . ' - Organization Management');
render_submenu();

// Get all organizations for display
$organizations = listOrganizations();
if (!is_array($organizations)) {
    $organizations = [];
}

// Establish LDAP connection for status checks
$ldap_connection = open_ldap_connection();
if (!$ldap_connection) {
    $message = "Failed to connect to LDAP server. Please check the logs for details.";
    $message_type = 'danger';
}
$member_group_cn = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
$disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';
$base_dn = $LDAP['base_dn'] ?? '';

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>Organization Management</h2>
            
            <?php if (isset($message)) : ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible" role="alert">
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (currentUserCanCreateOrganization()) : ?>
            <div class="row mb-3">
                <div class="col-md-12">
                    <a href="/manage/organizations/add.php" class="btn btn-success">
                        <i class="bi bi-plus-lg"></i> Add New Organization
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h3 class="card-title mb-0 h5">Existing Organizations</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($organizations)) : ?>
                        <p class="text-muted">No organizations found.</p>
                    <?php else : ?>
                        <div class="form-group">
                            <input class="form-control" id="org_search_input" type="text" placeholder="Search organizations..." style="margin-bottom: 15px;">
                        </div>
                        <div class="list-group">
                            <?php
                            // Filter organizations based on user permissions
                            $display_organizations = $organizations;
                            if (!$is_global_admin && !$is_maintainer) {
                                // Organization admins can only see their own organizations
                                $display_organizations = array_filter($organizations, function ($org) use ($user_organizations) {
                                    // Use the organization name directly
                                    $org_name = $org['name'] ?? 'Unknown';
                                    return in_array($org_name, $user_organizations);
                                });
                            }

                            if (empty($display_organizations)) : ?>
                                <p class="text-muted">No organizations found or you don't have permission to view any organizations.</p>
                            <?php else : ?>
                                <?php foreach ($display_organizations as $org) : ?>
                                    <?php
                                    // Use the organization name directly from the listOrganizations result
                                    $org_name = $org['name'] ?? 'Unknown Organization';
                                    $org_name_safe = htmlspecialchars($org_name);
                                    $org_name_url = urlencode($org_name);
                                    ?>
                                    <div class="list-group-item">
                                        <h5 class="mb-1"><?php echo $org_name_safe; ?></h5>
                                        <p class="mb-0">
                                            <?php if (isset($org['mail']) && !empty($org['mail'])) : ?>
                                                <strong>Email:</strong> <?php echo htmlspecialchars($org['mail']); ?><br>
                                            <?php endif; ?>
                                            <?php if (isset($org['telephoneNumber']) && !empty($org['telephoneNumber'])) : ?>
                                                <strong>Phone:</strong> <?php echo htmlspecialchars($org['telephoneNumber']); ?><br>
                                            <?php endif; ?>
                                            <?php if (isset($org['description']) && !empty($org['description'])) : ?>
                                                <strong>Status:</strong> <?php echo htmlspecialchars($org['description']); ?><br>
                                            <?php endif; ?>
                                            <?php
                                            $org_dn = $org['dn'] ?? '';
                                            $is_member = ($ldap_connection && $base_dn !== '' && $org_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $member_group_cn, $base_dn));
                                            $is_disabled = ($ldap_connection && $base_dn !== '' && $org_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $disabled_group_cn, $base_dn));
                                            ?>
                                            <?php if ($is_member) :
                                                ?><span class="badge bg-primary">Member</span><?php
                                            endif; ?>
                                            <?php if ($is_disabled) :
                                                ?><span class="badge bg-danger">Disabled</span><?php
                                            endif; ?>
                                            <strong>Account Status:</strong>
                                            <span class="badge bg-<?php
                                            if ($ldap_connection && ldap_organization_is_locked($ldap_connection, $org_name)) {
                                                echo 'danger';
                                            } else {
                                                echo 'success';
                                            }
                                            ?>">
                                                <?php
                                                if ($ldap_connection && ldap_organization_is_locked($ldap_connection, $org_name)) {
                                                    echo 'Locked';
                                                } else {
                                                    echo 'Active';
                                                }
                                                ?>
                                            </span>
                                        </p>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($LDAP['use_uuid_identification'] && isset($org['entryUUID'])) : ?>
                                                <a href="/manage/organizations/show/index.php?uuid=<?php echo urlencode($org['entryUUID']); ?>" class="btn btn-info">View</a>
                                                <a href="/manage/organizations/users/index.php?uuid=<?php echo urlencode($org['entryUUID']); ?>" class="btn btn-primary">Users</a>
                                                <?php if (currentUserCanDeleteOrganization($org_name)) : ?>
                                                    <button type="button" class="btn btn-danger" onclick="confirmDelete('<?php echo $org_name_safe; ?>', '<?php echo $org['entryUUID']; ?>')">Delete</button>
                                                <?php endif; ?>
                                                <?php if (currentUserCanDisableOrganization($org_name)) : ?>
                                                    <?php if ($ldap_connection && ldap_organization_is_locked($ldap_connection, $org_name)) : ?>
                                                        <button type="button" class="btn btn-success" onclick="confirmUnlockOrganization('<?php echo $org_name_safe; ?>')">Unlock</button>
                                                    <?php else : ?>
                                                        <button type="button" class="btn btn-warning" onclick="confirmLockOrganization('<?php echo $org_name_safe; ?>')">Lock</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <a href="/manage/organizations/show/index.php?org=<?php echo $org_name_url; ?>" class="btn btn-info">View</a>
                                                <a href="/manage/organizations/users/index.php?org=<?php echo $org_name_url; ?>" class="btn btn-primary">Users</a>
                                                <?php if (currentUserCanDeleteOrganization($org_name)) : ?>
                                                    <button type="button" class="btn btn-danger" onclick="confirmDelete('<?php echo $org_name_safe; ?>')">Delete</button>
                                                <?php endif; ?>
                                                <?php if (currentUserCanDisableOrganization($org_name)) : ?>
                                                    <?php if (ldap_organization_is_locked($ldap_connection, $org_name)) : ?>
                                                        <button type="button" class="btn btn-success" onclick="confirmUnlockOrganization('<?php echo $org_name_safe; ?>')">Unlock</button>
                                                    <?php else : ?>
                                                        <button type="button" class="btn btn-warning" onclick="confirmLockOrganization('<?php echo $org_name_safe; ?>')">Lock</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<?php
render_confirm_modal(
    'deleteModal',
    'Confirm Organization Deletion',
    '<p>Are you sure you want to delete the organization "<span id="deleteOrgName"></span>"?</p><p class="text-danger"><strong>Warning:</strong> This action cannot be undone and will remove all associated users and data.</p>',
    [
        ['name' => 'action', 'value' => 'delete_organization'],
        ['name' => 'org_name', 'id' => 'deleteOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'deleteOrgUuidInput'],
    ],
    'Delete Organization',
    'btn-danger'
);
render_confirm_modal(
    'lockModal',
    'Confirm Organization Lock',
    '<p>Are you sure you want to lock the organization "<span id="lockOrgName"></span>"?</p><p class="text-warning"><strong>Warning:</strong> This will disable all user accounts in this organization. Users will not be able to log in until the organization is unlocked.</p><p><strong>Note:</strong> This action is reversible. You can unlock the organization at any time.</p>',
    [
        ['name' => 'action', 'value' => 'lock_organization'],
        ['name' => 'org_name', 'id' => 'lockOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'lockOrgUuidInput'],
    ],
    'Lock Organization',
    'btn-warning'
);
render_confirm_modal(
    'unlockModal',
    'Confirm Organization Unlock',
    '<p>Are you sure you want to unlock the organization "<span id="unlockOrgName"></span>"?</p><p class="text-success"><strong>Effect:</strong> This will re-enable all user accounts in this organization. Users will be able to log in again.</p>',
    [
        ['name' => 'action', 'value' => 'unlock_organization'],
        ['name' => 'org_name', 'id' => 'unlockOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'unlockOrgUuidInput'],
    ],
    'Unlock Organization',
    'btn-success'
);
?>

<script src="<?php print get_asset_base(); ?>js/modals.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('org_search_input');
        const orgList = document.querySelector('.list-group');
        
        if (searchInput && orgList) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const items = orgList.querySelectorAll('.list-group-item');
                items.forEach(function(item) {
                    const text = item.textContent.toLowerCase();
                    item.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
        
        const messageBox = document.getElementById('msgbox');
        if (messageBox) {
            setTimeout(function() { messageBox.style.display = 'none'; }, 5000);
        }
    });

    function confirmLockOrganization(orgName, orgUuid) {
        confirmAction('lockModal', { lockOrgName: orgName, lockOrgNameInput: orgName, lockOrgUuidInput: orgUuid || '' });
    }
    function confirmUnlockOrganization(orgName, orgUuid) {
        confirmAction('unlockModal', { unlockOrgName: orgName, unlockOrgNameInput: orgName, unlockOrgUuidInput: orgUuid || '' });
    }
    function confirmDelete(orgName, orgUuid) {
        confirmAction('deleteModal', { deleteOrgName: orgName, deleteOrgNameInput: orgName, deleteOrgUuidInput: orgUuid || '' });
    }
</script>

<?php
// Clean up LDAP connection
if (isset($ldap_connection) && $ldap_connection) {
    ldap_close($ldap_connection);
}

render_footer();
?> 
