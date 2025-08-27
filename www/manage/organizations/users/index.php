<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once dirname(dirname(__DIR__)) . "/module_functions.inc.php";
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
    
    // Build entry and attempt ldap_add
    $entry = [
        'objectClass' => ['inetOrgPerson', 'top'],
        'uid' => $uid,
        'givenname' => $givenName,
        'sn' => $sn,
        'cn' => $cn, // Use the cn from the form
        'mail' => $mail,
        'userPassword' => password_hash($password, PASSWORD_DEFAULT), // For demo; use LDAP hash in production
    ];
    if ($passcode !== '') {
      # Add passcode to userPassword (multiple values supported)
      if (!isset($entry['userPassword'])) {
        $entry['userPassword'] = array();
      }
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
        $entry['userPassword'] = password_hash($password, PASSWORD_DEFAULT); // For demo; use LDAP hash in production
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
        $entry['userPassword'] = password_hash($new_password, PASSWORD_DEFAULT);
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
<script src="/js/jquery-3.6.0.min.js"></script>
<script src="/js/user_management.min.js"></script>
<script>
    // Debug: Check if jQuery and Bootstrap are loaded
    console.log('jQuery version:', typeof $ !== 'undefined' ? $.fn.jquery : 'NOT LOADED');
    console.log('Bootstrap modal:', typeof $ !== 'undefined' && $.fn.modal ? 'AVAILABLE' : 'NOT AVAILABLE');
    console.log('Bootstrap object:', typeof $ !== 'undefined' ? $.fn : 'jQuery not available');
    console.log('Bootstrap version check:', typeof $ !== 'undefined' ? Object.keys($.fn).filter(key => key.includes('modal')) : 'jQuery not available');
    
    // Check if Bootstrap is loaded by looking for specific functions
    if (typeof $ !== 'undefined') {
        console.log('Available jQuery plugins:', Object.keys($.fn));
        console.log('Bootstrap modal function:', typeof $.fn.modal);
        console.log('Bootstrap dropdown function:', typeof $.fn.dropdown);
        console.log('Bootstrap tooltip function:', typeof $.fn.tooltip);
    }
    
    // Debug: Check Bootstrap file accessibility
    console.log('Checking Bootstrap file accessibility...');
    $.ajax({
        url: '/bootstrap/js/bootstrap.min.js',
        method: 'HEAD',
        success: function() {
            console.log('✅ Bootstrap JS file is accessible');
        },
        error: function(xhr, status, error) {
            console.error('❌ Bootstrap JS file is NOT accessible:', status, error);
            console.log('Response status:', xhr.status);
            console.log('Response text:', xhr.responseText);
        }
    });
    
    // Debug: Check what scripts are actually loaded
    console.log('Scripts in document:', document.querySelectorAll('script[src]'));
    Array.from(document.querySelectorAll('script[src]')).forEach(script => {
        console.log('Script src:', script.src);
    });
    
    // Debug: Try to manually load Bootstrap
    console.log('Attempting to manually load Bootstrap...');
    var bootstrapScript = document.createElement('script');
    bootstrapScript.src = '/bootstrap/js/bootstrap.min.js';
    bootstrapScript.onload = function() {
        console.log('✅ Bootstrap manually loaded successfully');
        console.log('Bootstrap modal function now available:', typeof $ !== 'undefined' && $.fn.modal);
    };
    bootstrapScript.onerror = function() {
        console.error('❌ Failed to manually load Bootstrap');
    };
    document.head.appendChild(bootstrapScript);
    
    // Initialize form enhancements when page loads
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, initializing forms...');
        initializeUserManagementForms({
            givenNameField: 'edit_givenname',
            surnameField: 'edit_sn',
            displayField: 'edit_cn',
            emailField: 'edit_mail'
        });
    });

    // Function to open edit modal and populate with user data
    function openEditModal(userIdentifier, userUid) {
        console.log('openEditModal called with:', userIdentifier, userUid);
        
        // Check if jQuery is available
        if (typeof $ === 'undefined') {
            console.error('jQuery is not loaded!');
            alert('Error: jQuery not loaded. Please refresh the page.');
            return;
        }
        
        // Check if modal element exists
        const modalElement = document.getElementById('editUserModal');
        if (!modalElement) {
            console.error('Modal element not found!');
            alert('Error: Edit modal not found. Please refresh the page.');
            return;
        }
        
        console.log('Modal element found:', modalElement);
        console.log('Opening modal...');
        
        try {
            // Try Bootstrap 3 modal first
            if (typeof $.fn.modal !== 'undefined') {
                $('#editUserModal').modal('show');
                console.log('Bootstrap modal opened successfully');
            } else {
                // Fallback: manually show the modal
                console.log('Bootstrap modal not available, using manual fallback');
                $('#editUserModal').show();
                $('body').addClass('modal-open');
                $('#editUserModal').addClass('in');
                $('#editUserModal').attr('aria-hidden', 'false');
                
                // Add backdrop
                if (!$('.modal-backdrop').length) {
                    $('body').append('<div class="modal-backdrop fade in"></div>');
                }
            }
            
            // Set the user identifier in the hidden field
            $('#edit_uid_input').val(userIdentifier);
            console.log('User identifier set:', userIdentifier);
            
            // Fetch user data and populate the form
            fetchUserData(userIdentifier, userUid);
        } catch (error) {
            console.error('Error opening modal:', error);
            // Fallback: try manual modal display
            try {
                console.log('Trying manual modal display...');
                $('#editUserModal').show();
                $('body').addClass('modal-open');
                $('#editUserModal').addClass('in');
                $('#editUserModal').attr('aria-hidden', 'false');
                
                // Set the user identifier in the hidden field
                $('#edit_uid_input').val(userIdentifier);
                console.log('User identifier set (fallback):', userIdentifier);
                
                // Fetch user data and populate the form
                fetchUserData(userIdentifier, userUid);
            } catch (fallbackError) {
                console.error('Fallback modal display also failed:', fallbackError);
                alert('Error opening edit modal. Please refresh the page and try again.');
            }
        }
    }

    // Function to fetch user data via AJAX
    function fetchUserData(userIdentifier, userUid) {
        console.log('fetchUserData called with:', userIdentifier, userUid);
        
        const orgParam = '<?= $org_uuid ? "uuid" : "org" ?>';
        const orgValue = '<?= $org_uuid ?: $orgName ?>';
        const csrfToken = '<?= get_csrf_token() ?>';
        
        // Make AJAX request to get user data
        $.ajax({
            url: 'ajax_handler.php',
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            data: {
                action: 'fetch_user_data',
                [orgParam]: orgValue,
                'fetch_user_data': userIdentifier,
                'csrf_token': csrfToken
            },
            success: function(response) {
                console.log('User data received:', response);
                
                if (response.success && response.user_data) {
                    // Populate form fields with user data
                    $('#edit_givenname').val(response.user_data.givenName || '');
                    $('#edit_sn').val(response.user_data.sn || '');
                    $('#edit_mail').val(response.user_data.mail || '');
                    $('#editUserModalLabel').text('Edit User: ' + (response.user_data.uid || userUid));
                } else {
                    // No user data found or error
                    console.log('No user data or error:', response.error || 'Unknown error');
                    $('#edit_givenname').val('');
                    $('#edit_sn').val('');
                    $('#edit_mail').val('');
                    $('#editUserModalLabel').text('Edit User: ' + userUid);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error);
                console.log('Response status:', xhr.status);
                console.log('Response text:', xhr.responseText);
                
                // Try to parse error response
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse.error) {
                        console.error('Server error:', errorResponse.error);
                    }
                } catch (e) {
                    console.error('Could not parse error response');
                }
                
                // Fallback: clear fields
                $('#edit_givenname').val('');
                $('#edit_sn').val('');
                $('#edit_mail').val('');
                $('#editUserModalLabel').text('Edit User: ' + userUid);
            }
        });
    }
        
        // Test function to verify modal works
        function testModal() {
            console.log('Testing modal functionality...');
            if (typeof $ !== 'undefined' && $.fn.modal) {
                $('#editUserModal').modal('show');
                console.log('Test modal opened successfully');
            } else {
                console.log('Bootstrap modal not available, using manual fallback');
                $('#editUserModal').show();
                $('body').addClass('modal-open');
                $('#editUserModal').addClass('in');
                $('#editUserModal').attr('aria-hidden', 'false');
                
                // Add backdrop
                if (!$('.modal-backdrop').length) {
                    $('body').append('<div class="modal-backdrop fade in"></div>');
                }
                console.log('Test modal opened with fallback');
            }
        }
        
        // Test function to check session status
        function testSession() {
            console.log('Testing session status...');
            $.ajax({
                url: 'ajax_handler.php',
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                data: {
                    action: 'test_session'
                },
                success: function(response) {
                    console.log('Session test response:', response);
                },
                error: function(xhr, status, error) {
                    console.error('Session test error:', status, error);
                    console.log('Response status:', xhr.status);
                    console.log('Response text:', xhr.responseText);
                }
            });
        }
        
        // Function to close modal manually
        function closeModal() {
            if (typeof $ !== 'undefined' && $.fn.modal) {
                $('#editUserModal').modal('hide');
            } else {
                $('#editUserModal').hide();
                $('body').removeClass('modal-open');
                $('#editUserModal').removeClass('in');
                $('#editUserModal').attr('aria-hidden', 'true');
                $('.modal-backdrop').remove();
            }
        }
        
        // Add click handlers for modal close buttons
        $(document).ready(function() {
            // Handle close button clicks
            $(document).on('click', '[data-dismiss="modal"]', function() {
                closeModal();
            });
            
            // Handle clicking outside modal to close
            $(document).on('click', '#editUserModal', function(e) {
                if (e.target === this) {
                    closeModal();
                }
            });
            
            // Handle escape key
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) { // ESC key
                    closeModal();
                }
            });
        });
    </script>
<?php
render_footer(); 
