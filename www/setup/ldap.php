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

 if (isset($_POST['setup_people_ou'])) {
	$ou_add = @ ldap_add($ldap_connection, "ou=people,{$LDAP['base_dn']}", array( 'objectClass' => 'organizationalUnit', 'ou' => 'people' ));
	if ($ou_add) {
		print "$li_good Created OU <strong>ou=people,{$LDAP['base_dn']}</strong></li>\n";
	} else {
		$error = ldap_error($ldap_connection);
		print "$li_fail Couldn't create ou=people,{$LDAP['base_dn']}: <pre>$error</pre></li>\n";
	}
}

 if (isset($_POST['setup_global_roles_ou'])) {
  $ou_add = @ ldap_add($ldap_connection, "ou=roles,{$LDAP['base_dn']}", array( 'objectClass' => 'organizationalUnit', 'ou' => 'roles' ));
  if ($ou_add == TRUE) {
   print "$li_good Created OU <strong>ou=roles,{$LDAP['base_dn']}</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create ou=roles,{$LDAP['base_dn']}: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_admin_user'])) {
  $admin_email = (!empty($_POST['admin_email'])) ? $_POST['admin_email'] : 'admin@example.com';
  $admin_password = (!empty($_POST['admin_password'])) ? $_POST['admin_password'] : 'admin123';
  $admin_user = array(
   'objectClass' => array('top', 'inetOrgPerson'),
   'uid' => $admin_email,
   'cn' => 'System Administrator',
   'sn' => 'Administrator',
   'givenName' => 'System',
   'mail' => $admin_email,
   'userPassword' => ldap_hashed_password($admin_password),
   'description' => 'administrator'
  );
  
  $user_add = @ ldap_add($ldap_connection, "uid={$admin_email},ou=people,{$LDAP['base_dn']}", $admin_user);
  if ($user_add == TRUE) {
   print "$li_good Created system administrator user <strong>uid={$admin_email},ou=people,{$LDAP['base_dn']}</strong></li>\n";
   if ($admin_password == 'admin123') {
    print "$li_warn <strong>Default password is 'admin123' - please change this immediately!</strong></li>\n";
   } else {
    print "$li_good <strong>Custom password set successfully!</strong></li>\n";
   }
   if ($admin_email != 'admin@example.com') {
    print "$li_good <strong>Custom email address set: {$admin_email}</strong></li>\n";
   }
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create system administrator user: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_maintainer_user'])) {
  $maintainer_email = (!empty($_POST['maintainer_email'])) ? $_POST['maintainer_email'] : 'maintainer@example.com';
  $maintainer_password = (!empty($_POST['maintainer_password'])) ? $_POST['maintainer_password'] : 'maintainer123';
  $maintainer_user = array(
   'objectClass' => array('top', 'inetOrgPerson'),
   'uid' => $maintainer_email,
   'cn' => 'System Maintainer',
   'sn' => 'Maintainer',
   'givenName' => 'System',
   'mail' => $maintainer_email,
   'userPassword' => ldap_hashed_password($maintainer_password),
   'description' => 'maintainer'
  );
  
  $user_add = @ ldap_add($ldap_connection, "uid={$maintainer_email},ou=people,{$LDAP['base_dn']}", $maintainer_user);
  if ($user_add == TRUE) {
   print "$li_good Created system maintainer user <strong>uid={$maintainer_email},ou=people,{$LDAP['base_dn']}</strong></li>\n";
   if ($maintainer_password == 'maintainer123') {
    print "$li_warn <strong>Default password is 'maintainer123' - please change this immediately!</strong></li>\n";
   } else {
    print "$li_good <strong>Custom password set successfully!</strong></li>\n";
   }
   if ($maintainer_email != 'maintainer@example.com') {
    print "$li_good <strong>Custom email address set: {$maintainer_email}</strong></li>\n";
   }
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create system maintainer user: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_admin_role'])) {
  $admin_email = (!empty($_POST['admin_email'])) ? $_POST['admin_email'] : 'admin@example.com';
  $admin_role = array(
   'objectClass' => array('top', 'groupOfNames'),
   'cn' => 'administrators',
   'description' => 'Full system administrator with all privileges',
   'member' => array("uid={$admin_email},ou=people,{$LDAP['base_dn']}")
  );
  
  $role_add = @ ldap_add($ldap_connection, "cn=administrators,ou=roles,{$LDAP['base_dn']}", $admin_role);
  if ($role_add == TRUE) {
   print "$li_good Created administrators role <strong>cn=administrators,ou=roles,{$LDAP['base_dn']}</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create administrators role: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 if (isset($_POST['setup_maintainer_role'])) {
  $maintainer_email = (!empty($_POST['maintainer_email'])) ? $_POST['maintainer_email'] : 'maintainer@example.com';
  $maintainer_role = array(
   'objectClass' => array('top', 'groupOfNames'),
   'cn' => 'maintainers',
   'description' => 'System maintainer with limited privileges',
   'member' => array("uid={$maintainer_email},ou=people,{$LDAP['base_dn']}")
  );
  
  $role_add = @ ldap_add($ldap_connection, "cn=maintainers,ou=roles,{$LDAP['base_dn']}", $maintainer_role);
  if ($role_add == TRUE) {
   print "$li_good Created maintainers role <strong>cn=maintainers,ou=roles,{$LDAP['base_dn']}</strong></li>\n";
  }
  else {
   $error = ldap_error($ldap_connection);
   print "$li_fail Couldn't create maintainers role: <pre>$error</pre></li>\n";
   $no_errors = FALSE;
  }
 }

 # Note: Role membership verification is now automatic and runs after setup completion

 if (isset($_POST['setup_example_org'])) {
  $admin_email = (!empty($_POST['admin_email'])) ? $_POST['admin_email'] : 'admin@example.com';
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
   'creatorDN' => "uid={$admin_email},ou=people,{$LDAP['base_dn']}"
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

 # Automatically verify the complete setup
 if ($no_errors == TRUE) {
  print "$li_good <strong>Running automatic verification...</strong></li>\n";
  
  $admin_email = (!empty($_POST['admin_email'])) ? $_POST['admin_email'] : 'admin@example.com';
  $maintainer_email = (!empty($_POST['maintainer_email'])) ? $_POST['maintainer_email'] : 'maintainer@example.com';
  
  # Verify admin user is in administrators group
  $admin_group_filter = "(&(objectclass=groupOfNames)(cn=administrators))";
  $admin_group_search = ldap_search($ldap_connection, "ou=roles,{$LDAP['base_dn']}", $admin_group_filter);
  if ($admin_group_search) {
   $admin_group_entries = ldap_get_entries($ldap_connection, $admin_group_search);
   if ($admin_group_entries['count'] > 0) {
    $admin_group_dn = $admin_group_entries[0]['dn'];
    $admin_member_dn = "uid={$admin_email},ou=people,{$LDAP['base_dn']}";
    
    if (in_array($admin_member_dn, $admin_group_entries[0]['member'])) {
     print "$li_good ✓ Admin user <strong>{$admin_email}</strong> is properly member of administrators group</li>\n";
    } else {
     print "$li_warn ⚠ Admin user <strong>{$admin_email}</strong> is not in administrators group - adding now</li>\n";
     $add_member = ldap_mod_add($ldap_connection, $admin_group_dn, array('member' => $admin_member_dn));
     if ($add_member) {
      print "$li_good ✓ Successfully added admin user to administrators group</li>\n";
     } else {
      print "$li_fail ✗ Failed to add admin user to administrators group: <pre>" . ldap_error($ldap_connection) . "</pre></li>\n";
      $no_errors = FALSE;
     }
    }
   }
  }
  
  # Verify maintainer user is in maintainers group
  $maintainer_group_filter = "(&(objectclass=groupOfNames)(cn=maintainers))";
  $maintainer_group_search = ldap_search($ldap_connection, "ou=roles,{$LDAP['base_dn']}", $maintainer_group_filter);
  if ($maintainer_group_search) {
   $maintainer_group_entries = ldap_get_entries($ldap_connection, $maintainer_group_search);
   if ($maintainer_group_entries['count'] > 0) {
    $maintainer_group_dn = $maintainer_group_entries[0]['dn'];
    $maintainer_member_dn = "uid={$maintainer_email},ou=people,{$LDAP['base_dn']}";
    
    if (in_array($maintainer_member_dn, $maintainer_group_entries[0]['member'])) {
     print "$li_good ✓ Maintainer user <strong>{$maintainer_email}</strong> is properly member of maintainers group</li>\n";
    } else {
     print "$li_warn ⚠ Maintainer user <strong>{$maintainer_email}</strong> is not in maintainers group - adding now</li>\n";
     $add_member = ldap_mod_add($ldap_connection, $maintainer_group_dn, array('member' => $maintainer_member_dn));
     if ($add_member) {
      print "$li_good ✓ Successfully added maintainer user to maintainers group</li>\n";
     } else {
      print "$li_fail ✗ Failed to add maintainer user to maintainers group: <pre>" . ldap_error($ldap_connection) . "</pre></li>\n";
      $no_errors = FALSE;
     }
    }
   }
  }
  
  # Final verification summary
  if ($no_errors == TRUE) {
   print "$li_good <strong>✓ Setup verification completed successfully!</strong></li>\n";
   print "$li_good <strong>✓ Admin user: {$admin_email}</strong></li>\n";
   print "$li_good <strong>✓ Maintainer user: {$maintainer_email}</strong></li>\n";
   print "$li_good <strong>✓ Both users are properly assigned to their roles</strong></li>\n";
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
