<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap', 'organization']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $ldap_connection = open_ldap_connection();
    if ($ldap_connection === false) {
        $message = t('manage.orgs.msg.ldap_fail');
        $message_type = 'danger';
    } else {
        switch ($_POST['action']) {
            case 'member_organization':
                if (isset($_POST['org_name']) && (currentUserIsGlobalAdmin() || currentUserIsMaintainer())) {
                    $org_name = trim($_POST['org_name']);
                    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
                    $member_group_cn_post = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
                    $base_dn = $LDAP['base_dn'] ?? '';
                    if ($base_dn !== '' && function_exists('addToStatusGroup') && addToStatusGroup($ldap_connection, $org_dn, $member_group_cn_post, $base_dn)) {
                        $message = t('manage.orgs.msg.member_ok', ['org' => $org_name]);
                        $message_type = 'success';
                    } else {
                        $message = t('manage.orgs.msg.member_fail', ['org' => $org_name]);
                        $message_type = 'danger';
                    }
                } else {
                    $message = t('manage.orgs.msg.perm_denied');
                    $message_type = 'danger';
                }
                break;

            case 'unmember_organization':
                if (isset($_POST['org_name']) && (currentUserIsGlobalAdmin() || currentUserIsMaintainer())) {
                    $org_name = trim($_POST['org_name']);
                    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
                    $member_group_cn_post = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
                    $base_dn = $LDAP['base_dn'] ?? '';
                    if ($base_dn !== '' && function_exists('removeFromStatusGroup') && removeFromStatusGroup($ldap_connection, $org_dn, $member_group_cn_post, $base_dn)) {
                        $message = t('manage.orgs.msg.unmember_ok', ['org' => $org_name]);
                        $message_type = 'success';
                    } else {
                        $message = t('manage.orgs.msg.unmember_fail', ['org' => $org_name]);
                        $message_type = 'danger';
                    }
                } else {
                    $message = t('manage.orgs.msg.perm_denied');
                    $message_type = 'danger';
                }
                break;

            case 'disable_organization':
                if (isset($_POST['org_name']) && currentUserCanDisableOrganization($_POST['org_name'])) {
                    $org_name = trim($_POST['org_name']);
                    if (ldap_disable_organization($ldap_connection, $org_name)) {
                        $message = t('manage.orgs.msg.deactivate_ok', ['org' => $org_name]);
                        $message_type = 'success';
                    } else {
                        $message = t('manage.orgs.msg.deactivate_fail', ['org' => $org_name]);
                        $message_type = 'danger';
                    }
                } else {
                    $message = t('manage.orgs.msg.perm_denied');
                    $message_type = 'danger';
                }
                break;

            case 'enable_organization':
                if (isset($_POST['org_name']) && currentUserCanEnableOrganization($_POST['org_name'])) {
                    $org_name = trim($_POST['org_name']);
                    $enable_result = ldap_enable_organization($ldap_connection, $org_name);
                    if ($enable_result !== false && $enable_result['ok']) {
                        $message = t('manage.orgs.msg.activate_ok', ['org' => $org_name]);
                        if ($enable_result['still_disabled'] > 0) {
                            $message .= ' ' . t('manage.orgs.show.msg.activate_summary', [
                                'activated' => $enable_result['enabled'],
                                'still_inactive' => $enable_result['still_disabled'],
                            ]);
                        }
                        $message_type = 'success';
                    } else {
                        $message = t('manage.orgs.msg.activate_fail', ['org' => $org_name]);
                        $message_type = 'danger';
                    }
                } else {
                    $message = t('manage.orgs.msg.perm_denied');
                    $message_type = 'danger';
                }
                break;

            case 'delete_organization':
                // Existing delete logic
                if (isset($_POST['org_name']) && currentUserCanDeleteOrganization($_POST['org_name'])) {
                    $org_name = trim($_POST['org_name']);
                    $org_uuid = isset($_POST['org_uuid']) ? trim($_POST['org_uuid']) : '';

                    if (ldap_delete_organization($ldap_connection, $org_name, $org_uuid)) {
                        $message = t('manage.orgs.msg.delete_ok', ['org' => $org_name]);
                        $message_type = 'success';
                    } else {
                        $message = t('manage.orgs.msg.delete_fail', ['org' => $org_name]);
                        $message_type = 'danger';
                    }
                } else {
                    $message = t('manage.orgs.msg.perm_denied');
                    $message_type = 'danger';
                }
                break;
        }

        ldap_close($ldap_connection);
    }
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

render_header(t('manage.orgs.page_title', ['org' => $ORGANISATION_NAME ?? 'System']));
render_submenu();

// Get all organizations for display
$organizations = listOrganizations();
if (!is_array($organizations)) {
    $organizations = [];
}

// Establish LDAP connection for status checks
$ldap_connection = open_ldap_connection();
if (!$ldap_connection) {
    $message = t('manage.orgs.ldap_conn_failed');
    $message_type = 'danger';
}
$member_group_cn = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
$disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';
$base_dn = $LDAP['base_dn'] ?? '';

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2><?php echo htmlspecialchars(t('manage.orgs.heading'), ENT_QUOTES, 'UTF-8'); ?></h2>
            
            <?php if (isset($message)) : ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible" role="alert">
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></button>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (currentUserCanCreateOrganization()) : ?>
            <div class="row mb-3">
                <div class="col-md-12">
                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/new/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success">
                        <i class="bi bi-plus-lg"></i> <?php echo htmlspecialchars(t('manage.orgs.add_new'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h3 class="card-title mb-0 h5"><?php echo htmlspecialchars(t('manage.orgs.existing'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <?php if (empty($organizations)) : ?>
                        <p class="text-muted"><?php echo htmlspecialchars(t('manage.orgs.no_orgs'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else : ?>
                        <div class="form-group">
                            <input class="form-control" id="org_search_input" type="text" placeholder="<?php echo htmlspecialchars(t('manage.orgs.search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom: 15px;">
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
                                <p class="text-muted"><?php echo htmlspecialchars(t('manage.orgs.no_access_list'), ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php else : ?>
                                <?php foreach ($display_organizations as $org) : ?>
                                    <?php
                                    // Use the organization name directly from the listOrganizations result
                                    $org_name = $org['name'] ?? 'Unknown Organization';
                                    $org_name_safe = htmlspecialchars($org_name);
                                    $org_name_url = urlencode($org_name);
                                    ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                            <div class="flex-grow-1" style="min-width: 260px;">
                                                <h5 class="mb-1"><?php echo $org_name_safe; ?></h5>
                                                <p class="mb-0">
                                                    <?php if (isset($org['mail']) && !empty($org['mail'])) : ?>
                                                        <strong><?php echo htmlspecialchars(t('manage.orgs.email_label'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($org['mail']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if (isset($org['telephoneNumber']) && !empty($org['telephoneNumber'])) : ?>
                                                        <strong><?php echo htmlspecialchars(t('manage.orgs.phone_label'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($org['telephoneNumber']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if (isset($org['facsimileTelephoneNumber']) && !empty($org['facsimileTelephoneNumber'])) : ?>
                                                        <strong><?php echo htmlspecialchars(t('manage.orgs.fax_label'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($org['facsimileTelephoneNumber']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if (isset($org['description']) && !empty($org['description'])) : ?>
                                                        <strong><?php echo htmlspecialchars(t('manage.orgs.status_label'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($org['description']); ?><br>
                                                    <?php endif; ?>
                                                    <?php
                                                    $org_dn = $org['dn'] ?? '';
                                                    $is_member = ($ldap_connection && $base_dn !== '' && $org_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $member_group_cn, $base_dn));
                                                    $is_disabled = ($ldap_connection && $base_dn !== '' && $org_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $disabled_group_cn, $base_dn));
                                                    $org_disabled = ($ldap_connection && ldap_organization_is_disabled($ldap_connection, $org_name));
                                                    ?>
                                                    <?php if ($is_member) : ?>
                                                        <span class="badge bg-primary"><?php echo htmlspecialchars(t('manage.orgs.show.badge_member'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($is_disabled) : ?>
                                                        <span class="badge bg-danger"><?php echo htmlspecialchars(t('manage.orgs.show.badge_inactive'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                    <strong><?php echo htmlspecialchars(t('manage.orgs.status_label'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <span class="badge bg-<?php echo $org_disabled ? 'danger' : 'success'; ?>">
                                                        <?php echo htmlspecialchars($org_disabled ? t('manage.common.inactive') : t('manage.common.active'), ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </p>
                                                <?php
                                                $current_users = ($ldap_connection && function_exists('ldap_org_count_users')) ? ldap_org_count_users($ldap_connection, $org_name) : 0;
                                                $limit_users = ($ldap_connection && function_exists('ldap_org_get_user_limit')) ? ldap_org_get_user_limit($ldap_connection, $org_name) : null;
                                                ?>
                                                <div class="mt-2" style="max-width: 420px;">
                                                    <?php if ($limit_users === null) : ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars(t('manage.orgs.index.user_limit_unlimited', ['current' => (string) (int) $current_users]), ENT_QUOTES, 'UTF-8'); ?></small>
                                                    <?php else : ?>
                                                        <?php
                                                        $pct = $limit_users > 0 ? ($current_users / $limit_users) : 0.0;
                                                        $pct_display = (int) round(min($pct, 1.5) * 100);
                                                        $bar_class = 'bg-success';
                                                        if ($pct >= 1.0) {
                                                            $bar_class = 'bg-danger';
                                                        } elseif ($pct >= 0.8) {
                                                            $bar_class = 'bg-warning';
                                                        }
                                                        $width = (int) max(0, min(100, round($pct * 100)));
                                                        ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars(t('manage.orgs.index.users_usage', ['current' => (string) (int) $current_users, 'limit' => (string) (int) $limit_users]), ENT_QUOTES, 'UTF-8'); ?></small>
                                                        <div class="progress" title="<?php echo (int) $current_users; ?> / <?php echo (int) $limit_users; ?> (<?php echo $pct_display; ?>%)">
                                                            <div class="progress-bar <?php echo $bar_class; ?>" role="progressbar" style="width: <?php echo $width; ?>%;" aria-valuenow="<?php echo $width; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <?php
                                            $use_uuid = ($LDAP['use_uuid_identification'] && isset($org['entryUUID']));
                                            $org_uuid_val = $use_uuid ? (string) $org['entryUUID'] : '';
                                            $can_membership = (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) && $ldap_connection && $base_dn !== '' && $org_dn !== '' && function_exists('isInStatusGroup');
                                            $can_disable = currentUserCanDisableOrganization($org_name);
                                            $can_delete = currentUserCanDeleteOrganization($org_name);
                                            ?>

                                            <div class="d-flex align-items-center justify-content-end flex-wrap gap-2" style="min-width: 260px;">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.index.aria.view_users'), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php if ($use_uuid) : ?>
                                                        <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid_val) . '/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info"><?php echo htmlspecialchars(t('manage.common.view'), ENT_QUOTES, 'UTF-8'); ?></a>
                                                        <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid_val) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary"><?php echo htmlspecialchars(t('manage.common.users'), ENT_QUOTES, 'UTF-8'); ?></a>
                                                    <?php else : ?>
                                                        <?php /* UUID-only canonical routing: do not link by name. */ ?>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($can_membership) : ?>
                                                    <div class="vr"></div>
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_membership_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php if ($is_member) : ?>
                                                            <button type="button" class="btn btn-secondary" onclick="confirmUnmemberOrganization('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.unmember'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                        <?php else : ?>
                                                            <button type="button" class="btn btn-secondary" onclick="confirmMemberOrganization('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.member'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($can_disable) : ?>
                                                    <div class="vr"></div>
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_status_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php if ($org_disabled) : ?>
                                                            <button type="button" class="btn btn-success" onclick="confirmEnableOrganization('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.activate'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                        <?php else : ?>
                                                            <button type="button" class="btn btn-warning" onclick="confirmDisableOrganization('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.deactivate'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($can_delete) : ?>
                                                    <div class="vr"></div>
                                                    <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_delete_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button type="button" class="btn btn-danger" onclick="confirmDelete('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val); ?>')"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
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
    t('manage.orgs.show.modal.delete_title'),
    t('manage.orgs.show.modal.delete_body'),
    [
        ['name' => 'action', 'value' => 'delete_organization'],
        ['name' => 'org_name', 'id' => 'deleteOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'deleteOrgUuidInput'],
    ],
    t('manage.orgs.show.modal.delete_submit'),
    'btn-danger'
);
render_confirm_modal(
    'disableModal',
    t('manage.orgs.show.modal.deactivate_title'),
    t('manage.orgs.show.modal.deactivate_body'),
    [
        ['name' => 'action', 'value' => 'disable_organization'],
        ['name' => 'org_name', 'id' => 'disableOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'disableOrgUuidInput'],
    ],
    t('manage.orgs.show.modal.deactivate_submit'),
    'btn-warning'
);
render_confirm_modal(
    'enableModal',
    t('manage.orgs.show.modal.activate_title'),
    t('manage.orgs.show.modal.activate_body'),
    [
        ['name' => 'action', 'value' => 'enable_organization'],
        ['name' => 'org_name', 'id' => 'enableOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'enableOrgUuidInput'],
    ],
    t('manage.orgs.show.modal.activate_submit'),
    'btn-success'
);

render_confirm_modal(
    'memberModal',
    t('manage.orgs.show.modal.member_title'),
    t('manage.orgs.show.modal.member_body'),
    [
        ['name' => 'action', 'value' => 'member_organization'],
        ['name' => 'org_name', 'id' => 'memberOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'memberOrgUuidInput'],
    ],
    t('manage.orgs.show.modal.member_submit'),
    'btn-secondary'
);

render_confirm_modal(
    'unmemberModal',
    t('manage.orgs.show.modal.unmember_title'),
    t('manage.orgs.show.modal.unmember_body'),
    [
        ['name' => 'action', 'value' => 'unmember_organization'],
        ['name' => 'org_name', 'id' => 'unmemberOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'unmemberOrgUuidInput'],
    ],
    t('manage.orgs.show.modal.unmember_submit'),
    'btn-secondary'
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

    function confirmDisableOrganization(orgName, orgUuid) {
        confirmAction('disableModal', { disableOrgName: orgName, disableOrgNameInput: orgName, disableOrgUuidInput: orgUuid || '' });
    }
    function confirmEnableOrganization(orgName, orgUuid) {
        confirmAction('enableModal', { enableOrgName: orgName, enableOrgNameInput: orgName, enableOrgUuidInput: orgUuid || '' });
    }
    function confirmDelete(orgName, orgUuid) {
        confirmAction('deleteModal', { deleteOrgName: orgName, deleteOrgNameInput: orgName, deleteOrgUuidInput: orgUuid || '' });
    }
    function confirmMemberOrganization(orgName, orgUuid) {
        confirmAction('memberModal', { memberOrgName: orgName, memberOrgNameInput: orgName, memberOrgUuidInput: orgUuid || '' });
    }
    function confirmUnmemberOrganization(orgName, orgUuid) {
        confirmAction('unmemberModal', { unmemberOrgName: orgName, unmemberOrgNameInput: orgName, unmemberOrgUuidInput: orgUuid || '' });
    }
</script>

<?php
// Clean up LDAP connection
if (isset($ldap_connection) && $ldap_connection) {
    ldap_close($ldap_connection);
}

render_footer();
?> 
