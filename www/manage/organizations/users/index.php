<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";
include_once "organization_functions.inc.php";
include_once "user_functions.inc.php";

// Ensure CSRF token is generated early
get_csrf_token();

// Check if organization parameter is provided (support both UUID and name-based lookup)
$orgName = null;
$org_uuid = null;

if (isset($_GET['uuid']) && !empty($_GET['uuid'])) {
    // UUID-based lookup
    $org_uuid = $_GET['uuid'];
    if (!is_valid_uuid($org_uuid)) {
        render_header('Organization User Management');
        echo "<div class='alert alert-warning'>Invalid organization UUID provided.</div>";
        render_footer();
        exit;
    }
    
    // Get organization by UUID
    $ldap_connection = open_ldap_connection();
    $organization_by_uuid = ldap_get_organization_by_uuid($ldap_connection, $org_uuid);
    ldap_close($ldap_connection);
    
    if (!$organization_by_uuid) {
        render_header('Organization User Management');
        echo "<div class='alert alert-warning'>Organization with UUID '$org_uuid' not found.</div>";
        render_footer();
        exit;
    }
    
    $orgName = $organization_by_uuid['o'][0];
} elseif (isset($_GET['org']) && !empty($_GET['org'])) {
    // Legacy name-based lookup
    $orgName = $_GET['org'];
} else {
    render_header('Organization User Management');
    echo "<div class='alert alert-warning'>Organization identifier (UUID or name) is required.</div>";
    render_footer();
    exit;
}

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
        
        if ($orgNameVal === '') continue;
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
function getUsersInOrg($orgName) {
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
function getUserDn($orgName, $uid) {
    global $LDAP;
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    return "uid=$uid,$usersDn";
}

// Helper: get org manager DNs
function getOrgManagerDns($orgName) {
    global $LDAP;
    $ldap = open_ldap_connection();
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDn = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    $result = @ldap_read($ldap, $groupDn, '(objectClass=groupOfNames)', ['member']);
    if (!$result) return [];
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
    $password = $_POST['password'];
    $passcode = $_POST['passcode'];
    
    // Use email as username since that's how the system is configured
    $uid = $mail;
    
    if ($givenName === '' || $sn === '' || $mail === '' || $password === '') {
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
    
    # Add passcode to userPassword (multiple values supported)
    if (!isset($entry['userPassword'])) {
        $entry['userPassword'] = array();
    }
    if (!empty($passcode)) {
        $entry['userPassword'][] = ldap_hashed_passcode($passcode);
    }
    $add_result = @ldap_add($ldap, $userDn, $entry);
    if ($add_result) {
        $message = 'User added successfully.';
        $message_type = 'success';
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
    $mail = trim($_POST['edit_mail']);
    $password = $_POST['edit_password'];
    $passcode = $_POST['edit_passcode'];
    
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
        'cn' => $givenName . ' ' . $sn, // Construct cn from givenName + sn
        'mail' => $mail
    ];
    if ($password !== '') {
        $entry['userPassword'] = ldap_hashed_password($password); // For demo; use LDAP hash in production
    }
    if ($passcode !== '') {
      # Add passcode to userPassword (multiple values supported)
      if (!isset($entry['userPassword'])) {
        $entry['userPassword'] = array();
      }
      $entry['userPassword'][] = ldap_hashed_passcode($passcode);
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

// Handle reset password/passcode
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
    
    $new_password = $_POST['reset_password'];
    $new_passcode = $_POST['reset_passcode'];
    $ldap = open_ldap_connection();
    $entry = [];
    if ($new_password !== '') {
        $entry['userPassword'] = ldap_hashed_password($new_password);
    }
    if ($new_passcode !== '') {
      # Add passcode to userPassword (multiple values supported)
      if (!isset($entry['userPassword'])) {
        $entry['userPassword'] = array();
      }
      $entry['userPassword'][] = ldap_hashed_passcode($new_passcode);
    }
    if (!empty($entry)) {
        try {
            ldap_modify($ldap, $userDn, $entry);
            $message = 'Credentials reset successfully.';
            $message_type = 'success';
        } catch (Exception $e) {
            $message = 'Error resetting credentials: ' . htmlspecialchars($e->getMessage());
            $message_type = 'danger';
        }
    } else {
        $message = 'Please enter a new password and/or passcode.';
        $message_type = 'warning';
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
            <li class="breadcrumb-item"><a href="/manage/organizations/show/index.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName); ?>"><?= htmlspecialchars($orgDisplay) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Users</li>
        </ol>
    </nav>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Users in <?= htmlspecialchars($orgDisplay) ?></h2>
        <div>
            <button type="button" class="btn btn-info btn-sm me-2" onclick="testModal()">Test Modal</button>
            <button type="button" class="btn btn-warning btn-sm me-2" onclick="testSession()">Test Session</button>
            <a href="/manage/organizations/show/index.php?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>" class="btn btn-secondary mb-3">&larr; Back to Organization</a>
        </div>
    </div>
    <?php if ($message): ?>
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
            <?php foreach ($users as $user): 
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
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openEditModal('<?= htmlspecialchars($user_identifier) ?>', '<?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?>')">Edit</button>
                            <?php if (currentUserCanDisableUser($user_identifier)): ?>
                                <?php if (ldap_user_is_locked($ldap_connection, $user['dn'])): ?>
                                    <button type="button" class="btn btn-success btn-sm" onclick="confirmUnlockUser('<?= htmlspecialchars($user_identifier) ?>', '<?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?>')">Unlock</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-warning btn-sm" onclick="confirmLockUser('<?= htmlspecialchars($user_identifier) ?>', '<?= htmlspecialchars(get_ldap_attribute($user, 'uid')) ?>')">Lock</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&delete_user=<?= urlencode($user_identifier) ?>" onclick="return confirm('Are you sure you want to delete this user?')" class="btn btn-danger btn-sm">Delete</a>
                            <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&reset_user=<?= urlencode($user_identifier) ?>" class="btn btn-warning btn-sm">Reset</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="mt-3">
        <a href="/manage/organizations/users/add.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName); ?>" class="btn btn-success btn-sm ml-2">Create New User</a>
    </div>
    
    <h4>Quick Add User</h4>
    <p class="text-muted">Add a new user to this organization. The email address will be used as the username for login.</p>
    <form method="post" class="mb-4" id="add_user_form" onsubmit="return validateAddUserForm();">
        <?= csrf_token_field() ?>
        <div class="form-group">
            <label for="givenName">First Name</label>
            <input type="text" class="form-control" name="givenName" id="givenName" onchange="updateCommonName()" required>
        </div>
        <div class="form-group">
            <label for="sn">Last Name</label>
            <input type="text" class="form-control" name="sn" id="sn" onchange="updateCommonName()" required>
        </div>
        <div class="form-group">
            <label for="mail">Email (Username)</label>
            <input type="email" class="form-control" name="mail" id="mail" onchange="updateAccountUid(this.value)" required>
            <small class="text-muted">Email will be used as the username for login</small>
        </div>
        
        <!-- Hidden fields for auto-generated values -->
        <input type="hidden" name="cn" id="cn" value="">
        <input type="hidden" name="<?php echo $LDAP['account_attribute']; ?>" id="<?php echo $LDAP['account_attribute']; ?>" value="">
        
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" class="form-control" name="password" id="password" required>
        </div>
        <div class="form-group">
            <label for="passcode">Passcode (optional)</label>
            <input type="text" class="form-control" name="passcode" id="passcode">
        </div>
        <input type="hidden" name="add_user" value="1">
        <button type="submit" name="add_user" class="btn btn-primary" id="add_user_btn">Add User</button>
        <span id="add_user_spinner" style="display:none;"><span class="spinner-border spinner-border-sm"></span> Adding...</span>
    </form>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" role="dialog" aria-labelledby="editUserModalLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form method="post" action="">
            <?= csrf_token_field() ?>
            <div class="modal-header">
              <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
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
                <label for="edit_mail">Email</label>
                <input type="email" class="form-control" name="edit_mail" id="edit_mail" required>
              </div>
              <div class="form-group">
                <label for="edit_password">Password (leave blank to keep unchanged)</label>
                <input type="password" class="form-control" name="edit_password" id="edit_password">
              </div>
              <div class="form-group">
                <label for="edit_passcode">Passcode (leave blank to keep unchanged)</label>
                <input type="text" class="form-control" name="edit_passcode" id="edit_passcode">
              </div>
            </div>
            <div class="modal-footer">
              <button type="submit" name="save_user" class="btn btn-primary">Save Changes</button>
              <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    
    <!-- Lock User Modal -->
    <div class="modal fade" id="lockUserModal" tabindex="-1" role="dialog" aria-labelledby="lockUserModalLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form method="post" action="">
            <?= csrf_token_field() ?>
            <div class="modal-header bg-warning text-dark">
              <h5 class="modal-title" id="lockUserModalLabel">Lock User Account</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">
              <p>Are you sure you want to lock the user account for <strong><span id="lockUserName"></span></strong>?</p>
              <p class="text-warning"><strong>Warning:</strong> This will prevent the user from logging in until the account is unlocked.</p>
              <input type="hidden" name="lock_user" id="lockUserIdentifier" value="">
            </div>
            <div class="modal-footer">
              <button type="submit" class="btn btn-warning">Lock Account</button>
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    
    <!-- Unlock User Modal -->
    <div class="modal fade" id="unlockUserModal" tabindex="-1" role="dialog" aria-labelledby="unlockUserModalLabel" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form method="post" action="">
            <?= csrf_token_field() ?>
            <div class="modal-header bg-success text-white">
              <h5 class="modal-title" id="unlockUserModalLabel">Unlock User Account</h5>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">
              <p>Are you sure you want to unlock the user account for <strong><span id="unlockUserName"></span></strong>?</p>
              <p class="text-success">This will allow the user to log in again.</p>
              <input type="hidden" name="unlock_user" id="unlockUserIdentifier" value="">
            </div>
            <div class="modal-footer">
              <button type="submit" class="btn btn-success">Unlock Account</button>
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    
    <?php if (isset($_GET['reset_user'])): 
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
              <a href="/manage/organizations/users/index.php?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>" class="close text-dark">&times;</a>
            </div>
            <div class="modal-body">
              <input type="hidden" name="reset_uid" value="<?= htmlspecialchars($resetUserParam) ?>">
              <div class="form-group">
                <label for="reset_password">New Password (leave blank to keep unchanged)</label>
                <input type="password" class="form-control" name="reset_password" id="reset_password">
              </div>
              <div class="form-group">
                <label for="reset_passcode">New Passcode (leave blank to keep unchanged)</label>
                <input type="text" class="form-control" name="reset_passcode" id="reset_passcode">
              </div>
            </div>
            <div class="modal-footer">
              <button type="submit" name="reset_creds" class="btn btn-warning">Reset</button>
              <a href="/manage/organizations/users/index.php?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <p class="text-muted mt-2">(Role management and UI refinements complete.)</p>
</div>
<script src="/js/zxcvbn.min.js"></script>
<script src="/bootstrap/js/bootstrap.min.js"></script>
<script>
    // Debug: Check what's loaded
    console.log('jQuery loaded:', typeof $ !== 'undefined');
    console.log('Bootstrap loaded:', typeof $.fn !== 'undefined' && typeof $.fn.modal !== 'undefined');
    console.log('jQuery version:', typeof $ !== 'undefined' ? $.fn.jquery : 'not loaded');
    
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
        
        // Initialize form enhancements
        const givenNameField = document.getElementById('givenName');
        const surnameField = document.getElementById('sn');
        const displayField = document.getElementById('cn');
        const emailField = document.getElementById('mail');
        const passwordField = document.getElementById('password');
        
        // Auto-generate display name from first and last name
        if (givenNameField && surnameField && displayField) {
            function updateDisplayName() {
                const givenName = givenNameField.value.trim();
                const surname = surnameField.value.trim();
                if (givenName && surname) {
                    displayField.value = givenName + ' ' + surname;
                }
            }
            
            givenNameField.addEventListener('input', updateDisplayName);
            surnameField.addEventListener('input', updateDisplayName);
        }
        
        // Auto-generate account ID from email
        if (emailField && document.getElementById('<?php echo $LDAP["account_attribute"]; ?>')) {
            emailField.addEventListener('input', function() {
                const accountField = document.getElementById('<?php echo $LDAP["account_attribute"]; ?>');
                if (accountField) {
                    accountField.value = this.value.trim();
                }
            });
        }
    });
    
    // Lock/Unlock user functions
    function confirmLockUser(userIdentifier, userName) {
        console.log('confirmLockUser called with:', userIdentifier, userName);
        document.getElementById('lockUserIdentifier').value = userIdentifier;
        document.getElementById('lockUserName').textContent = userName;
        
        // Check if jQuery and modal are available
        if (typeof $ === 'undefined') {
            console.error('jQuery is not loaded');
            alert('Error: jQuery is not loaded. Please refresh the page.');
            return;
        }
        
        if (typeof $.fn.modal === 'undefined') {
            console.error('Bootstrap modal is not available');
            alert('Error: Bootstrap modal is not available. Please refresh the page.');
            return;
        }
        
        $('#lockUserModal').modal('show');
    }
    
    function confirmUnlockUser(userIdentifier, userName) {
        console.log('confirmUnlockUser called with:', userIdentifier, userName);
        document.getElementById('unlockUserIdentifier').value = userIdentifier;
        document.getElementById('unlockUserName').textContent = userName;
        
        // Check if jQuery and modal are available
        if (typeof $ === 'undefined') {
            console.error('jQuery is not loaded');
            alert('Error: jQuery is not loaded. Please refresh the page.');
            return;
        }
        
        if (typeof $.fn.modal === 'undefined') {
            console.error('Bootstrap modal is not available');
            alert('Error: Bootstrap modal is not available. Please refresh the page.');
            return;
        }
        
        $('#unlockUserModal').modal('show');
    }
    
    // Edit user modal function
    function openEditModal(userIdentifier, userName) {
        console.log('openEditModal called with:', userIdentifier, userName);
        
        // Check if jQuery and modal are available
        if (typeof $ === 'undefined') {
            console.error('jQuery is not loaded');
            alert('Error: jQuery is not loaded. Please refresh the page.');
            return;
        }
        
        if (typeof $.fn.modal === 'undefined') {
            console.error('Bootstrap modal is not available');
            alert('Error: Bootstrap modal is not available. Please refresh the page.');
            return;
        }
        
        // Set the user identifier in the modal
        document.getElementById('edit_uid_input').value = userIdentifier;
        
        // Show the modal
        $('#editUserModal').modal('show');
    }
</script>
<?php
// Close LDAP connection
ldap_close($ldap_connection);

render_footer();
?> 
