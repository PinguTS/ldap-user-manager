<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";
include_once "user_functions.inc.php";

// Ensure CSRF token is generated early
get_csrf_token();

set_page_access("admin");

render_header("$ORGANISATION_NAME - System Role Management");
render_submenu();

$ldap_connection = open_ldap_connection();

if (isset($_POST['add_user_to_role'])) {
  if (!validate_csrf_token()) {
    render_alert_banner("Security validation failed. Please refresh the page and try again.", "danger");
  } else {
    $user_identifier = $_POST['username'];
    $role_name = $_POST['role_name'];
    
    // Convert UUID to username if needed
    $username = get_username_from_identifier($ldap_connection, $user_identifier);
    
    if (ldap_add_member_to_group($ldap_connection, $role_name, $username)) {
      render_alert_banner("User <strong>$username</strong> was added to role <strong>$role_name</strong>.");
    } else {
      render_alert_banner("Failed to add user <strong>$username</strong> to role <strong>$role_name</strong>.", "danger", 15000);
    }
  }
}

if (isset($_POST['remove_user_from_role'])) {
  if (!validate_csrf_token()) {
    render_alert_banner("Security validation failed. Please refresh the page and try again.", "danger");
  } else {
    $user_identifier = $_POST['username'];
    $role_name = $_POST['role_name'];
    
    // Convert UUID to username if needed
    $username = get_username_from_identifier($ldap_connection, $user_identifier);
    
    // Prevent user from removing themselves from administrators role
    $current_user = $_SESSION['username'] ?? '';
    if ($username === $current_user && $role_name === $LDAP['admin_role']) {
      render_alert_banner("You cannot remove yourself from the administrators role. This would lock you out of the system.", "danger", 15000);
    } else {
      if (ldap_delete_member_from_group($ldap_connection, $role_name, $username)) {
        render_alert_banner("User <strong>$username</strong> was removed from role <strong>$role_name</strong>.");
      } else {
        render_alert_banner("Failed to remove user <strong>$username</strong> from role <strong>$role_name</strong>.", "danger", 15000);
      }
    }
  }
}

# Get only system users (not organization users)
# First, let's try the same approach that works for organization users
if ($LDAP_DEBUG) {
  error_log("Debug: Testing direct LDAP search like organization users do");
  $direct_search = @ldap_search($ldap_connection, $LDAP['people_dn'], '(objectClass=inetOrgPerson)', ['uid', 'cn', 'sn', 'mail', 'entryUUID']);
  if ($direct_search) {
    $direct_result = ldap_get_entries($ldap_connection, $direct_search);
    if ($direct_result && $direct_result['count'] > 0) {
      $first_user = $direct_result[0];
      error_log("Debug: Direct search (like org users) - First user attributes: " . print_r($first_user, true));
      error_log("Debug: Direct search (like org users) - Available keys: " . print_r(array_keys($first_user), true));
      
      // Check specifically for entryUUID
      if (isset($first_user['entryUUID'])) {
        error_log("Debug: Direct search - entryUUID found: " . print_r($first_user['entryUUID'], true));
      } else {
        // Check for case-insensitive match
        $found_uuid = false;
        foreach (array_keys($first_user) as $key) {
          // Skip numeric keys (array indices)
          if (is_string($key) && strcasecmp($key, 'entryUUID') === 0) {
            error_log("Debug: Direct search - entryUUID found with different casing ($key): " . print_r($first_user[$key], true));
            $found_uuid = true;
            break;
          }
        }
        if (!$found_uuid) {
          error_log("Debug: Direct search - entryUUID NOT found (case-insensitive check)");
        }
      }
    }
  }
}

# Now get users with our normal function
$required_fields = ['entryUUID', 'sn', 'mail', 'cn', 'givenname'];
$users = ldap_get_system_users($ldap_connection, 0, null, 'asc', null, null, $required_fields);

# Debug: Let's see what we're actually getting from the normal function
if ($LDAP_DEBUG) {
  error_log("Debug: Normal function - Requested fields: " . print_r($required_fields, true));
  error_log("Debug: Normal function - Account attribute (sort_key): " . $LDAP['account_attribute']);
  error_log("Debug: Normal function - Users data structure:");
  foreach ($users as $username => $attribs) {
    error_log("Debug: Normal function - Username: $username, Attributes: " . print_r($attribs, true));
    error_log("Debug: Normal function - Available keys: " . print_r(array_keys($attribs), true));
    
    // Check specifically for entryUUID
    if (isset($attribs['entryUUID'])) {
      error_log("Debug: Normal function - entryUUID found for $username: " . print_r($attribs['entryUUID'], true));
    } else {
      // Check for case-insensitive match
      $found_uuid = false;
      foreach (array_keys($attribs) as $key) {
        // Skip numeric keys (array indices)
        if (is_string($key) && strcasecmp($key, 'entryUUID') === 0) {
          error_log("Debug: Normal function - entryUUID found with different casing ($key) for $username: " . print_r($attribs[$key], true));
          $found_uuid = true;
          break;
        }
      }
      if (!$found_uuid) {
        error_log("Debug: Normal function - entryUUID NOT found for $username (case-insensitive check)");
      }
    }
  }
}

# Get global roles only
$global_roles = array($LDAP['admin_role'], $LDAP['maintainer_role']);

# Use only global roles for system role management
$all_roles = $global_roles;

?>

<div class="container">
  <h2>System Role Management</h2>
  
  <div class="alert alert-info">
    <strong>Global System Roles:</strong> This page manages system-level roles (administrator, maintainer) for system users only. 
    Organization user roles are managed within their respective organization pages.
  </div>
  
  <div class="alert alert-warning">
    <strong>Organization Role Management:</strong> To manage roles for organization users, go to 
    <a href="/manage/organizations/">Organizations</a> → select an organization → Users → manage individual user roles.
  </div>
  
  <div class="row">
    <div class="col-md-6">
      <h3>Add System User to Role</h3>
      <form method="post" class="form">
        <?= csrf_token_field() ?>
        <div class="form-group">
          <label for="username">System User:</label>
          <select name="username" id="username" class="form-control" required>
            <option value="">Select a system user...</option>
            <?php foreach ($users as $username => $attribs): ?>
              <?php 
                if ($LDAP_DEBUG) {
                  error_log("Debug: Processing user: $username");
                  error_log("Debug: User attributes: " . print_r($attribs, true));
                }
                $user_identifier = get_user_identifier($attribs, $username); 
                if ($LDAP_DEBUG) {
                  error_log("Debug: User identifier returned: " . $user_identifier);
                  error_log("Debug: User identifier length: " . strlen($user_identifier));
                  error_log("Debug: User identifier type: " . gettype($user_identifier));
                }
              ?>
              <option value="<?php print htmlspecialchars($user_identifier); ?>">
                <?php 
                $display_name = format_user_display_name($username, $attribs);
                print $display_name;
                ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label for="role_name">Role:</label>
          <select name="role_name" id="role_name" class="form-control" required>
            <option value="">Select a role...</option>
            <optgroup label="Global Roles">
              <?php foreach ($global_roles as $role): ?>
                <option value="<?php print htmlspecialchars($role); ?>">
                  <?php print htmlspecialchars(ucfirst($role)); ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
        
        <button type="submit" name="add_user_to_role" class="btn btn-success">Add System User to Role</button>
      </form>
    </div>
    
    <div class="col-md-6">
      <h3>Remove System User from Role</h3>
      <form method="post" class="form">
        <?= csrf_token_field() ?>
        <div class="form-group">
          <label for="remove_username">System User:</label>
          <select name="username" id="remove_username" class="form-control" required>
            <option value="">Select a system user...</option>
            <?php foreach ($users as $username => $attribs): ?>
              <?php 
                if ($LDAP_DEBUG) {
                  error_log("Debug: Processing user: $username");
                  error_log("Debug: User attributes: " . print_r($attribs, true));
                }
                $user_identifier = get_user_identifier($attribs, $username); 
                if ($LDAP_DEBUG) {
                  error_log("Debug: User identifier returned: " . $user_identifier);
                }
              ?>
              <option value="<?php print htmlspecialchars($user_identifier); ?>">
                <?php 
                $display_name = format_user_display_name($username, $attribs);
                print $display_name;
                ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label for="remove_role_name">Role:</label>
          <select name="role_name" id="remove_role_name" class="form-control" required>
            <option value="">Select a role...</option>
            <optgroup label="Global Roles">
              <?php foreach ($global_roles as $role): ?>
                <option value="<?php print htmlspecialchars($role); ?>">
                  <?php print htmlspecialchars(ucfirst($role)); ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
        
        <button type="submit" name="remove_user_from_role" class="btn btn-danger">Remove System User from Role</button>
      </form>
      
      <div class="alert alert-warning" style="margin-top: 15px;">
        <strong>Security Note:</strong> You cannot remove yourself from the administrators role as this would lock you out of the system.
        <?php if (!empty($_SESSION['username'])): ?>
          <br><small>Currently logged in as: <strong><?php print htmlspecialchars($_SESSION['username']); ?></strong></small>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <hr>
  
  <h3>Current System Role Memberships</h3>
  <div class="alert alert-info">
    <strong>Global Roles Only:</strong> This section shows members of system-level roles only. 
    Organization user role management is done within each organization's user management page.
  </div>
  <div class="row">
    <?php foreach ($all_roles as $role): ?>
      <div class="col-md-6">
        <div class="panel panel-default">
          <div class="panel-heading">
            <h4 class="panel-title">
              <?php print htmlspecialchars(ucfirst($role)); ?> Role
              <span class="label label-primary">Global</span>
            </h4>
          </div>
          <div class="panel-body">
            <?php 
            $members = ldap_get_role_members($ldap_connection, $role);
            if ($members && count($members) > 0): ?>
              <ul class="list-group">
                <?php foreach ($members as $member_dn): ?>
                  <li class="list-group-item">
                    <?php 
                    $display_name = get_user_display_from_dn($ldap_connection, $member_dn);
                    print $display_name;
                    
                    // Extract username from DN for comparison with current user
                    if (preg_match('/uid=([^,]+)/', $member_dn, $matches)) {
                      $member_username = $matches[1];
                      if ($member_username === ($_SESSION['username'] ?? '')) {
                        print ' <span class="label label-info pull-right">You</span>';
                      }
                    }
                    ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="text-muted">No members in this role.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const removeUsernameSelect = document.getElementById('remove_username');
  const removeRoleSelect = document.getElementById('remove_role_name');
  const currentUser = '<?php print htmlspecialchars($_SESSION['username'] ?? ''); ?>';
  const adminRole = '<?php print htmlspecialchars($LDAP['admin_role']); ?>';
  
  function checkSelfRemoval() {
    const selectedUserIdentifier = removeUsernameSelect.value;
    const selectedRole = removeRoleSelect.value;
    
    // Remove any existing warning
    const existingWarning = document.getElementById('self-removal-warning');
    if (existingWarning) {
      existingWarning.remove();
    }
    
    // Check if user is trying to remove themselves from administrators role
    // We need to check if the selected user identifier corresponds to the current user
    // This will be handled server-side, but we can show a general warning
    if (selectedRole === adminRole) {
      // Show warning for any administrator role removal
      const warning = document.createElement('div');
      warning.id = 'self-removal-warning';
      warning.className = 'alert alert-warning';
      warning.style.marginTop = '15px';
      warning.innerHTML = '<strong>⚠️ Security Note:</strong> Removing users from the administrators role requires careful consideration. You cannot remove yourself from this role.';
      
      // Insert warning after the form
      const form = removeUsernameSelect.closest('form');
      form.parentNode.insertBefore(warning, form.nextSibling);
    }
  }
  
  // Add event listeners
  removeUsernameSelect.addEventListener('change', checkSelfRemoval);
  removeRoleSelect.addEventListener('change', checkSelfRemoval);
  
  // Check on page load
  checkSelfRemoval();
});
</script>

<?php

ldap_close($ldap_connection);
render_footer();
?> 
