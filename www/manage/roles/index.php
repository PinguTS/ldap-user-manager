<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage(['ldap', 'user']);

// Ensure CSRF token is generated early
getCsrfToken();

setPageAccess("admin");

$orgName = (string) ($ORGANISATION_NAME ?? 'System');
renderHeader(t('manage.roles.page_title', ['org' => $orgName]));
render_submenu();

$ldap_connection = lum_ldap_data_connection();
if ($ldap_connection === false) {
    renderAlertBanner(t('manage.users.msg.ldap_unavailable'), 'danger');
    $users = [];
    $all_roles = [];
} else {
    if (isset($_POST['add_user_to_role'])) {
        if (!validateCsrfToken()) {
            renderAlertBanner(t('manage.common.msg.security_validation_failed'), "danger");
        } else {
            $user_identifier = $_POST['username'];
            $role_name = $_POST['role_name'];

            // Convert UUID to username if needed
            $username = get_username_from_identifier($ldap_connection, $user_identifier);

            if (ldap_add_member_to_group($ldap_connection, $role_name, $username)) {
                renderAlertBanner(t('manage.roles.msg.add_ok', ['user' => $username, 'role' => $role_name]));
            } else {
                renderAlertBanner(t('manage.roles.msg.add_fail', ['user' => $username, 'role' => $role_name]), "danger", 15000);
            }
        }
    }

    if (isset($_POST['remove_user_from_role'])) {
        if (!validateCsrfToken()) {
            renderAlertBanner(t('manage.common.msg.security_validation_failed'), "danger");
        } else {
            $user_identifier = $_POST['username'];
            $role_name = $_POST['role_name'];

            // Convert UUID to username if needed
            $username = get_username_from_identifier($ldap_connection, $user_identifier);

            // Prevent user from removing themselves from administrators role
            $current_user = $_SESSION['username'] ?? '';
            if ($username === $current_user && $role_name === $LDAP['admin_role']) {
                renderAlertBanner(t('manage.roles.msg.cannot_remove_self_admin'), "danger", 15000);
            } else {
                if (ldap_delete_member_from_group($ldap_connection, $role_name, $username)) {
                    renderAlertBanner(t('manage.roles.msg.remove_ok', ['user' => $username, 'role' => $role_name]));
                } else {
                    renderAlertBanner(t('manage.roles.msg.remove_fail', ['user' => $username, 'role' => $role_name]), "danger", 15000);
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
    # Use admin bind for listing reliability — same pattern as listOrganizations().
    $required_fields = ['entryUUID', 'sn', 'mail', 'cn', 'givenname'];
    $listConn = open_ldap_connection();
    $users = ldap_get_system_users(
        $listConn !== false ? $listConn : $ldap_connection,
        0,
        null,
        'asc',
        null,
        null,
        $required_fields
    );

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
}
?>

<div class="container">
  <h2><?php echo htmlspecialchars(t('manage.roles.heading'), ENT_QUOTES, 'UTF-8'); ?></h2>
  
  <div class="alert alert-info">
    <strong><?php echo htmlspecialchars(t('manage.roles.global_roles_title'), ENT_QUOTES, 'UTF-8'); ?></strong>
    <?php echo htmlspecialchars(t('manage.roles.global_roles_body'), ENT_QUOTES, 'UTF-8'); ?>
  </div>
  
  <div class="alert alert-warning">
    <strong><?php echo htmlspecialchars(t('manage.roles.org_roles_title'), ENT_QUOTES, 'UTF-8'); ?></strong>
    <?php echo htmlspecialchars(t('manage.roles.org_roles_body_prefix'), ENT_QUOTES, 'UTF-8'); ?>
    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.submenu.organizations'), ENT_QUOTES, 'UTF-8'); ?></a>
    <?php echo htmlspecialchars(t('manage.roles.org_roles_body_suffix'), ENT_QUOTES, 'UTF-8'); ?>
  </div>
  
  <div class="row">
    <div class="col-md-6">
      <h3><?php echo htmlspecialchars(t('manage.roles.add.heading'), ENT_QUOTES, 'UTF-8'); ?></h3>
      <form method="post" class="form">
        <?= csrfTokenField() ?>
        <div class="form-group">
          <label for="username"><?php echo htmlspecialchars(t('manage.roles.system_user_label'), ENT_QUOTES, 'UTF-8'); ?></label>
          <select name="username" id="username" class="form-control" required>
            <option value=""><?php echo htmlspecialchars(t('manage.roles.select_system_user'), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php foreach ($users as $username => $attribs) : ?>
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
          <label for="role_name"><?php echo htmlspecialchars(t('manage.roles.role_label'), ENT_QUOTES, 'UTF-8'); ?></label>
          <select name="role_name" id="role_name" class="form-control" required>
            <option value=""><?php echo htmlspecialchars(t('manage.roles.select_role'), ENT_QUOTES, 'UTF-8'); ?></option>
            <optgroup label="<?php echo htmlspecialchars(t('manage.roles.global_roles_optgroup'), ENT_QUOTES, 'UTF-8'); ?>">
              <?php foreach ($global_roles as $role) : ?>
                <option value="<?php print htmlspecialchars($role); ?>">
                    <?php print htmlspecialchars(ucfirst($role)); ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
        
        <button type="submit" name="add_user_to_role" class="btn btn-success"><?php echo htmlspecialchars(t('manage.roles.add.submit'), ENT_QUOTES, 'UTF-8'); ?></button>
      </form>
    </div>
    
    <div class="col-md-6">
      <h3><?php echo htmlspecialchars(t('manage.roles.remove.heading'), ENT_QUOTES, 'UTF-8'); ?></h3>
      <form method="post" class="form">
        <?= csrfTokenField() ?>
        <div class="form-group">
          <label for="remove_username"><?php echo htmlspecialchars(t('manage.roles.system_user_label'), ENT_QUOTES, 'UTF-8'); ?></label>
          <select name="username" id="remove_username" class="form-control" required>
            <option value=""><?php echo htmlspecialchars(t('manage.roles.select_system_user'), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php foreach ($users as $username => $attribs) : ?>
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
          <label for="remove_role_name"><?php echo htmlspecialchars(t('manage.roles.role_label'), ENT_QUOTES, 'UTF-8'); ?></label>
          <select name="role_name" id="remove_role_name" class="form-control" required>
            <option value=""><?php echo htmlspecialchars(t('manage.roles.select_role'), ENT_QUOTES, 'UTF-8'); ?></option>
            <optgroup label="<?php echo htmlspecialchars(t('manage.roles.global_roles_optgroup'), ENT_QUOTES, 'UTF-8'); ?>">
              <?php foreach ($global_roles as $role) : ?>
                <option value="<?php print htmlspecialchars($role); ?>">
                    <?php print htmlspecialchars(ucfirst($role)); ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>
        
        <button type="submit" name="remove_user_from_role" class="btn btn-danger"><?php echo htmlspecialchars(t('manage.roles.remove.submit'), ENT_QUOTES, 'UTF-8'); ?></button>
      </form>
      
      <div class="alert alert-warning" style="margin-top: 15px;">
        <strong><?php echo htmlspecialchars(t('manage.roles.security_note_title'), ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php echo htmlspecialchars(t('manage.roles.security_note_body'), ENT_QUOTES, 'UTF-8'); ?>
        <?php if (!empty($_SESSION['username'])) : ?>
          <br><small><?php echo htmlspecialchars(t('manage.roles.current_user', ['user' => (string) $_SESSION['username']]), ENT_QUOTES, 'UTF-8'); ?></small>
        <?php endif; ?>
      </div>
    </div>
  </div>
  
  <hr>
  
  <h3><?php echo htmlspecialchars(t('manage.roles.current_memberships_heading'), ENT_QUOTES, 'UTF-8'); ?></h3>
  <div class="alert alert-info">
    <strong><?php echo htmlspecialchars(t('manage.roles.global_roles_only_title'), ENT_QUOTES, 'UTF-8'); ?></strong>
    <?php echo htmlspecialchars(t('manage.roles.global_roles_only_body'), ENT_QUOTES, 'UTF-8'); ?>
  </div>
  <div class="row">
    <?php foreach ($all_roles as $role) : ?>
      <div class="col-md-6">
        <div class="card">
          <div class="card-header">
            <h4 class="card-title">
              <?php echo htmlspecialchars(t('manage.roles.role_heading', ['role' => ucfirst($role)]), ENT_QUOTES, 'UTF-8'); ?>
              <span class="badge bg-primary"><?php echo htmlspecialchars(t('manage.roles.badge_global'), ENT_QUOTES, 'UTF-8'); ?></span>
            </h4>
          </div>
          <div class="card-body">
            <?php
            $members = ldap_get_role_members($ldap_connection, $role);
            if ($members && count($members) > 0) : ?>
              <ul class="list-group">
                <?php foreach ($members as $member_dn) : ?>
                  <li class="list-group-item">
                    <?php
                    $display_name = get_user_display_from_dn($ldap_connection, $member_dn);
                    print $display_name;

                    // Extract username from DN for comparison with current user
                    if (preg_match('/uid=([^,]+)/', $member_dn, $matches)) {
                        $member_username = $matches[1];
                        if ($member_username === ($_SESSION['username'] ?? '')) {
                            print ' <span class="badge bg-info float-end">' . htmlspecialchars(t('manage.roles.badge_you'), ENT_QUOTES, 'UTF-8') . '</span>';
                        }
                    }
                    ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else : ?>
              <p class="text-muted"><?php echo htmlspecialchars(t('manage.roles.no_members'), ENT_QUOTES, 'UTF-8'); ?></p>
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
  const adminRoleWarningHtml = <?php echo json_encode(t('manage.roles.admin_role_warning_html')); ?>;
  
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
      warning.innerHTML = adminRoleWarningHtml;
      
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

lum_close_ldap_if_not_manage($ldap_connection);
renderFooter();
?> 
