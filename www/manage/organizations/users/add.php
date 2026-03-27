<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap', 'organization', 'user', 'mail', 'password_reset']);

// Ensure CSRF token is generated early
get_csrf_token();

// Check if organization parameter is provided (support both uuid and org)
$org_uuid = $_GET['uuid'] ?? null;
$org_name = $_GET['org'] ?? null;

if (!$org_uuid && !$org_name) {
    render_alert_banner(t('manage.org_users.add.msg.org_identifier_required'), "warning");
    render_footer();
    exit(0);
}

// If UUID is provided, get organization by UUID
if ($org_uuid) {
    $ldap = open_ldap_connection();
    if (!$ldap) {
        render_alert_banner(t('manage.orgs.ldap_conn_failed'), "danger");
        render_footer();
        exit(0);
    }

    $organization = ldap_get_organization_by_uuid($ldap, $org_uuid);

    // Debug: log the organization data structure
    error_log("add_org_user.php: Organization data retrieved for UUID $org_uuid: " . print_r($organization, true));

    ldap_close($ldap);

    if (!$organization) {
        // Debug: log the UUID and search details
        error_log("add_org_user.php: Failed to find organization with UUID: $org_uuid");
        render_alert_banner(t('manage.common.org_not_found'), "danger");
        render_footer();
        exit(0);
    }

    // Try different possible organization name attributes
    $org_name = null;
    if (isset($organization['o']) && is_array($organization['o']) && count($organization['o']) > 0) {
        $org_name = $organization['o'][0];
        error_log("add_org_user.php: Using organization name from 'o' attribute: $org_name");
    } elseif (isset($organization['name']) && is_array($organization['name']) && count($organization['name']) > 0) {
        $org_name = $organization['name'][0];
        error_log("add_org_user.php: Using organization name from 'name' attribute: $org_name");
    } elseif (isset($organization['cn']) && is_array($organization['cn']) && count($organization['cn']) > 0) {
        $org_name = $organization['cn'][0];
        error_log("add_org_user.php: Using organization name from 'cn' attribute: $org_name");
    }

    if (!$org_name) {
        // Debug: log the organization structure to help troubleshoot
        error_log("add_org_user.php: Organization data structure: " . print_r($organization, true));
        render_alert_banner(t('manage.org_users.add.msg.cannot_determine_org_name'), "danger");
        render_footer();
        exit(0);
    }

    error_log("add_org_user.php: Successfully determined organization name: $org_name");
}

// Check if user has appropriate permissions for this organization
set_page_access(["admin", "maintainer", "org_admin"]);

// Verify the organization exists (if not already verified by UUID)
if (!$organization) {
    $organizations = listOrganizations();
    foreach ($organizations as $org) {
        if ($org['name'] === $org_name) {
            $organization = $org;
            break;
        }
    }

    if (!$organization) {
        render_alert_banner(t(
            'manage.org_users.add.msg.org_not_found',
            ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]
        ), "danger");
        render_footer();
        exit(0);
    }
}

$attribute_map = $LDAP['default_attribute_map'];
if (isset($LDAP['account_additional_attributes'])) {
    $attribute_map = ldap_complete_attribute_map($attribute_map, $LDAP['account_additional_attributes']);
}

if (!array_key_exists($LDAP['account_attribute'], $attribute_map)) {
    $attribute_map = array_merge($attribute_map, array($LDAP['account_attribute'] => array("label" => "Account UID")));
}

// Normalize common LDAP attribute aliases used by forms/config.
$get_attribute_post_value = static function (string $attribute): string {
    $aliases = [
        $attribute,
    ];
    if ($attribute === 'givenname') {
        $aliases[] = 'givenName';
    } elseif ($attribute === 'givenName') {
        $aliases[] = 'givenname';
    }

    foreach ($aliases as $alias) {
        if (isset($_POST[$alias]) && $_POST[$alias] !== '') {
            return (string) $_POST[$alias];
        }
    }

    return '';
};

$invalid_password = false;
$mismatched_passwords = false;
$invalid_username = false;
$weak_password = false;
$invalid_email = false;
        // Common Name (cn) is auto-generated, no validation needed
$invalid_givenname = false;
$invalid_sn = false;
$invalid_account_identifier = false;
$invalid_user_role = false;

$new_account_r = array();
$account_attribute = $LDAP['account_attribute'];

// Available user roles for organization users
        $available_user_roles = [$LDAP['user_role'], $LDAP['org_admin_role']];

// Handle form submission
if (isset($_POST['create_org_user'])) {
    if (!validate_csrf_token()) {
        render_alert_banner(t('manage.common.msg.security_validation_failed'), "danger");
    } else {
        // Process form data
        foreach ($attribute_map as $attribute => $attr_r) {
            if (isset($_FILES[$attribute]['size']) && $_FILES[$attribute]['size'] > 0) {
                // File upload validation
                global $FILE_UPLOAD_MAX_SIZE, $FILE_UPLOAD_ALLOWED_MIME_TYPES;
                $max_file_size = $FILE_UPLOAD_MAX_SIZE;
                $allowed_mime_types = $FILE_UPLOAD_ALLOWED_MIME_TYPES;
                $file_size = $_FILES[$attribute]['size'];
                $file_tmp = $_FILES[$attribute]['tmp_name'];
                $file_error = $_FILES[$attribute]['error'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file_tmp);
                finfo_close($finfo);

                if ($file_error !== UPLOAD_ERR_OK) {
                    render_alert_banner(
                        t('manage.org_users.add.msg.file_upload_error', ['attribute' => htmlspecialchars((string) $attribute, ENT_QUOTES, 'UTF-8')]),
                        'danger',
                        10000
                    );
                    continue;
                }
                if ($file_size > $max_file_size) {
                    render_alert_banner(
                        t('manage.org_users.add.msg.file_too_large', ['attribute' => htmlspecialchars((string) $attribute, ENT_QUOTES, 'UTF-8'), 'max' => '2MB']),
                        'danger',
                        10000
                    );
                    continue;
                }
                if (!in_array($mime_type, $allowed_mime_types)) {
                    render_alert_banner(
                        t('manage.org_users.add.msg.file_invalid_type', ['attribute' => htmlspecialchars((string) $attribute, ENT_QUOTES, 'UTF-8')]),
                        'danger',
                        10000
                    );
                    continue;
                }

                $this_attribute = array();
                $this_attribute[0] = file_get_contents($file_tmp);
                $$attribute = $this_attribute;
                $new_account_r[$attribute] = $this_attribute;
            } else {
                // Regular form field
                $attribute_value = $get_attribute_post_value((string) $attribute);
                if ($attribute_value !== '') {
                    $$attribute = array(0 => $attribute_value);
                    $new_account_r[$attribute] = array(0 => $attribute_value);
                } else {
                    $$attribute = array();
                }
            }
        }

        // Keep canonical first-name key available for validation/template usage.
        if (empty($givenName[0]) && !empty($givenname[0])) {
            $givenName = $givenname;
        }

        // Ensure Account UID is set from email if not already provided
        if (empty($new_account_r[$account_attribute][0]) && !empty($mail[0])) {
            $new_account_r[$account_attribute] = $mail;
        }

        // Auto-populate Common Name (cn) from givenName and sn if not provided
        if (empty($cn[0]) && (!empty($givenName[0]) || !empty($sn[0]))) {
            $cn_parts = [];
            if (!empty($givenName[0])) {
                $cn_parts[] = $givenName[0];
            }
            if (!empty($sn[0])) {
                $cn_parts[] = $sn[0];
            }
            $cn = array(0 => implode(' ', $cn_parts));
            $new_account_r['cn'] = $cn;
        }

        // Get password and validation
        $password = (string) ($_POST['password'] ?? '');
        $password_match = (string) ($_POST['password_match'] ?? '');
        $user_role = $_POST['user_role'] ?? 'user';
        $send_password_set_link = isset($_POST['send_password_set_link']) && $_POST['send_password_set_link'] === 'on';
        if ($send_password_set_link && !is_password_reset_link_enabled()) {
            $send_password_set_link = false;
            render_alert_banner(t('manage.users.new.error.password_set_link_disabled_secret_missing'), 'warning', 10000);
        }

        // Validation
        $account_identifier = $new_account_r[$account_attribute][0] ?? '';
        $this_cn = $cn[0] ?? '';
        $this_mail = $mail[0] ?? '';
        $this_givenName = $givenName[0] ?? '';
        $this_sn = $sn[0] ?? '';

        // Common Name (cn) is auto-generated from givenName + sn, so no validation needed
        if (empty($account_identifier)) {
            $invalid_account_identifier = true;
        }
        if (empty($this_givenName)) {
            $invalid_givenname = true;
        }
        if (empty($this_sn)) {
            $invalid_sn = true;
        }
        if (!$send_password_set_link) {
            if ($password === '') {
                $invalid_password = true;
            }
            if ($password !== $password_match) {
                $mismatched_passwords = true;
            }
        }
        if (!in_array($user_role, $available_user_roles)) {
            $invalid_user_role = true;
        }
        if (empty($this_mail)) {
            $invalid_email = true;
        }
        if (!empty($this_mail) && !is_valid_email($this_mail)) {
            $invalid_email = true;
        }

        // Check if username already exists
        $ldap_connection = open_ldap_connection();
        $existing_user = ldap_search($ldap_connection, $LDAP['org_dn'], "({$LDAP['account_attribute']}=" . ldap_escape($account_identifier, "", LDAP_ESCAPE_FILTER) . ")");
        if ($existing_user && ldap_count_entries($ldap_connection, $existing_user) > 0) {
            $invalid_username = true;
        }

        // If all validation passes, create the user
        if (
            !$invalid_account_identifier && !$invalid_givenname && !$invalid_sn &&
            !$invalid_password && !$mismatched_passwords && !$invalid_user_role && !$invalid_email && !$invalid_username
        ) {
            // Add organization and role to the account data
            $new_account_r['organization'] = [$org_name];
            $new_account_r['description'] = [$user_role];
            if ($send_password_set_link) {
                // Set a random temporary password; user will set their own via email link.
                $password = bin2hex(random_bytes(16));
            }
            $new_account_r['password'] = [$password];

            // Create the user account
            $new_account = ldap_new_account($ldap_connection, $new_account_r);

            if ($new_account) {
                $creation_message = t(
                    'manage.org_users.add.msg.created_ok',
                    [
                        'account' => htmlspecialchars((string) $account_identifier, ENT_QUOTES, 'UTF-8'),
                        'org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8'),
                    ]
                );

                // Add user to organization admin role if selected
                if ($user_role === $LDAP['org_admin_role']) {
                    $org_admin_add = addUserToOrgAdmin($org_name, $new_account);
                    if (!$org_admin_add) {
                        $creation_message .= ' ' . t('manage.org_users.add.msg.warn_org_admin_failed');
                    }
                }

                // Send password set link if requested
                if ($send_password_set_link && isset($this_mail) && $EMAIL_SENDING_ENABLED === true) {
                    $payload = build_password_action_payload((string) $account_identifier, 'set');
                    $token = create_password_action_token($payload);
                    $setUrl = build_password_action_url($token);
                    $ttlMinutes = (int) ceil(get_password_reset_token_ttl_seconds() / 60);

                    $vars = [
                        'login' => (string) $account_identifier,
                        'first_name' => (string) $this_givenName,
                        'last_name' => (string) $this_sn,
                        'password_set_url' => $setUrl,
                        'token_expires_minutes' => (string) $ttlMinutes,
                    ];

                    $mail_body = parse_mail_template((string) $new_account_mail_body, $vars);
                    $mail_subject = parse_mail_template((string) $new_account_mail_subject, $vars);

                    $sent_email = send_email($this_mail, "$this_givenName $this_sn", $mail_subject, $mail_body);
                    if ($sent_email) {
                        $creation_message .= ' ' . t(
                            'manage.org_users.add.msg.email_sent_ok',
                            ['email' => htmlspecialchars((string) $this_mail, ENT_QUOTES, 'UTF-8')]
                        );
                    } else {
                        $creation_message .= ' ' . t('manage.org_users.add.msg.email_send_failed');
                    }
                }

                render_alert_banner($creation_message, "success");

                // Redirect back to organization users page
                // Redirect back to organization users page, preserving UUID if available
                if ($org_uuid) {
                    header("Location: /manage/organizations/" . urlencode((string) $org_uuid) . "/users/");
                } else {
                    header("Location: /manage/organizations/");
                }
                exit(0);
            } else {
                render_alert_banner(t('manage.org_users.add.msg.create_failed'), "danger");
            }

            ldap_close($ldap_connection);
        }
    }
}

render_header(t('manage.org_users.add.page_title', ['org' => $org_name]));
render_submenu();

// Display any validation errors
$errors = "";
if ($invalid_givenname) {
    $errors .= "<li>" . t('manage.org_users.add.error.first_name_required') . "</li>\n";
}
if ($invalid_sn) {
    $errors .= "<li>" . t('manage.org_users.add.error.last_name_required') . "</li>\n";
}
if ($invalid_account_identifier) {
    $errors .= "<li>" . t('manage.org_users.add.error.email_invalid_or_exists') . "</li>\n";
}
if ($invalid_password) {
    $errors .= "<li>" . t('manage.org_users.add.error.password_required') . "</li>\n";
}
if ($mismatched_passwords) {
    $errors .= "<li>" . t('manage.org_users.add.error.passwords_do_not_match') . "</li>\n";
}
if ($invalid_email) {
    $errors .= "<li>" . t('manage.org_users.add.error.email_invalid') . "</li>\n";
}
if ($invalid_user_role) {
    $errors .= "<li>" . t('manage.org_users.add.error.role_invalid') . "</li>\n";
}

if ($errors != "") { ?>
    <div class="alert alert-warning">
        <p><?php echo htmlspecialchars(t('manage.org_users.add.error.form_issues'), ENT_QUOTES, 'UTF-8'); ?></p>
        <ul><?php print strip_tags($errors, '<li>'); ?></ul>
    </div>
<?php } ?>

<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.dashboard'), ENT_QUOTES, 'UTF-8'); ?></a></li>
            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.organizations'), ENT_QUOTES, 'UTF-8'); ?></a></li>
            <?php if ($org_uuid) : ?>
                <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode((string) $org_uuid) . '/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($org_name); ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode((string) $org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.users'), ENT_QUOTES, 'UTF-8'); ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars(t('manage.common.add_user'), ENT_QUOTES, 'UTF-8'); ?></li>
        </ol>
    </nav>
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header">
                    <h3><?php echo htmlspecialchars(t('manage.org_users.add.card_title', ['org' => $org_name]), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong><?php echo htmlspecialchars(t('manage.org_users.add.note_label'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars(t('manage.org_users.add.note_text'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    
                    <form class="form-horizontal" action="" enctype="multipart/form-data" method="post">
                        <?= csrf_token_field() ?>
                        <input type="hidden" name="create_org_user" value="1">
                        
                        <!-- Organization (pre-selected and locked) -->
                        <div class="form-group">
                            <label class="col-sm-3 form-label"><?php echo htmlspecialchars(t('manage.org_users.add.organization_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="col-sm-6">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($org_name); ?>" readonly>
                                <small class="text-muted"><?php echo htmlspecialchars(t('manage.org_users.add.organization_preselected_hint'), ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                        </div>

                        <!-- User Role -->
                        <div class="form-group">
                            <label for="user_role" class="col-sm-3 form-label"><?php echo htmlspecialchars(t('manage.users.new.user_role_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <div class="col-sm-6">
                                <select class="form-control" name="user_role" id="user_role" required>
                                    <option value=""><?php echo htmlspecialchars(t('manage.roles.select_role'), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <option value="<?php echo $LDAP['user_role']; ?>" <?php echo (isset($_POST['user_role']) && $_POST['user_role'] === $LDAP['user_role']) ? 'selected' : ''; ?>><?php echo $LDAP['role_display_labels']['user_role']; ?></option>
                                    <option value="<?php echo $LDAP['org_admin_role']; ?>" <?php echo (isset($_POST['user_role']) && $_POST['user_role'] === $LDAP['org_admin_role']) ? 'selected' : ''; ?>><?php echo $LDAP['role_display_labels']['org_admin_role']; ?></option>
                                </select>
                            </div>
                        </div>

                        <!-- Account Identifier (Auto-generated from Email) -->
                        <div class="form-group" style="display: none;">
                            <label for="<?php echo $account_attribute; ?>" class="col-sm-3 form-label">
                                <strong><?php echo htmlspecialchars($attribute_map[$account_attribute]['label']); ?></strong>
                            </label>
                            <div class="col-sm-6">
                                <input type="hidden" name="<?php echo $account_attribute; ?>" 
                                       id="<?php echo $account_attribute; ?>" 
                                       value="<?php echo htmlspecialchars($_POST[$account_attribute] ?? ''); ?>">
                                <small class="text-muted"><?php echo htmlspecialchars(t('manage.org_users.add.auto_generated_email_address'), ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                        </div>

                        <!-- Display Name (cn) -->
                        <div class="form-group">
                            <label for="cn" class="col-sm-3 form-label">
                                <strong><?php echo htmlspecialchars(t('manage.common.display_name'), ENT_QUOTES, 'UTF-8'); ?></strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="text" class="form-control" name="cn" id="cn"
                                       value="<?php echo htmlspecialchars($_POST['cn'] ?? ''); ?>" required>
                                <small class="text-muted"><?php echo htmlspecialchars(t('manage.common.display_name_hint'), ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                        </div>

                        <!-- First Name -->
                        <div class="form-group">
                            <label for="givenName" class="col-sm-3 form-label">
                                <strong><?php echo htmlspecialchars(t('manage.common.first_name'), ENT_QUOTES, 'UTF-8'); ?></strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="text" class="form-control" name="givenName" id="givenName" 
                                       value="<?php echo htmlspecialchars($_POST['givenName'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>

                        <!-- Last Name -->
                        <div class="form-group">
                            <label for="sn" class="col-sm-3 form-label">
                                <strong><?php echo htmlspecialchars(t('manage.common.last_name'), ENT_QUOTES, 'UTF-8'); ?></strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="text" class="form-control" name="sn" id="sn" 
                                       value="<?php echo htmlspecialchars($_POST['sn'] ?? ''); ?>" 
                                       required>
                            </div>
                        </div>

                        <!-- Email (Account UID) -->
                        <div class="form-group">
                            <label for="mail" class="col-sm-3 form-label">
                                <strong><?php echo htmlspecialchars(t('manage.common.email_username'), ENT_QUOTES, 'UTF-8'); ?></strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="email" class="form-control" name="mail" id="mail" 
                                       value="<?php echo htmlspecialchars($_POST['mail'] ?? ''); ?>" 
                                       required>
                                <small class="text-muted"><?php echo htmlspecialchars(t('manage.common.email_username_hint'), ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                        </div>

                        <div id="password_fields">
                            <!-- Password -->
                            <div class="form-group">
                                <label for="password" class="col-sm-3 form-label">
                                    <strong><?php echo htmlspecialchars(t('login.password_label'), ENT_QUOTES, 'UTF-8'); ?></strong><sup>*</sup>
                                </label>
                                <div class="col-sm-6">
                                    <input type="password" class="form-control" name="password" id="password" required>
                                </div>
                            </div>

                            <!-- Confirm Password -->
                            <div class="form-group">
                                <label for="password_match" class="col-sm-3 form-label">
                                    <strong><?php echo htmlspecialchars(t('manage.users.new.confirm_password_label'), ENT_QUOTES, 'UTF-8'); ?></strong><sup>*</sup>
                                </label>
                                <div class="col-sm-6">
                                    <input type="password" class="form-control" name="password_match" id="password_match" required>
                                </div>
                            </div>
                        </div>

                        <!-- Send password set link option -->
                        <?php if ($EMAIL_SENDING_ENABLED === true) : ?>
                        <div class="form-group">
                            <label class="col-sm-3 form-label"></label>
                            <div class="col-sm-6">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" id="send_password_set_link" name="send_password_set_link" <?php echo (isset($_POST['send_password_set_link']) && $_POST['send_password_set_link'] === 'on') ? 'checked' : ''; ?> <?php echo !is_password_reset_link_enabled() ? 'disabled' : ''; ?>>
                                        <?php echo htmlspecialchars(t('manage.org_users.email_reset_checkbox'), ENT_QUOTES, 'UTF-8'); ?>
                                    </label>
                                    <?php if (!is_password_reset_link_enabled()) : ?>
                                        <div class="alert alert-warning mt-2 mb-0 py-2">
                                            <?php echo htmlspecialchars(t('manage.users.new.error.password_set_link_disabled_secret_missing'), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Submit Buttons -->
                        <div class="form-group">
                            <div class="col-sm-6 offset-sm-3">
                                <button type="submit" name="create_org_user" value="1" class="btn btn-success"><?php echo htmlspecialchars(t('manage.org_users.add.create_user_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
                                <?php if ($org_uuid) : ?>
                                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode((string) $org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('modal.cancel'), ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php print get_asset_base(); ?>js/password_utils.js"></script>
<script src="<?php print get_asset_base(); ?>js/form-sync.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var passwordConfig = <?php echo get_password_strength_config_js(); ?>;
        if (typeof initializePasswordStrength === 'function') {
            initializePasswordStrength({
                passwordFieldId: 'password',
                confirmFieldId: 'password_match',
                config: passwordConfig
            });
        }
        if (typeof initFormSync === 'function') {
            initFormSync({
                givenNameId: 'givenName',
                snId: 'sn',
                cnId: 'cn',
                emailId: 'mail',
                accountAttributeId: '<?php echo $LDAP["account_attribute"]; ?>'
            });
        }

        var checkbox = document.getElementById('send_password_set_link');
        var passwordFields = document.getElementById('password_fields');
        var passwordInput = document.getElementById('password');
        var confirmInput = document.getElementById('password_match');

        function togglePasswordFields() {
            if (!checkbox || !passwordFields || !passwordInput || !confirmInput) {
                return;
            }
            var useLink = checkbox.checked;
            passwordFields.style.display = useLink ? 'none' : '';
            passwordInput.required = !useLink;
            confirmInput.required = !useLink;
            if (useLink) {
                passwordInput.value = '';
                confirmInput.value = '';
            }
        }

        if (checkbox) {
            <?php if (!is_password_reset_link_enabled()) : ?>
            checkbox.disabled = true;
            <?php endif; ?>
            checkbox.addEventListener('change', togglePasswordFields);
            togglePasswordFields();
        }
    });
</script>

<?php render_footer(); ?>
