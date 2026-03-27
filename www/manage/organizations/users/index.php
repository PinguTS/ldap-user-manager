<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap', 'organization', 'user', 'mail', 'password_reset']);

// Ensure CSRF token is generated early
get_csrf_token();

$message = '';
$message_type = '';

$res = resolve_organization_from_request();
if ($res['error'] !== null) {
    render_header(t('manage.common.org_users_title'));
    echo "<div class='alert alert-warning'>" . htmlspecialchars($res['error']) . "</div>";
    render_footer();
    exit;
}
$orgName = $res['org_name'] ?? '';
$org_uuid = $res['org_uuid'] ?? '';

// Access control: only admins, maintainers, or org managers for this org
set_page_access(["admin", "maintainer", "org_admin"]);
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
            // Extract the organization name from DN like "o=OrgName,ou=organizations,dc=pingu,dc=info"
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
    render_header(t('manage.common.org_users_title'));
    render_submenu();
    echo "<div class='alert alert-warning'>" . htmlspecialchars(t('manage.common.valid_org_prompt'), ENT_QUOTES, 'UTF-8') . "</div>";
    echo '<ul>';
    foreach ($orgs as $org) {
        // Extract organization name from DN or use 'o' attribute
        $orgNameVal = '';
        if (isset($org['o']) && !empty($org['o'])) {
            // If 'o' is a DN, extract just the organization name
            if (strpos($org['o'], ',') !== false) {
                // Extract the organization name from DN like "o=OrgName,ou=organizations,dc=pingu,dc=info"
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
    render_footer();
    exit;
}

// Fetch users in the organization
function getUsersInOrg($orgName)
{
    global $LDAP;
    $ldap = open_ldap_connection();
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];

    // First check if the users DN exists before searching
    $dnExists = @ldap_read($ldap, $usersDn, '(objectClass=*)', ['dn']);
    if (!$dnExists) {
        // The users DN doesn't exist, which means no users have been created yet
        ldap_close($ldap);
        return [];
    }

    $filter = '(objectClass=inetOrgPerson)';
    $attributes = ['uid', 'cn', 'sn', 'mail', 'entryUUID'];
    $result = @ldap_search($ldap, $usersDn, $filter, $attributes);
    if (!$result) {
        // Log the error but don't show it to the user
        error_log("getUsersInOrg: LDAP search failed for DN: $usersDn. Error: " . ldap_error($ldap));
        ldap_close($ldap);
        return [];
    }

    $entries = ldap_get_entries($ldap, $result);
    ldap_close($ldap);
    $users = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $users[] = $entries[$i];
    }
    return $users;
}

// Helper: get DN for a user in an org
function getUserDn($orgName, $uid)
{
    global $LDAP;
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    return "uid=$uid,$usersDn";
}

// Helper: get org manager DNs
function getOrgManagerDns($orgName)
{
    global $LDAP;
    $ldap = open_ldap_connection();
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDn = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    $result = @ldap_read($ldap, $groupDn, '(objectClass=groupOfNames)', ['member']);
    if (!$result) {
        return [];
    }
    $entries = ldap_get_entries($ldap, $result);
    $dns = [];
    if ($entries['count'] > 0 && isset($entries[0]['member'])) {
        for ($i = 0; $i < $entries[0]['member']['count']; $i++) {
            $dns[] = $entries[0]['member'][$i];
        }
    }
    return $dns;
}

// Helper: DN match utility (case-insensitive)
function dnInList(string $dn, array $dns): bool
{
    foreach ($dns as $entryDn) {
        if (is_string($entryDn) && strcasecmp($dn, $entryDn) === 0) {
            return true;
        }
    }

    return false;
}

// Helper: count managers excluding placeholder entries
function getRealManagerCount(array $orgManagerDns): int
{
    return count(array_filter($orgManagerDns, function ($dn) {
        return is_string($dn) && stripos($dn, 'cn=placeholder') === false;
    }));
}

// Helper: count managers that are users of this specific organization
function getOrgScopedManagerCount(string $orgName, array $orgManagerDns): int
{
    global $LDAP;

    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $orgUsersBaseDn = 'ou=people,o=' . $orgRDN . ',' . $LDAP['org_dn'];

    return count(array_filter($orgManagerDns, function ($dn) use ($orgUsersBaseDn) {
        if (!is_string($dn) || stripos($dn, 'cn=placeholder') !== false) {
            return false;
        }

        $dnLower = strtolower($dn);
        $orgUsersBaseDnLower = strtolower($orgUsersBaseDn);

        return str_ends_with($dnLower, $orgUsersBaseDnLower);
    }));
}

// Handle org manager role toggle
if (isset($_GET['toggle_manager']) && isset($_GET['uid'])) {
    $toggleUserParam = $_GET['uid'];

    // Check if this is a UUID or uid
    $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $toggleUserParam);

    if ($is_uuid) {
        // UUID-based lookup - get user DN from UUID
        $ldap_connection = open_ldap_connection();
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $toggleUserParam, $usersDn);
        ldap_close($ldap_connection);

        if ($user_by_uuid) {
            $userDn = $user_by_uuid['dn'];
            $toggleUserDisplay = get_ldap_attribute($user_by_uuid, 'uid') ?: $toggleUserParam;
        } else {
            $message = t('manage.users.msg.user_not_found');
            $message_type = 'danger';
            goto after_toggle_manager;
        }
    } else {
        // Legacy uid-based lookup
        $userDn = getUserDn($orgName, $toggleUserParam);
        $toggleUserDisplay = $toggleUserParam;
    }

    $orgManagerDns = getOrgManagerDns($orgName);
    $ldap = open_ldap_connection();
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
        $orgManagerDns = getOrgManagerDns($orgName);
    }
    try {
        if (dnInList($userDn, $orgManagerDns)) {
            $managerCount = getOrgScopedManagerCount($orgName, $orgManagerDns);
            if ($managerCount <= 1) {
                $message = t('manage.org_users.msg.cannot_remove_last_manager');
                $message_type = 'danger';
            } else {
                $result = removeUserFromOrgAdmin($orgName, $userDn);
                if (is_array($result) && $result[0] === true) {
                    $message = t('manage.org_users.msg.removed_org_manager', ['user' => $toggleUserDisplay]);
                    $message_type = 'warning';
                } else {
                    $err = is_array($result) ? ($result[1] ?? '') : '';
                    $message = t('manage.org_users.msg.update_org_manager_fail', ['error' => $err]);
                    $message_type = 'danger';
                }
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

        // Check if this is a UUID or uid
        $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $user_identifier);

        if ($is_uuid) {
            // UUID-based lookup
            $ldap_connection = open_ldap_connection();
            $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
            $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
            $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $user_identifier, $usersDn);

            if ($user_by_uuid) {
                $user_dn = $user_by_uuid['dn'];
                $user_display = get_ldap_attribute($user_by_uuid, 'uid') ?: $user_identifier;
            } else {
                $message = t('manage.users.msg.user_not_found');
                $message_type = 'danger';
                ldap_close($ldap_connection);
                goto after_disable_user;
            }
        } else {
            // Legacy uid-based lookup
            $user_dn = getUserDn($orgName, $user_identifier);
            $user_display = $user_identifier;
            $ldap_connection = open_ldap_connection();
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

        // Check if this is a UUID or uid
        $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $user_identifier);

        if ($is_uuid) {
            // UUID-based lookup
            $ldap_connection = open_ldap_connection();
            $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
            $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
            $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $user_identifier, $usersDn);

            if ($user_by_uuid) {
                $user_dn = $user_by_uuid['dn'];
                $user_display = get_ldap_attribute($user_by_uuid, 'uid') ?: $user_identifier;
            } else {
                $message = t('manage.users.msg.user_not_found');
                $message_type = 'danger';
                ldap_close($ldap_connection);
                goto after_enable_user;
            }
        } else {
            // Legacy uid-based lookup
            $user_dn = getUserDn($orgName, $user_identifier);
            $user_display = $user_identifier;
            $ldap_connection = open_ldap_connection();
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

        // Check if this is a UUID or uid
        $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $user_identifier);

        if ($is_uuid) {
            // UUID-based lookup
            $ldap_connection = open_ldap_connection();
            $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
            $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
            $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $user_identifier, $usersDn);

            if ($user_by_uuid) {
                $user_dn = $user_by_uuid['dn'];
                $user_display = get_ldap_attribute($user_by_uuid, 'uid') ?: $user_identifier;
            } else {
                $message = t('manage.users.msg.user_not_found');
                $message_type = 'danger';
                ldap_close($ldap_connection);
                goto after_delete_user;
            }
        } else {
            // Legacy uid-based lookup
            $user_dn = getUserDn($orgName, $user_identifier);
            $user_display = $user_identifier;
            $ldap_connection = open_ldap_connection();
        }

        $orgManagerDnsForDelete = getOrgManagerDns($orgName);
        $isOrgManagerToDelete = dnInList($user_dn, $orgManagerDnsForDelete);
        $managerCountForDelete = getOrgScopedManagerCount($orgName, $orgManagerDnsForDelete);
        if ($isOrgManagerToDelete && $managerCountForDelete <= 1) {
            $message = t('manage.org_users.msg.cannot_remove_last_manager');
            $message_type = 'danger';
        } elseif (ldap_delete($ldap_connection, $user_dn)) {
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

// Handle add user form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        validate_csrf_token();
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

        if ($sendPasswordSetLink) {
            global $EMAIL_SENDING_ENABLED, $new_account_mail_subject, $new_account_mail_body;
            if ($EMAIL_SENDING_ENABLED === true) {
                $payload = build_password_action_payload($mail, 'set');
                $token = create_password_action_token($payload);
                $setUrl = build_password_action_url($token);
                $ttlMinutes = (int) ceil(get_password_reset_token_ttl_seconds() / 60);
                $vars = [
                    'login' => $mail,
                    'first_name' => $givenName,
                    'last_name' => $sn,
                    'password_set_url' => $setUrl,
                    'token_expires_minutes' => (string) $ttlMinutes,
                ];
                $subject = parse_mail_template((string) $new_account_mail_subject, $vars);
                $body = parse_mail_template((string) $new_account_mail_body, $vars);
                send_email($mail, trim($givenName . ' ' . $sn), $subject, $body);
                $message .= ' ' . t('manage.org_users.msg.password_set_link_sent');
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

    // Check if this is a UUID or uid
    $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deleteUserParam);

    if ($is_uuid) {
        // UUID-based lookup - get user DN from UUID
        $ldap_connection = open_ldap_connection();
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $deleteUserParam, $usersDn);
        ldap_close($ldap_connection);

        if ($user_by_uuid) {
            $userDn = $user_by_uuid['dn'];
            $deleteUserDisplay = get_ldap_attribute($user_by_uuid, 'uid') ?: $deleteUserParam;
        } else {
            $message = t('manage.users.msg.user_not_found');
            $message_type = 'danger';
            goto after_delete_user;
        }
    } else {
        // Legacy uid-based lookup
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $userDn = "uid=" . ldap_escape($deleteUserParam, '', LDAP_ESCAPE_DN) . ",$usersDn";
        $deleteUserDisplay = $deleteUserParam;
    }

    $ldap = open_ldap_connection();

    $orgManagerDnsForDelete = getOrgManagerDns($orgName);
    $isOrgManagerToDelete = dnInList($userDn, $orgManagerDnsForDelete);
    $managerCountForDelete = getOrgScopedManagerCount($orgName, $orgManagerDnsForDelete);

    if ($isOrgManagerToDelete && $managerCountForDelete <= 1) {
        $message = t('manage.org_users.msg.cannot_remove_last_manager');
        $message_type = 'danger';
    } else {
        // Remove user from all groups before deletion
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
    }
    ldap_close($ldap);
}
after_delete_user:

// Handle edit user
$editUser = null;
if (isset($_GET['edit_user'])) {
    $editUserParam = $_GET['edit_user'];

    // Check if this is a UUID or uid
    $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $editUserParam);

    if ($is_uuid) {
        // UUID-based lookup
        $ldap_connection = open_ldap_connection();
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $editUserParam, $usersDn);
        ldap_close($ldap_connection);

        if ($user_by_uuid) {
            $editUser = $user_by_uuid;
        }
    } else {
        // Legacy uid-based lookup
        $existingUsers = getUsersInOrg($orgName);
        if (!is_array($existingUsers)) {
            $existingUsers = [];
        }
        foreach ($existingUsers as $user) {
            if (strtolower(get_ldap_attribute($user, 'uid')) === strtolower($editUserParam)) {
                $editUser = $user;
                break;
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf_token();
    }
    $uid = trim($_POST['edit_uid']);
    $givenName = trim($_POST['edit_givenname']);
    $sn = trim($_POST['edit_sn']);
    $cn = trim($_POST['edit_cn'] ?? '');
    $mail = trim($_POST['edit_mail']);
    $password = (string) ($_POST['edit_password'] ?? '');
    $passwordMatch = (string) ($_POST['edit_password_match'] ?? '');
    $passScore = isset($_POST['edit_pass_score']) && is_numeric($_POST['edit_pass_score']) ? (int) $_POST['edit_pass_score'] : null;

    // Check if the edit_uid is a UUID or uid
    $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uid);

    if ($is_uuid) {
        // UUID-based lookup - get user DN from UUID
        $ldap_connection = open_ldap_connection();
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $uid, $usersDn);
        ldap_close($ldap_connection);

        if ($user_by_uuid) {
            $userDn = $user_by_uuid['dn'];
        } else {
            $message = t('manage.users.msg.user_not_found');
            $message_type = 'danger';
            goto after_edit_user;
        }
    } else {
        // Legacy uid-based lookup
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $userDn = "uid=" . ldap_escape($uid, '', LDAP_ESCAPE_DN) . ",$usersDn";
    }

    $ldap = open_ldap_connection();
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
        ldap_modify($ldap, $userDn, $entry);
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

// Handle reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_creds'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf_token();
    }
    $resetUserParam = $_POST['reset_uid'];

    // Check if this is a UUID or uid
    $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $resetUserParam);

    if ($is_uuid) {
        // UUID-based lookup - get user DN from UUID
        $ldap_connection = open_ldap_connection();
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $resetUserParam, $usersDn);
        ldap_close($ldap_connection);

        if ($user_by_uuid) {
            $userDn = $user_by_uuid['dn'];
        } else {
            $message = t('manage.users.msg.user_not_found');
            $message_type = 'danger';
            goto after_reset_user;
        }
    } else {
        // Legacy uid-based lookup
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $userDn = "uid=" . ldap_escape($resetUserParam, '', LDAP_ESCAPE_DN) . ",$usersDn";
    }

    $new_password = (string) ($_POST['reset_password'] ?? '');
    $new_password_match = (string) ($_POST['reset_password_match'] ?? '');
    $send_reset_link = isset($_POST['send_password_reset_link']) && $_POST['send_password_reset_link'] === 'on';
    if ($send_reset_link && !is_password_reset_link_enabled()) {
        $send_reset_link = false;
    }
    $passScore = isset($_POST['pass_score']) && is_numeric($_POST['pass_score']) ? (int) $_POST['pass_score'] : null;

    $ldap = open_ldap_connection();

    if ($send_reset_link) {
        global $EMAIL_SENDING_ENABLED, $reset_password_mail_subject, $reset_password_mail_body;
        if ($EMAIL_SENDING_ENABLED === true) {
            $read = @ldap_read($ldap, $userDn, '(objectClass=*)', ['mail', 'givenName', 'sn', $LDAP['account_attribute']]);
            $entries = $read ? ldap_get_entries($ldap, $read) : null;
            $userMail = '';
            $first = '';
            $last = '';
            $login = '';
            if (is_array($entries) && ($entries['count'] ?? 0) > 0) {
                $userMail = (string) ($entries[0]['mail'][0] ?? '');
                $first = (string) ($entries[0]['givenname'][0] ?? $entries[0]['givenName'][0] ?? '');
                $last = (string) ($entries[0]['sn'][0] ?? '');
                $login = (string) ($entries[0][strtolower($LDAP['account_attribute'])][0] ?? $userMail);
            }
            if ($userMail !== '' && is_valid_email($userMail)) {
                $payload = build_password_action_payload($login !== '' ? $login : $userMail, 'reset');
                $token = create_password_action_token($payload);
                $resetUrl = build_password_action_url($token);
                $ttlMinutes = (int) ceil(get_password_reset_token_ttl_seconds() / 60);
                $vars = [
                    'login' => ($login !== '' ? $login : $userMail),
                    'first_name' => $first,
                    'last_name' => $last,
                    'password_reset_url' => $resetUrl,
                    'token_expires_minutes' => (string) $ttlMinutes,
                ];
                $subject = parse_mail_template((string) $reset_password_mail_subject, $vars);
                $body = parse_mail_template((string) $reset_password_mail_body, $vars);
                send_email($userMail, trim($first . ' ' . $last), $subject, $body);
            }
        }
        $message = t('password.reset.message');
        $message_type = 'success';
    } else {
        $validation = validate_password_submission($new_password, $new_password_match, $passScore);
        if (!$validation['ok']) {
            $message = implode(' ', $validation['errors']);
            $message_type = 'danger';
            ldap_close($ldap);
            goto after_reset_user;
        }
        $entry = ['userPassword' => ldap_hashed_password($new_password)];
        try {
            ldap_modify($ldap, $userDn, $entry);
            $message = t('manage.org_users.msg.credentials_reset_ok');
            $message_type = 'success';
        } catch (Exception $e) {
            $message = t('manage.org_users.msg.credentials_reset_fail', ['error' => $e->getMessage()]);
            $message_type = 'danger';
        }
    }

    ldap_close($ldap);
}
after_reset_user:

$users = getUsersInOrg($orgName);
if (!is_array($users)) {
    $users = [];
}
$orgManagerDns = getOrgManagerDns($orgName);

// Open LDAP connection for display and operations
$ldap_connection = open_ldap_connection();

render_header(t('manage.org_users.page_title', ['org' => $orgDisplay]));
render_submenu();
?>
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.dashboard'), ENT_QUOTES, 'UTF-8'); ?></a></li>
            <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.organizations'), ENT_QUOTES, 'UTF-8'); ?></a></li>
            <?php if ($org_uuid) : ?>
                <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/', ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($orgDisplay) ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars(t('manage.common.users'), ENT_QUOTES, 'UTF-8'); ?></li>
        </ol>
    </nav>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2><?= htmlspecialchars(t('manage.common.users_in_org', ['org' => $orgDisplay]), ENT_QUOTES, 'UTF-8') ?></h2>
        <div>
            <?php if ($org_uuid) : ?>
                <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary mb-3">&larr; <?php echo htmlspecialchars(t('manage.common.back_to_org'), ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($message) : ?>
        <div class="alert alert-<?= $message_type ?>" id="msgbox"> <?= $message ?> </div>
    <?php endif; ?>
    <?php $is_disabled_org = ldap_organization_is_disabled($ldap_connection, $orgName); ?>
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
                <th><?php echo htmlspecialchars(t('manage.common.status'), ENT_QUOTES, 'UTF-8'); ?></th>
                <th><?php echo htmlspecialchars(t('manage.org_users.manager_header'), ENT_QUOTES, 'UTF-8'); ?></th>
                <th><?php echo htmlspecialchars(t('manage.common.actions'), ENT_QUOTES, 'UTF-8'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            $orgManagerCount = getOrgScopedManagerCount($orgName, $orgManagerDns);
            ?>
            <?php foreach ($users as $user) :
                // Use robust UUID extraction for user actions
                $user_uuid = get_user_uuid($user);
                $user_identifier = $user_uuid ?: get_ldap_attribute($user, 'uid');
                $user_dn = $user['dn'] ?? getUserDn($orgName, get_ldap_attribute($user, 'uid'));
                $isManager = is_string($user_dn) && dnInList($user_dn, $orgManagerDns);
                $isLastManager = $isManager && $orgManagerCount <= 1;
                ?>
                <tr<?= $isManager ? ' class="table-success"' : '' ?>>
                    <td><?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?></td>
                    <td><?= safe_display_name($user, 'cn', 'givenName', 'sn') ?></td>
                    <td><?= htmlspecialchars(get_ldap_attribute($user, 'mail')) ?></td>
                    <td>
                        <?php
                        $is_disabled = ldap_user_is_disabled($ldap_connection, $user['dn']);
                        $is_individually_disabled = ldap_user_is_individually_disabled($ldap_connection, $user['dn']);
                        if ($is_disabled_org) {
                            echo '<span class="badge bg-danger" title="' . htmlspecialchars(t('manage.org_users.deactivate_reason.org'), ENT_QUOTES, 'UTF-8') . '">'
                                . htmlspecialchars(t('manage.common.inactive'), ENT_QUOTES, 'UTF-8') . '</span>';
                        } else {
                            $can_enable_user = currentUserCanEnableUser($user_identifier);
                            $can_disable_user = currentUserCanDisableUser($user_identifier);
                            $status_form_target = $is_individually_disabled ? 'enable_user' : 'disable_user';
                            $status_title = $is_individually_disabled ? t('manage.common.activate') : t('manage.common.deactivate');
                            $can_toggle_status = $is_individually_disabled ? $can_enable_user : $can_disable_user;
                            ?>
                            <form method="post" class="d-inline-flex align-items-center justify-content-center m-0">
                                <?= csrf_token_field() ?>
                                <input type="hidden" name="<?= $status_form_target ?>" value="<?= htmlspecialchars($user_identifier, ENT_QUOTES, 'UTF-8') ?>">
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
                        ?>
                    </td>
                    <td class="text-center">
                        <form method="get" class="d-inline-flex align-items-center justify-content-center">
                            <input type="hidden" name="<?= $org_uuid ? 'uuid' : 'org' ?>" value="<?= htmlspecialchars($org_uuid ?: $orgName) ?>">
                            <input type="hidden" name="uid" value="<?= htmlspecialchars($user_identifier) ?>">
                            <input type="hidden" name="toggle_manager" value="1">
                            <div class="form-check form-switch m-0">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    id="org-manager-switch-<?= htmlspecialchars((string) $user_identifier, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $isManager ? 'checked' : '' ?>
                                    <?= $isLastManager ? 'disabled' : '' ?>
                                    onchange="this.form.submit()"
                                    title="<?= htmlspecialchars($isLastManager ? t('manage.org_users.msg.cannot_remove_last_manager') : t('manage.common.toggle_manager_title'), ENT_QUOTES, 'UTF-8') ?>"
                                    aria-label="<?= htmlspecialchars(t('manage.common.org_manager'), ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>
                        </form>
                    </td>
                    <td>
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.common.user_actions_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&edit_user=<?= urlencode($user_identifier) ?>" class="btn btn-secondary btn-sm"><?php echo htmlspecialchars(t('manage.common.edit'), ENT_QUOTES, 'UTF-8'); ?></a>
                                <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&reset_user=<?= urlencode($user_identifier) ?>" class="btn btn-primary btn-sm"><?php echo htmlspecialchars(t('manage.common.new_password'), ENT_QUOTES, 'UTF-8'); ?></a>
                            </div>
                            <div class="ms-2 ps-2 border-start">
                                <?php if ($isManager) : ?>
                                    <button type="button" class="btn btn-danger btn-sm" disabled title="<?php echo htmlspecialchars(t('manage.org_users.msg.cannot_delete_org_manager'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                                <?php else : ?>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteUser('<?= htmlspecialchars($user_identifier) ?>', '<?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?>')"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="mt-3">
        <?php if ($org_uuid) : ?>
            <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/users/new/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success btn-sm ml-2"><?php echo htmlspecialchars(t('manage.common.create_new_user'), ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endif; ?>
    </div>
    
    <h4><?php echo htmlspecialchars(t('manage.common.quick_add_user'), ENT_QUOTES, 'UTF-8'); ?></h4>
    <p class="text-muted"><?php echo htmlspecialchars(t('manage.common.quick_add_lead'), ENT_QUOTES, 'UTF-8'); ?></p>
    <form method="post" class="mb-4" id="add_user_form" onsubmit="return validateAddUserForm();">
        <?= csrf_token_field() ?>
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
                    <?php echo htmlspecialchars(t('manage.org_users.email_reset_checkbox'), ENT_QUOTES, 'UTF-8'); ?>
                </label>
                <?php if (!is_password_reset_link_enabled()) : ?>
                    <div class="alert alert-warning mt-2 mb-0 py-2">
                        <?php echo htmlspecialchars(t('manage.users.new.error.password_set_link_disabled_secret_missing'), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <input type="hidden" name="add_user" value="1">
        <button type="submit" name="add_user" class="btn btn-primary" id="add_user_btn"><?php echo htmlspecialchars(t('manage.common.add_user'), ENT_QUOTES, 'UTF-8'); ?></button>
        <span id="add_user_spinner" style="display:none;"><span class="spinner-border spinner-border-sm"></span> <?php echo htmlspecialchars(t('manage.common.adding'), ENT_QUOTES, 'UTF-8'); ?></span>
    </form>

    <script src="<?php print get_asset_base(); ?>js/password_utils.js"></script>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function(){
        const passwordConfig = <?php echo get_password_strength_config_js(); ?>;
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
            <?= csrf_token_field() ?>
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
            <?= csrf_token_field() ?>
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
    render_confirm_modal(
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
        $is_uuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $resetUserParam);

        // Get user information for display
        $resetUserDisplay = '';
        if ($is_uuid) {
            // UUID-based lookup
            $ldap_connection = open_ldap_connection();
            $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
            $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
            $user_by_uuid = ldap_get_entry_by_uuid($ldap_connection, $resetUserParam, $usersDn);
            ldap_close($ldap_connection);

            if ($user_by_uuid) {
                $resetUserDisplay = get_ldap_attribute($user_by_uuid, 'uid') ?: $resetUserParam;
            } else {
                $resetUserDisplay = $resetUserParam;
            }
        } else {
            // Legacy uid-based lookup
            $resetUserDisplay = $resetUserParam;
        }
        ?>
    <div class="modal show" tabindex="-1" style="display:block; background:rgba(0,0,0,0.3); z-index:1050;">
      <div class="modal-dialog">
        <div class="modal-content border-warning">
          <form method="post">
            <?= csrf_token_field() ?>
            <div class="modal-header bg-warning text-dark">
              <h5 class="modal-title"><?= htmlspecialchars(t('manage.org_users.reset_title', ['user' => $resetUserDisplay]), ENT_QUOTES, 'UTF-8') ?></h5>
              <?php if ($org_uuid) : ?>
                  <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn-close text-dark" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></a>
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
                  <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('modal.cancel'), ENT_QUOTES, 'UTF-8'); ?></a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
</div>
<script src="<?php print get_asset_base(); ?>js/modals.js"></script>
<script src="<?php print get_asset_base(); ?>js/form-sync.js"></script>
<script src="<?php print get_asset_base(); ?>js/password_utils.js"></script>
<script>
    // Initialize organization user search functionality
    document.addEventListener('DOMContentLoaded', function() {
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
            var passwordConfig = <?php echo get_password_strength_config_js(); ?>;
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

render_footer();
?> 
