<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage(['ldap', 'organization']);

// Ensure CSRF token is generated early
getCsrfToken();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrfToken()) {
        $message = t('manage.common.msg.security_validation_failed');
        $message_type = 'danger';
    } else {
    $ldap_connection = lum_ldap_data_connection();
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
                    if ($base_dn !== '' && function_exists('add_to_status_group') && add_to_status_group($ldap_connection, $org_dn, $member_group_cn_post, $base_dn)) {
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
                    if ($base_dn !== '' && function_exists('remove_from_status_group') && remove_from_status_group($ldap_connection, $org_dn, $member_group_cn_post, $base_dn)) {
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
                        $message = t('manage.orgs.show.msg.activate_ok', ['org' => $org_name]);
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

        lum_close_ldap_if_not_manage($ldap_connection);
    }
    }
}

setPageAccess(["admin", "maintainer", "org_admin"]);

$is_global_admin = currentUserIsGlobalAdmin();
$is_maintainer   = currentUserIsMaintainer();
$user_organizations = [];

if (!$is_global_admin && !$is_maintainer) {
    $all_orgs = listOrganizations();
    foreach ($all_orgs as $org) {
        if (currentUserIsOrgManager($org['name'])) {
            $user_organizations[] = $org['name'];
        }
    }
}

renderHeader(t('manage.orgs.page_title', ['org' => $ORGANISATION_NAME ?? 'System']));
render_submenu();
renderFlash();

// --- Filter and sort parameters (GET) ---
$valid_sorts   = ['name_asc', 'name_desc', 'change_desc', 'change_asc'];
$valid_statuses = ['', 'active', 'inactive'];
$valid_members  = ['', 'member', 'nonmember'];

$sort_input          = (string) ($_GET['sort'] ?? '');
$filter_status_input = (string) ($_GET['filter_status'] ?? '');
$filter_member_input = (string) ($_GET['filter_member'] ?? '');

$sort_param          = in_array($sort_input, $valid_sorts, true) ? $sort_input : 'name_asc';
$filter_status_param = in_array($filter_status_input, $valid_statuses, true) ? $filter_status_input : '';
$filter_member_param = in_array($filter_member_input, $valid_members, true) ? $filter_member_input : '';

$has_active_filters = $filter_status_param !== '' || $filter_member_param !== '' || $sort_param !== 'name_asc';

// Get all organizations
$organizations = listOrganizations();
if (!is_array($organizations)) {
    $organizations = [];
}

// Establish LDAP connection for status checks
$ldap_connection = lum_ldap_data_connection();
if (!$ldap_connection) {
    $message = t('manage.orgs.ldap_conn_failed');
    $message_type = 'danger';
}
$member_group_cn  = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
$disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';
$base_dn = $LDAP['base_dn'] ?? '';

// Filter organizations based on user permissions
$display_organizations = $organizations;
if (!$is_global_admin && !$is_maintainer) {
    $display_organizations = array_filter($organizations, static function (array $org) use ($user_organizations): bool {
        return in_array($org['name'] ?? 'Unknown', $user_organizations, true);
    });
}

// Pre-compute status flags and last-change data for all displayed orgs
$org_data_list = [];
foreach ($display_organizations as $org) {
    $org_dn     = $org['dn'] ?? '';
    $org_name_v = $org['name'] ?? 'Unknown';
    $is_member   = ($ldap_connection && $base_dn !== '' && $org_dn !== ''
        && function_exists('is_in_status_group')
        && is_in_status_group($ldap_connection, $org_dn, $member_group_cn, $base_dn));
    $is_disabled = ($ldap_connection && $base_dn !== '' && $org_dn !== ''
        && function_exists('is_in_status_group')
        && is_in_status_group($ldap_connection, $org_dn, $disabled_group_cn, $base_dn));
    $org_disabled = ($ldap_connection && ldap_organization_is_disabled($ldap_connection, $org_name_v));

    $last_change = function_exists('get_org_last_change_from_op_attrs')
        ? get_org_last_change_from_op_attrs(
            (string) ($org['modifyTimestamp'] ?? ''),
            (string) ($org['modifiersName'] ?? '')
        )
        : null;

    $org_data_list[] = array_merge($org, [
        'is_member'   => $is_member,
        'is_disabled' => $is_disabled,
        'org_disabled' => $org_disabled,
        'last_change' => $last_change,
    ]);
}

// Apply status filter
if ($filter_status_param === 'active') {
    $org_data_list = array_values(array_filter($org_data_list, static fn(array $o): bool => !$o['org_disabled']));
} elseif ($filter_status_param === 'inactive') {
    $org_data_list = array_values(array_filter($org_data_list, static fn(array $o): bool => (bool) $o['org_disabled']));
}

// Apply membership filter
if ($filter_member_param === 'member') {
    $org_data_list = array_values(array_filter($org_data_list, static fn(array $o): bool => (bool) $o['is_member']));
} elseif ($filter_member_param === 'nonmember') {
    $org_data_list = array_values(array_filter($org_data_list, static fn(array $o): bool => !$o['is_member']));
}

// Apply sort
switch ($sort_param) {
    case 'name_desc':
        usort($org_data_list, static fn(array $a, array $b): int => strcmp(
            strtolower((string) ($b['name'] ?? '')),
            strtolower((string) ($a['name'] ?? ''))
        ));
        break;
    case 'change_desc':
        usort($org_data_list, static function (array $a, array $b): int {
            $ta = (int) (($a['last_change']['timestamp'] ?? null) ?? 0);
            $tb = (int) (($b['last_change']['timestamp'] ?? null) ?? 0);
            return $tb <=> $ta;
        });
        break;
    case 'change_asc':
        usort($org_data_list, static function (array $a, array $b): int {
            $ta = (int) (($a['last_change']['timestamp'] ?? null) ?? 0);
            $tb = (int) (($b['last_change']['timestamp'] ?? null) ?? 0);
            return $ta <=> $tb;
        });
        break;
    case 'name_asc':
    default:
        usort($org_data_list, static fn(array $a, array $b): int => strcmp(
            strtolower((string) ($a['name'] ?? '')),
            strtolower((string) ($b['name'] ?? ''))
        ));
        break;
}

$total_org_count    = count($display_organizations);
$filtered_org_count = count($org_data_list);

// Build base URL without filter/sort params for reset link
$base_list_url = htmlspecialchars(getBaseUrl() . 'manage/organizations/', ENT_QUOTES, 'UTF-8');

/**
 * Build a URL for the org list with updated filter/sort params, preserving all others.
 *
 * @param array<string, string> $overrides Key-value pairs to override in the current query
 */
function build_filter_url(array $overrides): string
{
    $params = [
        'sort'          => $_GET['sort'] ?? 'name_asc',
        'filter_status' => $_GET['filter_status'] ?? '',
        'filter_member' => $_GET['filter_member'] ?? '',
    ];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    // Remove empty params to keep URLs clean
    $params = array_filter($params, static fn(string $v): bool => $v !== '');
    $qs = http_build_query($params);
    return htmlspecialchars(
        (getBaseUrl() . 'manage/organizations/' . ($qs !== '' ? '?' . $qs : '')),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Format the organization list count label for display.
 */
function format_org_list_count_label(int $shown, int $total): string
{
    if ($shown === $total) {
        return $total === 1
            ? t('manage.orgs.index.org_count_one')
            : t('manage.orgs.index.org_count_many', ['count' => (string) $total]);
    }

    return t('manage.orgs.index.showing_orgs_summary', [
        'shown' => (string) $shown,
        'total' => (string) $total,
    ]);
}

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
                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/new/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success">
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
                    <!-- Filter and sort bar -->
                    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
                        <!-- Text search (client-side) -->
                        <input class="form-control" id="org_search_input" type="text"
                               placeholder="<?php echo htmlspecialchars(t('manage.orgs.search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>"
                               style="max-width: 260px;">

                        <!-- Status filter -->
                        <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.index.filter_status_label'), ENT_QUOTES, 'UTF-8'); ?>">
                            <a href="<?php echo build_filter_url(['filter_status' => '']); ?>"
                               class="btn btn-outline-secondary<?php echo $filter_status_param === '' ? ' active' : ''; ?>">
                                <?php echo htmlspecialchars(t('manage.orgs.index.filter_all'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <a href="<?php echo build_filter_url(['filter_status' => 'active']); ?>"
                               class="btn btn-outline-success<?php echo $filter_status_param === 'active' ? ' active' : ''; ?>">
                                <?php echo htmlspecialchars(t('manage.common.active'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <a href="<?php echo build_filter_url(['filter_status' => 'inactive']); ?>"
                               class="btn btn-outline-danger<?php echo $filter_status_param === 'inactive' ? ' active' : ''; ?>">
                                <?php echo htmlspecialchars(t('manage.common.inactive'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </div>

                        <!-- Member filter -->
                        <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.index.filter_member_label'), ENT_QUOTES, 'UTF-8'); ?>">
                            <a href="<?php echo build_filter_url(['filter_member' => '']); ?>"
                               class="btn btn-outline-secondary<?php echo $filter_member_param === '' ? ' active' : ''; ?>">
                                <?php echo htmlspecialchars(t('manage.orgs.index.filter_all'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <a href="<?php echo build_filter_url(['filter_member' => 'member']); ?>"
                               class="btn btn-outline-primary<?php echo $filter_member_param === 'member' ? ' active' : ''; ?>">
                                <?php echo htmlspecialchars(t('manage.orgs.show.badge_member'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <a href="<?php echo build_filter_url(['filter_member' => 'nonmember']); ?>"
                               class="btn btn-outline-secondary<?php echo $filter_member_param === 'nonmember' ? ' active' : ''; ?>">
                                <?php echo htmlspecialchars(t('manage.orgs.index.filter_non_member'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </div>

                        <!-- Sort dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-sort-down"></i>
                                <?php
                                $sort_labels = [
                                    'name_asc'    => t('manage.orgs.index.sort_name_asc'),
                                    'name_desc'   => t('manage.orgs.index.sort_name_desc'),
                                    'change_desc' => t('manage.orgs.index.sort_change_desc'),
                                    'change_asc'  => t('manage.orgs.index.sort_change_asc'),
                                ];
                                echo htmlspecialchars($sort_labels[$sort_param], ENT_QUOTES, 'UTF-8');
                                ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php foreach ($sort_labels as $sort_key => $sort_label) : ?>
                                <li>
                                    <a class="dropdown-item<?php echo $sort_param === $sort_key ? ' active' : ''; ?>"
                                       href="<?php echo build_filter_url(['sort' => $sort_key]); ?>">
                                        <?php echo htmlspecialchars($sort_label, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <?php if ($has_active_filters) : ?>
                        <a href="<?php echo $base_list_url; ?>" id="org_reset_filters" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-x-circle"></i> <?php echo htmlspecialchars(t('manage.orgs.index.reset_filters'), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <?php endif; ?>

                        <div id="org_list_count"
                             class="ms-auto text-body-secondary small"
                             role="status"
                             aria-live="polite"
                             data-total="<?php echo (int) $total_org_count; ?>"
                             data-shown="<?php echo (int) $filtered_org_count; ?>">
                            <?php echo htmlspecialchars(format_org_list_count_label($filtered_org_count, $total_org_count), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>

                    <div class="list-group" id="org_list">
                        <?php if (empty($org_data_list)) : ?>
                            <p class="text-muted"><?php echo htmlspecialchars(
                                $total_org_count > 0
                                    ? t('manage.orgs.index.no_filter_match')
                                    : t('manage.orgs.no_access_list'),
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?></p>
                        <?php else : ?>
                            <?php foreach ($org_data_list as $org) : ?>
                                <?php
                                $org_name        = $org['name'] ?? 'Unknown Organization';
                                $org_name_safe   = htmlspecialchars($org_name, ENT_QUOTES, 'UTF-8');
                                $is_member       = (bool) $org['is_member'];
                                $org_disabled    = (bool) $org['org_disabled'];
                                $is_disabled_grp = (bool) $org['is_disabled'];
                                $last_change     = $org['last_change'];
                                ?>
                                <div class="list-group-item" data-org-name="<?php echo $org_name_safe; ?>">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                        <div class="flex-grow-1" style="min-width: 260px;">
                                            <h5 class="mb-1"><?php echo $org_name_safe; ?></h5>
                                            <p class="mb-0">
                                                <?php if (!empty($org['mail'])) : ?>
                                                    <strong><?php echo htmlspecialchars(t('manage.orgs.email_label'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($org['mail']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($org['telephoneNumber'])) : ?>
                                                    <strong><?php echo htmlspecialchars(t('manage.orgs.phone_label'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($org['telephoneNumber']); ?><br>
                                                <?php endif; ?>
                                                <?php if ($is_member) : ?>
                                                    <span class="badge bg-primary"><?php echo htmlspecialchars(t('manage.orgs.show.badge_member'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                                <?php if ($is_disabled_grp) : ?>
                                                    <span class="badge bg-danger"><?php echo htmlspecialchars(t('manage.orgs.show.badge_inactive'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars(t('manage.orgs.status_label'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                                <span class="badge bg-<?php echo $org_disabled ? 'danger' : 'success'; ?>">
                                                    <?php echo htmlspecialchars($org_disabled ? t('manage.common.inactive') : t('manage.common.active'), ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                                <?php if ($last_change !== null && $last_change['timestamp'] > 0) : ?>
                                                    <br><small class="text-muted">
                                                        <i class="bi bi-clock-history"></i>
                                                        <?php echo htmlspecialchars(t('manage.orgs.index.last_modified'), ENT_QUOTES, 'UTF-8'); ?>:
                                                        <span title="<?php echo htmlspecialchars(date('Y-m-d H:i', $last_change['timestamp']), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php echo htmlspecialchars(format_relative_time($last_change['timestamp']), ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                        <?php if (!empty($last_change['actor_display'])) : ?>
                                                            <?php echo htmlspecialchars(t('manage.orgs.index.by_actor'), ENT_QUOTES, 'UTF-8'); ?>
                                                            <strong><?php echo htmlspecialchars($last_change['actor_display'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </p>
                                            <?php
                                            $current_users = ($ldap_connection && function_exists('ldap_org_count_users')) ? ldap_org_count_users($ldap_connection, $org_name) : 0;
                                            $limit_users   = ($ldap_connection && function_exists('ldap_org_get_user_limit')) ? ldap_org_get_user_limit($ldap_connection, $org_name) : null;
                                            ?>
                                            <div class="mt-2" style="max-width: 420px;">
                                                <?php if ($limit_users === null) : ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars(t('manage.orgs.index.user_limit_unlimited', ['current' => (string) (int) $current_users]), ENT_QUOTES, 'UTF-8'); ?></small>
                                                <?php else : ?>
                                                    <?php
                                                    $pct         = $limit_users > 0 ? ($current_users / $limit_users) : 0.0;
                                                    $pct_display = (int) round(min($pct, 1.5) * 100);
                                                    $bar_class   = 'bg-success';
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
                                        $use_uuid        = ($LDAP['use_uuid_identification'] && isset($org['entryUUID']));
                                        $org_uuid_val    = $use_uuid ? (string) $org['entryUUID'] : '';
                                        $org_dn          = $org['dn'] ?? '';
                                        $can_membership  = (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) && $ldap_connection && $base_dn !== '' && $org_dn !== '' && function_exists('is_in_status_group');
                                        $can_disable     = currentUserCanDisableOrganization($org_name);
                                        $can_delete      = currentUserCanDeleteOrganization($org_name);
                                        ?>

                                        <div class="d-flex align-items-center justify-content-end flex-wrap gap-2" style="min-width: 260px;">
                                            <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.index.aria.view_users'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php if ($use_uuid) : ?>
                                                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid_val) . '/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info"><?php echo htmlspecialchars(t('manage.common.view'), ENT_QUOTES, 'UTF-8'); ?></a>
                                                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid_val) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary"><?php echo htmlspecialchars(t('manage.common.users'), ENT_QUOTES, 'UTF-8'); ?></a>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($can_membership) : ?>
                                                <div class="vr"></div>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_membership_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php if ($is_member) : ?>
                                                        <button type="button" class="btn btn-secondary" onclick="confirmUnmemberOrganization('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val, ENT_QUOTES, 'UTF-8'); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.unmember'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                    <?php else : ?>
                                                        <button type="button" class="btn btn-secondary" onclick="confirmMemberOrganization('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val, ENT_QUOTES, 'UTF-8'); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.member'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($can_disable) : ?>
                                                <div class="vr"></div>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_status_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php if ($org_disabled) : ?>
                                                        <button type="button" class="btn btn-success" onclick="confirmEnableOrganization('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val, ENT_QUOTES, 'UTF-8'); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.activate'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                    <?php else : ?>
                                                        <button type="button" class="btn btn-warning" onclick="confirmDisableOrganization('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val, ENT_QUOTES, 'UTF-8'); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.deactivate'), ENT_QUOTES, 'UTF-8'); ?></button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($can_delete) : ?>
                                                <div class="vr"></div>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_delete_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="button" class="btn btn-danger" onclick="confirmDelete('<?php echo $org_name_safe; ?>', '<?php echo htmlspecialchars($org_uuid_val, ENT_QUOTES, 'UTF-8'); ?>')"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
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

<!-- Confirmation modals -->
<?php
renderConfirmModal(
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
renderConfirmModal(
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
renderConfirmModal(
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
renderConfirmModal(
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
renderConfirmModal(
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

<script src="<?php print getAssetBase(); ?>js/modals.js"></script>
<script>
    window.orgListCountI18n = {
        one: <?php echo json_encode(t('manage.orgs.index.org_count_one'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        many: <?php echo json_encode(t('manage.orgs.index.org_count_many'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        summary: <?php echo json_encode(t('manage.orgs.index.showing_orgs_summary'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    };

    function updateOrgListCount() {
        const countEl = document.getElementById('org_list_count');
        const searchInput = document.getElementById('org_search_input');
        const orgList = document.getElementById('org_list');
        const i18n = window.orgListCountI18n;

        if (!countEl || !orgList || !i18n) {
            return;
        }

        const total = parseInt(countEl.dataset.total, 10) || 0;
        const serverShown = parseInt(countEl.dataset.shown, 10) || 0;
        const searchTerm = searchInput ? searchInput.value.trim() : '';
        let shown = serverShown;

        if (searchTerm) {
            shown = 0;
            orgList.querySelectorAll('.list-group-item').forEach(function(item) {
                if (item.style.display !== 'none') {
                    shown++;
                }
            });
        }

        if (shown === total && !searchTerm) {
            countEl.textContent = total === 1
                ? i18n.one
                : i18n.many.replace(':count', String(total));
        } else {
            countEl.textContent = i18n.summary
                .replace(':shown', String(shown))
                .replace(':total', String(total));
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('org_search_input');
        const orgList = document.getElementById('org_list');
        const resetLink = document.getElementById('org_reset_filters');

        if (searchInput && orgList) {
            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                orgList.querySelectorAll('.list-group-item').forEach(function(item) {
                    const text = (item.dataset.orgName || item.textContent).toLowerCase();
                    item.style.display = text.includes(term) ? '' : 'none';
                });
                updateOrgListCount();
            });
        }

        if (resetLink && searchInput) {
            resetLink.addEventListener('click', function() {
                searchInput.value = '';
            });
        }

        updateOrgListCount();

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
if (isset($ldap_connection) && $ldap_connection) {
    lum_close_ldap_if_not_manage($ldap_connection);
}

renderFooter();
?>
