<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";
include_once "organization_functions.inc.php";

// Ensure session is started and CSRF token is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Refresh session activity to prevent timeout
$_SESSION['last_activity'] = time();



get_csrf_token();

// Check if organization parameter is provided (support both UUID and name-based lookup)
$org_name = null;
$org_uuid = null;

if (isset($_GET['uuid']) && !empty($_GET['uuid'])) {
    // UUID-based lookup
    $org_uuid = $_GET['uuid'];
    if (!is_valid_uuid($org_uuid)) {
        render_alert_banner("Invalid organization UUID provided.", "warning");
        render_footer();
        exit(0);
    }
    
    // Get organization by UUID
    $ldap_connection = open_ldap_connection();
    $organization_by_uuid = ldap_get_organization_by_uuid($ldap_connection, $org_uuid);
    ldap_close($ldap_connection);
    
    if (!$organization_by_uuid) {
        render_alert_banner("Organization with UUID '$org_uuid' not found.", "warning");
        render_footer();
        exit(0);
    }
    
    $org_name = $organization_by_uuid['o'][0];
} elseif (isset($_GET['org']) && !empty($_GET['org'])) {
    // Legacy name-based lookup
    $org_name = $_GET['org'];
} else {
    render_alert_banner("Organization identifier (UUID or name) is required.", "warning");
    render_footer();
    exit(0);
}

// Check if user has appropriate permissions
if (!(currentUserIsGlobalAdmin() || currentUserIsMaintainer() || currentUserIsOrgManager($org_name))) {
    // Redirect unauthorized users
    header("Location: ../index.php");
    exit;
}

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

// Get organization users
$org_users = getOrganizationUsers($org_name);

// Get organization roles
$org_roles = [];
$ldap_connection = open_ldap_connection();
$org_dn = "o=" . ldap_escape($org_name, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];

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
    <li class="breadcrumb-item"><a href="organizations.php">Organizations</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?php print htmlspecialchars($org_name); ?></li>
   </ol>
  </nav>

  <div class="panel panel-default">
   <div class="panel-heading clearfix">
    <span class="panel-title pull-left"><h3><?php print htmlspecialchars($org_name); ?></h3></span>
    <a href="<?php print $THIS_MODULE_PATH; ?>/organizations.php" class="btn btn-default pull-right">Back to Organizations</a>
   </div>
   <div class="panel-body">
    
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
                 if (!empty($address_parts[2])) $city_state_zip[] = $address_parts[2]; // City
                 if (!empty($address_parts[3])) $city_state_zip[] = $address_parts[3]; // State
                 if (!empty($address_parts[1])) $city_state_zip[] = $address_parts[1]; // ZIP
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
      <div class="btn-group-vertical" style="width: 100%;">
       <a href="<?php print $THIS_MODULE_PATH; ?>/org_users.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>" class="btn btn-info">View All Users</a>
                       <a href="<?php print $THIS_MODULE_PATH; ?>/add_org_user.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>" class="btn btn-success">Add New User</a>
       <?php if ($can_modify_org): ?>
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
                       foreach ($recent_users as $user) { 
                       ?>
                        <tr>
                         <td><?php print safe_display_name($user); ?></td>
                         <td><?php print htmlspecialchars($user['mail']); ?></td>
                         <td><?php print htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role'] ?? 'user'))); ?></td>
                         <td>
                          <a href="<?php print $THIS_MODULE_PATH; ?>/org_users.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>&edit_user=<?php echo urlencode($user['uid'][0] ?? $user['mail']); ?>" class="btn btn-xs btn-primary">Edit</a>
                         </td>
                        </tr>
                       <?php } ?>
                      </tbody>
                     </table>
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
  <?php if ($can_modify_org): ?>
  <div class="panel panel-default" id="editForm" style="display: none;">
   <div class="panel-heading text-center">Edit Organization</div>
   <div class="panel-body text-center">
    <form class="form-horizontal" action="" method="post">
     <?= csrf_token_field() ?>
     <input type="hidden" name="update_organization">
     
     <!-- Debug info (remove in production) -->
     <?php if (isset($_GET['debug_csrf'])): ?>
     <div class="alert alert-info">
      <strong>CSRF Debug Info:</strong><br>
      Session Token: <?php echo htmlspecialchars(substr($_SESSION['csrf_token'] ?? 'NOT_SET', 0, 16)) . '...'; ?><br>
      Session ID: <?php echo htmlspecialchars(session_id()); ?><br>
      Session Status: <?php echo session_status(); ?>
     </div>
     <?php endif; ?>
     
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
                 if ($col_count > 0) echo '</div>';
                 echo '<div class="row">';
             }
             
             echo '<div class="col-sm-6">';
             echo '<div class="form-group">';
             
             // Add required indicator if field is required
             if ($is_required) {
                 $label .= ' <sup>*</sup>';
             }
             
             echo '<label for="' . $form_field . '" class="col-sm-4 control-label">' . $label . '</label>';
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
     if ($col_count > 0) echo '</div>';
     ?>
     
     <!-- Address Fields (dynamically generated from configuration) -->
     <?php
     // Generate address fields dynamically using configuration
     $address_col_count = 0;
     foreach ($LDAP['org_address_fields'] as $field_name => $field_config) {
         // Start new row every 2 fields
         if ($address_col_count % 2 === 0) {
             if ($address_col_count > 0) echo '</div>';
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
         
         echo '<label for="' . $field_name . '" class="col-sm-4 control-label">' . $label . '</label>';
         echo '<div class="col-sm-8">';
         
         // Generate input field
         $required_attr = $field_config['required'] ? ' required' : '';
         echo '<input type="' . $field_config['type'] . '" class="form-control" id="' . $field_name . '" name="' . $field_name . '" ' .
              'value="' . htmlspecialchars($current_value) . '"' . $required_attr . '>';
         
         echo '</div></div></div>';
         $address_col_count++;
     }
     
     // Close the last address row if needed
     if ($address_col_count > 0) echo '</div>';
     ?>
     
     <div class="form-group">
      <button type="submit" class="btn btn-primary">Update Organization</button>
      <button type="button" class="btn btn-default" onclick="hideEditForm()">Cancel</button>
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
}

function hideEditForm() {
    document.getElementById('editForm').style.display = 'none';
}
</script>

<?php

render_footer();

?> 