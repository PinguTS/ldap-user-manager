<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage(['ldap', 'organization', 'user', 'mail', 'password_reset']);

// Ensure CSRF token is generated early
getCsrfToken();

$message = '';
$message_type = '';

$res = resolve_organization_from_request();
if ($res['error'] !== null) {
    renderHeader(t('manage.common.org_users_title'));
    echo "<div class='alert alert-warning'>" . htmlspecialchars($res['error']) . "</div>";
    renderFooter();
    exit;
}
$orgName = $res['org_name'] ?? '';
$org_uuid = $res['org_uuid'] ?? '';

// Access control: only admins, maintainers, or org managers for this org
setPageAccess(["admin", "maintainer", "org_admin"]);
$orgs = listOrganizations();
if (!is_array($orgs)) {
    $orgs = [];
}

// Validate orgName
$orgExists = false;
$orgDisplay = '';
foreach ($orgs as $org) {
    // Extract organization name from DN or use 'o' attribute
    $current_org_name = '';
    if (isset($org['o']) && !empty($org['o'])) {
        // If 'o' is a DN, extract just the organization name
        if (strpos($org['o'], ',') !== false) {
            // Extract the organization name from DN like "o=OrgName,ou=organizations,dc=example,dc=com"
            $current_org_name = preg_replace('/^o=([^,]+).*$/', '$1', $org['o']);
        } else {
            $current_org_name = $org['o'];
        }
    } elseif (isset($org['dn']) && !empty($org['dn'])) {
        // Extract from DN attribute
        $current_org_name = preg_replace('/^o=([^,]+).*$/', '$1', $org['dn']);
    }

    if (strtolower($current_org_name) === strtolower($orgName)) {
        $orgExists = true;
        $orgDisplay = $current_org_name;
        break;
    }
}

if (!$orgName || !$orgExists) {
    renderHeader(t('manage.common.org_users_title'));
    render_submenu();
    echo "<div class='alert alert-warning'>" . htmlspecialchars(t('manage.common.valid_org_prompt'), ENT_QUOTES, 'UTF-8') . "</div>";
    echo '<ul>';
    foreach ($orgs as $org) {
        // Extract organization name from DN or use 'o' attribute
        $orgNameVal = '';
        if (isset($org['o']) && !empty($org['o'])) {
            // If 'o' is a DN, extract just the organization name
            if (strpos($org['o'], ',') !== false) {
                // Extract the organization name from DN like "o=OrgName,ou=organizations,dc=example,dc=com"
                $orgNameVal = preg_replace('/^o=([^,]+).*$/', '$1', $org['o']);
            } else {
                $orgNameVal = $org['o'];
            }
        } elseif (isset($org['dn']) && !empty($org['dn'])) {
            // Extract from DN attribute
            $orgNameVal = preg_replace('/^o=([^,]+).*$/', '$1', $org['dn']);
        }

        if ($orgNameVal === '') {
            continue;
        }
        // Get UUID for this organization if available
        $org_uuid_val = '';
        if (isset($org['entryUUID']) && !empty($org['entryUUID'])) {
            $org_uuid_val = $org['entryUUID'];
        }

        $link_param = $org_uuid_val ? 'uuid=' . urlencode($org_uuid_val) : 'org=' . urlencode($orgNameVal);
        echo '<li><a href="org_users.php?' . $link_param . '">' . htmlspecialchars($orgNameVal) . '</a></li>';
    }
    echo '</ul>';
    renderFooter();
    exit;
}

// One-shot flash after password-reset PRG (GET only so POST handlers keep their messages)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_SESSION['manage_org_users_reset_flash']) && is_array($_SESSION['manage_org_users_reset_flash'])) {
    $orgUsersResetFlash = $_SESSION['manage_org_users_reset_flash'];
    unset($_SESSION['manage_org_users_reset_flash']);
    $ftype = $orgUsersResetFlash['type'] ?? '';
    $fmsg = $orgUsersResetFlash['message'] ?? '';
    $allowedFlashTypes = ['success', 'danger', 'warning', 'info'];
    if (is_string($ftype) && is_string($fmsg) && in_array($ftype, $allowedFlashTypes, true) && $fmsg !== '') {
        $message_type = $ftype;
        $message = $fmsg;
    }
}

// Handle org manager role toggle
if (isset($_GET['toggle_manager']) && isset($_GET['uid'])) {
    $toggleUserParam = $_GET['uid'];

    $userEntry = org_resolve_user_entry($orgName, (string) $toggleUserParam);
    if ($userEntry === null) {
        $message = t('manage.users.msg.user_not_found');
        $message_type = 'danger';
        goto after_toggle_manager;
    }
    $userDn = (string) $userEntry['dn'];
    $toggleUserDisplay = get_ldap_attribute($userEntry, 'uid') !== '' ? get_ldap_attribute($userEntry, 'uid') : (string) $toggleUserParam;

    $orgManagerDns = org_get_manager_dns($orgName);
    $ldap = open_ldap_connection();
    if ($ldap === false) {
        $message = t('manage.orgs.msg.ldap_fail');
        $message_type = 'danger';
        goto after_toggle_manager;
    }
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $orgAdminsDn = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRDN}," . $LDAP['org_dn'];

    // First, ensure the roles directory exists
    $rolesDN = "ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    $rolesDirExists = @ldap_read($ldap, $rolesDN, '(objectClass=*)', ['dn']);
    if (!$rolesDirExists) {
        // Create the ou=roles directory under the organization
        $rolesDirEntry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou' => 'roles',
            'description' => 'Roles for organization ' . $orgName
        ];

        $createRolesDir = @ldap_add($ldap, $rolesDN, $rolesDirEntry);
        if (!$createRolesDir) {
            $ldap_err = ldap_error($ldap);
            error_log("Failed to create roles directory at DN: $rolesDN -- LDAP error: $ldap_err");
            $message = t('manage.org_users.msg.roles_directory_create_fail', ['error' => $ldap_err]);
            $message_type = 'danger';
            ldap_close($ldap);
            goto after_toggle_manager;
        }
    }

    // Ensure OrgAdmins group exists
    $orgAdmins_search = @ldap_read($ldap, $orgAdminsDn, '(objectClass=groupOfNames)', ['cn']);
    $orgAdmins_exists = false;
    if ($orgAdmins_search) {
        $orgAdmins_entries = ldap_get_entries($ldap, $orgAdmins_search);
        if ($orgAdmins_entries && $orgAdmins_entries['count'] > 0) {
            $orgAdmins_exists = true;
        }
    }
    if (!$orgAdmins_exists) {
        global $USER_DN;
        $orgAdminsGroup = [
            'objectClass' => ['top', 'groupOfNames'],
            'cn' => $LDAP['org_admin_role'],
            'member' => [$USER_DN]
        ];
        $orgAdmins_create = @ldap_add($ldap, $orgAdminsDn, $orgAdminsGroup);
        if (!$orgAdmins_create) {
            $ldap_err = ldap_error($ldap);
            error_log("Failed to create OrgAdmins group at DN: $orgAdminsDn -- LDAP error: $ldap_err");
            $message = t('manage.org_users.msg.org_admin_group_create_fail', ['error' => $ldap_err]);
            $message_type = 'danger';
            ldap_close($ldap);
            goto after_toggle_manager;
        }
        // Refresh orgManagerDns after creation
        $orgManagerDns = org_get_manager_dns($orgName);
    }
    try {
        if (org_dn_in_list($userDn, $orgManagerDns)) {
            $result = removeUserFromOrgAdmin($orgName, $userDn);
            if ($result[0]) {
                $message = t('manage.org_users.msg.removed_org_manager', ['user' => $toggleUserDisplay]);
                $message_type = 'warning';
            } else {
                $message = t('manage.org_users.msg.update_org_manager_fail', ['error' => $result[1]]);
                $message_type = 'danger';
            }
        } else {
            addUserToOrgAdmin($orgName, $userDn);
            $message = t('manage.org_users.msg.assigned_org_manager', ['user' => $toggleUserDisplay]);
            $message_type = 'success';
        }
    } catch (Exception $e) {
        $message = t('manage.org_users.msg.update_org_manager_fail', ['error' => $e->getMessage()]);
        $message_type = 'danger';
    }
    after_toggle_manager:
}

// Handle user disable/enable
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['disable_user']) && currentUserCanDisableUser($_POST['disable_user'])) {
        $user_identifier = trim($_POST['disable_user']);
        $resolved = org_resolve_user_entry($orgName, $user_identifier);
        if ($resolved === null) {
            $message = t('manage.users.msg.user_not_found');
            $message_type = 'danger';
            goto after_disable_user;
        }
        $user_dn = (string) $resolved['dn'];
        $user_display = get_ldap_attribute($resolved, 'uid') !== '' ? get_ldap_attribute($resolved, 'uid') : $user_identifier;
        $ldap_connection = open_ldap_connection();
        if ($ldap_connection === false) {
            $message = t('manage.orgs.msg.ldap_fail');
            $message_type = 'danger';
            goto after_disable_user;
        }

        if (ldap_disable_user_account($ldap_connection, $user_dn)) {
            $message = t('manage.users.msg.deactivate_ok', ['user' => $user_display]);
            $message_type = 'success';
        } else {
            $ldap_error = ldap_error($ldap_connection);
            $message = t('manage.users.msg.deactivate_fail', ['user' => $user_display, 'error' => $ldap_error]);
            $message_type = 'danger';
        }
        ldap_close($ldap_connection);
        after_disable_user:
    } elseif (isset($_POST['enable_user']) && currentUserCanEnableUser($_POST['enable_user'])) {
        $user_identifier = trim($_POST['enable_user']);
        $resolved = org_resolve_user_entry($orgName, $user_identifier);
        if ($resolved === null) {
            $message = t('manage.users.msg.user_not_found');
            $message_type = 'danger';
            goto after_enable_user;
        }
        $user_dn = (string) $resolved['dn'];
        $user_display = get_ldap_attribute($resolved, 'uid') !== '' ? get_ldap_attribute($resolved, 'uid') : $user_identifier;
        $ldap_connection = open_ldap_connection();
        if ($ldap_connection === false) {
            $message = t('manage.orgs.msg.ldap_fail');
            $message_type = 'danger';
            goto after_enable_user;
        }

        if (ldap_enable_user_account($ldap_connection, $user_dn)) {
            $message = t('manage.users.msg.activate_ok', ['user' => $user_display]);
            $message_type = 'success';
        } else {
            $ldap_error = ldap_error($ldap_connection);
            $message = t('manage.users.msg.activate_fail', ['user' => $user_display, 'error' => $ldap_error]);
            $message_type = 'danger';
        }
        ldap_close($ldap_connection);
        after_enable_user:
    } elseif (isset($_POST['delete_user']) && currentUserCanDisableUser($_POST['delete_user'])) {
        $user_identifier = trim($_POST['delete_user']);
        $resolved = org_resolve_user_entry($orgName, $user_identifier);
        if ($resolved === null) {
            $message = t('manage.users.msg.user_not_found');
            $message_type = 'danger';
            goto after_delete_user;
        }
        $user_dn = (string) $resolved['dn'];
        $user_display = get_ldap_attribute($resolved, 'uid') !== '' ? get_ldap_attribute($resolved, 'uid') : $user_identifier;
        $ldap_connection = open_ldap_connection();
        if ($ldap_connection === false) {
            $message = t('manage.orgs.msg.ldap_fail');
            $message_type = 'danger';
            goto after_delete_user;
        }

        $group_cleanup_success = ldap_remove_user_from_all_groups($ldap_connection, $user_dn);
        if (!$group_cleanup_success) {
            error_log('Warning: Failed to remove user from some groups before deletion (POST)');
        }
        if (ldap_delete($ldap_connection, $user_dn)) {
            $message = t('manage.users.msg.delete_ok', ['user' => $user_display]);
            $message_type = 'success';
        } else {
            $ldap_error = ldap_error($ldap_connection);
            $message = t('manage.org_users.msg.delete_fail_ldap', ['error' => $ldap_error]);
            $message_type = 'danger';
        }
        ldap_close($ldap_connection);
    }
}

// Redirect success flag handling (used after POST/Redirect/GET)
if (isset($_GET['updated']) && (string) $_GET['updated'] === '1') {
    $message = t('manage.users.msg.profile_update_ok');
    $message_type = 'success';
}
if (isset($_GET['credentials_reset']) && (string) $_GET['credentials_reset'] === '1') {
    $message = t('manage.org_users.msg.credentials_reset_ok');
    $message_type = 'success';
}
if (isset($_GET['reset_link_sent']) && (string) $_GET['reset_link_sent'] === '1') {
    $emailParam = isset($_GET['email']) ? (string) $_GET['email'] : '';
    if ($emailParam !== '' && strlen($emailParam) <= 254 && filter_var($emailParam, FILTER_VALIDATE_EMAIL)) {
        $message = t('manage.org_users.msg.reset_link_sent', ['email' => htmlspecialchars($emailParam, ENT_QUOTES, 'UTF-8')]);
    } else {
        $message = t('manage.org_users.msg.reset_link_sent_ok');
    }
    $message_type = 'success';
}

// Handle add user form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        validateCsrfToken();
    } catch (Exception $e) {
        $message = t('manage.common.msg.security_validation_failed');
        $message_type = 'danger';
        goto after_add_user;
    }
    $givenName = trim($_POST['givenName']);
    $sn = trim($_POST['sn']);
    $mail = trim($_POST['mail']);
    $cn = trim($_POST['cn']);
    $password = (string) ($_POST['password'] ?? '');
    $passwordMatch = (string) ($_POST['password_match'] ?? '');
    $sendPasswordSetLink = isset($_POST['send_password_set_link']) && $_POST['send_password_set_link'] === 'on';
    if ($sendPasswordSetLink && !is_password_reset_link_enabled()) {
        $sendPasswordSetLink = false;
    }
    $passScore = isset($_POST['pass_score']) && is_numeric($_POST['pass_score']) ? (int) $_POST['pass_score'] : null;

    // Use email as username since that's how the system is configured
    $uid = $mail;

    if ($givenName === '' || $sn === '' || $cn === '' || $mail === '' || (!$sendPasswordSetLink && $password === '')) {
        $message = t('manage.org_users.msg.all_fields_required');
        $message_type = 'danger';
        goto after_add_user;
    }
    // Duplicate user check
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    $userDn = "uid=" . ldap_escape($uid, '', LDAP_ESCAPE_DN) . "," . $usersDn;
    $ldap = open_ldap_connection();

    // Ensure the users directory exists before trying to add a user
    $usersDirExists = @ldap_read($ldap, $usersDn, '(objectClass=*)', ['dn']);
    if (!$usersDirExists) {
        // Create the ou=people directory under the organization
        $usersDirEntry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou' => 'people',
            'description' => 'Users for organization ' . $orgName
        ];

        $createUsersDir = @ldap_add($ldap, $usersDn, $usersDirEntry);
        if (!$createUsersDir) {
            $ldap_err = ldap_error($ldap);
            error_log("Failed to create users directory at DN: $usersDn -- LDAP error: $ldap_err");
            $message = t('manage.org_users.msg.users_directory_create_fail', ['error' => $ldap_err]);
            $message_type = 'danger';
            ldap_close($ldap);
            goto after_add_user;
        }
    }

    $search = @ldap_search($ldap, $usersDn, "(uid=" . ldap_escape($uid, '', LDAP_ESCAPE_FILTER) . ")");
    $entries = $search ? ldap_get_entries($ldap, $search) : false;
    if ($entries && $entries['count'] > 0) {
        $message = t('manage.org_users.msg.email_exists_in_org');
        $message_type = 'danger';
        ldap_close($ldap);
        goto after_add_user;
    }

    // Create user entry
    if ($sendPasswordSetLink) {
        // Random temporary password; user will set their own via emailed link.
        $password = bin2hex(random_bytes(16));
    } else {
        $validation = validate_password_submission($password, $passwordMatch, $passScore);
        if (!$validation['ok']) {
            $message = implode(' ', $validation['errors']);
            $message_type = 'danger';
            ldap_close($ldap);
            goto after_add_user;
        }
    }

    $entry = array(
        'objectClass' => array('top', 'inetOrgPerson', 'organizationalPerson', 'person'),
        'uid' => $uid,
        'cn' => $cn,
        'sn' => $sn,
        'givenName' => $givenName,
        'mail' => $mail,
        'userPassword' => ldap_hashed_password($password), // Use consistent LDAP hashing
        'account_attribute' => $LDAP['account_attribute']
    );
    $add_result = @ldap_add($ldap, $userDn, $entry);
    if ($add_result) {
        $message = t('manage.org_users.msg.added_ok');
        $message_type = 'success';

        global $EMAIL_SENDING_ENABLED;
        if (($EMAIL_SENDING_ENABLED ?? false) === true && isValidEmail($mail)) {
            $emailLocale = lum_resolve_transactional_email_locale_for_new_org_user((string) $orgName, (string) ($LDAP['user_role'] ?? 'user'));
            $sentOk = lum_with_transactional_email_locale($emailLocale, function () use (
                $sendPasswordSetLink,
                $mail,
                $givenName,
                $sn
            ): bool {
                if ($sendPasswordSetLink) {
                    $payload = build_password_action_payload($mail, 'set');
                    $token = create_password_action_token($payload);
                    $setUrl = build_password_action_url($token);
                    $vars = array_merge(lum_password_action_token_expiry_mail_vars(), [
                        'login' => $mail,
                        'first_name' => $givenName,
                        'last_name' => $sn,
                        'password_set_url' => $setUrl,
                    ]);
                    $parsedAccount = lum_load_parsed_combined_transactional_template('new_account.html');
                    $subject = parse_mail_template((string) $parsedAccount['subject'], $vars);
                    $body = parse_mail_template((string) $parsedAccount['body'], $vars);

                    return send_email($mail, trim($givenName . ' ' . $sn), $subject, $body);
                }

                return lum_send_account_welcome_email(
                    $mail,
                    trim($givenName . ' ' . $sn),
                    [
                        'login' => $mail,
                        'first_name' => $givenName,
                        'last_name' => $sn,
                    ]
                );
            });
            if ($sentOk) {
                $message .= ' ' . t('manage.org_users.add.msg.email_sent_ok', ['email' => $mail]);
            } else {
                $message .= ' ' . t('manage.org_users.add.msg.email_send_failed');
            }
        }
    } else {
        $ldap_err = ldap_error($ldap);
        $message = t('manage.org_users.msg.add_fail', ['error' => $ldap_err]);
        $message_type = 'danger';
    }

    ldap_close($ldap);
}
after_add_user:

// Handle delete user
if (isset($_GET['delete_user'])) {
    $deleteUserParam = $_GET['delete_user'];
    if (!currentUserCanDisableUser($deleteUserParam)) {
        $message = t('manage.users.msg.permission_denied_invalid_user');
        $message_type = 'danger';
        goto after_delete_user;
    }

    $resolvedDelete = org_resolve_user_entry($orgName, (string) $deleteUserParam);
    if ($resolvedDelete === null) {
        $message = t('manage.users.msg.user_not_found');
        $message_type = 'danger';
        goto after_delete_user;
    }
    $userDn = (string) $resolvedDelete['dn'];
    $deleteUserDisplay = get_ldap_attribute($resolvedDelete, 'uid') !== '' ? get_ldap_attribute($resolvedDelete, 'uid') : (string) $deleteUserParam;

    $ldap = open_ldap_connection();
    if ($ldap === false) {
        $message = t('manage.orgs.msg.ldap_fail');
        $message_type = 'danger';
        goto after_delete_user;
    }

    $group_cleanup_success = ldap_remove_user_from_all_groups($ldap, $userDn);
    if (!$group_cleanup_success) {
        error_log("Warning: Failed to remove user from some groups before deletion");
    }

    try {
        ldap_delete($ldap, $userDn);
        $message = t('manage.users.msg.delete_ok', ['user' => $deleteUserDisplay]);
        $message_type = 'warning';
    } catch (Exception $e) {
        $message = t('manage.org_users.msg.delete_fail_exception', ['error' => $e->getMessage()]);
        $message_type = 'danger';
    }
    ldap_close($ldap);
}
after_delete_user:

// Handle edit user
$editUser = null;
if (isset($_GET['edit_user'])) {
    $editUserParam = $_GET['edit_user'];
    $editUser = org_resolve_user_entry($orgName, (string) $editUserParam);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateCsrfToken();
    }
    $uid = trim($_POST['edit_uid']);
    $givenName = trim($_POST['edit_givenname']);
    $sn = trim($_POST['edit_sn']);
    $cn = trim($_POST['edit_cn'] ?? '');
    $mail = trim($_POST['edit_mail']);
    $password = (string) ($_POST['edit_password'] ?? '');
    $passwordMatch = (string) ($_POST['edit_password_match'] ?? '');
    $passScore = isset($_POST['edit_pass_score']) && is_numeric($_POST['edit_pass_score']) ? (int) $_POST['edit_pass_score'] : null;

    $userDn = org_resolve_user_dn($orgName, $uid);
    if ($userDn === null || $userDn === '') {
        $message = t('manage.users.msg.user_not_found');
        $message_type = 'danger';
        goto after_edit_user;
    }

    $ldap = open_ldap_connection();
    if ($ldap === false) {
        $message = t('manage.orgs.msg.ldap_fail');
        $message_type = 'danger';
        goto after_edit_user;
    }
    $entry = [
        'givenname' => $givenName,
        'sn' => $sn,
        'cn' => ($cn !== '' ? $cn : trim($givenName . ' ' . $sn)),
        'mail' => $mail
    ];
    if ($password !== '') {
        $validation = validate_password_submission($password, $passwordMatch, $passScore);
        if (!$validation['ok']) {
            $message = implode(' ', $validation['errors']);
            $message_type = 'danger';
            ldap_close($ldap);
            goto after_edit_user;
        }
        $entry['userPassword'] = ldap_hashed_password($password);
    }
    try {
        ldap_modify($ldap, (string) $userDn, $entry);
        $baseParam = $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName);
        header('Location: ?' . $baseParam . '&updated=1');
        exit;
    } catch (Exception $e) {
        $message = t('manage.users.msg.profile_update_fail') . ' ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $message_type = 'danger';
    }
    ldap_close($ldap);
}
after_edit_user:

// Handle reset password (PRG: always redirect; success via query string, failure/warning via session flash)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_creds'])) {
    validateCsrfToken();
    $baseParam = $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName);

    $resetUserParam = $_POST['reset_uid'] ?? '';
    $resetTargetDn = org_resolve_user_dn($orgName, (string) $resetUserParam);
    if ($resetTargetDn === null || $resetTargetDn === '') {
        $_SESSION['manage_org_users_reset_flash'] = [
            'type' => 'danger',
            'message' => t('manage.users.msg.user_not_found'),
        ];
        header('Location: ?' . $baseParam);
        exit;
    }
    $resetDn = (string) $resetTargetDn;

    $new_password = (string) ($_POST['reset_password'] ?? '');
    $new_password_match = (string) ($_POST['reset_password_match'] ?? '');
    $send_reset_link = isset($_POST['send_password_reset_link']) && $_POST['send_password_reset_link'] === 'on';
    if ($send_reset_link && !is_password_reset_link_enabled()) {
        $send_reset_link = false;
    }
    $passScore = isset($_POST['pass_score']) && is_numeric($_POST['pass_score']) ? (int) $_POST['pass_score'] : null;

    $ldap = open_ldap_connection();
    if ($ldap === false) {
        $_SESSION['manage_org_users_reset_flash'] = [
            'type' => 'danger',
            'message' => t('manage.orgs.msg.ldap_fail'),
        ];
        header('Location: ?' . $baseParam);
        exit;
    }

    if ($send_reset_link) {
        global $EMAIL_SENDING_ENABLED;
        if (($EMAIL_SENDING_ENABLED ?? false) !== true) {
            ldap_close($ldap);
            $_SESSION['manage_org_users_reset_flash'] = [
                'type' => 'warning',
                'message' => t('manage.password_reset_admin.msg.unavailable'),
            ];
            header('Location: ?' . $baseParam);
            exit;
        }
        $sendResult = send_password_reset_email_for_user_dn($ldap, $resetDn, 'admin');
        ldap_close($ldap);
        if ($sendResult['ok']) {
            $sentTo = (string) ($sendResult['email'] ?? '');
            header('Location: ?' . $baseParam . '&reset_link_sent=1&email=' . rawurlencode($sentTo));
            exit;
        }
        $reason = $sendResult['reason'] ?? '';
        if ($reason === 'no_valid_email') {
            $flashMsg = t('manage.org_users.msg.reset_link_no_valid_email');
        } elseif ($reason === 'send_failed') {
            $flashMsg = t('manage.org_users.msg.reset_link_smtp_failed');
        } else {
            $flashMsg = t('manage.password_reset_admin.msg.unavailable');
        }
        $_SESSION['manage_org_users_reset_flash'] = [
            'type' => 'danger',
            'message' => $flashMsg,
        ];
        header('Location: ?' . $baseParam);
        exit;
    }

    $validation = validate_password_submission($new_password, $new_password_match, $passScore);
    if (!$validation['ok']) {
        ldap_close($ldap);
        $_SESSION['manage_org_users_reset_flash'] = [
            'type' => 'danger',
            'message' => implode(' ', $validation['errors']),
        ];
        header('Location: ?' . $baseParam);
        exit;
    }
    $entry = ['userPassword' => ldap_hashed_password($new_password)];
    try {
        ldap_modify($ldap, $resetDn, $entry);
        ldap_close($ldap);
        header('Location: ?' . $baseParam . '&credentials_reset=1');
        exit;
    } catch (Exception $e) {
        ldap_close($ldap);
        $_SESSION['manage_org_users_reset_flash'] = [
            'type' => 'danger',
            'message' => t('manage.org_users.msg.credentials_reset_fail', ['error' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]),
        ];
        header('Location: ?' . $baseParam);
        exit;
    }
}

$users = getOrganizationUsers($orgName);
if (!is_array($users)) {
    $users = [];
}
$orgManagerDns = org_get_manager_dns($orgName);

// Open LDAP connection for display and operations
$ldap_connection = open_ldap_connection();
if ($ldap_connection === false) {
    $message = ($message ?? '') !== '' ? $message : t('manage.orgs.msg.ldap_fail');
    $message_type = 'danger';
}

renderHeader(t('manage.org_users.page_title', ['org' => $orgDisplay]));
render_submenu();
?>
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.dashboard'), ENT_QUOTES, 'UTF-8'); ?></a></li>
            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.organizations'), ENT_QUOTES, 'UTF-8'); ?></a></li>
            <?php if ($org_uuid) : ?>
                <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/', ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($orgDisplay) ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars(t('manage.common.users'), ENT_QUOTES, 'UTF-8'); ?></li>
        </ol>
    </nav>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><?= htmlspecialchars(t('manage.common.users_in_org', ['org' => $orgDisplay]), ENT_QUOTES, 'UTF-8') ?></h2>
        <div>
            <?php if ($org_uuid) : ?>
                <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary mb-3">&larr; <?php echo htmlspecialchars(t('manage.common.back_to_org'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($message) : ?>
        <div class="alert alert-<?= $message_type ?>" id="msgbox"> <?= $message ?> </div>
    <?php endif; ?>
    <?php
    $is_disabled_org = false;
    if ($ldap_connection !== false) {
        $is_disabled_org = ldap_organization_is_disabled($ldap_connection, $orgName);
    }
    ?>
    <?php if ($is_disabled_org) : ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars(t('manage.org_users.org_disabled_banner'), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    <input class="form-control mb-2" id="user_search_input" type="text" placeholder="<?php echo htmlspecialchars(t('manage.common.placeholder_search_users'), ENT_QUOTES, 'UTF-8'); ?>">
    <table class="table table-bordered" id="user_table">
        <thead>
            <tr>
                <th><?php echo htmlspecialchars(t('manage.common.username_email'), ENT_QUOTES, 'UTF-8'); ?></th>
                <th><?php echo htmlspecialchars(t('manage.common.full_name'), ENT_QUOTES, 'UTF-8'); ?></th>
                <th><?php echo htmlspecialchars(t('manage.common.email'), ENT_QUOTES, 'UTF-8'); ?></th>
                <th class="text-center"><?php echo htmlspecialchars(t('manage.common.status'), ENT_QUOTES, 'UTF-8'); ?></th>
                <th class="text-center"><?php echo htmlspecialchars(t('manage.common.manager'), ENT_QUOTES, 'UTF-8'); ?></th>
                <th><?php echo htmlspecialchars(t('manage.common.actions'), ENT_QUOTES, 'UTF-8'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            $orgManagerCount = org_get_scoped_manager_count($orgName, $orgManagerDns);
            ?>
            <?php foreach ($users as $user) :
                // Use robust UUID extraction for user actions
                $user_uuid = get_user_uuid($user);
                $user_identifier = $user_uuid !== '' ? $user_uuid : get_ldap_attribute($user, 'uid');
                $user_dn = $user['dn'] ?? org_resolve_user_dn($orgName, get_ldap_attribute($user, 'uid')) ?? '';
                $isManager = $user_dn !== '' && org_dn_in_list($user_dn, $orgManagerDns);
                $isLastManager = $isManager && $orgManagerCount <= 1;
                ?>
                <tr<?= $isManager ? ' class="table-success"' : '' ?>>
                    <td><?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?></td>
                    <td><?= safeDisplayName($user, 'cn', 'givenName', 'sn') ?></td>
                    <td><?= htmlspecialchars(get_ldap_attribute($user, 'mail')) ?></td>
                    <td class="text-center">
                        <?php
                        if ($ldap_connection !== false && isset($user['dn']) && is_string($user['dn'])) {
                            renderOrgUsersTableStatusCell($ldap_connection, $user['dn'], $is_disabled_org, $user_identifier);
                        } elseif ($is_disabled_org) {
                            echo '<span class="badge bg-danger" title="' . htmlspecialchars(t('manage.org_users.deactivate_reason.org'), ENT_QUOTES, 'UTF-8') . '">'
                                . htmlspecialchars(t('manage.common.inactive'), ENT_QUOTES, 'UTF-8') . '</span>';
                        }
                        ?>
                    </td>
                    <td class="text-center">
                        <?php renderOrgUsersPageManagerToggle($user_identifier, $isManager, $isLastManager, $org_uuid, $orgName); ?>
                    </td>
                    <td>
                        <?php renderOrgUsersPageActionCell($user_identifier, $user, $isManager, $org_uuid, $orgName); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="mt-3">
        <?php if ($org_uuid) : ?>
            <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/users/new/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success btn-sm ml-2"><?php echo htmlspecialchars(t('manage.common.create_new_user'), ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endif; ?>
    </div>
    
    <h4><?php echo htmlspecialchars(t('manage.common.quick_add_user'), ENT_QUOTES, 'UTF-8'); ?></h4>
    <p class="text-muted"><?php echo htmlspecialchars(t('manage.common.quick_add_lead'), ENT_QUOTES, 'UTF-8'); ?></p>
    <form method="post" class="mb-4" id="add_user_form" onsubmit="return validateAddUserForm();">
        <?= csrfTokenField() ?>
        <div class="form-group">
            <label for="givenName"><?php echo htmlspecialchars(t('manage.common.first_name'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="text" class="form-control" name="givenName" id="givenName" required>
        </div>
        <div class="form-group">
            <label for="sn"><?php echo htmlspecialchars(t('manage.common.last_name'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="text" class="form-control" name="sn" id="sn" required>
        </div>
        <div class="form-group">
            <label for="cn"><?php echo htmlspecialchars(t('manage.common.display_name'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="text" class="form-control" name="cn" id="cn" required>
            <small class="text-muted"><?php echo htmlspecialchars(t('manage.common.display_name_hint'), ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
        <div class="form-group">
            <label for="mail"><?php echo htmlspecialchars(t('manage.common.email_username'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="email" class="form-control" name="mail" id="mail" required>
            <small class="text-muted"><?php echo htmlspecialchars(t('manage.common.email_username_hint'), ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
        
        <!-- Hidden fields for auto-generated values -->
        <input type="hidden" name="<?php echo $LDAP['account_attribute']; ?>" id="<?php echo $LDAP['account_attribute']; ?>" value="">
        
        <div class="form-group">
            <label for="password"><?php echo htmlspecialchars(t('manage.common.new_password_label'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="password" class="form-control" name="password" id="password" required>
        </div>
        <div class="form-group">
            <label for="password_match"><?php echo htmlspecialchars(t('manage.common.confirm_new_password'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="password" class="form-control" name="password_match" id="password_match" required>
        </div>
        <input type="hidden" id="pass_score" value="0" name="pass_score">

        <?php global $EMAIL_SENDING_ENABLED; ?>
        <?php if ($EMAIL_SENDING_ENABLED === true) : ?>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="send_password_set_link" name="send_password_set_link" <?php echo !is_password_reset_link_enabled() ? 'disabled' : ''; ?>>
                <label class="form-check-label" for="send_password_set_link">
                    <?php echo htmlspecialchars(t('manage.org_users.email_invite_link_checkbox'), ENT_QUOTES, 'UTF-8'); ?>
                </label>
                <?php if (!is_password_reset_link_enabled()) : ?>
                    <div class="alert alert-warning mt-2 mb-0 py-2">
                        <?php echo htmlspecialchars(t('manage.users.new.error.password_set_link_disabled_secret_missing'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <p class="text-muted small mt-2 mb-0"><?php echo htmlspecialchars(t('manage.org_users.email_after_create_note'), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>
        <input type="hidden" name="add_user" value="1">
        <button type="submit" name="add_user" class="btn btn-primary" id="add_user_btn"><?php echo htmlspecialchars(t('manage.common.add_user'), ENT_QUOTES, 'UTF-8'); ?></button>
        <span id="add_user_spinner" style="display:none;"><span class="spinner-border spinner-border-sm"></span> <?php echo htmlspecialchars(t('manage.common.adding'), ENT_QUOTES, 'UTF-8'); ?></span>
    </form>

    <script src="<?php print getAssetBase(); ?>js/password_utils.js"></script>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function(){
        const passwordConfig = <?php echo getPasswordStrengthConfigJs(); ?>;
        if (typeof initializePasswordStrength === 'function') {
            initializePasswordStrength({
                passwordFieldId: 'password',
                confirmFieldId: 'password_match',
                config: passwordConfig
            });
        }

        const checkbox = document.getElementById('send_password_set_link');
        const pw = document.getElementById('password');
        const pw2 = document.getElementById('password_match');
        function togglePw() {
            if (!checkbox || !pw || !pw2) return;
            const useLink = checkbox.checked;
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
    });
    </script>

    <!-- Edit User Modal -->
    <?php if ($editUser) : ?>
    <div class="modal show" tabindex="-1" style="display:block; background:rgba(0,0,0,0.3); z-index:1050;">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form method="post" action="">
            <?= csrfTokenField() ?>
            <div class="modal-header">
              <h5 class="modal-title" id="editUserModalLabel"><?php echo htmlspecialchars(t('manage.common.edit_user'), ENT_QUOTES, 'UTF-8'); ?></h5>
              <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>" class="btn-close" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></a>
            </div>
            <div class="modal-body">
              <input type="hidden" name="edit_uid" id="edit_uid_input" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'uid')) ?>">
              <div class="form-group">
                <label for="edit_givenname"><?php echo htmlspecialchars(t('manage.common.first_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" class="form-control" name="edit_givenname" id="edit_givenname" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'givenName')) ?>" required>
              </div>
              <div class="form-group">
                <label for="edit_sn"><?php echo htmlspecialchars(t('manage.common.last_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" class="form-control" name="edit_sn" id="edit_sn" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'sn')) ?>" required>
              </div>
              <div class="form-group">
                <label for="edit_cn"><?php echo htmlspecialchars(t('manage.common.display_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" class="form-control" name="edit_cn" id="edit_cn" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'cn')) ?>" required>
                <small class="text-muted"><?php echo htmlspecialchars(t('manage.common.display_name_hint'), ENT_QUOTES, 'UTF-8'); ?></small>
              </div>
              <div class="form-group">
                <label for="edit_mail"><?php echo htmlspecialchars(t('manage.common.email'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="email" class="form-control" name="edit_mail" id="edit_mail" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'mail')) ?>" required>
              </div>
              <div class="form-group">
                <label for="edit_password"><?php echo htmlspecialchars(t('manage.common.new_password_optional'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" class="form-control" name="edit_password" id="edit_password">
              </div>
              <div class="form-group mt-2">
                <label for="edit_password_match"><?php echo htmlspecialchars(t('manage.common.confirm_new_password'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" class="form-control" name="edit_password_match" id="edit_password_match">
              </div>
              <input type="hidden" id="edit_pass_score" value="0" name="edit_pass_score">
            </div>
            <div class="modal-footer">
              <button type="submit" name="save_user" class="btn btn-primary"><?php echo htmlspecialchars(t('manage.common.save_changes'), ENT_QUOTES, 'UTF-8'); ?></button>
              <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('manage.common.cancel'), ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php else : ?>
    <div class="modal fade" id="editUserModal" tabindex="-1" role="dialog" aria-labelledby="editUserModalLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form method="post" action="">
            <?= csrfTokenField() ?>
            <div class="modal-header">
              <h5 class="modal-title" id="editUserModalLabel"><?php echo htmlspecialchars(t('manage.common.edit_user'), ENT_QUOTES, 'UTF-8'); ?></h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="edit_uid" id="edit_uid_input" value="">
              <div class="form-group">
                <label for="edit_givenname"><?php echo htmlspecialchars(t('manage.common.first_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" class="form-control" name="edit_givenname" id="edit_givenname" required>
              </div>
              <div class="form-group">
                <label for="edit_sn"><?php echo htmlspecialchars(t('manage.common.last_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" class="form-control" name="edit_sn" id="edit_sn" required>
              </div>
              <div class="form-group">
                <label for="edit_cn"><?php echo htmlspecialchars(t('manage.common.display_name'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="text" class="form-control" name="edit_cn" id="edit_cn" required>
                <small class="text-muted"><?php echo htmlspecialchars(t('manage.common.display_name_hint'), ENT_QUOTES, 'UTF-8'); ?></small>
              </div>
              <div class="form-group">
                <label for="edit_mail"><?php echo htmlspecialchars(t('manage.common.email'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="email" class="form-control" name="edit_mail" id="edit_mail" required>
              </div>
              <div class="form-group">
                <label for="edit_password"><?php echo htmlspecialchars(t('manage.common.new_password_optional'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" class="form-control" name="edit_password" id="edit_password">
              </div>
              <div class="form-group mt-2">
                <label for="edit_password_match"><?php echo htmlspecialchars(t('manage.common.confirm_new_password'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" class="form-control" name="edit_password_match" id="edit_password_match">
              </div>
              <input type="hidden" id="edit_pass_score" value="0" name="edit_pass_score">
            </div>
            <div class="modal-footer">
              <button type="submit" name="save_user" class="btn btn-primary"><?php echo htmlspecialchars(t('manage.common.save_changes'), ENT_QUOTES, 'UTF-8'); ?></button>
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo htmlspecialchars(t('manage.common.cancel'), ENT_QUOTES, 'UTF-8'); ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
    
    <?php
    renderConfirmModal(
        'deleteUserModal',
        t('manage.org_users.modal.delete_title'),
        t('manage.org_users.modal.delete_body'),
        [['name' => 'delete_user', 'id' => 'deleteUserIdentifier']],
        t('manage.org_users.modal.delete_submit'),
        'btn-danger'
    );
    ?>
    <?php if (isset($_GET['reset_user'])) :
        $resetUserParam = $_GET['reset_user'];
        $resetEntry = org_resolve_user_entry($orgName, (string) $resetUserParam);
        $resetUserDisplay = $resetEntry !== null && get_ldap_attribute($resetEntry, 'uid') !== ''
            ? get_ldap_attribute($resetEntry, 'uid')
            : (string) $resetUserParam;
        ?>
    <div class="modal show" tabindex="-1" style="display:block; background:rgba(0,0,0,0.3); z-index:1050;">
      <div class="modal-dialog">
        <div class="modal-content border-warning">
          <form method="post">
            <?= csrfTokenField() ?>
            <div class="modal-header bg-warning text-dark">
              <h5 class="modal-title"><?= htmlspecialchars(t('manage.org_users.reset_title', ['user' => $resetUserDisplay]), ENT_QUOTES, 'UTF-8') ?></h5>
              <?php if ($org_uuid) : ?>
                  <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn-close text-dark" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></a>
              <?php endif; ?>
            </div>
            <div class="modal-body">
              <input type="hidden" name="reset_uid" value="<?= htmlspecialchars($resetUserParam) ?>">
              <div class="form-group">
                <label for="reset_password"><?php echo htmlspecialchars(t('manage.org_users.reset_new_pw'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" class="form-control" name="reset_password" id="reset_password" required>
              </div>
              <div class="form-group mt-2">
                <label for="reset_password_match"><?php echo htmlspecialchars(t('manage.org_users.reset_confirm_pw'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" class="form-control" name="reset_password_match" id="reset_password_match" required>
              </div>
              <input type="hidden" id="reset_pass_score" value="0" name="pass_score">

              <?php global $EMAIL_SENDING_ENABLED; ?>
              <?php if ($EMAIL_SENDING_ENABLED === true) : ?>
                <div class="form-check mt-3">
                  <input class="form-check-input" type="checkbox" id="send_password_reset_link" name="send_password_reset_link" <?php echo !is_password_reset_link_enabled() ? 'disabled' : ''; ?>>
                  <label class="form-check-label" for="send_password_reset_link">
                    <?php echo htmlspecialchars(t('manage.org_users.email_reset_checkbox'), ENT_QUOTES, 'UTF-8'); ?>
                  </label>
                    <?php if (!is_password_reset_link_enabled()) : ?>
                        <div class="alert alert-warning mt-2 mb-0 py-2">
                            <?php echo htmlspecialchars(t('manage.users.new.error.password_set_link_disabled_secret_missing'), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="modal-footer">
              <button type="submit" name="reset_creds" class="btn btn-warning"><?php echo htmlspecialchars(t('manage.org_users.reset_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
              <?php if ($org_uuid) : ?>
                  <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('modal.cancel'), ENT_QUOTES, 'UTF-8'); ?></a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
</div>
<script src="<?php print getAssetBase(); ?>js/table-search.js"></script>
<script src="<?php print getAssetBase(); ?>js/modals.js"></script>
<script src="<?php print getAssetBase(); ?>js/form-sync.js"></script>
<script src="<?php print getAssetBase(); ?>js/password_utils.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof initializeTableSearch === 'function') {
            initializeTableSearch('user_search_input', 'user_table');
        }

        // Auto-dismiss messages after 5 seconds
        const messageBox = document.getElementById('msgbox');
        if (messageBox) {
            setTimeout(function() {
                messageBox.style.display = 'none';
            }, 5000);
        }
        
        // Form sync: display name from givenName+sn, email -> account attribute
        if (typeof initFormSync === 'function') {
            initFormSync({
                givenNameId: 'givenName',
                snId: 'sn',
                cnId: 'cn',
                emailId: 'mail',
                accountAttributeId: '<?php echo $LDAP["account_attribute"]; ?>'
            });
            initFormSync({
                givenNameId: 'edit_givenname',
                snId: 'edit_sn',
                cnId: 'edit_cn'
            });
        }

        // Edit modal + reset modal strength + optional email link toggles
        if (typeof initializePasswordStrength === 'function') {
            var passwordConfig = <?php echo getPasswordStrengthConfigJs(); ?>;
            initializePasswordStrength({
                passwordFieldId: 'edit_password',
                confirmFieldId: 'edit_password_match',
                config: passwordConfig,
                hiddenFieldId: 'edit_pass_score'
            });
            initializePasswordStrength({
                passwordFieldId: 'reset_password',
                confirmFieldId: 'reset_password_match',
                config: passwordConfig
            });
        }

        var resetCheckbox = document.getElementById('send_password_reset_link');
        var resetPw = document.getElementById('reset_password');
        var resetPw2 = document.getElementById('reset_password_match');
        function toggleResetPw() {
            if (!resetCheckbox || !resetPw || !resetPw2) return;
            var useLink = resetCheckbox.checked;
            resetPw.required = !useLink;
            resetPw2.required = !useLink;
            resetPw.disabled = useLink;
            resetPw2.disabled = useLink;
            if (useLink) {
                resetPw.value = '';
                resetPw2.value = '';
            }
        }
        if (resetCheckbox) {
            <?php if (!is_password_reset_link_enabled()) : ?>
            resetCheckbox.disabled = true;
            <?php endif; ?>
            resetCheckbox.addEventListener('change', toggleResetPw);
            toggleResetPw();
        }
    });

    function confirmDeleteUser(userIdentifier, userName) {
        confirmAction('deleteUserModal', { deleteUserName: userName, deleteUserIdentifier: userIdentifier });
    }
</script>
<?php
// Close LDAP connection
ldap_close($ldap_connection);

renderFooter();
?> 
