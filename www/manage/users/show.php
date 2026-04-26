<?php

declare(strict_types=1);

// Define LDAP escape constants for PHP < 7.3 compatibility
if (!defined('LDAP_ESCAPE_FILTER')) {
    define('LDAP_ESCAPE_FILTER', 0);
}
if (!defined('LDAP_ESCAPE_DN')) {
    define('LDAP_ESCAPE_DN', 0);
}

set_include_path(".:" . __DIR__ . "/../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage(['ldap', 'user', 'password_reset', 'mail']);
require_once "organization_functions.inc.php";

// Ensure CSRF token is generated early
getCsrfToken();

setPageAccess(["admin", "user"]); // Allow both admin and user roles

$orgName = (string) ($ORGANISATION_NAME ?? 'System');
renderHeader(t('manage.users.profile.page_title', ['org' => $orgName]));
render_submenu();

$invalid_password = false;
$mismatched_passwords = false;
$invalid_username = false;
$weak_password = false;
$to_update = array();

if ($SMTP['host'] != "") {
    $can_send_email = true;
} else {
    $can_send_email = false;
}

$LDAP['default_attribute_map']["mail"]  = array("label" => t('manage.common.email'), "onkeyup" => "check_if_we_should_enable_sending_email();");

$attribute_map = $LDAP['default_attribute_map'];
if (isset($LDAP['account_additional_attributes'])) {
    $attribute_map = ldap_complete_attribute_map($attribute_map, $LDAP['account_additional_attributes']);
}
if (! array_key_exists($LDAP['account_attribute'], $attribute_map)) {
    $attribute_r = array_merge($attribute_map, array($LDAP['account_attribute'] => array("label" => t('manage.common.account_id'))));
}

// Ensure common attributes used in the profile form are included in updates
if (!array_key_exists('telephonenumber', $attribute_map)) {
    $attribute_map['telephonenumber'] = ['label' => t('manage.users.phone_number')];
}

// Check if user parameter is provided (support both UUID and legacy account_identifier)
$account_identifier = null;
$user_uuid = null;

if (isset($_GET['uuid']) && $_GET['uuid'] !== '') {
    $user_uuid = trim((string) $_GET['uuid']);
} elseif (isset($_POST['uuid']) && $_POST['uuid'] !== '') {
    $user_uuid = trim((string) $_POST['uuid']);
}

if ($user_uuid !== null && $user_uuid !== '') {
    // UUID-based lookup
    if (!is_valid_uuid($user_uuid)) {
        renderAlertBanner(t('manage.users.msg.invalid_uuid'), "warning");
        renderFooter();
        exit(0);
    }

    // Get user by UUID
    $ldap_connection = lum_ldap_data_connection();
    if ($ldap_connection === false) {
        renderAlertBanner(t('manage.orgs.msg.ldap_fail'), 'danger');
        renderFooter();
        exit(0);
    }
    $user_by_uuid = ldap_get_user_by_uuid($ldap_connection, $user_uuid);
    lum_close_ldap_if_not_manage($ldap_connection);

    if (!$user_by_uuid) {
        renderAlertBanner(t('manage.users.msg.user_not_found'), "warning");
        error_log("show_user.php: UUID lookup failed for UUID: $user_uuid");
        renderFooter();
        exit(0);
    }

    // Use the primary identifier from the user entry
    $account_identifier = $user_by_uuid[$LDAP['account_attribute']][0] ?? $user_by_uuid['mail'][0] ?? $user_by_uuid['uid'][0];
} elseif (isset($_POST['account_identifier']) || isset($_GET['account_identifier'])) {
    $user_uuid = null;
    // Legacy account_identifier lookup
    $account_identifier = (isset($_POST['account_identifier']) ? $_POST['account_identifier'] : $_GET['account_identifier']);
    $account_identifier = urldecode($account_identifier);
} else {
    renderAlertBanner(t('manage.users.msg.identifier_required'), "warning");
    renderFooter();
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
}

$ldap_connection = lum_ldap_data_connection();
if ($ldap_connection === false) {
    renderAlertBanner(t('manage.orgs.msg.ldap_fail'), 'danger');
    renderFooter();
    exit(0);
}

// Search for user across both organizations and system users
if ($user_uuid !== null && $user_uuid !== '') {
    // UUID-based lookup - we already have the user data
    // Convert single user entry to expected format
    $user = [];
    $user[0] = $user_by_uuid;
    $user['count'] = 1;

    // Determine user location based on DN
    if (strpos($user_by_uuid['dn'], $LDAP['org_dn']) !== false) {
        $user_location = 'organization';
    } else {
        $user_location = 'system';
    }
} else {
    // Legacy account_identifier-based lookup
    $ldap_search_query = "({$LDAP['account_attribute']}=" . ldap_escape($account_identifier, "", LDAP_ESCAPE_FILTER) . ")";

    // First try to find in organizations
    $ldap_search = ldap_search($ldap_connection, $LDAP['org_dn'], $ldap_search_query);
    $user = null;
    $user_location = '';

    if ($ldap_search && ldap_count_entries($ldap_connection, $ldap_search) > 0) {
        $user = ldap_get_entries($ldap_connection, $ldap_search);
        $user_location = 'organization';
    } else {
        // Try system users
        $ldap_search = ldap_search($ldap_connection, $LDAP['people_dn'], $ldap_search_query);
        if ($ldap_search && ldap_count_entries($ldap_connection, $ldap_search) > 0) {
            $user = ldap_get_entries($ldap_connection, $ldap_search);
            $user_location = 'system';
        }
    }
}

if (!$user || $user['count'] == 0) {
    renderAlertBanner(t('manage.users.msg.user_not_found'), "warning");
    renderFooter();
    exit(0);
}

$userRow = $user[0];
if (!is_array($userRow)) {
    renderAlertBanner(t('manage.users.msg.user_not_found'), 'warning');
    renderFooter();
    exit(0);
}
$user_data = $userRow;

// Check if current user can edit this profile
$can_edit = false;
if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) {
    $can_edit = true;
} elseif (currentUserIsOrgAdmin()) {
    // Check if user belongs to current user's organization
    $user_org = $user_data['o'][0] ?? '';
    if ($user_org && currentUserIsOrgManager($user_org)) {
        $can_edit = true;
    }
} elseif ($VALIDATED && $USER_ID === $account_identifier) {
    // User can edit their own profile
    $can_edit = true;
}

global $EMAIL_SENDING_ENABLED;
$privileged_password_reset_editor = currentUserIsGlobalAdmin() || currentUserIsMaintainer() || currentUserIsOrgAdmin();
$can_offer_password_reset_email = $can_edit
    && $privileged_password_reset_editor
    && (($EMAIL_SENDING_ENABLED ?? false) === true)
    && is_password_reset_link_enabled();

// Admin / maintainer / org manager: email password reset link (separate from profile save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_password_reset_email'])) {
    if (!$can_edit || !$privileged_password_reset_editor) {
        renderAlertBanner(t('manage.users.msg.permission_denied_invalid_user'), 'danger', 10000);
    } elseif (($EMAIL_SENDING_ENABLED ?? false) !== true || !is_password_reset_link_enabled()) {
        renderAlertBanner(t('manage.password_reset_admin.msg.unavailable'), 'warning', 10000);
    } else {
        $sendResult = send_password_reset_email_for_user_dn($ldap_connection, (string) $user_data['dn'], 'admin');
        if ($sendResult['ok']) {
            $sentTo = (string) ($sendResult['email'] ?? '');
            renderAlertBanner(
                t('manage.password_reset_admin.msg.sent', ['email' => $sentTo]),
                'success',
                10000
            );
        } else {
            $reason = $sendResult['reason'] ?? '';
            if ($reason === 'no_valid_email') {
                renderAlertBanner(t('manage.password_reset_admin.msg.no_valid_email'), 'danger', 10000);
            } elseif ($reason === 'send_failed') {
                renderAlertBanner(t('manage.password_reset_admin.msg.smtp_failed'), 'danger', 10000);
            } else {
                renderAlertBanner(t('manage.password_reset_admin.msg.unavailable'), 'warning', 10000);
            }
        }
    }
}

// Handle form submission for profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && $can_edit) {
    $update_data = [];
    $errors = [];

    // Normalize POST keys because LDAP attribute keys may differ in case/style
    // (e.g. config uses "givenname" but form uses "givenName")
    $post_lower = [];
    foreach ($_POST as $k => $v) {
        $post_lower[strtolower((string) $k)] = $v;
    }

    // Collect form data
    foreach ($attribute_map as $attr => $config) {
        $attr_lc = strtolower((string) $attr);
        if (isset($post_lower[$attr_lc]) && !empty(trim((string) $post_lower[$attr_lc]))) {
            $update_data[$attr] = trim((string) $post_lower[$attr_lc]);
        }
    }

    // Handle password change
    if (!empty($_POST['new_password'])) {
        $passScore = isset($_POST['pass_score']) && is_numeric($_POST['pass_score']) ? (int) $_POST['pass_score'] : null;
        $validation = validate_password_submission(
            (string) $_POST['new_password'],
            (string) ($_POST['confirm_new_password'] ?? ''),
            $passScore
        );
        if (!$validation['ok']) {
            $errors = array_merge($errors, $validation['errors']);
        } else {
            // Hash the password before storing it
            $update_data['userPassword'] = ldap_hashed_password((string) $_POST['new_password']);
        }
    }

    // If no errors, update the user
    if (empty($errors)) {
        $result = updateUser($user_data['dn'], $update_data);
        if ($result) {
            renderAlertBanner(t('manage.users.msg.profile_update_ok'), 'success', 10000);
            // Refresh user data
            $ldap_search = ldap_read($ldap_connection, $user_data['dn'], '(objectClass=*)');
            if ($ldap_search) {
                $updated_user = ldap_get_entries($ldap_connection, $ldap_search);
                if ($updated_user['count'] > 0) {
                    $updatedRow = $updated_user[0];
                    if (is_array($updatedRow)) {
                        $user_data = $updatedRow;
                    }
                }
            }
        } else {
            renderAlertBanner(t('manage.users.msg.profile_update_fail'), 'danger', 10000);
        }
    } else {
        renderAlertBanner(
            t('manage.users.msg.profile_validation_failed', ['errors' => implode(', ', $errors)]),
            'danger',
            10000
        );
    }
}

lum_close_ldap_if_not_manage($ldap_connection);

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <?php
            $profileName = (string) (get_ldap_attribute($user_data, 'cn') ?: get_ldap_attribute($user_data, $LDAP['account_attribute']) ?: t('user.no_name_available'));
            ?>
            <h2><?php echo htmlspecialchars(t('manage.users.profile.heading', ['name' => $profileName]), ENT_QUOTES, 'UTF-8'); ?></h2>
            
            <?php
            // Reopen LDAP connection for role lookup in display
            $ldap_connection = lum_ldap_data_connection();
            ?>
            
            <?php if ($can_edit) : ?>
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title"><?php echo htmlspecialchars(t('manage.users.edit_profile_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php echo csrfTokenField(); ?>
                        <?php if ($user_uuid !== null && $user_uuid !== '') : ?>
                            <input type="hidden" name="uuid" value="<?php echo htmlspecialchars($user_uuid, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php else : ?>
                            <input type="hidden" name="account_identifier" value="<?php echo htmlspecialchars((string) $account_identifier, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endif; ?>

                        <!-- Account Information -->
                        <h4><?php echo htmlspecialchars(t('manage.users.section.account_information'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="<?php echo $LDAP['account_attribute']; ?>"><?php echo htmlspecialchars((string) ($attribute_map[$LDAP['account_attribute']]['label'] ?? t('manage.common.account_id')), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="text" class="form-control" id="<?php echo $LDAP['account_attribute']; ?>" name="<?php echo $LDAP['account_attribute']; ?>" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, $LDAP['account_attribute'])); ?>" readonly>
                                    <small class="form-text text-muted"><?php echo htmlspecialchars(t('manage.users.account_id_immutable_help'), ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="userRoles"><?php echo htmlspecialchars(t('manage.common.roles'), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <?php
                                    // Get user's role memberships
                                    $user_roles = ldap_user_group_membership($ldap_connection, $user_data['dn']);
                                    $role_display = !empty($user_roles) ? implode(', ', $user_roles) : t('manage.users.no_roles_assigned');
                                    ?>
                                    <input type="text" class="form-control" id="userRoles" name="userRoles" 
                                           value="<?php echo htmlspecialchars($role_display); ?>" readonly>
                                    <small class="form-text text-muted"><?php echo htmlspecialchars(t('manage.users.roles_readonly_help'), ENT_QUOTES, 'UTF-8'); ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Personal Information -->
                        <h4><?php echo htmlspecialchars(t('manage.users.section.personal_information'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="givenName"><?php echo htmlspecialchars(t('manage.common.first_name'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <input type="text" class="form-control" id="givenName" name="givenName" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'givenName')); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="sn"><?php echo htmlspecialchars(t('manage.common.last_name'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <input type="text" class="form-control" id="sn" name="sn" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'sn')); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="cn"><?php echo htmlspecialchars(t('manage.common.display_name'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <input type="text" class="form-control" id="cn" name="cn" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'cn')); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Information -->
                        <h4><?php echo htmlspecialchars(t('manage.users.section.contact_information'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="mail"><?php echo htmlspecialchars(t('manage.common.email'), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="email" class="form-control" id="mail" name="mail" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'mail')); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="telephoneNumber"><?php echo htmlspecialchars(t('manage.users.phone_number'), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="tel" class="form-control" id="telephoneNumber" name="telephoneNumber" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'telephoneNumber')); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Password Change -->
                        <h4><?php echo htmlspecialchars(t('manage.users.section.change_password'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $mismatched_passwords ? 'is-invalid' : ''; ?>">
                                    <label for="new_password"><?php echo htmlspecialchars(t('manage.common.new_password_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="password" class="form-control" id="new_password" name="new_password">
                                    <?php if ($mismatched_passwords) : ?>
                                        <span class="help-block"><?php echo htmlspecialchars(t('manage.users.msg.passwords_do_not_match'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="confirm_new_password"><?php echo htmlspecialchars(t('manage.common.confirm_new_password'), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password">
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="pass_score" value="0" name="pass_score">

                        <?php if ($privileged_password_reset_editor) : ?>
                            <h4><?php echo htmlspecialchars(t('manage.password_reset_admin.section_title'), ENT_QUOTES, 'UTF-8'); ?></h4>
                            <?php if ($can_offer_password_reset_email) : ?>
                                <p class="text-muted small"><?php echo htmlspecialchars(t('manage.password_reset_admin.help'), ENT_QUOTES, 'UTF-8'); ?></p>
                                <button type="submit" name="send_password_reset_email" value="1" class="btn btn-outline-warning mb-3"><?php echo htmlspecialchars(t('manage.password_reset_admin.submit'), ENT_QUOTES, 'UTF-8'); ?></button>
                            <?php elseif (($EMAIL_SENDING_ENABLED ?? false) !== true) : ?>
                                <p class="text-muted small"><?php echo htmlspecialchars(t('manage.users.new.error.email_sending_not_configured'), ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php else : ?>
                                <p class="text-muted small"><?php echo htmlspecialchars(t('manage.users.new.password_set_link_disabled_help'), ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Additional Attributes -->
                        <?php if (isset($LDAP['account_additional_attributes'])) : ?>
                        <h4><?php echo htmlspecialchars(t('manage.users.section.additional_information'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <?php foreach ($LDAP['account_additional_attributes'] as $attr_name => $attr_config) : ?>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="<?php echo htmlspecialchars($attr_name); ?>"><?php echo htmlspecialchars($attr_config['label'] ?? ucfirst(str_replace('_', ' ', $attr_name))); ?></label>
                                        <?php if (isset($attr_config['type']) && $attr_config['type'] === 'textarea') : ?>
                                            <textarea class="form-control" id="<?php echo htmlspecialchars($attr_name); ?>" name="<?php echo htmlspecialchars($attr_name); ?>" rows="3"><?php echo htmlspecialchars(get_ldap_attribute($user_data, $attr_name)); ?></textarea>
                                        <?php else : ?>
                                            <input type="<?php echo htmlspecialchars($attr_config['type'] ?? 'text'); ?>" class="form-control" id="<?php echo htmlspecialchars($attr_name); ?>" name="<?php echo htmlspecialchars($attr_name); ?>" 
                                                   value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, $attr_name)); ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <button type="submit" name="update_profile" class="btn btn-success"><?php echo htmlspecialchars(t('manage.users.update_profile'), ENT_QUOTES, 'UTF-8'); ?></button>
                            <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('manage.users.back_to_users'), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    </form>
                </div>
            </div>
            <?php else : ?>
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h3 class="card-title"><?php echo htmlspecialchars(t('manage.users.read_only_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?php echo htmlspecialchars(t('manage.users.read_only_body'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('manage.users.back_to_users'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- User Details Display -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php echo htmlspecialchars(t('manage.users.details_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <dl class="dl-horizontal">
                                <dt><?php echo htmlspecialchars(t('manage.common.account_id'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, $LDAP['account_attribute'])); ?></dd>
                                
                                <dt><?php echo htmlspecialchars(t('manage.common.display_name'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'cn')); ?></dd>
                                
                                <dt><?php echo htmlspecialchars(t('manage.common.first_name'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'givenName')); ?></dd>
                                
                                <dt><?php echo htmlspecialchars(t('manage.common.last_name'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'sn')); ?></dd>
                                
                                <dt><?php echo htmlspecialchars(t('manage.common.roles'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd>
                                    <?php
                                    // Get user's role memberships for display
                                    $user_roles = ldap_user_group_membership($ldap_connection, $user_data['dn']);
                                    if (!empty($user_roles)) {
                                        echo htmlspecialchars(implode(', ', $user_roles));
                                    } else {
                                        echo '<span class="text-muted">' . htmlspecialchars(t('manage.users.no_roles_assigned'), ENT_QUOTES, 'UTF-8') . '</span>';
                                    }
                                    ?>
                                </dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <dl class="dl-horizontal">
                                <dt><?php echo htmlspecialchars(t('manage.common.email'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'mail')); ?></dd>
                                
                                <dt><?php echo htmlspecialchars(t('manage.users.phone_number'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'telephoneNumber')); ?></dd>
                                
                                <dt><?php echo htmlspecialchars(t('manage.users.location'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd><?php echo htmlspecialchars(t('manage.users.location_user', ['location' => ucfirst($user_location)]), ENT_QUOTES, 'UTF-8'); ?></dd>
                                
                                <dt><?php echo htmlspecialchars(t('manage.users.dn'), ENT_QUOTES, 'UTF-8'); ?>:</dt>
                                <dd><code><?php echo htmlspecialchars($user_data['dn']); ?></code></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php print getAssetBase(); ?>js/password_utils.js"></script>
<script src="<?php print getAssetBase(); ?>js/form-sync.js"></script>
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function(){
    // Form sync: display name from givenName+sn (editable; stops overwriting once user edits cn)
    if (typeof initFormSync === 'function') {
        initFormSync({
            givenNameId: 'givenName',
            snId: 'sn',
            cnId: 'cn'
        });
    }

    const passwordConfig = <?php echo getPasswordStrengthConfigJs(); ?>;
    initializePasswordStrength({
        passwordFieldId: 'new_password',
        confirmFieldId: 'confirm_new_password',
        config: passwordConfig
    });
});
</script>

<?php
// Close LDAP connection
lum_close_ldap_if_not_manage($ldap_connection);

renderFooter();
?>
