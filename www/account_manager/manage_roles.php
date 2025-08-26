<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";

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
    $username = $_POST['username'];
    $role_name = $_POST['role_name'];
    
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
    $username = $_POST['username'];
    $role_name = $_POST['role_name'];
    
    if (ldap_delete_member_from_group($ldap_connection, $role_name, $username)) {
      render_alert_banner("User <strong>$username</strong> was removed from role <strong>$role_name</strong>.");
    } else {
      render_alert_banner("Failed to remove user <strong>$username</strong> from role <strong>$role_name</strong>.", "danger", 15000);
    }
  }
}

# Get only system users (not organization users)
$users = ldap_get_system_users($ldap_connection);

# Get global roles
$global_roles = array($LDAP['admin_role'], $LDAP['maintainer_role']);

# Get organization-specific roles
$org_roles = array();
# First get all organizations
$orgs_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], "(objectclass=organization)", array('o'));
if ($orgs_search) {
  $orgs_result = @ ldap_get_entries($ldap_connection, $orgs_search);
  for ($i = 0; $i < $orgs_result['count']; $i++) {
    if (isset($orgs_result[$i]['o'][0])) {
      $org_name = $orgs_result[$i]['o'][0];
      $org_roles_dn = "ou=roles,o=" . ldap_escape($org_name, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
      
      # Check if the roles directory exists for this organization
      $roles_dir_exists = @ ldap_read($ldap_connection, $org_roles_dn, '(objectClass=*)', ['dn']);
      if ($roles_dir_exists) {
        # Search for roles under ou=roles within this organization
        $roles_search = @ ldap_search($ldap_connection, $org_roles_dn, "(objectclass=groupOfNames)", array('cn'));
        if ($roles_search) {
          $roles_result = @ ldap_get_entries($ldap_connection, $roles_search);
          for ($j = 0; $j < $roles_result['count']; $j++) {
            if (isset($roles_result[$j]['cn'][0])) {
              $org_roles[] = $roles_result[$j]['cn'][0];
            }
          }
        }
      }
    }
  }
}

$all_roles = array_merge($global_roles, $org_roles);

?>

<div class="container">
  <h2>System Role Management</h2>
  
  <div class="alert alert-info">
    <strong>System Users Only:</strong> This page manages roles for system-level users only. 
    Organization user roles are managed within their respective organization pages.
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
              <option value="<?php print htmlspecialchars($username); ?>">
                <?php print htmlspecialchars($username); ?> 
                (<?php print safe_display_name($attribs); ?>)
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
            <?php if (!empty($org_roles)): ?>
              <optgroup label="Organization Roles (Read-Only)">
                <?php foreach ($org_roles as $role): ?>
                  <option value="<?php print htmlspecialchars($role); ?>" disabled>
                    <?php print htmlspecialchars(ucfirst($role)); ?> - Manage in Organization
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
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
              <option value="<?php print htmlspecialchars($username); ?>">
                <?php print htmlspecialchars($username); ?> 
                (<?php print safe_display_name($attribs); ?>)
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
            <?php if (!empty($org_roles)): ?>
              <optgroup label="Organization Roles (Read-Only)">
                <?php foreach ($org_roles as $role): ?>
                  <option value="<?php print htmlspecialchars($role); ?>" disabled>
                    <?php print htmlspecialchars(ucfirst($role)); ?> - Manage in Organization
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
        </div>
        
        <button type="submit" name="remove_user_from_role" class="btn btn-danger">Remove System User from Role</button>
      </form>
    </div>
  </div>
  
  <hr>
  
  <h3>Current System Role Memberships</h3>
  <div class="alert alert-warning">
    <strong>Note:</strong> This section shows members of all roles (both system and organization roles), 
    but you can only manage system users here. Organization user role management is done within each organization.
  </div>
  <div class="row">
    <?php foreach ($all_roles as $role): ?>
      <div class="col-md-6">
        <div class="panel panel-default">
          <div class="panel-heading">
            <h4 class="panel-title">
              <?php print htmlspecialchars(ucfirst($role)); ?> Role
              <?php if (in_array($role, $global_roles)): ?>
                <span class="label label-primary">Global</span>
              <?php else: ?>
                <span class="label label-info">Organization</span>
              <?php endif; ?>
            </h4>
          </div>
          <div class="panel-body">
            <?php 
            $members = ldap_get_role_members($ldap_connection, $role);
            if ($members && count($members) > 0): ?>
              <ul class="list-group">
                <?php foreach ($members as $member): ?>
                  <li class="list-group-item">
                    <?php print htmlspecialchars($member); ?>
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

<?php

ldap_close($ldap_connection);
render_footer();
?> 