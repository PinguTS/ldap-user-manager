<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "module_functions.inc.php";

validate_setup_cookie();
set_page_access("setup");

render_header("$ORGANISATION_NAME account manager setup");

$show_finish_button = TRUE;

$ldap_connection = open_ldap_connection();

?>
<script>
    $(document).ready(function(){
     $('[data-toggle="popover"]').popover();
    });
</script>
<div class="form-group">
  <form action="<?php print $THIS_MODULE_PATH; ?>/setup_ldap.php" method="post">
  <input type="hidden" name="fix_problems">


    <div class='container'>

     <div class="panel panel-default">
      <div class="panel-heading">LDAP connection tests</div>
      <div class="panel-body">
       <ul class="list-group">
<?php

#Can we connect?  The open_ldap_connection() function will call die() if we can't.
print "$li_good Connected to {$LDAP['uri']}</li>\n";

#TLS?
if ($LDAP['connection_type'] != "plain") {
 print "$li_good Encrypted connection to {$LDAP['uri']} via {$LDAP['connection_type']}</li>\n";
}
else {
 print "$li_warn Unable to connect to {$LDAP['uri']} via StartTLS. ";
 print "<a href='#' data-toggle='popover' title='StartTLS' data-content='";
 print "The connection to the LDAP server works, but encrypted communication can&#39;t be enabled.";
 print "'>What's this?</a></li>\n";
}


?>
       </ul>
      </div>
     </div>

     <div class="panel panel-default">
      <div class="panel-heading">LDAP structure checks</div>
      <div class="panel-body">
       <ul class="list-group">
<?php

# Check for organizations OU
$org_result = array('count' => 0);
$sys_users_result = array('count' => 0);
$global_roles_result = array('count' => 0);

$org_filter = "(&(objectclass=organizationalUnit)(ou=organizations))";
$ldap_org_search = ldap_search($ldap_connection, "{$LDAP['base_dn']}", $org_filter);

if ($ldap_org_search === false) {
 print "$li_fail Unable to search for organizations OU. LDAP search failed. ";
 print "<a href='#' data-toggle='popover' title='Organizations OU' data-content='";
 print "This is the Organizational Unit (OU) that organizations are stored under.";
 print "'>What's this?</a>";
 print "<label class='pull-right'><input type='checkbox' name='setup_organizations_ou' class='pull-right' checked>Create?&nbsp;</label>";
 print "</li>\n";
 $show_finish_button = FALSE;
}
else {
 $org_result = ldap_get_entries($ldap_connection, $ldap_org_search);

 if ($org_result['count'] != 1) {
  print "$li_fail The organizations OU (<strong>ou=organizations,{$LDAP['base_dn']}</strong>) doesn't exist. ";
  print "<a href='#' data-toggle='popover' title='Organizations OU' data-content='";
  print "This is the Organizational Unit (OU) that organizations are stored under.";
  print "'>What's this?</a>";
  print "<label class='pull-right'><input type='checkbox' name='setup_organizations_ou' class='pull-right' checked>Create?&nbsp;</label>";
  print "</li>\n";
  $show_finish_button = FALSE;
 }
 else {
  print "$li_good The organizations OU (<strong>ou=organizations,{$LDAP['base_dn']}</strong>) is present.</li>";
 }
}

# Check for system_users OU
$sys_users_filter = "(&(objectclass=organizationalUnit)(ou=system_users))";
$ldap_sys_users_search = ldap_search($ldap_connection, "{$LDAP['base_dn']}", $sys_users_filter);

if ($ldap_sys_users_search === false) {
 print "$li_fail Unable to search for system users OU. LDAP search failed. ";
 print "<a href='#' data-toggle='popover' title='System Users OU' data-content='";
 print "This is the Organizational Unit (OU) that system-level users (administrators, maintainers) are stored under.";
 print "'>What's this?</a>";
 print "<label class='pull-right'><input type='checkbox' name='setup_system_users_ou' class='pull-right' checked>Create?&nbsp;</label>";
 print "</li>\n";
 $show_finish_button = FALSE;
}
else {
 $sys_users_result = ldap_get_entries($ldap_connection, $ldap_sys_users_search);

 if ($sys_users_result['count'] != 1) {
  print "$li_fail The system users OU (<strong>ou=system_users,{$LDAP['base_dn']}</strong>) doesn't exist. ";
  print "<a href='#' data-toggle='popover' title='System Users OU' data-content='";
  print "This is the Organizational Unit (OU) that system-level users (administrators, maintainers) are stored under.";
  print "'>What's this?</a>";
  print "<label class='pull-right'><input type='checkbox' name='setup_system_users_ou' class='pull-right' checked>Create?&nbsp;</label>";
  print "</li>\n";
  $show_finish_button = FALSE;
 }
 else {
  print "$li_good The system users OU (<strong>ou=system_users,{$LDAP['base_dn']}</strong>) is present.</li>";
 }
}

# Check for global roles OU
$global_roles_filter = "(&(objectclass=organizationalUnit)(ou=roles))";
$ldap_global_roles_search = ldap_search($ldap_connection, "ou=organizations,{$LDAP['base_dn']}", $global_roles_filter);

if ($ldap_global_roles_search === false) {
 print "$li_fail Unable to search for global roles OU. The organizations OU may not exist yet. ";
 print "<a href='#' data-toggle='popover' title='Global Roles OU' data-content='";
 print "This is the Organizational Unit (OU) that global system roles (administrator, maintainer) are stored under.";
 print "'>What's this?</a>";
 print "<label class='pull-right'><input type='checkbox' name='setup_global_roles_ou' class='pull-right' checked>Create?&nbsp;</label>";
 print "</li>\n";
 $show_finish_button = FALSE;
}
else {
 $global_roles_result = ldap_get_entries($ldap_connection, $ldap_global_roles_search);

 if ($global_roles_result['count'] != 1) {
  print "$li_fail The global roles OU (<strong>ou=roles,ou=organizations,{$LDAP['base_dn']}</strong>) doesn't exist. ";
  print "<a href='#' data-toggle='popover' title='Global Roles OU' data-content='";
  print "This is the Organizational Unit (OU) that global system roles (administrator, maintainer) are stored under.";
  print "'>What's this?</a>";
  print "<label class='pull-right'><input type='checkbox' name='setup_global_roles_ou' class='pull-right' checked>Create?&nbsp;</label>";
  print "</li>\n";
  $show_finish_button = FALSE;
 }
 else {
  print "$li_good The global roles OU (<strong>ou=roles,ou=organizations,{$LDAP['base_dn']}</strong>) is present.</li>";
 }
}

?>
       </ul>
      </div>
     </div>

     <div class="panel panel-default">
      <div class="panel-heading">LDAP role and user checks</div>
      <div class="panel-body">
       <ul class="list-group">
<?php

# Check for administrator role
$admin_role_result = array('count' => 0);
$maintainer_role_result = array('count' => 0);
$admin_user_result = array('count' => 0);

$admin_role_filter = "(&(objectclass=groupOfNames)(cn=administrator))";
$ldap_admin_role_search = ldap_search($ldap_connection, "ou=roles,ou=organizations,{$LDAP['base_dn']}", $admin_role_filter);

if ($ldap_admin_role_search === false) {
 print "$li_fail Unable to search for administrator role. The roles OU may not exist yet. ";
 print "<a href='#' data-toggle='popover' title='Administrator Role' data-content='";
 print "This role defines users with full system administrator privileges.";
 print "'>What's this?</a>";
 print "<label class='pull-right'><input type='checkbox' name='setup_admin_role' class='pull-right' checked>Create?&nbsp;</label>";
 print "</li>\n";
 $show_finish_button = FALSE;
}
else {
 $admin_role_result = ldap_get_entries($ldap_connection, $ldap_admin_role_search);

 if ($admin_role_result['count'] != 1) {
  print "$li_fail The administrator role (<strong>cn=administrator,ou=roles,ou=organizations,{$LDAP['base_dn']}</strong>) doesn't exist. ";
  print "<a href='#' data-toggle='popover' title='Administrator Role' data-content='";
  print "This role defines users with full system administrator privileges.";
  print "'>What's this?</a>";
  print "<label class='pull-right'><input type='checkbox' name='setup_admin_role' class='pull-right' checked>Create?&nbsp;</label>";
  print "</li>\n";
  $show_finish_button = FALSE;
 }
 else {
  print "$li_good The administrator role (<strong>cn=administrator,ou=roles,ou=organizations,{$LDAP['base_dn']}</strong>) is present.</li>";
 }
}

# Check for maintainer role
$maintainer_role_filter = "(&(objectclass=groupOfNames)(cn=maintainer))";
$ldap_maintainer_role_search = ldap_search($ldap_connection, "ou=roles,ou=organizations,{$LDAP['base_dn']}", $maintainer_role_filter);

if ($ldap_maintainer_role_search === false) {
 print "$li_fail Unable to search for maintainer role. The roles OU may not exist yet. ";
 print "<a href='#' data-toggle='popover' title='Maintainer Role' data-content='";
 print "This role defines users with system maintainer privileges (cannot modify administrators).";
 print "'>What's this?</a>";
 print "<label class='pull-right'><input type='checkbox' name='setup_maintainer_role' class='pull-right' checked>Create?&nbsp;</label>";
 print "</li>\n";
 $show_finish_button = FALSE;
}
else {
 $maintainer_role_result = ldap_get_entries($ldap_connection, $ldap_maintainer_role_search);

 if ($maintainer_role_result['count'] != 1) {
  print "$li_fail The maintainer role (<strong>cn=maintainer,ou=roles,ou=organizations,{$LDAP['base_dn']}</strong>) doesn't exist. ";
  print "<a href='#' data-toggle='popover' title='Maintainer Role' data-content='";
  print "This role defines users with system maintainer privileges (cannot modify administrators).";
  print "'>What's this?</a>";
  print "<label class='pull-right'><input type='checkbox' name='setup_maintainer_role' class='pull-right' checked>Create?&nbsp;</label>";
  print "</li>\n";
  $show_finish_button = FALSE;
 }
 else {
  print "$li_good The maintainer role (<strong>cn=maintainer,ou=roles,ou=organizations,{$LDAP['base_dn']}</strong>) is present.</li>";
 }
}

# Check for system administrator user
$admin_user_filter = "(&(objectclass=inetOrgPerson)(uid=admin@example.com))";
$ldap_admin_user_search = ldap_search($ldap_connection, "ou=system_users,{$LDAP['base_dn']}", $admin_user_filter);

if ($ldap_admin_user_search === false) {
 print "$li_fail Unable to search for system administrator user. The system_users OU may not exist yet. ";
 print "<a href='#' data-toggle='popover' title='System Administrator' data-content='";
 print "This is the initial system administrator account that will have full privileges.";
 print "'>What's this?</a>";
 print "<label class='pull-right'><input type='checkbox' name='setup_admin_user' class='pull-right' checked>Create?&nbsp;</label>";
 print "</li>\n";
 $show_finish_button = FALSE;
}
else {
 $admin_user_result = ldap_get_entries($ldap_connection, $ldap_admin_user_search);

 if ($admin_user_result['count'] != 1) {
  print "$li_fail The system administrator user (<strong>uid=admin@example.com,ou=system_users,{$LDAP['base_dn']}</strong>) doesn't exist. ";
  print "<a href='#' data-toggle='popover' title='System Administrator' data-content='";
  print "This is the initial system administrator account that will have full privileges.";
  print "'>What's this?</a>";
  print "<label class='pull-right'><input type='checkbox' name='setup_admin_user' class='pull-right' checked>Create?&nbsp;</label>";
  print "</li>\n";
  $show_finish_button = FALSE;
 }
 else {
  print "$li_good The system administrator user (<strong>uid=admin@example.com,ou=system_users,{$LDAP['base_dn']}</strong>) is present.</li>";
 }
}

 # Check if admin user is in administrator role
 if (isset($admin_role_result) && isset($admin_user_result) && $admin_role_result['count'] == 1 && $admin_user_result['count'] == 1) {
  $admin_role_dn = $admin_role_result[0]['dn'];
  $admin_user_dn = $admin_user_result[0]['dn'];
  
  $admin_members = ldap_get_role_members($ldap_connection, 'administrator');
  if (!in_array($admin_user_dn, $admin_members)) {
   print "$li_fail The system administrator user is not a member of the administrator role. ";
   print "<a href='#' data-toggle='popover' title='Role Membership' data-content='";
   print "The administrator user must be a member of the administrator role to have proper privileges.";
   print "'>What's this?</a>";
   print "<label class='pull-right'><input type='checkbox' name='setup_admin_role_membership' class='pull-right' checked>Fix?&nbsp;</label>";
   print "</li>\n";
   $show_finish_button = FALSE;
  }
  else {
   print "$li_good The system administrator user is properly assigned to the administrator role.</li>";
  }
 }

?>
       </ul>
      </div>
     </div>

     <div class="panel panel-default">
      <div class="panel-heading">Example organization setup</div>
      <div class="panel-body">
       <ul class="list-group">
<?php

# Check for example organization
$example_org_filter = "(&(objectclass=organization)(o=Example Company))";
$ldap_example_org_search = ldap_search($ldap_connection, "ou=organizations,{$LDAP['base_dn']}", $example_org_filter);

if ($ldap_example_org_search === false) {
 print "$li_warn Unable to search for example organization. The organizations OU may not exist yet. ";
 print "<a href='#' data-toggle='popover' title='Example Organization' data-content='";
 print "This is a sample organization to demonstrate the system structure. It's optional but recommended for testing.";
 print "'>What's this?</a>";
 print "<label class='pull-right'><input type='checkbox' name='setup_example_org' class='pull-right' checked>Create?&nbsp;</label>";
 print "</li>\n";
}
else {
 $example_org_result = ldap_get_entries($ldap_connection, $ldap_example_org_search);

 if ($example_org_result['count'] != 1) {
  print "$li_warn The example organization (<strong>o=Example Company,ou=organizations,{$LDAP['base_dn']}</strong>) doesn't exist. ";
  print "<a href='#' data-toggle='popover' title='Example Organization' data-content='";
  print "This is a sample organization to demonstrate the system structure. It's optional but recommended for testing.";
  print "'>What's this?</a>";
  print "<label class='pull-right'><input type='checkbox' name='setup_example_org' class='pull-right' checked>Create?&nbsp;</label>";
  print "</li>\n";
 }
 else {
  print "$li_good The example organization (<strong>o=Example Company,ou=organizations,{$LDAP['base_dn']}</strong>) is present.</li>";
 }
}

?>
       </ul>
      </div>
     </div>
<?php

##############

if ($show_finish_button == TRUE) {
?>
     </form>
     <div class='well'>
      <form action="<?php print "{$SERVER_PATH}log_in"; ?>">
       <input type='submit' class="btn btn-success center-block" value='Done'>
      </form>
     </div>
<?php
}
else {
?>
     <div class='well'>
      <input type='submit' class="btn btn-primary center-block" value='Next >'>
     </div>
     </form>
<?php
}


?>
 </div>
</div>
<?php

render_footer();

?>
