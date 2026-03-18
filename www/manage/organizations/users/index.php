<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap', 'organization', 'user', 'mail', 'password_reset']);

// Ensure CSRF token is generated early
get_csrf_token();

$res = resolve_organization_from_request();
if ($res['error'] !== null) {
    render_header('Organization User Management');
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

render_header('User Management for Organization: ' . htmlspecialchars($orgDisplay));
render_submenu();

if (!$orgName || !$orgExists) {
    echo "<div class='alert alert-warning'>Please select a valid organization.</div>";
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
        } else {
            $message = 'User not found with UUID: ' . $toggleUserParam;
            $message_type = 'danger';
            goto after_toggle_manager;
        }
    } else {
        // Legacy uid-based lookup
        $userDn = getUserDn($orgName, $toggleUserParam);
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
            $message = 'Failed to create roles directory: ' . htmlspecialchars($ldap_err);
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
            $message = 'Failed to create organization administrators group: ' . htmlspecialchars($ldap_err);
            $message_type = 'danger';
            ldap_close($ldap);
            goto after_toggle_manager;
        }
        // Refresh orgManagerDns after creation
        $orgManagerDns = getOrgManagerDns($orgName);
    }
    try {
        if (in_array($userDn, $orgManagerDns)) {
            removeUserFromOrgAdmin($orgName, $userDn);
            $message = 'User removed from Org Manager role.';
            $message_type = 'warning';
        } else {
            addUserToOrgAdmin($orgName, $userDn);
            $message = 'User assigned as Org Manager.';
            $message_type = 'success';
        }
    } catch (Exception $e) {
        $message = 'Error updating Org Manager role: ' . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
    }
    after_toggle_manager:
}

// Handle user lock/unlock
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['lock_user']) && currentUserCanDisableUser($_POST['lock_user'])) {
        $user_identifier = trim($_POST['lock_user']);

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
            } else {
                $message = 'User not found with UUID: ' . $user_identifier;
                $message_type = 'danger';
                ldap_close($ldap_connection);
                goto after_lock_user;
            }
        } else {
            // Legacy uid-based lookup
            $user_dn = getUserDn($orgName, $user_identifier);
            $ldap_connection = open_ldap_connection();
        }

        if (ldap_lock_user_account($ldap_connection, $user_dn)) {
            $message = "User has been locked successfully.";
            $message_type = 'success';
        } else {
            $ldap_error = ldap_error($ldap_connection);
            $message = "Failed to lock user. LDAP Error: $ldap_error";
            $message_type = 'danger';
        }
        ldap_close($ldap_connection);
        after_lock_user:
    } elseif (isset($_POST['unlock_user']) && currentUserCanEnableUser($_POST['unlock_user'])) {
        $user_identifier = trim($_POST['unlock_user']);

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
            } else {
                $message = 'User not found with UUID: ' . $user_identifier;
                $message_type = 'danger';
                ldap_close($ldap_connection);
                goto after_unlock_user;
            }
        } else {
            // Legacy uid-based lookup
            $user_dn = getUserDn($orgName, $user_identifier);
            $ldap_connection = open_ldap_connection();
        }

        if (ldap_unlock_user_account($ldap_connection, $user_dn)) {
            $message = "User has been unlocked successfully.";
            $message_type = 'success';
        } else {
            $ldap_error = ldap_error($ldap_connection);
            $message = "Failed to unlock user. LDAP Error: $ldap_error";
            $message_type = 'danger';
        }
        ldap_close($ldap_connection);
        after_unlock_user:
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
            } else {
                $message = 'User not found with UUID: ' . $user_identifier;
                $message_type = 'danger';
                ldap_close($ldap_connection);
                goto after_delete_user;
            }
        } else {
            // Legacy uid-based lookup
            $user_dn = getUserDn($orgName, $user_identifier);
            $ldap_connection = open_ldap_connection();
        }

        if (ldap_delete($ldap_connection, $user_dn)) {
            $message = "User has been deleted successfully.";
            $message_type = 'success';
        } else {
            $ldap_error = ldap_error($ldap_connection);
            $message = "Failed to delete user. LDAP Error: $ldap_error";
            $message_type = 'danger';
        }
        ldap_close($ldap_connection);
    }
}

// Message handling
$message = '';
$message_type = '';

// Handle add user form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        validate_csrf_token();
    } catch (Exception $e) {
        $message = 'Security validation failed. Please refresh the page and try again.';
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
        $message = 'All fields are required.';
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
            $message = 'Failed to create users directory: ' . htmlspecialchars($ldap_err);
            $message_type = 'danger';
            ldap_close($ldap);
            goto after_add_user;
        }
    }

    $search = @ldap_search($ldap, $usersDn, "(uid=" . ldap_escape($uid, '', LDAP_ESCAPE_FILTER) . ")");
    $entries = $search ? ldap_get_entries($ldap, $search) : false;
    if ($entries && $entries['count'] > 0) {
        $message = 'A user with this email address already exists in this organization.';
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
        $message = 'User added successfully.';
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
                $message .= ' A password set link was sent by email.';
            }
        }
    } else {
        $ldap_err = ldap_error($ldap);
        $message = 'Failed to add user: ' . htmlspecialchars($ldap_err);
        $message_type = 'danger';
    }

    ldap_close($ldap);
}
after_add_user:

// Handle delete user
if (isset($_GET['delete_user'])) {
    $deleteUserParam = $_GET['delete_user'];

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
        } else {
            $message = 'User not found with UUID: ' . $deleteUserParam;
            $message_type = 'danger';
            goto after_delete_user;
        }
    } else {
        // Legacy uid-based lookup
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
        $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
        $userDn = "uid=" . ldap_escape($deleteUserParam, '', LDAP_ESCAPE_DN) . ",$usersDn";
    }

    $ldap = open_ldap_connection();

    // Remove user from all groups before deletion
    $group_cleanup_success = ldap_remove_user_from_all_groups($ldap, $userDn);
    if (!$group_cleanup_success) {
        error_log("Warning: Failed to remove user from some groups before deletion");
        // Continue with deletion even if group cleanup failed
    }

    try {
        ldap_delete($ldap, $userDn);
        $message = 'User deleted successfully.';
        $message_type = 'warning';
    } catch (Exception $e) {
        $message = 'Error deleting user: ' . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
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
            $message = 'User not found with UUID: ' . $uid;
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
        $message = 'User updated successfully.';
        $message_type = 'success';
    } catch (Exception $e) {
        $message = 'Error updating user: ' . htmlspecialchars($e->getMessage());
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
            $message = 'User not found with UUID: ' . $resetUserParam;
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
        $message = 'If the address exists, a password reset link has been sent.';
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
            $message = 'Credentials reset successfully.';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Error resetting credentials: ' . htmlspecialchars($e->getMessage());
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
?>
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/manage/">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/manage/organizations/">Organizations</a></li>
            <?php if ($org_uuid) : ?>
                <li class="breadcrumb-item"><a href="/manage/organizations/<?php echo urlencode($org_uuid); ?>/"><?= htmlspecialchars($orgDisplay) ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page">Users</li>
        </ol>
    </nav>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Users in <?= htmlspecialchars($orgDisplay) ?></h2>
        <div>
            <?php if ($org_uuid) : ?>
                <a href="/manage/organizations/<?php echo urlencode($org_uuid); ?>/" class="btn btn-secondary mb-3">&larr; Back to Organization</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($message) : ?>
        <div class="alert alert-<?= $message_type ?>" id="msgbox"> <?= $message ?> </div>
    <?php endif; ?>
    <input class="form-control mb-2" id="user_search_input" type="text" placeholder="Search users..">
    <table class="table table-bordered" id="user_table">
        <thead>
            <tr>
                <th>Username/Email</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Org Manager</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user) :
                $isManager = in_array(getUserDn($orgName, get_ldap_attribute($user, 'uid')), $orgManagerDns);
                // Use robust UUID extraction for user actions
                $user_uuid = get_user_uuid($user);
                $user_identifier = $user_uuid ?: get_ldap_attribute($user, 'uid');
                ?>
                <tr<?= $isManager ? ' class="table-success"' : '' ?>>
                    <td><?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?></td>
                    <td><?= safe_display_name($user, 'cn', 'givenName', 'sn') ?></td>
                    <td><?= htmlspecialchars(get_ldap_attribute($user, 'mail')) ?></td>
                    <td>
                        <?php
                        $is_locked = ldap_user_is_locked($ldap_connection, $user['dn']);
                        echo $is_locked ? '<span class="badge bg-danger">Locked</span>' : '<span class="badge bg-success">Active</span>';
                        ?>
                    </td>
                    <td>
                        <form method="get" style="display:inline">
                            <input type="hidden" name="<?= $org_uuid ? 'uuid' : 'org' ?>" value="<?= htmlspecialchars($org_uuid ?: $orgName) ?>">
                            <input type="hidden" name="uid" value="<?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?>">
                            <input type="hidden" name="toggle_manager" value="1">
                            <input type="checkbox" onchange="this.form.submit()" <?= $isManager ? 'checked' : '' ?> title="Toggle Org Manager role">
                        </form>
                    </td>
                    <td>
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <div class="btn-group btn-group-sm" role="group" aria-label="User actions">
                                <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&edit_user=<?= urlencode($user_identifier) ?>" class="btn btn-secondary btn-sm">Edit</a>
                            <?php if (currentUserCanDisableUser($user_identifier)) : ?>
                                <?php if (ldap_user_is_locked($ldap_connection, $user['dn'])) : ?>
                                    <button type="button" class="btn btn-success btn-sm" onclick="confirmUnlockUser('<?= htmlspecialchars($user_identifier) ?>', '<?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?>')">Unlock</button>
                                <?php else : ?>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="confirmLockUser('<?= htmlspecialchars($user_identifier) ?>', '<?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?>')">Lock</button>
                                <?php endif; ?>
                            <?php endif; ?>
                                <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&reset_user=<?= urlencode($user_identifier) ?>" class="btn btn-primary btn-sm">New password</a>
                            </div>
                            <div class="ms-2 ps-2 border-start">
                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteUser('<?= htmlspecialchars($user_identifier) ?>', '<?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?>')">Delete</button>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="mt-3">
        <?php if ($org_uuid) : ?>
            <a href="/manage/organizations/<?php echo urlencode($org_uuid); ?>/users/new/" class="btn btn-success btn-sm ml-2">Create New User</a>
        <?php endif; ?>
    </div>
    
    <h4>Quick Add User</h4>
    <p class="text-muted">Add a new user to this organization. The email address will be used as the username for login.</p>
    <form method="post" class="mb-4" id="add_user_form" onsubmit="return validateAddUserForm();">
        <?= csrf_token_field() ?>
        <div class="form-group">
            <label for="givenName">First Name</label>
            <input type="text" class="form-control" name="givenName" id="givenName" required>
        </div>
        <div class="form-group">
            <label for="sn">Last Name</label>
            <input type="text" class="form-control" name="sn" id="sn" required>
        </div>
        <div class="form-group">
            <label for="cn">Display Name</label>
            <input type="text" class="form-control" name="cn" id="cn" required>
            <small class="text-muted">Auto-filled from First Name + Last Name (you can edit it).</small>
        </div>
        <div class="form-group">
            <label for="mail">Email (Username)</label>
            <input type="email" class="form-control" name="mail" id="mail" required>
            <small class="text-muted">Email will be used as the username for login</small>
        </div>
        
        <!-- Hidden fields for auto-generated values -->
        <input type="hidden" name="<?php echo $LDAP['account_attribute']; ?>" id="<?php echo $LDAP['account_attribute']; ?>" value="">
        
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" class="form-control" name="password" id="password" required>
        </div>
        <div class="form-group">
            <label for="password_match">Confirm Password</label>
            <input type="password" class="form-control" name="password_match" id="password_match" required>
        </div>
        <input type="hidden" id="pass_score" value="0" name="pass_score">

        <?php global $EMAIL_SENDING_ENABLED; ?>
        <?php if ($EMAIL_SENDING_ENABLED === true) : ?>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="send_password_set_link" name="send_password_set_link" <?php echo !is_password_reset_link_enabled() ? 'disabled' : ''; ?>>
                <label class="form-check-label" for="send_password_set_link">
                    Email password setup link (user sets their own password)
                </label>
                <?php if (!is_password_reset_link_enabled()) : ?>
                    <div class="alert alert-warning mt-2 mb-0 py-2">
                        Password links are disabled because <code>PASSWORD_RESET_TOKEN_SECRET</code> is not configured.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <input type="hidden" name="add_user" value="1">
        <button type="submit" name="add_user" class="btn btn-primary" id="add_user_btn">Add User</button>
        <span id="add_user_spinner" style="display:none;"><span class="spinner-border spinner-border-sm"></span> Adding...</span>
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
              <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
              <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>" class="btn-close" aria-label="Close"></a>
            </div>
            <div class="modal-body">
              <input type="hidden" name="edit_uid" id="edit_uid_input" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'uid')) ?>">
              <div class="form-group">
                <label for="edit_givenname">First Name</label>
                <input type="text" class="form-control" name="edit_givenname" id="edit_givenname" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'givenName')) ?>" required>
              </div>
              <div class="form-group">
                <label for="edit_sn">Last Name</label>
                <input type="text" class="form-control" name="edit_sn" id="edit_sn" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'sn')) ?>" required>
              </div>
              <div class="form-group">
                <label for="edit_cn">Display Name</label>
                <input type="text" class="form-control" name="edit_cn" id="edit_cn" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'cn')) ?>" required>
                <small class="text-muted">Auto-filled from First Name + Last Name (you can edit it).</small>
              </div>
              <div class="form-group">
                <label for="edit_mail">Email</label>
                <input type="email" class="form-control" name="edit_mail" id="edit_mail" value="<?= htmlspecialchars(get_ldap_attribute($editUser, 'mail')) ?>" required>
              </div>
              <div class="form-group">
                <label for="edit_password">New Password (leave blank to keep unchanged)</label>
                <input type="password" class="form-control" name="edit_password" id="edit_password">
              </div>
              <div class="form-group mt-2">
                <label for="edit_password_match">Confirm New Password</label>
                <input type="password" class="form-control" name="edit_password_match" id="edit_password_match">
              </div>
              <input type="hidden" id="edit_pass_score" value="0" name="edit_pass_score">
            </div>
            <div class="modal-footer">
              <button type="submit" name="save_user" class="btn btn-primary">Save Changes</button>
              <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>" class="btn btn-secondary">Cancel</a>
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
              <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="edit_uid" id="edit_uid_input" value="">
              <div class="form-group">
                <label for="edit_givenname">First Name</label>
                <input type="text" class="form-control" name="edit_givenname" id="edit_givenname" required>
              </div>
              <div class="form-group">
                <label for="edit_sn">Last Name</label>
                <input type="text" class="form-control" name="edit_sn" id="edit_sn" required>
              </div>
              <div class="form-group">
                <label for="edit_cn">Display Name</label>
                <input type="text" class="form-control" name="edit_cn" id="edit_cn" required>
                <small class="text-muted">Auto-filled from First Name + Last Name (you can edit it).</small>
              </div>
              <div class="form-group">
                <label for="edit_mail">Email</label>
                <input type="email" class="form-control" name="edit_mail" id="edit_mail" required>
              </div>
              <div class="form-group">
                <label for="edit_password">New Password (leave blank to keep unchanged)</label>
                <input type="password" class="form-control" name="edit_password" id="edit_password">
              </div>
              <div class="form-group mt-2">
                <label for="edit_password_match">Confirm New Password</label>
                <input type="password" class="form-control" name="edit_password_match" id="edit_password_match">
              </div>
              <input type="hidden" id="edit_pass_score" value="0" name="edit_pass_score">
            </div>
            <div class="modal-footer">
              <button type="submit" name="save_user" class="btn btn-primary">Save Changes</button>
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
    
    <?php
    render_confirm_modal(
        'lockUserModal',
        'Lock User Account',
        '<p>Are you sure you want to lock the user account for <strong><span id="lockUserName"></span></strong>?</p><p class="text-warning"><strong>Warning:</strong> This will prevent the user from logging in until the account is unlocked.</p>',
        [['name' => 'lock_user', 'id' => 'lockUserIdentifier']],
        'Lock Account',
        'btn-warning'
    );
    render_confirm_modal(
        'unlockUserModal',
        'Unlock User Account',
        '<p>Are you sure you want to unlock the user account for <strong><span id="unlockUserName"></span></strong>?</p><p class="text-success">This will allow the user to log in again.</p>',
        [['name' => 'unlock_user', 'id' => 'unlockUserIdentifier']],
        'Unlock Account',
        'btn-success'
    );
    render_confirm_modal(
        'deleteUserModal',
        'Delete User Account',
        '<p>Are you sure you want to delete the user "<span id="deleteUserName"></span>"?</p><p class="text-danger"><strong>Warning:</strong> This action cannot be undone and will remove all associated data.</p><p class="text-warning"><strong>Note:</strong> This will permanently delete the user account from this organization.</p>',
        [['name' => 'delete_user', 'id' => 'deleteUserIdentifier']],
        'Delete Account',
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
              <h5 class="modal-title">Reset Credentials for <?= htmlspecialchars($resetUserDisplay) ?></h5>
              <?php if ($org_uuid) : ?>
                  <a href="/manage/organizations/<?php echo urlencode($org_uuid); ?>/users/" class="btn-close text-dark" aria-label="Close"></a>
              <?php endif; ?>
            </div>
            <div class="modal-body">
              <input type="hidden" name="reset_uid" value="<?= htmlspecialchars($resetUserParam) ?>">
              <div class="form-group">
                <label for="reset_password">New Password</label>
                <input type="password" class="form-control" name="reset_password" id="reset_password" required>
              </div>
              <div class="form-group mt-2">
                <label for="reset_password_match">Confirm New Password</label>
                <input type="password" class="form-control" name="reset_password_match" id="reset_password_match" required>
              </div>
              <input type="hidden" id="reset_pass_score" value="0" name="pass_score">

              <?php global $EMAIL_SENDING_ENABLED; ?>
              <?php if ($EMAIL_SENDING_ENABLED === true) : ?>
                <div class="form-check mt-3">
                  <input class="form-check-input" type="checkbox" id="send_password_reset_link" name="send_password_reset_link" <?php echo !is_password_reset_link_enabled() ? 'disabled' : ''; ?>>
                  <label class="form-check-label" for="send_password_reset_link">
                    Email password reset link (user sets a new password)
                  </label>
                    <?php if (!is_password_reset_link_enabled()) : ?>
                        <div class="alert alert-warning mt-2 mb-0 py-2">
                            Password links are disabled because <code>PASSWORD_RESET_TOKEN_SECRET</code> is not configured.
                        </div>
                    <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="modal-footer">
              <button type="submit" name="reset_creds" class="btn btn-warning">Reset</button>
              <?php if ($org_uuid) : ?>
                  <a href="/manage/organizations/<?php echo urlencode($org_uuid); ?>/users/" class="btn btn-secondary">Cancel</a>
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

    function confirmLockUser(userIdentifier, userName) {
        confirmAction('lockUserModal', { lockUserIdentifier: userIdentifier, lockUserName: userName });
    }
    function confirmUnlockUser(userIdentifier, userName) {
        confirmAction('unlockUserModal', { unlockUserIdentifier: userIdentifier, unlockUserName: userName });
    }
    function confirmDeleteUser(userIdentifier, userName) {
        confirmAction('deleteUserModal', { deleteUserName: userName, deleteUserIdentifier: userIdentifier });
    }
</script>
<?php
// Close LDAP connection
ldap_close($ldap_connection);

render_footer();
?> 
