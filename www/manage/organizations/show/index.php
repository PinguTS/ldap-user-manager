<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap', 'organization']);

// Ensure session is started and CSRF token is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Refresh session activity to prevent timeout
$_SESSION['last_activity'] = time();



get_csrf_token();

$res = resolve_organization_from_request();
if ($res['error'] !== null) {
    render_alert_banner($res['error'], "warning");
    render_footer();
    exit(0);
}
$org_name = $res['org_name'] ?? '';
$org_uuid = $res['org_uuid'] ?? '';
$organization_by_uuid = $res['organization'];

// Use the enhanced access control function
set_page_access(["admin", "maintainer", "org_admin"]);

// Check if user can modify this organization
$can_modify_org = currentUserCanModifyOrganization($org_name);

render_header("$ORGANISATION_NAME account manager");
render_submenu();

// Handle organization updates
if (isset($_POST['update_organization'])) {
    // Debug logging for form submission
    error_log("Organization update form submitted. POST data: " . print_r($_POST, true));
    error_log("Session CSRF token: " . ($_SESSION['csrf_token'] ?? 'NOT_SET'));

    // Check if session is still valid
    if (session_status() !== PHP_SESSION_ACTIVE) {
        render_alert_banner("Session expired. Please log in again.", "danger");
        error_log("Session not active during organization update");
    } elseif (!validate_csrf_token()) {
        render_alert_banner("Security validation failed. Please refresh the page and try again.", "danger");
        error_log("CSRF token validation failed for organization update. Session token: " . ($_SESSION['csrf_token'] ?? 'NOT_SET') . ", Posted token: " . ($_POST['csrf_token'] ?? 'NOT_POSTED'));
    } elseif (!$can_modify_org) {
        render_alert_banner("You do not have permission to modify this organization.", "danger");
    } else {
        $base_dn = $LDAP['base_dn'] ?? '';
        // Use the same field mapping logic as organization creation
        $org_data = [];

        // Map form fields to LDAP attributes using the configuration
        foreach ($LDAP['org_field_mappings'] as $form_field => $ldap_attr) {
            if (isset($_POST[$form_field]) && !empty(trim($_POST[$form_field]))) {
                $org_data[$ldap_attr] = trim($_POST[$form_field]);
            }
        }

        // Special handling for postalAddress from individual address fields
        // These fields are not in the LDAP schema but are used for form input
        $address_fields = ['org_address', 'org_zip', 'org_city', 'org_state', 'org_country'];
        $has_address_data = false;
        foreach ($address_fields as $field) {
            if (isset($_POST[$field]) && !empty(trim($_POST[$field]))) {
                $has_address_data = true;
                break;
            }
        }

        if ($has_address_data) {
            $postal_parts = [
                trim($_POST['org_address'] ?? ''),
                trim($_POST['org_zip'] ?? ''),
                trim($_POST['org_city'] ?? ''),
                trim($_POST['org_state'] ?? ''),
                trim($_POST['org_country'] ?? '')
            ];
            $postal_address = implode('$', $postal_parts);
            if (!empty(trim($postal_address, '$'))) {
                $org_data['postalAddress'] = $postal_address;
            }
        }

        // Membership metadata (only for member organizations)
        if (isset($_POST['membership_is_member']) && (int) $_POST['membership_is_member'] === 1) {
            $org_data['memberNumber'] = trim($_POST['memberNumber'] ?? '');
            $org_data['memberSince'] = trim($_POST['memberSince'] ?? '');
            $org_data['taxIdentificationNumber'] = trim($_POST['taxIdentificationNumber'] ?? '');
            $org_data['contactPersonUID'] = trim($_POST['contactPersonUID'] ?? '');
        }

        // Status group toggles (admin/maintainer only)
        $can_toggle_status = (currentUserIsGlobalAdmin() || currentUserIsMaintainer());
        if ($can_toggle_status && $base_dn !== '' && function_exists('addToStatusGroup') && function_exists('removeFromStatusGroup')) {
            $update_ldap = open_ldap_connection();
            if ($update_ldap !== false) {
                $member_group_cn_post = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
                $disabled_group_cn_post = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';
                $update_org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
                $want_member = isset($_POST['membership_is_member']) && (int) $_POST['membership_is_member'] === 1;
                $want_disabled = isset($_POST['organization_disabled']) && (int) $_POST['organization_disabled'] === 1;
                $currently_member = isInStatusGroup($update_ldap, $update_org_dn, $member_group_cn_post, $base_dn);
                $currently_disabled = isInStatusGroup($update_ldap, $update_org_dn, $disabled_group_cn_post, $base_dn);
                if ($want_member && !$currently_member) {
                    addToStatusGroup($update_ldap, $update_org_dn, $member_group_cn_post, $base_dn);
                } elseif (!$want_member && $currently_member) {
                    removeFromStatusGroup($update_ldap, $update_org_dn, $member_group_cn_post, $base_dn);
                }
                if ($want_disabled && !$currently_disabled) {
                    addToStatusGroup($update_ldap, $update_org_dn, $disabled_group_cn_post, $base_dn);
                } elseif (!$want_disabled && $currently_disabled) {
                    removeFromStatusGroup($update_ldap, $update_org_dn, $disabled_group_cn_post, $base_dn);
                }
                ldap_close($update_ldap);
            }
        }

        // Use UUID for update if available, otherwise fall back to name
        $update_identifier = $org_uuid ?: $org_name;
        $result = updateOrganization($update_identifier, $org_data);
        if ($result) {
            render_alert_banner("Organization '$org_name' updated successfully.", "success");
        } else {
            render_alert_banner("Failed to update organization. Check the logs for more information.", "danger");
        }
    }
}

// Get organization details
if ($org_uuid) {
    // Use the organization data we already retrieved by UUID
    $organization = $organization_by_uuid;
    // Convert to the format expected by the rest of the code
    $organization = [
        'name' => $organization['o'][0],
        'entryUUID' => $org_uuid,
        'description' => isset($organization['description'][0]) ? $organization['description'][0] : '',
        'mail' => isset($organization['mail'][0]) ? $organization['mail'][0] : '',
        'telephoneNumber' => isset($organization['telephonenumber'][0]) ? $organization['telephonenumber'][0] : '',
        'labeledURI' => isset($organization['labeleduri'][0]) ? $organization['labeleduri'][0] : '',
        'postalAddress' => isset($organization['postaladdress'][0]) ? $organization['postaladdress'][0] : '',
        'memberNumber' => isset($organization['membernumber'][0]) ? $organization['membernumber'][0] : '',
        'memberSince' => isset($organization['membersince'][0]) ? $organization['membersince'][0] : '',
        'taxIdentificationNumber' => isset($organization['taxidentificationnumber'][0]) ? $organization['taxidentificationnumber'][0] : '',
        'contactPersonUID' => isset($organization['contactpersonuid'][0]) ? $organization['contactpersonuid'][0] : '',
        // Add individual address fields for backward compatibility
        'street' => '',
        'city' => '',
        'state' => '',
        'postalCode' => '',
        'country' => ''
    ];

    // Parse postalAddress if available (format: Street$ZIP$City$State$Country)
    if (!empty($organization['postalAddress'])) {
        $address_parts = explode('$', $organization['postalAddress']);
        // Store parsed parts for form display if needed
        if (count($address_parts) >= 5) {
            $organization['_parsed_street'] = $address_parts[0];
            $organization['_parsed_postalCode'] = $address_parts[1];
            $organization['_parsed_city'] = $address_parts[2];
            $organization['_parsed_state'] = $address_parts[3];
            $organization['_parsed_country'] = $address_parts[4];
        }
    }
} else {
    // Legacy name-based lookup
    $organizations = listOrganizations();
    $organization = null;

    foreach ($organizations as $org) {
        if ($org['name'] === $org_name) {
            $organization = $org;
                    // Parse postalAddress if available for backward compatibility
            if (!empty($organization['postalAddress'])) {
                $address_parts = explode('$', $organization['postalAddress']);
                if (count($address_parts) >= 5) {
                    $organization['_parsed_street'] = $address_parts[0];
                    $organization['_parsed_postalCode'] = $address_parts[1];
                    $organization['_parsed_city'] = $address_parts[2];
                    $organization['_parsed_state'] = $address_parts[3];
                    $organization['_parsed_country'] = $address_parts[4];
                }
            }
            break;
        }
    }

    if (!$organization) {
        render_alert_banner("Organization '$org_name' not found.", "danger");
        render_footer();
        exit(0);
    }
}

// Ensure membership metadata keys exist (legacy path may not have them)
if (!isset($organization['memberNumber'])) {
    $organization['memberNumber'] = '';
}
if (!isset($organization['memberSince'])) {
    $organization['memberSince'] = '';
}
if (!isset($organization['taxIdentificationNumber'])) {
    $organization['taxIdentificationNumber'] = '';
}
if (!isset($organization['contactPersonUID'])) {
    $organization['contactPersonUID'] = '';
}

// Get organization users
$org_users = getOrganizationUsers($org_name);

// Get organization roles and status group flags
$org_roles = [];
$ldap_connection = open_ldap_connection();
$org_dn = "o=" . ldap_escape($org_name, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
$base_dn = $LDAP['base_dn'] ?? '';
$member_group_cn = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
$disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';
$is_member_org = ($ldap_connection !== false && $base_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $member_group_cn, $base_dn));
$is_disabled_org = ($ldap_connection !== false && $base_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $disabled_group_cn, $base_dn));

// First check if the organization DN exists before searching for roles
$orgExists = @ldap_read($ldap_connection, $org_dn, '(objectClass=*)', ['dn']);
if ($orgExists) {
    // Search for roles under ou=roles within the organization
    $org_roles_dn = "ou=roles,o=" . ldap_escape($org_name, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];

    // Check if the roles directory exists
    $rolesDirExists = @ldap_read($ldap_connection, $org_roles_dn, '(objectClass=*)', ['dn']);
    if ($rolesDirExists) {
        $roles_search = @ldap_search($ldap_connection, $org_roles_dn, "(objectClass=groupOfNames)");
        if ($roles_search) {
            $roles_entries = ldap_get_entries($ldap_connection, $roles_search);
            if ($roles_entries && isset($roles_entries['count'])) {
                for ($i = 0; $i < $roles_entries['count']; $i++) {
                    $role_name = $roles_entries[$i]['cn'][0];
                    $member_count = isset($roles_entries[$i]['member']) ? $roles_entries[$i]['member']['count'] : 0;
                    $org_roles[] = [
                        'name' => $role_name,
                        'member_count' => $member_count,
                        'description' => isset($roles_entries[$i]['description']) ? $roles_entries[$i]['description'][0] : ''
                    ];
                }
            }
        }
    } else {
        // Roles directory doesn't exist yet, which means no roles have been created
        error_log("show_organization: Roles directory not found: $org_roles_dn");
    }
} else {
    // Log the error but don't show it to the user
    error_log("show_organization: Organization DN not found: $org_dn");
}

?>

<div class="container">
 <div class="col-sm-12">

  <nav aria-label="breadcrumb">
   <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/manage/">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="/manage/organizations/">Organizations</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?php print htmlspecialchars($org_name); ?></li>
   </ol>
  </nav>

  <div class="card">
   <div class="card-header clearfix">
    <span class="card-title mb-0 float-start"><h3 class="h5 mb-0"><?php print htmlspecialchars($org_name); ?></h3></span>
    <a href="<?php print $THIS_MODULE_PATH; ?>/organizations" class="btn btn-secondary float-end">Back to Organizations</a>
   </div>
   <div class="card-body">
    
    <div class="row">
     <div class="col-sm-6">
      <h4>Organization Information</h4>
      <table class="table table-striped">
       <tr>
        <th>Name:</th>
        <td><?php print htmlspecialchars($org_name); ?></td>
       </tr>
       <tr>
        <th>Description:</th>
        <td><?php print htmlspecialchars($organization['description'] ?? 'No description'); ?></td>
       </tr>
       <tr>
        <th>Address:</th>
        <td>
         <?php if (!empty($organization['postalAddress'])) { ?>
          <div>
                <?php
                $address_parts = explode('$', $organization['postalAddress']);
                if (count($address_parts) >= 5) {
                    // Format: Street$ZIP$City$State$Country
                    if (!empty($address_parts[0])) { ?>
                <div><?php print htmlspecialchars($address_parts[0]); ?></div>
                    <?php } ?>
                    <?php if (!empty($address_parts[1]) || !empty($address_parts[2]) || !empty($address_parts[3])) { ?>
                <div>
                        <?php
                        $city_state_zip = [];
                        if (!empty($address_parts[2])) {
                            $city_state_zip[] = $address_parts[2]; // City
                        }
                        if (!empty($address_parts[3])) {
                            $city_state_zip[] = $address_parts[3]; // State
                        }
                        if (!empty($address_parts[1])) {
                            $city_state_zip[] = $address_parts[1]; // ZIP
                        }
                        print htmlspecialchars(implode(', ', $city_state_zip));
                        ?>
                </div>
                    <?php } ?>
                    <?php if (!empty($address_parts[4])) { ?>
                <div><?php print htmlspecialchars($address_parts[4]); ?></div>
                    <?php } ?>
                <?php } else { ?>
            <div><?php print htmlspecialchars($organization['postalAddress']); ?></div>
                <?php } ?>
          </div>
         <?php } else { ?>
          <em>No address</em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th>Phone:</th>
        <td><?php print htmlspecialchars($organization['telephoneNumber'] ?? 'No phone'); ?></td>
       </tr>
       <tr>
        <th>Website:</th>
        <td>
         <?php if (!empty($organization['labeledURI'])) { ?>
          <a href="<?php print htmlspecialchars($organization['labeledURI']); ?>" target="_blank"><?php print htmlspecialchars($organization['labeledURI']); ?></a>
         <?php } else { ?>
          <em>No website</em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th>Email:</th>
        <td>
         <?php if (!empty($organization['mail'])) { ?>
          <a href="mailto:<?php print htmlspecialchars($organization['mail']); ?>"><?php print htmlspecialchars($organization['mail']); ?></a>
         <?php } else { ?>
          <em>No email</em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th>Membership:</th>
        <td>
         <?php if ($is_member_org) {
                ?><span class="badge bg-primary">Member</span><?php
         } ?>
         <?php if ($is_disabled_org) {
                ?><span class="badge bg-danger">Disabled</span><?php
         } ?>
         <?php if (!$is_member_org && !$is_disabled_org) {
                ?><em>—</em><?php
         } ?>
        </td>
       </tr>
       <?php if ($is_member_org && (!empty($organization['memberNumber']) || !empty($organization['memberSince']) || !empty($organization['taxIdentificationNumber']) || !empty($organization['contactPersonUID']))) : ?>
       <tr>
        <th>Member details:</th>
        <td>
            <?php if (!empty($organization['memberNumber'])) {
                ?>Number: <?php print htmlspecialchars($organization['memberNumber']); ?><br><?php
            } ?>
            <?php if (!empty($organization['memberSince'])) {
                ?>Since: <?php print htmlspecialchars($organization['memberSince']); ?><br><?php
            } ?>
            <?php if (!empty($organization['taxIdentificationNumber'])) {
                ?>Tax ID: <?php print htmlspecialchars($organization['taxIdentificationNumber']); ?><br><?php
            } ?>
            <?php if (!empty($organization['contactPersonUID'])) {
                ?>Contact UID: <?php print htmlspecialchars($organization['contactPersonUID']); ?><?php
            } ?>
        </td>
       </tr>
       <?php endif; ?>
      </table>
     </div>
     
     <div class="col-sm-6">
      <h4>Statistics</h4>
      <table class="table table-striped">
       <tr>
        <th>Total Users:</th>
        <td><?php print count($org_users); ?></td>
       </tr>
       <tr>
        <th>Total Roles:</th>
        <td><?php print count($org_roles); ?></td>
       </tr>
       <tr>
        <th>Created:</th>
        <td><?php print isset($organization['created']) ? htmlspecialchars($organization['created']) : 'Unknown'; ?></td>
       </tr>
      </table>
      
      <h4>Actions</h4>
      <div class="btn-group" role="group">
                            <a href="/manage/organizations/users/index.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>" class="btn btn-info">View All Users</a>
                            <a href="/manage/organizations/users/add.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>" class="btn btn-success">Add New User</a>
                        </div>
       <?php if ($can_modify_org) : ?>
       <button class="btn btn-primary" onclick="showEditForm()">Edit Organization</button>
       <?php endif; ?>
      </div>
     </div>
    </div>
    
    <hr>
    
    <div class="row">
     <div class="col-sm-6">
      <h4>Recent Users</h4>
      <?php if (empty($org_users)) { ?>
       <p>No users in this organization.</p>
      <?php } else { ?>
                           <div class="table-responsive">
                     <table class="table table-striped table-hover">
                      <thead>
                       <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Actions</th>
                       </tr>
                      </thead>
                      <tbody>
                       <?php
                        $recent_users = array_slice($org_users, 0, 5); // Show only first 5 users
                        foreach ($recent_users as $user) :
                            ?>
                        <tr>
                         <td><?php print safe_display_name($user, 'cn', 'givenName', 'sn'); ?></td>
                         <td><?php print htmlspecialchars($user['mail']); ?></td>
                         <td><?php print htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role'] ?? 'user'))); ?></td>
                         <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="/manage/organizations/users/index.php?<?php echo $org_uuid !== '' ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>&edit_user=<?php echo urlencode((string) ($user['entryUUID'] ?? $user['mail'] ?? $user['cn'] ?? '')); ?>" class="btn btn-secondary btn-sm">Edit</a>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteUser('<?php echo htmlspecialchars($user['entryUUID'] ?? $user['mail'] ?? $user['cn']); ?>')">Delete</button>
                                </div>
                         </td>
                       </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                    </div>
                    <div class="text-center mt-3">
                        <a href="/manage/organizations/users/index.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>">View all users</a>
                    </div>
          <?php if (count($org_users) > 5) { ?>
        <p><em>Showing 5 of <?php print count($org_users); ?> users. 
            <a href="<?php print $THIS_MODULE_PATH; ?>/org_users.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>">View all users</a>
        </em></p>
          <?php } ?>
      <?php } ?>
     </div>
     
     <div class="col-sm-6">
      <h4>Organization Roles</h4>
      <?php if (empty($org_roles)) { ?>
       <p>No roles defined for this organization.</p>
      <?php } else { ?>
       <div class="table-responsive">
        <table class="table table-striped table-hover">
         <thead>
          <tr>
           <th>Role Name</th>
           <th>Members</th>
           <th>Description</th>
          </tr>
         </thead>
         <tbody>
          <?php foreach ($org_roles as $role) { ?>
           <tr>
            <td><strong><?php print htmlspecialchars($role['name']); ?></strong></td>
            <td><?php print $role['member_count']; ?></td>
            <td><?php print htmlspecialchars($role['description'] ?? ''); ?></td>
           </tr>
          <?php } ?>
         </tbody>
        </table>
       </div>
      <?php } ?>
     </div>
    </div>
    
   </div>
  </div>

  <!-- Edit Organization Form (Hidden by default) -->
  <?php if ($can_modify_org) : ?>
  <div class="card" id="editForm" style="display: none;">
   <div class="card-header text-center">Edit Organization</div>
   <div class="card-body text-center">
    <form class="form-horizontal" action="" method="post">
        <?= csrf_token_field() ?>
     <input type="hidden" name="update_organization">
     
        <?php
     // Generate form fields dynamically using configuration
        $col_count = 0;

     // Get the list of form fields to display (excluding org_name which shouldn't be editable)
        $form_fields_to_display = array_keys($LDAP['org_field_mappings']);
        unset($form_fields_to_display[array_search('org_name', $form_fields_to_display)]);

        foreach ($form_fields_to_display as $form_field) {
            if (isset($LDAP['org_field_labels'][$form_field]) && isset($LDAP['org_field_types'][$form_field])) {
                $label = $LDAP['org_field_labels'][$form_field];
                $field_type = $LDAP['org_field_types'][$form_field];
                $ldap_attr = $LDAP['org_field_mappings'][$form_field];

                // Check if field is required based on configuration
                $is_required = in_array($ldap_attr, $LDAP['org_optional_fields']) ? false : true;

                // Start new row every 2 fields
                if ($col_count % 2 === 0) {
                    if ($col_count > 0) {
                        echo '</div>';
                    }
                    echo '<div class="row">';
                }

                echo '<div class="col-sm-6">';
                echo '<div class="form-group">';

                // Add required indicator if field is required
                if ($is_required) {
                    $label .= ' <sup>*</sup>';
                }

                echo '<label for="' . $form_field . '" class="col-sm-4 form-label">' . $label . '</label>';
                echo '<div class="col-sm-8">';

                // Get current value from organization data
                $current_value = '';
                if (isset($organization[$ldap_attr])) {
                    $current_value = $organization[$ldap_attr];
                } elseif (isset($organization[$form_field])) {
                    $current_value = $organization[$form_field];
                } else {
                    // Try to get value from the mapped LDAP attribute
                    $current_value = $organization[$ldap_attr] ?? '';
                }

                // Add required attribute if field is required
                $required_attr = $is_required ? ' required' : '';

                if ($field_type === 'textarea') {
                    echo '<textarea class="form-control" id="' . $form_field . '" name="' . $form_field . '" rows="2"' . $required_attr . '>' . htmlspecialchars($current_value) . '</textarea>';
                } else {
                    echo '<input type="' . $field_type . '" class="form-control" id="' . $form_field . '" name="' . $form_field . '" value="' . htmlspecialchars($current_value) . '"' . $required_attr . '>';
                }

                echo '</div></div></div>';
                $col_count++;
            }
        }

     // Close the last row if needed
        if ($col_count > 0) {
            echo '</div>';
        }
        ?>
     
     <!-- Address Fields (dynamically generated from configuration) -->
        <?php
     // Generate address fields dynamically using configuration
        $address_col_count = 0;
        foreach ($LDAP['org_address_fields'] as $field_name => $field_config) {
            // Start new row every 2 fields
            if ($address_col_count % 2 === 0) {
                if ($address_col_count > 0) {
                    echo '</div>';
                }
                echo '<div class="row">';
            }

            echo '<div class="col-sm-6">';
            echo '<div class="form-group">';

            // Get current value from parsed address data
            $current_value = '';
            switch ($field_name) {
                case 'org_address':
                    $current_value = $organization['_parsed_street'] ?? '';
                    break;
                case 'org_zip':
                    $current_value = $organization['_parsed_postalCode'] ?? '';
                    break;
                case 'org_city':
                    $current_value = $organization['_parsed_city'] ?? '';
                    break;
                case 'org_state':
                    $current_value = $organization['_parsed_state'] ?? '';
                    break;
                case 'org_country':
                    $current_value = $organization['_parsed_country'] ?? '';
                    break;
            }

            // Generate label with required indicator if needed
            $label = $field_config['label'];
            if ($field_config['required']) {
                $label .= ' <sup>*</sup>';
            }

            echo '<label for="' . $field_name . '" class="col-sm-4 form-label">' . $label . '</label>';
            echo '<div class="col-sm-8">';

            // Generate input field
            $required_attr = $field_config['required'] ? ' required' : '';
            echo '<input type="' . $field_config['type'] . '" class="form-control" id="' . $field_name . '" name="' . $field_name . '" ' .
              'value="' . htmlspecialchars($current_value) . '"' . $required_attr . '>';

            echo '</div></div></div>';
            $address_col_count++;
        }

     // Close the last address row if needed
        if ($address_col_count > 0) {
            echo '</div>';
        }
        ?>

     <!-- Membership section (admin/maintainer only) -->
        <?php if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) : ?>
     <div class="row mt-3">
      <div class="col-sm-12">
       <h5 class="mb-2">Membership</h5>
       <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" id="membership_is_member" name="membership_is_member" value="1" <?php echo $is_member_org ? 'checked' : ''; ?>>
        <label class="form-check-label" for="membership_is_member">Member organization</label>
       </div>
       <div id="membership_fields" class="border rounded p-3 mb-2" style="display: <?php echo $is_member_org ? 'block' : 'none'; ?>;">
        <div class="row">
         <div class="col-sm-6 mb-2">
          <label for="memberNumber" class="form-label">Member number</label>
          <input type="text" class="form-control" id="memberNumber" name="memberNumber" value="<?php echo htmlspecialchars($organization['memberNumber'] ?? ''); ?>">
         </div>
         <div class="col-sm-6 mb-2">
          <label for="memberSince" class="form-label">Member since (YYYY-MM-DD)</label>
          <input type="text" class="form-control" id="memberSince" name="memberSince" value="<?php echo htmlspecialchars($organization['memberSince'] ?? ''); ?>">
         </div>
         <div class="col-sm-6 mb-2">
          <label for="taxIdentificationNumber" class="form-label">Tax identification number</label>
          <input type="text" class="form-control" id="taxIdentificationNumber" name="taxIdentificationNumber" value="<?php echo htmlspecialchars($organization['taxIdentificationNumber'] ?? ''); ?>">
         </div>
         <div class="col-sm-6 mb-2">
          <label for="contactPersonUID" class="form-label">Contact person UID</label>
          <input type="text" class="form-control" id="contactPersonUID" name="contactPersonUID" value="<?php echo htmlspecialchars($organization['contactPersonUID'] ?? ''); ?>">
         </div>
        </div>
       </div>
       <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" id="organization_disabled" name="organization_disabled" value="1" <?php echo $is_disabled_org ? 'checked' : ''; ?>>
        <label class="form-check-label" for="organization_disabled">Organization disabled</label>
       </div>
      </div>
     </div>
        <?php endif; ?>
     
     <div class="form-group">
      <button type="submit" class="btn btn-primary">Update Organization</button>
      <button type="button" class="btn btn-secondary" onclick="hideEditForm()">Cancel</button>
     </div>
    </form>
   </div>
  </div>
  <?php endif; ?>

 </div>
</div>

<script>
function showEditForm() {
    document.getElementById('editForm').style.display = 'block';
    document.getElementById('editForm').scrollIntoView({ behavior: 'smooth' });
    toggleMembershipFields();
}

function hideEditForm() {
    document.getElementById('editForm').style.display = 'none';
}

function toggleMembershipFields() {
    var cb = document.getElementById('membership_is_member');
    var div = document.getElementById('membership_fields');
    if (cb && div) {
        div.style.display = cb.checked ? 'block' : 'none';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    var membershipCb = document.getElementById('membership_is_member');
    if (membershipCb) {
        membershipCb.addEventListener('change', toggleMembershipFields);
    }
});
</script>

<?php
render_footer();

?> 