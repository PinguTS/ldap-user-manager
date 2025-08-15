<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";

validate_setup_cookie();
set_page_access("setup");

render_header("$ORGANISATION_NAME account manager setup verification");

$ldap_connection = open_ldap_connection();

?>
<div class='container'>
 <div class="panel panel-default">
  <div class="panel-heading">LDAP Setup Verification</div>
   <div class="panel-body">
    <ul class="list-group">

<?php

# Test 1: Check if OUs exist
print "<li class='list-group-item'><strong>Test 1: Organizational Units</strong></li>\n";

$ou_tests = array(
    "ou=organizations,{$LDAP['base_dn']}" => "Organizations OU",
    "ou=people,{$LDAP['base_dn']}" => "People OU", 
    "ou=roles,{$LDAP['base_dn']}" => "Roles OU"
);

foreach ($ou_tests as $ou_dn => $ou_name) {
    $ou_search = ldap_read($ldap_connection, $ou_dn, "(objectClass=*)", array("dn"));
    if ($ou_search && ldap_count_entries($ldap_connection, $ou_search) > 0) {
        print "<li class='list-group-item list-group-item-success'>✓ {$ou_name} exists</li>\n";
    } else {
        print "<li class='list-group-item list-group-item-danger'>✗ {$ou_name} missing</li>\n";
    }
}

# Test 2: Check if system users exist
print "<li class='list-group-item'><strong>Test 2: System Users</strong></li>\n";

$user_tests = array(
    "(&(objectclass=inetOrgPerson)(description=administrator))" => "Administrator User",
    "(&(objectclass=inetOrgPerson)(description=maintainer))" => "Maintainer User"
);

foreach ($user_tests as $filter => $user_name) {
    $user_search = ldap_search($ldap_connection, "ou=people,{$LDAP['base_dn']}", $filter);
    if ($user_search && ldap_count_entries($ldap_connection, $user_search) > 0) {
        $entries = ldap_get_entries($ldap_connection, $user_search);
        $email = $entries[0]['mail'][0];
        print "<li class='list-group-item list-group-item-success'>✓ {$user_name} exists ({$email})</li>\n";
    } else {
        print "<li class='list-group-item list-group-item-danger'>✗ {$user_name} missing</li>\n";
    }
}

# Test 3: Check if role groups exist
print "<li class='list-group-item'><strong>Test 3: Role Groups</strong></li>\n";

$role_tests = array(
    "(&(objectclass=groupOfNames)(cn=administrators))" => "Administrators Group",
    "(&(objectclass=groupOfNames)(cn=maintainers))" => "Maintainers Group"
);

foreach ($role_tests as $filter => $role_name) {
    $role_search = ldap_search($ldap_connection, "ou=roles,{$LDAP['base_dn']}", $filter);
    if ($role_search && ldap_count_entries($ldap_connection, $role_search) > 0) {
        print "<li class='list-group-item list-group-item-success'>✓ {$role_name} exists</li>\n";
    } else {
        print "<li class='list-group-item list-group-item-danger'>✗ {$role_name} missing</li>\n";
    }
}

# Test 4: Check role memberships
print "<li class='list-group-item'><strong>Test 4: Role Memberships</strong></li>\n";

# Check administrators group membership
$admin_group_search = ldap_search($ldap_connection, "ou=roles,{$LDAP['base_dn']}", "(&(objectclass=groupOfNames)(cn=administrators))");
if ($admin_group_search && ldap_count_entries($ldap_connection, $admin_group_search) > 0) {
    $admin_group_entries = ldap_get_entries($ldap_connection, $admin_group_search);
    $admin_group = $admin_group_entries[0];
    
    if (isset($admin_group['member'])) {
        $member_count = $admin_group['member']['count'];
        print "<li class='list-group-item list-group-item-success'>✓ Administrators group has {$member_count} member(s)</li>\n";
        
        for ($i = 0; $i < $member_count; $i++) {
            $member_dn = $admin_group['member'][$i];
            print "<li class='list-group-item list-group-item-info'>  - {$member_dn}</li>\n";
        }
    } else {
        print "<li class='list-group-item list-group-item-warning'>⚠ Administrators group has no members</li>\n";
    }
} else {
    print "<li class='list-group-item list-group-item-danger'>✗ Cannot find administrators group</li>\n";
}

# Check maintainers group membership
$maintainer_group_search = ldap_search($ldap_connection, "ou=roles,{$LDAP['base_dn']}", "(&(objectclass=groupOfNames)(cn=maintainers))");
if ($maintainer_group_search && ldap_count_entries($ldap_connection, $maintainer_group_search) > 0) {
    $maintainer_group_entries = ldap_get_entries($ldap_connection, $maintainer_group_search);
    $maintainer_group = $maintainer_group_entries[0];
    
    if (isset($maintainer_group['member'])) {
        $member_count = $maintainer_group['member']['count'];
        print "<li class='list-group-item list-group-item-success'>✓ Maintainers group has {$member_count} member(s)</li>\n";
        
        for ($i = 0; $i < $member_count; $i++) {
            $member_dn = $maintainer_group['member'][$i];
            print "<li class='list-group-item list-group-item-info'>  - {$member_dn}</li>\n";
        }
    } else {
        print "<li class='list-group-item list-group-item-warning'>⚠ Maintainers group has no members</li>\n";
    }
} else {
    print "<li class='list-group-item list-group-item-danger'>✗ Cannot find maintainers group</li>\n";
}

# Test 5: Test authentication
print "<li class='list-group-item'><strong>Test 5: Authentication Test</strong></li>\n";

$admin_search = ldap_search($ldap_connection, "ou=people,{$LDAP['base_dn']}", "(&(objectclass=inetOrgPerson)(description=administrator))");
if ($admin_search && ldap_count_entries($ldap_connection, $admin_search) > 0) {
    $admin_entries = ldap_get_entries($ldap_connection, $admin_search);
    $admin_dn = $admin_entries[0]['dn'];
    
    // Try to bind as admin user (this will test if the user can authenticate)
    $test_connection = ldap_connect($LDAP['uri']);
    if ($test_connection) {
        ldap_set_option($test_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($test_connection, LDAP_OPT_REFERRALS, 0);
        
        // Note: We can't test actual password authentication without knowing the password
        // But we can verify the user entry is valid
        $user_read = ldap_read($test_connection, $admin_dn, "(objectClass=*)", array("uid", "cn", "mail"));
        if ($user_read) {
            print "<li class='list-group-item list-group-item-success'>✓ Administrator user entry is valid and readable</li>\n";
        } else {
            print "<li class='list-group-item list-group-item-warning'>⚠ Administrator user entry exists but may have issues</li>\n";
        }
        
        ldap_close($test_connection);
    } else {
        print "<li class='list-group-item list-group-item-warning'>⚠ Cannot test user authentication (connection issue)</li>\n";
    }
} else {
    print "<li class='list-group-item list-group-item-danger'>✗ Cannot find administrator user for authentication test</li>\n";
}

?>
    </ul>
   </div>
 </div>
 
 <div class='well'>
  <form action="<?php print $THIS_MODULE_PATH; ?>">
   <input type='submit' class="btn btn-primary center-block" value='Back to Setup'>
  </form>
 </div>
</div>

<?php

render_footer();

?>
