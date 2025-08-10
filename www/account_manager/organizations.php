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

// Handle organization creation
if (isset($_POST['create_organization'])) {
    validate_csrf_token();
    
    $org_name = trim($_POST['org_name']);
    $org_description = trim($_POST['org_description']);
    $org_address = trim($_POST['org_address']);
    $org_city = trim($_POST['org_city']);
    $org_state = trim($_POST['org_state']);
    $org_zip = trim($_POST['org_zip']);
    $org_country = trim($_POST['org_country']);
    $org_phone = trim($_POST['org_phone']);
    $org_website = trim($_POST['org_website']);
    $org_email = trim($_POST['org_email']);
    
    if (empty($org_name)) {
        render_alert_banner("Organization name is required.", "warning");
    } else {
        // Build postalAddress in the format: Street$City$State$ZIP$Country
        $postal_address = $org_address . '$' . $org_city . '$' . $org_state . '$' . $org_zip . '$' . $org_country;
        
        $org_data = [
            'name' => $org_name,
            'description' => $org_description,
            'postalAddress' => $postal_address,
            'telephoneNumber' => $org_phone,
            'labeledURI' => $org_website,
            'mail' => $org_email
        ];
        
        $result = createOrganization($org_data);
        if ($result) {
            render_alert_banner("Organization '$org_name' created successfully.", "success");
        } else {
            render_alert_banner("Failed to create organization. Check the logs for more information.", "danger");
        }
    }
}

// Handle organization deletion
if (isset($_POST['delete_organization'])) {
    validate_csrf_token();
    
    $org_name = $_POST['org_name'];
    $result = deleteOrganization($org_name);
    if ($result) {
        render_alert_banner("Organization '$org_name' deleted successfully.", "success");
    } else {
        render_alert_banner("Failed to delete organization. It may not be empty or you may not have permission.", "danger");
    }
}

// Get list of organizations
$organizations = listOrganizations();

?>

<div class="container">
 <div class="col-sm-12">

  <div class="panel panel-default">
   <div class="panel-heading text-center">Create New Organization</div>
   <div class="panel-body text-center">
    <form class="form-horizontal" action="" method="post">
     <?= csrf_token_field() ?>
     <input type="hidden" name="create_organization">
     
     <div class="row">
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_name" class="col-sm-4 control-label">Organization Name<sup>&ast;</sup></label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_name" name="org_name" required>
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_description" class="col-sm-4 control-label">Description</label>
        <div class="col-sm-8">
         <textarea class="form-control" id="org_description" name="org_description" rows="2"></textarea>
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_address" class="col-sm-4 control-label">Street Address</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_address" name="org_address">
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_city" class="col-sm-4 control-label">City</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_city" name="org_city">
        </div>
       </div>
      </div>
      
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_state" class="col-sm-4 control-label">State/Province</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_state" name="org_state">
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_zip" class="col-sm-4 control-label">ZIP/Postal Code</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_zip" name="org_zip">
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_country" class="col-sm-4 control-label">Country</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_country" name="org_country">
        </div>
       </div>
       
       <div class="form-group">
        <label for="org_phone" class="col-sm-4 control-label">Phone</label>
        <div class="col-sm-8">
         <input type="text" class="form-control" id="org_phone" name="org_phone">
        </div>
       </div>
      </div>
     </div>
     
     <div class="row">
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_website" class="col-sm-4 control-label">Website</label>
        <div class="col-sm-8">
         <input type="url" class="form-control" id="org_website" name="org_website" placeholder="https://example.com">
        </div>
       </div>
      </div>
      
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_email" class="col-sm-4 control-label">Email</label>
        <div class="col-sm-8">
         <input type="email" class="form-control" id="org_email" name="org_email" placeholder="info@example.com">
        </div>
       </div>
      </div>
     </div>
     
     <div class="form-group">
      <button type="submit" class="btn btn-success">Create Organization</button>
     </div>
    </form>
   </div>
  </div>

  <div class="panel panel-default">
   <div class="panel-heading text-center">Existing Organizations</div>
   <div class="panel-body">
    
    <?php if (empty($organizations)) { ?>
     <div class="text-center">
      <p>No organizations found.</p>
     </div>
    <?php } else { ?>
     
     <div class="table-responsive">
      <table class="table table-striped table-hover">
       <thead>
        <tr>
         <th>Organization Name</th>
         <th>Description</th>
         <th>Address</th>
         <th>Contact</th>
         <th>Users</th>
         <th>Roles</th>
         <th>Actions</th>
        </tr>
       </thead>
       <tbody>
        <?php foreach ($organizations as $org) { ?>
         <tr>
          <td>
           <strong><?php print htmlspecialchars($org['name']); ?></strong>
          </td>
          <td>
           <?php print htmlspecialchars($org['description'] ?? ''); ?>
          </td>
          <td>
           <?php if (isset($org['address'])) { ?>
            <div>
             <?php if (!empty($org['address']['street'])) { ?>
              <div><?php print htmlspecialchars($org['address']['street']); ?></div>
             <?php } ?>
             <?php if (!empty($org['address']['city']) || !empty($org['address']['state']) || !empty($org['address']['zip'])) { ?>
              <div>
               <?php 
               $city_state_zip = [];
               if (!empty($org['address']['city'])) $city_state_zip[] = $org['address']['city'];
               if (!empty($org['address']['state'])) $city_state_zip[] = $org['address']['state'];
               if (!empty($org['address']['zip'])) $city_state_zip[] = $org['address']['zip'];
               print htmlspecialchars(implode(', ', $city_state_zip));
               ?>
              </div>
             <?php } ?>
             <?php if (!empty($org['address']['country'])) { ?>
              <div><?php print htmlspecialchars($org['address']['country']); ?></div>
             <?php } ?>
            </div>
           <?php } else { ?>
            <em>No address</em>
           <?php } ?>
          </td>
          <td>
           <div>
            <?php if (!empty($org['phone'])) { ?>
             <div><i class="glyphicon glyphicon-phone"></i> <?php print htmlspecialchars($org['phone']); ?></div>
            <?php } ?>
            <?php if (!empty($org['website'])) { ?>
             <div><i class="glyphicon glyphicon-globe"></i> <a href="<?php print htmlspecialchars($org['website']); ?>" target="_blank"><?php print htmlspecialchars($org['website']); ?></a></div>
            <?php } ?>
            <?php if (!empty($org['email'])) { ?>
             <div><i class="glyphicon glyphicon-envelope"></i> <a href="mailto:<?php print htmlspecialchars($org['email']); ?>"><?php print htmlspecialchars($org['email']); ?></a></div>
            <?php } ?>
           </div>
          </td>
          <td>
           <?php 
           $user_count = isset($org['user_count']) ? $org['user_count'] : 0;
           print $user_count;
           ?>
          </td>
          <td>
           <?php 
           $role_count = isset($org['role_count']) ? $org['role_count'] : 0;
           print $role_count;
           ?>
          </td>
          <td>
           <div class="btn-group-vertical">
            <a href="<?php print $THIS_MODULE_PATH; ?>/org_users.php?org=<?php print urlencode($org['name']); ?>" class="btn btn-sm btn-info">View Users</a>
            <a href="<?php print $THIS_MODULE_PATH; ?>/show_organization.php?org=<?php print urlencode($org['name']); ?>" class="btn btn-sm btn-primary">Details</a>
            <?php if ($user_count == 0) { ?>
             <form action="" method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this organization? This action cannot be undone.');">
              <?= csrf_token_field() ?>
              <input type="hidden" name="delete_organization">
              <input type="hidden" name="org_name" value="<?php print htmlspecialchars($org['name']); ?>">
              <button type="submit" class="btn btn-sm btn-danger">Delete</button>
             </form>
            <?php } else { ?>
             <button class="btn btn-sm btn-danger" disabled title="Cannot delete organization with users">Delete</button>
            <?php } ?>
           </div>
          </td>
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

<?php

render_footer();

?> 