<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "module_functions.inc.php";
include_once "organization_functions.inc.php";

validate_setup_cookie();
set_page_access("setup");

render_header("$ORGANISATION_NAME account manager setup");

$ldap_connection = open_ldap_connection();

$no_errors = TRUE;
$show_create_admin_button = FALSE;

# Set up missing stuff

if (isset($_POST['fix_problems'])) {
?>
<script>
    $(document).ready(function(){
     $('[data-toggle="popover"]').popover(); 
    });
</script>
<div class='container'>

 <div class="panel panel-default">
  <div class="panel-heading">Updating LDAP...</div>
   <div class="panel-body">
    <ul class="list-group">

<?php

 if (isset($_POST['setup_organizations_ou'])) {
  $ou_add = @ ldap_add($ldap_connection, "ou=organizations,{$LDAP['base_dn']}", array( 'objectClass' => 'organizationalUnit', 'ou' => 'organizations' ));
  if ($ou_add == TRUE) {
   print "$li_good Created OU <strong>ou=organizations,{$LDAP['base_dn']}</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create ou=organizations,{$LDAP['base_dn']}: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_system_users_ou'])) {
  $ou_add = @ ldap_add($ldap_connection, "ou=system_users,{$LDAP['base_dn']}", array( 'objectClass' => 'organizationalUnit', 'ou' => 'system_users' ));
  if ($ou_add == TRUE) {
   print "$li_good Created OU <strong>ou=system_users,{$LDAP['base_dn']}</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create ou=system_users,{$LDAP['base_dn']}: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_global_roles_ou'])) {
  $ou_add = @ ldap_add($ldap_connection, "ou=roles,ou=organizations,{$LDAP['base_dn']}", array( 'objectClass' => 'organizationalUnit', 'ou' => 'roles' ));
  if ($ou_add == TRUE) {
   print "$li_good Created OU <strong>ou=roles,ou=organizations,{$LDAP['base_dn']}</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create ou=roles,ou=organizations,{$LDAP['base_dn']}: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_admin_role'])) {
  $admin_role = array(
   'objectClass' => array('top', 'groupOfNames'),
   'cn' => 'administrator',
   'description' => 'Full system administrator with all privileges',
   'member' => array()
  );
  
  $role_add = @ ldap_add($ldap_connection, "cn=administrator,ou=roles,ou=organizations,{$LDAP['base_dn']}", $admin_role);
  if ($role_add == TRUE) {
   print "$li_good Created administrator role <strong>cn=administrator,ou=roles,ou=organizations,{$LDAP['base_dn']}</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create administrator role: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_maintainer_role'])) {
  $maintainer_role = array(
   'objectClass' => array('top', 'groupOfNames'),
   'cn' => 'maintainer',
   'description' => 'System maintainer with limited privileges',
   'member' => array()
  );
  
  $role_add = @ ldap_add($ldap_connection, "cn=maintainer,ou=roles,ou=organizations,{$LDAP['base_dn']}", $maintainer_role);
  if ($role_add == TRUE) {
   print "$li_good Created maintainer role <strong>cn=maintainer,ou=roles,ou=organizations,{$LDAP['base_dn']}</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create maintainer role: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_admin_user'])) {
  $admin_user = array(
   'objectClass' => array('top', 'inetOrgPerson'),
   'uid' => 'admin@example.com',
   'cn' => 'System Administrator',
   'sn' => 'Administrator',
   'givenName' => 'System',
   'mail' => 'admin@example.com',
   'userPassword' => ldap_hashed_password('admin123'), // Default password - should be changed
   'userRole' => 'administrator'
  );
  
  $user_add = @ ldap_add($ldap_connection, "uid=admin@example.com,ou=system_users,{$LDAP['base_dn']}", $admin_user);
  if ($user_add == TRUE) {
   print "$li_good Created system administrator user <strong>uid=admin@example.com,ou=system_users,{$LDAP['base_dn']}</strong></li>\n";
   print "$li_warn <strong>Default password is 'admin123' - please change this immediately!</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create system administrator user: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_admin_role_membership'])) {
  $admin_user_dn = "uid=admin@example.com,ou=system_users,{$LDAP['base_dn']}";
  $admin_role_dn = "cn=administrator,ou=roles,ou=organizations,{$LDAP['base_dn']}";
  
  $modify = array(
   'member' => array($admin_user_dn)
  );
  
  $role_modify = @ ldap_modify($ldap_connection, $admin_role_dn, $modify);
  if ($role_modify == TRUE) {
   print "$li_good Added system administrator user to administrator role</li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't add user to administrator role: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_example_org'])) {
  $example_org_data = array(
   'o' => 'Example Company',
   'street' => '123 Business Street',
   'city' => 'New York',
   'state' => 'NY',
   'postalCode' => '10001',
   'country' => 'USA',
   'telephoneNumber' => '+1-555-0123',
   'labeledURI' => 'https://examplecompany.com',
   'mail' => 'info@examplecompany.com',
   'creatorDN' => "uid=admin@example.com,ou=system_users,{$LDAP['base_dn']}"
  );
  
  $org_result = createOrganization($example_org_data);
  if ($org_result[0] === TRUE) {
   print "$li_good Created example organization: <strong>Example Company</strong></li>\n";
  }
  else {
   print "$li_fail Couldn't create example organization: <pre>{$org_result[1]}</pre></li>\n";
   $no_errors = FALSE;
  }
 }

?>
  </ul>
 </div>
</div>
<?php

##############

 if ($no_errors == TRUE) {
  if ($show_create_admin_button == FALSE) {
 ?>
 </form>
 <div class='well'>
  <form action="<?php print $THIS_MODULE_PATH; ?>">
   <input type='submit' class="btn btn-success center-block" value='Finished' class='center-block'>
  </form>
 </div>
 <?php
  }
  else {
  ?>
    <div class='well'>
    <input type='submit' class="btn btn-warning center-block" value='Create new account >' class='center-block'>
   </form>
  </div>
  <?php 
  }
 }
 else {
 ?>
 </form>
 <div class='well'>
  <form action="<?php print $THIS_MODULE_PATH; ?>/run_checks.php">
   <input type='submit' class="btn btn-danger center-block" value='< Re-run setup' class='center-block'>
  </form>
 </div>
<?php

 }

}

render_footer();

?>
