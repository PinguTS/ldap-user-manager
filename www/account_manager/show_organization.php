<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "module_functions.inc.php";
include_once "access_functions.inc.php";
include_once "organization_functions.inc.php";

set_page_access("admin");

render_header("$ORGANISATION_NAME account manager");
render_submenu();

if (!isset($_GET['org'])) {
    render_alert_banner("Organization name is required.", "warning");
    render_footer();
    exit(0);
}

$org_name = $_GET['org'];

// Handle organization updates
if (isset($_POST['update_organization'])) {
    validate_csrf_token();
    
    $org_description = trim($_POST['org_description']);
    $org_address = trim($_POST['org_address']);
    $org_city = trim($_POST['org_city']);
    $org_state = trim($_POST['org_state']);
    $org_zip = trim($_POST['org_zip']);
    $org_country = trim($_POST['org_country']);
    $org_phone = trim($_POST['org_phone']);
    $org_website = trim($_POST['org_website']);
    $org_email = trim($_POST['org_email']);
    
    // Build postalAddress in the format: Street$City$State$ZIP$Country
    $postal_address = $org_address . '$' . $org_city . '$' . $org_state . '$' . $org_zip . '$' . $org_country;
    
    $org_data = [
        'description' => $org_description,
        'postalAddress' => $postal_address,
        'telephoneNumber' => $org_phone,
        'labeledURI' => $org_website,
        'mail' => $org_email
    ];
    
    $result = updateOrganization($org_name, $org_data);
    if ($result) {
        render_alert_banner("Organization '$org_name' updated successfully.", "success");
    } else {
        render_alert_banner("Failed to update organization. Check the logs for more information.", "danger");
    }
}

// Get organization details
$organizations = listOrganizations();
$organization = null;

foreach ($organizations as $org) {
    if ($org['name'] === $org_name) {
        $organization = $org;
        break;
    }
}

if (!$organization) {
    render_alert_banner("Organization '$org_name' not found.", "danger");
    render_footer();
    exit(0);
}

// Get organization users
$org_users = getOrganizationUsers($org_name);

// Get organization roles
$org_roles = [];
$ldap_connection = open_ldap_connection();
    $org_dn = "o=" . ldap_escape($org_name, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
$roles_search = ldap_search($ldap_connection, $org_dn, "(objectClass=groupOfNames)");
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

?>

<div class="container">
 <div class="col-sm-12">

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
         <?php if (isset($organization['address'])) { ?>
          <div>
           <?php if (!empty($organization['address']['street'])) { ?>
            <div><?php print htmlspecialchars($organization['address']['street']); ?></div>
           <?php } ?>
           <?php if (!empty($organization['address']['city']) || !empty($organization['address']['state']) || !empty($organization['address']['zip'])) { ?>
            <div>
             <?php 
             $city_state_zip = [];
             if (!empty($organization['address']['city'])) $city_state_zip[] = $organization['address']['city'];
             if (!empty($organization['address']['state'])) $city_state_zip[] = $organization['address']['state'];
             if (!empty($organization['address']['zip'])) $city_state_zip[] = $organization['address']['zip'];
             print htmlspecialchars(implode(', ', $city_state_zip));
             ?>
            </div>
           <?php } ?>
           <?php if (!empty($organization['address']['country'])) { ?>
            <div><?php print htmlspecialchars($organization['address']['country']); ?></div>
           <?php } ?>
          </div>
         <?php } else { ?>
          <em>No address</em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th>Phone:</th>
        <td><?php print htmlspecialchars($organization['phone'] ?? 'No phone'); ?></td>
       </tr>
       <tr>
        <th>Website:</th>
        <td>
         <?php if (!empty($organization['website'])) { ?>
          <a href="<?php print htmlspecialchars($organization['website']); ?>" target="_blank"><?php print htmlspecialchars($organization['website']); ?></a>
         <?php } else { ?>
          <em>No website</em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th>Email:</th>
        <td>
         <?php if (!empty($organization['email'])) { ?>
          <a href="mailto:<?php print htmlspecialchars($organization['email']); ?>"><?php print htmlspecialchars($organization['email']); ?></a>
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
       <a href="<?php print $THIS_MODULE_PATH; ?>/org_users.php?org=<?php print urlencode($org_name); ?>" class="btn btn-info">View All Users</a>
       <a href="<?php print $THIS_MODULE_PATH; ?>/new_user.php?org=<?php print urlencode($org_name); ?>" class="btn btn-success">Add New User</a>
       <button class="btn btn-primary" onclick="showEditForm()">Edit Organization</button>
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
            <td><?php print htmlspecialchars($user['cn'] ?? $user['givenName'] . ' ' . $user['sn']); ?></td>
            <td><?php print htmlspecialchars($user['mail']); ?></td>
            <td><?php print htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role'] ?? 'user'))); ?></td>
            <td>
             <a href="<?php print $THIS_MODULE_PATH; ?>/show_user.php?account_identifier=<?php print urlencode($user['mail']); ?>" class="btn btn-xs btn-primary">View</a>
            </td>
           </tr>
          <?php } ?>
         </tbody>
        </table>
       </div>
       <?php if (count($org_users) > 5) { ?>
        <p><em>Showing 5 of <?php print count($org_users); ?> users. <a href="<?php print $THIS_MODULE_PATH; ?>/org_users.php?org=<?php print urlencode($org_name); ?>">View all users</a></em></p>
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
  <div class="panel panel-default" id="editForm" style="display: none;">
   <div class="panel-heading text-center">Edit Organization</div>
   <div class="panel-body text-center">
    <form class="form-horizontal" action="" method="post">
     <?= csrf_token_field() ?>
     <input type="hidden" name="update_organization">
     
     <div class="row">
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_description" class="col-sm-4 control-label">Description</label>
        <div class="col-sm-8">
         <textarea class="form-control" id="org_description" name="org_description" rows="2"><?php print htmlspecialchars($organization['description'] ?? ''); ?></textarea>
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_address" class="col-sm-4 control-label">Street Address</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_address" name="org_address" value="<?php print htmlspecialchars($organization['address']['street'] ?? ''); ?>">
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_city" class="col-sm-4 control-label">City</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_city" name="org_city" value="<?php print htmlspecialchars($organization['address']['city'] ?? ''); ?>">
        </div>
       </div>
      </div>
      
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_state" class="col-sm-4 control-label">State/Province</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_state" name="org_state" value="<?php print htmlspecialchars($organization['address']['state'] ?? ''); ?>">
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_zip" class="col-sm-4 control-label">ZIP/Postal Code</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_zip" name="org_zip" value="<?php print htmlspecialchars($organization['address']['zip'] ?? ''); ?>">
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_country" class="col-sm-4 control-label">Country</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_country" name="org_country" value="<?php print htmlspecialchars($organization['address']['country'] ?? ''); ?>">
        </div>
       </div>
      </div>
     </div>
     
     <div class="row">
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_phone" class="col-sm-4 control-label">Phone</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_phone" name="org_phone" value="<?php print htmlspecialchars($organization['phone'] ?? ''); ?>">
        </div>
       </div>
      </div>
      
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_website" class="col-sm-4 control-label">Website</label>
        <div class="col-sm-8">
         <input type="url" class="form-control" id="org_website" name="org_website" value="<?php print htmlspecialchars($organization['website'] ?? ''); ?>" placeholder="https://example.com">
        </div>
       </div>
      </div>
     </div>
     
     <div class="row">
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_email" class="col-sm-4 control-label">Email</label>
        <div class="col-sm-8">
         <input type="email" class="form-control" id="org_email" name="org_email" value="<?php print htmlspecialchars($organization['email'] ?? ''); ?>" placeholder="info@example.com">
        </div>
       </div>
      </div>
     </div>
     
     <div class="form-group">
      <button type="submit" class="btn btn-primary">Update Organization</button>
      <button type="button" class="btn btn-default" onclick="hideEditForm()">Cancel</button>
     </div>
    </form>
   </div>
  </div>

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