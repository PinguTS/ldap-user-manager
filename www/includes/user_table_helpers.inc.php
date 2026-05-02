<?php

declare(strict_types=1);

if (!function_exists('get_ldap_attribute')) {
    include_once __DIR__ . '/user_functions.inc.php';
}

/**
 * Shared HTML for org user tables (status/manager toggles, action cells) and system user status badges.
 */

/**
 * Status column on organization user list (POST enable/disable by permission, org-wide disable badge).
 *
 * @param resource|\LDAP\Connection $ldapConnection
 */
function renderOrgUsersTableStatusCell($ldapConnection, string $userDn, bool $isOrgDisabled, string $userIdentifier): void
{
    $is_individually_disabled = ldap_user_is_individually_disabled($ldapConnection, $userDn);
    if ($isOrgDisabled) {
        echo '<span class="badge bg-danger" title="' . htmlspecialchars(t('manage.org_users.deactivate_reason.org'), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars(t('manage.common.inactive'), ENT_QUOTES, 'UTF-8') . '</span>';

        return;
    }
    $can_enable_user = currentUserCanEnableUser($userIdentifier);
    $can_disable_user = currentUserCanDisableUser($userIdentifier);
    $status_form_target = $is_individually_disabled ? 'enable_user' : 'disable_user';
    $status_title = $is_individually_disabled ? t('manage.common.activate') : t('manage.common.deactivate');
    $can_toggle_status = $is_individually_disabled ? $can_enable_user : $can_disable_user;
    ?>
                            <form method="post" class="d-inline-flex align-items-center justify-content-center m-0">
                                <?= csrfTokenField() ?>
                                <input type="hidden" name="<?= htmlspecialchars($status_form_target, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($userIdentifier, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="form-check form-switch m-0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        <?= !$is_individually_disabled ? 'checked' : '' ?>
                                        <?= !$can_toggle_status ? 'disabled' : '' ?>
                                        onchange="this.form.submit()"
                                        title="<?= htmlspecialchars($status_title, ENT_QUOTES, 'UTF-8') ?>"
                                        aria-label="<?= htmlspecialchars(t('manage.common.status'), ENT_QUOTES, 'UTF-8') ?>"
                                    >
                                </div>
                            </form>
    <?php
}

/**
 * Status column on organization show "recent users" (POST toggle_recent_user_active).
 *
 * @param resource|\LDAP\Connection $ldapConnection
 */
function renderOrgShowRecentStatusCell($ldapConnection, string $userDn, string $userIdentifier, bool $orgDisabled): void
{
    // Match renderOrgUsersTableStatusCell: "disabled" here means org disabledAccounts group (not ldap_user_is_disabled, which is pwd lock + org-wide only).
    $isIndividuallyDisabled = ldap_user_is_individually_disabled($ldapConnection, $userDn);
    if ($orgDisabled) {
        echo '<span class="badge bg-danger">' . htmlspecialchars(t('manage.common.inactive'), ENT_QUOTES, 'UTF-8') . '</span>';

        return;
    }
    ?>
                                <form method="post" class="d-inline-flex align-items-center justify-content-center">
                                    <?= csrfTokenField() ?>
                                    <input type="hidden" name="action" value="toggle_recent_user_active">
                                    <input type="hidden" name="user_identifier" value="<?php echo htmlspecialchars($userIdentifier, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="target_state" value="<?php echo $isIndividuallyDisabled ? 'enable' : 'disable'; ?>">
                                    <div class="form-check form-switch m-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               <?= !$isIndividuallyDisabled ? 'checked' : '' ?>
                                               onchange="this.form.submit()"
                                               title="<?php echo htmlspecialchars($isIndividuallyDisabled ? t('manage.common.activate') : t('manage.common.deactivate'), ENT_QUOTES, 'UTF-8'); ?>"
                                               aria-label="<?php echo htmlspecialchars(t('manage.common.status'), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </form>
    <?php
}

/**
 * Manager toggle (POST) for recent users on org show page.
 */
function renderOrgShowRecentManagerToggle(string $userIdentifier, bool $isManager, bool $isLastManager): void
{
    ?>
                            <form method="post" class="d-inline-flex align-items-center justify-content-center">
                                <?= csrfTokenField() ?>
                                <input type="hidden" name="action" value="toggle_recent_user_manager">
                                <input type="hidden" name="user_identifier" value="<?php echo htmlspecialchars($userIdentifier, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           <?= $isManager ? 'checked' : '' ?>
                                           onchange="this.form.submit()"
                                           title="<?php echo htmlspecialchars($isLastManager ? t('manage.org_users.msg.last_manager_toggle_hint') : t('manage.common.toggle_manager_title'), ENT_QUOTES, 'UTF-8'); ?>"
                                           aria-label="<?php echo htmlspecialchars(t('manage.common.manager'), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </form>
    <?php
}

/**
 * Manager toggle (GET) for full org user list.
 */
function renderOrgUsersPageManagerToggle(
    string $userIdentifier,
    bool $isManager,
    bool $isLastManager,
    ?string $orgUuid,
    string $orgName
): void {
    ?>
                        <form method="post" class="d-inline-flex align-items-center justify-content-center">
                            <?= csrfTokenField() ?>
                            <input type="hidden" name="<?= $orgUuid ? 'uuid' : 'org' ?>" value="<?= htmlspecialchars($orgUuid ?: $orgName, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="uid" value="<?= htmlspecialchars($userIdentifier, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="toggle_manager" value="1">
                            <div class="form-check form-switch m-0">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    id="org-manager-switch-<?= htmlspecialchars($userIdentifier, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $isManager ? 'checked' : '' ?>
                                    onchange="this.form.submit()"
                                    title="<?= htmlspecialchars($isLastManager ? t('manage.org_users.msg.last_manager_toggle_hint') : t('manage.common.toggle_manager_title'), ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="<?= htmlspecialchars(t('manage.common.manager'), ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                        </form>
    <?php
}

/**
 * Action buttons for recent users on org show (edit link + delete).
 */
function renderOrgShowRecentUserActions(string $orgUuid, array $user, bool $isManager): void
{
    ?>
                                <div class="d-inline-flex align-items-center flex-wrap gap-1">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.common.user_actions_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php if ($orgUuid !== '' && isset($user['entryUUID'])) : ?>
                                            <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($orgUuid) . '/users/?edit_user=' . urlencode((string) $user['entryUUID']), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary btn-sm"><?php echo htmlspecialchars(t('manage.common.edit'), ENT_QUOTES, 'UTF-8'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="vr"></div>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php if ($isManager) : ?>
                                            <button type="button" class="btn btn-danger btn-sm" disabled title="<?php echo htmlspecialchars(t('manage.org_users.msg.cannot_delete_org_manager'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                                        <?php else : ?>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteUser('<?php echo htmlspecialchars((string) ($user['entryUUID'] ?? $user['mail'] ?? $user['cn']), ENT_QUOTES, 'UTF-8'); ?>')"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
    <?php
}

/**
 * Action buttons for org users index table (edit, reset password, delete).
 */
function renderOrgUsersPageActionCell(
    string $userIdentifier,
    array $user,
    bool $isManager,
    ?string $orgUuid,
    string $orgName
): void {
    $orgQuery = $orgUuid ? 'uuid=' . urlencode($orgUuid) : 'org=' . urlencode($orgName);
    ?>
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.common.user_actions_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                <a href="?<?= $orgQuery ?>&edit_user=<?= urlencode($userIdentifier) ?>" class="btn btn-secondary btn-sm"><?php echo htmlspecialchars(t('manage.common.edit'), ENT_QUOTES, 'UTF-8'); ?></a>
                                <a href="?<?= $orgQuery ?>&reset_user=<?= urlencode($userIdentifier) ?>" class="btn btn-primary btn-sm"><?php echo htmlspecialchars(t('manage.common.new_password'), ENT_QUOTES, 'UTF-8'); ?></a>
                            </div>
                            <div class="ms-2 ps-2 border-start">
                                <?php if ($isManager) : ?>
                                    <button type="button" class="btn btn-danger btn-sm" disabled title="<?php echo htmlspecialchars(t('manage.org_users.msg.cannot_delete_org_manager'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                                <?php else : ?>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteUser('<?= htmlspecialchars($userIdentifier, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(get_ldap_attribute($user, 'uid'), ENT_QUOTES, 'UTF-8') ?>')"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
    <?php
}

/**
 * System users list: inactive/active/unknown badge with deactivate reason tooltip.
 *
 * @param resource|\LDAP\Connection $ldapConnection
 */
function renderSystemUserStatusBadge($ldapConnection, ?string $userDn): void
{
    if (!$userDn) {
        echo '<span class="badge bg-secondary">' . htmlspecialchars(t('manage.common.unknown'), ENT_QUOTES, 'UTF-8') . '</span>';

        return;
    }
    $is_disabled = ldap_user_is_disabled($ldapConnection, $userDn);
    $is_individually_disabled = ldap_user_is_individually_disabled($ldapConnection, $userDn);
    $user_org_name = get_organization_from_user_dn($userDn);
    $is_user_org_disabled = ($user_org_name !== false && $user_org_name !== '' && ldap_organization_is_disabled($ldapConnection, $user_org_name));
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
        echo '<span class="badge bg-danger" title="' . htmlspecialchars($badge_title, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('manage.common.inactive'), ENT_QUOTES, 'UTF-8') . '</span>';
    } else {
        echo '<span class="badge bg-success">' . htmlspecialchars(t('manage.common.active'), ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
