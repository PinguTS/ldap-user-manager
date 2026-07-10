<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage(['ldap', 'organization', 'mail', 'password_reset']);

// Ensure CSRF token is generated early
getCsrfToken();



setPageAccess(["admin", "maintainer"]);

$page_title = t('manage.users.new.page_title');
$admin_setup = false;
$page_messages = [];

$invalid_password = false;
$mismatched_passwords = false;
$invalid_username = false;
$weak_password = false;
$invalid_email = false;
$disabled_email_tickbox = true;
$invalid_cn = false;
$invalid_givenname = false;
$invalid_sn = false;

$invalid_user_role = false;

$new_account_r = array();



// Get available user roles based on current user's permissions
$available_user_roles = [];
if (currentUserIsGlobalAdmin()) {
    // System administrators can create users with any system role
    $available_user_roles = [$LDAP['admin_role'], $LDAP['maintainer_role']];
} elseif (currentUserIsMaintainer()) {
    // System maintainers can only create users with maintainer role
    $available_user_roles = [$LDAP['maintainer_role']];
} else {
    // Other users cannot create system users
    $available_user_roles = [];
}

// Process form data directly for system user creation
if (isset($_POST['mail']) && !empty(trim($_POST['mail']))) {
    $new_account_r['mail'] = trim($_POST['mail']);
}
if (isset($_POST['cn']) && !empty(trim($_POST['cn']))) {
    $new_account_r['cn'] = trim($_POST['cn']);
}
if (isset($_POST['givenName']) && !empty(trim($_POST['givenName']))) {
    $new_account_r['givenName'] = trim($_POST['givenName']);
}
if (isset($_POST['sn']) && !empty(trim($_POST['sn']))) {
    $new_account_r['sn'] = trim($_POST['sn']);
}
if (isset($_POST['userPassword']) && !empty(trim($_POST['userPassword']))) {
    $new_account_r['userPassword'] = trim($_POST['userPassword']);
}
if (isset($_POST['userRole']) && !empty(trim($_POST['userRole']))) {
    $new_account_r['userRole'] = trim($_POST['userRole']);
}
if (isset($_POST['telephoneNumber']) && !empty(trim($_POST['telephoneNumber']))) {
    $new_account_r['telephoneNumber'] = trim($_POST['telephoneNumber']);
}

// Debug: Log what was submitted (remove this in production)
// Note: do not log POST data here (may include passwords).

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {
    if (!validateCsrfToken()) {
        $page_messages[] = ['type' => 'danger', 'message' => t('manage.common.msg.security_validation_failed')];
    } else {
        // Validate required fields
        $errors = [];

        if (empty($new_account_r['mail'])) {
        $invalid_email = true;
        $errors[] = t('manage.users.new.error.email_required');
    }

    if (empty($new_account_r['cn'])) {
        $invalid_cn = true;
        $errors[] = t('manage.users.new.error.common_name_required');
    }

    if (empty($new_account_r['givenName'])) {
        $invalid_givenname = true;
        $errors[] = t('manage.users.new.error.first_name_required');
    }

    if (empty($new_account_r['sn'])) {
        $invalid_sn = true;
        $errors[] = t('manage.users.new.error.last_name_required');
    }

    $send_password_set_link = isset($_POST['send_password_set_link']) && $_POST['send_password_set_link'] === 'on';
    if ($send_password_set_link) {
        global $EMAIL_SENDING_ENABLED;
        if ($EMAIL_SENDING_ENABLED !== true || !is_password_reset_link_enabled()) {
            $errors[] = !is_password_reset_link_enabled()
                ? t('manage.users.new.error.password_set_link_disabled_secret_missing')
                : t('manage.users.new.error.email_sending_not_configured');
        }
    } else {
        if (empty($new_account_r['userPassword'])) {
            $invalid_password = true;
            $errors[] = t('manage.users.new.error.password_required');
        }

        if (isset($new_account_r['userPassword']) && isset($_POST['confirm_password']) && $new_account_r['userPassword'] !== $_POST['confirm_password']) {
            $mismatched_passwords = true;
            $errors[] = t('manage.users.new.error.passwords_do_not_match');
        }
    }

  // Organization is not required for system users

    if (empty($new_account_r['userRole'])) {
        $invalid_user_role = true;
        $errors[] = t('manage.users.new.error.role_required');
    }

  // Validate role permissions
    if (!empty($new_account_r['userRole'])) {
      // Validate that maintainers cannot create administrator roles
        if (currentUserIsMaintainer() && $new_account_r['userRole'] === $LDAP['admin_role']) {
            $invalid_user_role = true;
            $errors[] = $LDAP['error_messages']['maintainer_cannot_create_admin'];
        }

        if (!in_array($new_account_r['userRole'], $available_user_roles)) {
            $invalid_user_role = true;
            $errors[] = t('manage.users.new.error.role_not_permitted');
        }
    }

  // If no errors, create the account
    if (empty($errors)) {
        $plainPassword = (string) ($new_account_r['userPassword'] ?? '');
        if ($send_password_set_link) {
            $plainPassword = bin2hex(random_bytes(16));
        }

        // Hash the password before passing it to createUserAccount for security
        $new_account_r['userPassword'] = ldap_hashed_password($plainPassword);

        $result = createUserAccount($new_account_r);
        if ($result[0]) {
            $flashMessage = t('manage.users.new.msg.created_ok', ['user' => $new_account_r['cn'] ?? '']);

            global $EMAIL_SENDING_ENABLED;
            $login = (string) ($new_account_r['mail'] ?? '');
            $first = (string) ($new_account_r['givenName'] ?? '');
            $last = (string) ($new_account_r['sn'] ?? '');
            if (($EMAIL_SENDING_ENABLED ?? false) === true && $login !== '' && isValidEmail($login)) {
                $emailLocale = lum_resolve_transactional_email_locale_for_system_user_invite((string) ($new_account_r['userRole'] ?? ''));
                $sentOk = lum_with_transactional_email_locale($emailLocale, function () use (
                    $send_password_set_link,
                    $login,
                    $first,
                    $last
                ): bool {
                    if ($send_password_set_link) {
                        $payload = build_password_action_payload($login, 'set');
                        $token = create_password_action_token($payload);
                        $setUrl = build_password_action_url($token);

                        $vars = array_merge(lum_password_action_token_expiry_mail_vars(), [
                            'login' => $login,
                            'first_name' => $first,
                            'last_name' => $last,
                            'password_set_url' => $setUrl,
                        ]);

                        $parsedAccount = lum_load_parsed_combined_transactional_template('new_account.html');
                        $subject = parse_mail_template((string) $parsedAccount['subject'], $vars);
                        $body = parse_mail_template((string) $parsedAccount['body'], $vars);

                        $preheader = lum_email_preheader('email.preheader.new_account', 'Set your password to activate your account.');

                        return send_email($login, trim($first . ' ' . $last), $subject, $body, $preheader);
                    }

                    return lum_send_account_welcome_email(
                        $login,
                        trim($first . ' ' . $last),
                        [
                            'login' => $login,
                            'first_name' => $first,
                            'last_name' => $last,
                        ]
                    );
                });
                if (!$sentOk) {
                    $flashMessage .= ' ' . t('manage.users.new.msg.email_send_failed');
                }
            }

            setFlash($flashMessage, 'success', 10000);
            header('Location: ' . getBaseUrl() . 'manage/users/');
            exit;
        } else {
            error_log("new user: ldap_new_account failed: " . $result[1]);
            $page_messages[] = ['message' => t('manage.users.new.msg.created_fail'), 'type' => 'danger', 'timeout' => 10000];
        }
    } else {
        $page_messages[] = ['message' => t('manage.users.new.msg.validation_failed', ['errors' => implode(', ', $errors)]), 'type' => 'danger', 'timeout' => 10000];
    }
    }
}

$orgName = (string) ($ORGANISATION_NAME ?? 'System');
renderHeader(t('manage.users.new.header', ['org' => $orgName]));
render_submenu();

foreach ($page_messages as $page_message) {
    renderAlertBanner(
        (string) $page_message['message'],
        (string) $page_message['type'],
        (int) $page_message['timeout']
    );
}

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2><?php echo htmlspecialchars(t('manage.users.new.heading'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="alert alert-info">
                <strong><?php echo htmlspecialchars(t('manage.users.new.system_user_title'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php echo htmlspecialchars(t('manage.users.new.system_user_body'), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title"><?php echo htmlspecialchars(t('manage.users.new.card_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <form method="post" action="" enctype="multipart/form-data" id="newUserForm">
                        <?php echo csrfTokenField(); ?>
                        
                        <!-- Account Information -->
                        <h4><?php echo htmlspecialchars(t('manage.users.section.account_information'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_email ? 'is-invalid' : ''; ?>">
                                    <label for="mail"><?php echo htmlspecialchars(t('manage.users.new.email_account_id_label'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <input type="email" class="form-control" id="mail" name="mail" 
                                           value="<?php echo htmlspecialchars($new_account_r['mail'] ?? ''); ?>" required>
                                    <?php if ($invalid_email) : ?>
                                        <span class="help-block"><?php echo htmlspecialchars(t('manage.users.new.error.email_required'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_user_role ? 'is-invalid' : ''; ?>">
                                    <label for="userRole"><?php echo htmlspecialchars(t('manage.users.new.user_role_label'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <select class="form-control" id="userRole" name="userRole" required>
                                        <option value=""><?php echo htmlspecialchars(t('manage.users.new.select_role'), ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php foreach ($available_user_roles as $role) : ?>
                                            <option value="<?php echo htmlspecialchars($role); ?>" <?php echo ($new_account_r['userRole'] ?? '') === $role ? 'selected' : ''; ?>>
                                                <?php
                                                $role_label = match ($role) {
                                                    $LDAP['admin_role'] => $LDAP['role_display_labels']['admin_role'],
                                                    $LDAP['maintainer_role'] => $LDAP['role_display_labels']['maintainer_role'],
                                                    default => ucfirst(str_replace('_', ' ', $role))
                                                };
                                                echo htmlspecialchars($role_label);
    ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($invalid_user_role) : ?>
                                        <span class="help-block"><?php echo htmlspecialchars(t('manage.users.new.error.role_required'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Personal Information -->
                        <h4><?php echo htmlspecialchars(t('manage.users.section.personal_information'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group <?php echo $invalid_givenname ? 'is-invalid' : ''; ?>">
                                    <label for="givenName"><?php echo htmlspecialchars(t('manage.common.first_name'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <input type="text" class="form-control" id="givenName" name="givenName" 
                                           value="<?php echo htmlspecialchars($new_account_r['givenName'] ?? ''); ?>" required>
                                    <?php if ($invalid_givenname) : ?>
                                        <span class="help-block"><?php echo htmlspecialchars(t('manage.users.new.error.first_name_required'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group <?php echo $invalid_sn ? 'is-invalid' : ''; ?>">
                                    <label for="sn"><?php echo htmlspecialchars(t('manage.common.last_name'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <input type="text" class="form-control" id="sn" name="sn" 
                                           value="<?php echo htmlspecialchars($new_account_r['sn'] ?? ''); ?>" required>
                                    <?php if ($invalid_sn) : ?>
                                        <span class="help-block"><?php echo htmlspecialchars(t('manage.users.new.error.last_name_required'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group <?php echo $invalid_cn ? 'is-invalid' : ''; ?>">
                                    <label for="cn"><?php echo htmlspecialchars(t('manage.common.display_name'), ENT_QUOTES, 'UTF-8'); ?> * <small class="text-muted"><?php echo htmlspecialchars(t('manage.common.display_name_hint'), ENT_QUOTES, 'UTF-8'); ?></small></label>
                                    <input type="text" class="form-control" id="cn" name="cn" 
                                           value="<?php echo htmlspecialchars($new_account_r['cn'] ?? ''); ?>" required>
                                    <?php if ($invalid_cn) : ?>
                                        <span class="help-block"><?php echo htmlspecialchars(t('manage.users.new.error.display_name_required'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Information -->
                        <h4><?php echo htmlspecialchars(t('manage.users.section.contact_information'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="telephoneNumber"><?php echo htmlspecialchars(t('manage.users.phone_number'), ENT_QUOTES, 'UTF-8'); ?></label>
                                    <input type="tel" class="form-control" id="telephoneNumber" name="telephoneNumber" 
                                           value="<?php echo htmlspecialchars($new_account_r['telephoneNumber'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        

                        
                        <!-- Security -->
                        <h4><?php echo htmlspecialchars(t('manage.users.new.section.security'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <?php global $EMAIL_SENDING_ENABLED; ?>
                        <?php if ($EMAIL_SENDING_ENABLED === true) : ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="send_password_set_link" name="send_password_set_link">
                                <label class="form-check-label" for="send_password_set_link">
                                    <?php echo htmlspecialchars(t('manage.org_users.email_invite_link_checkbox'), ENT_QUOTES, 'UTF-8'); ?>
                                </label>
                            </div>
                            <?php if (!is_password_reset_link_enabled()) : ?>
                                <div class="alert alert-warning mt-2 mb-0">
                                    <?php echo htmlspecialchars(t('manage.users.new.password_set_link_disabled_help'), ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                            <p class="text-muted small mt-2 mb-0"><?php echo htmlspecialchars(t('manage.org_users.email_after_create_note'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_password ? 'is-invalid' : ''; ?>">
                                    <label for="userPassword"><?php echo htmlspecialchars(t('login.password_label'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <input type="password" class="form-control" id="userPassword" name="userPassword" required>
                                    <?php if ($invalid_password) : ?>
                                        <span class="help-block"><?php echo htmlspecialchars(t('manage.users.new.error.password_required'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group <?php echo $mismatched_passwords ? 'is-invalid' : ''; ?>">
                                    <label for="confirm_password"><?php echo htmlspecialchars(t('manage.users.new.confirm_password_label'), ENT_QUOTES, 'UTF-8'); ?> *</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    <?php if ($mismatched_passwords) : ?>
                                        <span class="help-block"><?php echo htmlspecialchars(t('manage.users.new.error.passwords_do_not_match'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Attributes -->
                        <?php if (isset($LDAP['account_additional_attributes'])) : ?>
                        <h4><?php echo htmlspecialchars(t('manage.users.section.additional_information'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="row">
                            <?php foreach ($LDAP['account_additional_attributes'] as $attr_name => $attr_config) : ?>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="<?php echo htmlspecialchars($attr_name); ?>"><?php echo htmlspecialchars($attr_config['label'] ?? ucfirst(str_replace('_', ' ', $attr_name))); ?></label>
                                        <?php if (isset($attr_config['type']) && $attr_config['type'] === 'textarea') : ?>
                                            <textarea class="form-control" id="<?php echo htmlspecialchars($attr_name); ?>" name="<?php echo htmlspecialchars($attr_name); ?>" rows="3"><?php echo htmlspecialchars($new_account_r[$attr_name] ?? ''); ?></textarea>
                                        <?php else : ?>
                                            <input type="<?php echo htmlspecialchars($attr_config['type'] ?? 'text'); ?>" class="form-control" id="<?php echo htmlspecialchars($attr_name); ?>" name="<?php echo htmlspecialchars($attr_name); ?>" 
                                                   value="<?php echo htmlspecialchars($new_account_r[$attr_name] ?? ''); ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <button type="submit" name="create_account" class="btn btn-success"><?php echo htmlspecialchars(t('manage.users.new.create_account_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
                            <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('manage.common.cancel'), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php print getAssetBase(); ?>js/password_utils.js"></script>
<script src="<?php print getAssetBase(); ?>js/form-sync.js"></script>
<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function(){
    // Get password strength configuration from server
    const passwordConfig = <?php echo getPasswordStrengthConfigJs(); ?>;
    
    // Initialize unified password strength checking with dynamic config
    initializePasswordStrength({
        passwordFieldId: 'userPassword',
        confirmFieldId: 'confirm_password',
        config: passwordConfig
    });

    const checkbox = document.getElementById('send_password_set_link');
    const pw = document.getElementById('userPassword');
    const pw2 = document.getElementById('confirm_password');
    function togglePw() {
        if (!checkbox || !pw || !pw2) return;
        const useLink = checkbox.checked;
        if (useLink && checkbox.disabled) {
            return;
        }
        pw.required = !useLink;
        pw2.required = !useLink;
        pw.disabled = useLink;
        pw2.disabled = useLink;
        if (useLink) {
            pw.value = '';
            pw2.value = '';
        }
    }
    if (checkbox) {
        <?php if (!is_password_reset_link_enabled()) : ?>
        checkbox.disabled = true;
        <?php endif; ?>
        checkbox.addEventListener('change', togglePw);
        togglePw();
    }
    
    // Form sync: display name from givenName+sn
    if (typeof initFormSync === 'function') {
        initFormSync({
            givenNameId: 'givenName',
            snId: 'sn',
            cnId: 'cn'
        });
    }
});
</script>

<?php
renderFooter();
?>
