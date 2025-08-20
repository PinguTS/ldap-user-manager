<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";
include_once "organization_functions.inc.php";

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
if (!(currentUserIsGlobalAdmin() || currentUserIsMaintainer() || currentUserIsOrgManager($orgName))) {
    render_header('Organization User Management');
    echo "<div class='alert alert-danger'>You do not have permission to access this page.";
    render_footer();
    exit;
}
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
    $attributes = ['uid', 'cn', 'sn', 'mail'];
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
    $uid = $_GET['uid'];
    $userDn = getUserDn($orgName, $uid);
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
    $givenname = trim($_POST['givenname']);
    $sn = trim($_POST['sn']);
    $mail = trim($_POST['mail']);
    $password = $_POST['password'];
    $passcode = $_POST['passcode'];
    
    // Use email as username since that's how the system is configured
    $uid = $mail;
    
    if ($givenname === '' || $sn === '' || $mail === '' || $password === '') {
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
        'givenname' => $givenname,
        'sn' => $sn,
        'cn' => $givenname . ' ' . $sn, // Construct cn from givenname + sn
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
    $uidToDelete = $_GET['delete_user'];
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    $userDn = "uid=" . ldap_escape($uidToDelete, '', LDAP_ESCAPE_DN) . ",$usersDn";
    $ldap = open_ldap_connection();
    try {
        ldap_delete($ldap, $userDn);
        $message = 'User deleted successfully.';
        $message_type = 'warning';
    } catch (Exception $e) {
        $message = 'Error deleting user: ' . htmlspecialchars($e->getMessage());
        $message_type = 'danger';
    }
}

// Handle edit user
$editUser = null;
if (isset($_GET['edit_user'])) {
    $uidToEdit = $_GET['edit_user'];
    $existingUsers = getUsersInOrg($orgName);
    if (!is_array($existingUsers)) {
        $existingUsers = [];
    }
    foreach ($existingUsers as $user) {
        if (strtolower($user['uid'][0]) === strtolower($uidToEdit)) {
            $editUser = $user;
            break;
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf_token();
    }
    $uid = trim($_POST['edit_uid']);
    $givenname = trim($_POST['edit_givenname']);
    $sn = trim($_POST['edit_sn']);
    $mail = trim($_POST['edit_mail']);
    $password = $_POST['edit_password'];
    $passcode = $_POST['edit_passcode'];
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    $userDn = "uid=" . ldap_escape($uid, '', LDAP_ESCAPE_DN) . ",$usersDn";
    $ldap = open_ldap_connection();
    $entry = [
        'givenname' => $givenname,
        'sn' => $sn,
        'cn' => $givenname . ' ' . $sn, // Construct cn from givenname + sn
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
}

// Handle reset password/passcode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_creds'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf_token();
    }
    $uid = trim($_POST['reset_uid']);
    $new_password = $_POST['reset_password'];
    $new_passcode = $_POST['reset_passcode'];
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDn = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    $userDn = "uid=" . ldap_escape($uid, '', LDAP_ESCAPE_DN) . ",$usersDn";
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
}

$users = getUsersInOrg($orgName);
if (!is_array($users)) {
    $users = [];
}
$orgManagerDns = getOrgManagerDns($orgName);
?>
<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="organizations.php">Organizations</a></li>
            <li class="breadcrumb-item"><a href="show_organization.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName); ?>"><?= htmlspecialchars($orgDisplay) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Users</li>
        </ol>
    </nav>
    <h2>Users in Organization: <?= htmlspecialchars($orgDisplay) ?></h2>
    <a href="show_organization.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName); ?>" class="btn btn-secondary mb-3">&larr; Back to Organization</a>
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
                $isManager = in_array(getUserDn($orgName, $user['uid'][0] ?? ''), $orgManagerDns);
            ?>
                <tr<?= $isManager ? ' class="table-success"' : '' ?>>
                    <td><?= htmlspecialchars($user['uid'][0] ?? '') ?></td>
                    <td><?= safe_display_name($user); ?></td>
                    <td><?= htmlspecialchars($user['mail'][0] ?? '') ?></td>
                    <td>
                        <form method="get" style="display:inline">
                            <input type="hidden" name="<?= $org_uuid ? 'uuid' : 'org' ?>" value="<?= htmlspecialchars($org_uuid ?: $orgName) ?>">
                            <input type="hidden" name="uid" value="<?= htmlspecialchars($user['uid'][0] ?? '') ?>">
                            <input type="hidden" name="toggle_manager" value="1">
                            <input type="checkbox" onchange="this.form.submit()" <?= $isManager ? 'checked' : '' ?> title="Toggle Org Manager role">
                        </form>
                    </td>
                    <td>
                        <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&edit_user=<?= urlencode($user['uid'][0]) ?>" class="btn btn-secondary btn-sm">Edit</a>
                        <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&delete_user=<?= urlencode($user['uid'][0]) ?>" onclick="return confirm('Are you sure you want to delete this user?');" class="btn btn-danger btn-sm">Delete</a>
                        <a href="?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>&reset_user=<?= urlencode($user['uid'][0]) ?>" class="btn btn-warning btn-sm">Reset</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <h3>Add User to Organization</h3>
    
    <!-- Link to full user creation form -->
    <div class="alert alert-info">
        <strong>Need to add a user?</strong> Use the full user creation form for complete user setup with all options.
        <a href="add_org_user.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName); ?>" class="btn btn-success btn-sm ml-2">Create New User</a>
    </div>
    
    <h4>Quick Add User</h4>
    <p class="text-muted">Add a new user to this organization. The email address will be used as the username for login.</p>
    <form method="post" class="mb-4" id="add_user_form" onsubmit="return validateAddUserForm();">
        <?= csrf_token_field() ?>
        <div class="form-group">
            <label for="givenname">First Name</label>
            <input type="text" class="form-control" name="givenname" id="givenname" required>
        </div>
        <div class="form-group">
            <label for="sn">Last Name</label>
            <input type="text" class="form-control" name="sn" id="sn" required>
        </div>
        <div class="form-group">
            <label for="mail">Email (Username)</label>
            <input type="email" class="form-control" name="mail" id="mail" required>
            <small class="form-text text-muted">Email will be used as the username for login</small>
        </div>
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
    <?php if ($editUser): ?>
    <div class="modal show" tabindex="-1" style="display:block; background:rgba(0,0,0,0.3); z-index:1050;">
      <div class="modal-dialog">
        <div class="modal-content border-primary">
          <form method="post">
            <?= csrf_token_field() ?>
            <div class="modal-header bg-primary text-white">
              <h5 class="modal-title">Edit User: <?= htmlspecialchars($editUser['uid'][0]) ?></h5>
              <a href="org_users.php?<?= $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($orgName) ?>" class="close text-white">&times;</a>
            </div>
            <div class="modal-body">
              <input type="hidden" name="edit_uid" value="<?= htmlspecialchars($editUser['uid'][0]) ?>">
              <div class="form-group">
                <label for="edit_givenname">First Name</label>
                <input type="text" class="form-control" name="edit_givenname" id="edit_givenname" value="<?= htmlspecialchars($editUser['givenname'][0] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label for="edit_sn">Last Name</label>
                <input type="text" class="form-control" name="edit_sn" id="edit_sn" value="<?= htmlspecialchars($editUser['sn'][0] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label for="edit_mail">Email</label>
                <input type="email" class="form-control" name="edit_mail" id="edit_mail" value="<?= htmlspecialchars($editUser['mail'][0] ?? '') ?>" required>
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
              <a href="org_users.php?org=<?= urlencode($orgName) ?>" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['reset_user'])): $resetUid = $_GET['reset_user']; ?>
    <div class="modal show" tabindex="-1" style="display:block; background:rgba(0,0,0,0.3); z-index:1050;">
      <div class="modal-dialog">
        <div class="modal-content border-warning">
          <form method="post">
            <?= csrf_token_field() ?>
            <div class="modal-header bg-warning text-dark">
              <h5 class="modal-title">Reset Credentials for <?= htmlspecialchars($resetUid) ?></h5>
              <a href="org_users.php?org=<?= urlencode($orgName) ?>" class="close text-dark">&times;</a>
            </div>
            <div class="modal-body">
              <input type="hidden" name="reset_uid" value="<?= htmlspecialchars($resetUid) ?>">
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
              <a href="org_users.php?org=<?= urlencode($orgName) ?>" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <p class="text-muted mt-2">(Role management and UI refinements complete.)</p>
</div>
<script>
// Client-side validation for add user form
function validateAddUserForm() {
    var required = ['cn','sn','mail','password'];
    for (var i=0; i<required.length; i++) {
        var el = document.getElementById(required[i]);
        if (!el.value.trim()) {
            alert('Please fill in all required fields.');
            el.focus();
            return false;
        }
    }
    document.getElementById('add_user_btn').disabled = true;
    document.getElementById('add_user_spinner').style.display = '';
    return true;
}
// Search/filter for users
const userSearchInput = document.getElementById('user_search_input');
if (userSearchInput) {
    userSearchInput.addEventListener('keyup', function() {
        var value = this.value.toLowerCase();
        var rows = document.querySelectorAll('#user_table tbody tr');
        rows.forEach(function(row) {
            row.style.display = row.textContent.toLowerCase().indexOf(value) > -1 ? '' : 'none';
        });
    });
}
// Auto-dismiss feedback messages after 4 seconds
setTimeout(function() {
    var msg = document.getElementById('msgbox');
    if (msg) { msg.style.display = 'none'; }
}, 4000);
</script>
<?php
render_footer(); 