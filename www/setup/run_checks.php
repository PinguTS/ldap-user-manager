<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "module_functions.inc.php";
include_once __DIR__ . '/bootstrap_setup.inc.php';

validate_setup_cookie();
set_page_access("setup");

render_header("$ORGANISATION_NAME account manager setup");

$show_finish_button = true;

$ldap_connection = open_ldap_connection();

?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function(el) {
            new bootstrap.Popover(el);
        });
    });
</script>
<div class="form-group">
  <form action="<?php print $THIS_MODULE_PATH; ?>/ldap.php" method="post">
  <input type="hidden" name="fix_problems">


    <div class='container'>

     <div class="card">
      <div class="card-header">LDAP connection tests</div>
      <div class="card-body">
       <ul class="list-group">
<?php

#Can we connect?  The open_ldap_connection() function will call die() if we can't.
print "$li_good Connected to {$LDAP['uri']}</li>\n";

#TLS?
if ($LDAP['connection_type'] != "plain") {
    print "$li_good Encrypted connection to {$LDAP['uri']} via {$LDAP['connection_type']}</li>\n";
} else {
    print "$li_warn Unable to connect to {$LDAP['uri']} via StartTLS. ";
    print "<a href='#' data-bs-toggle='popover' data-bs-title='StartTLS' data-bs-content='";
    print "The connection to the LDAP server works, but encrypted communication can&#39;t be enabled.";
    print "'>What's this?</a></li>\n";
}


?>
       </ul>
      </div>
     </div>

     <div class="card">
      <div class="card-header">LDAP structure checks</div>
      <div class="card-body">
       <ul class="list-group">
<?php

# Check for organizations OU
$org_result = array('count' => 0);
$sys_users_result = array('count' => 0);
$global_roles_result = array('count' => 0);

$org_filter = "(&(objectclass=organizationalUnit)(ou=" . $LDAP['org_ou'] . "))";
$ldap_org_search = ldap_search($ldap_connection, "{$LDAP['base_dn']}", $org_filter);

if ($ldap_org_search === false) {
    print "$li_fail Unable to search for organizations OU. LDAP search failed. ";
    print "<a href='#' data-bs-toggle='popover' data-bs-title='Organizations OU' data-bs-content='";
    print "This is the Organizational Unit (OU) that organizations are stored under.";
    print "'>What's this?</a>";
    print "<label class='float-end'><input type='checkbox' name='setup_organizations_ou' class='float-end' checked>Create?&nbsp;</label>";
    print "</li>\n";
    $show_finish_button = false;
} else {
    $org_result = ldap_get_entries($ldap_connection, $ldap_org_search);

    if ($org_result['count'] != 1) {
        print "$li_fail The organizations OU (<strong>{$LDAP['org_dn']}</strong>) doesn't exist. ";
        print "<a href='#' data-bs-toggle='popover' data-bs-title='Organizations OU' data-bs-content='";
        print "This is the Organizational Unit (OU) that organizations are stored under.";
        print "'>What's this?</a>";
        print "<label class='float-end'><input type='checkbox' name='setup_organizations_ou' class='float-end' checked>Create?&nbsp;</label>";
        print "</li>\n";
        $show_finish_button = false;
    } else {
        print "$li_good The organizations OU (<strong>{$LDAP['org_dn']}</strong>) is present.</li>";
    }
}

# Check for people OU
$people_filter = "(&(objectclass=organizationalUnit)(ou=people))";
$people_search = ldap_search($ldap_connection, $LDAP['base_dn'], $people_filter);
if (ldap_count_entries($ldap_connection, $people_search) == 0) {
    print "$li_fail The people OU (<strong>ou=people,{$LDAP['base_dn']}</strong>) doesn't exist. ";
    print "<label class='float-end'><input type='checkbox' name='setup_people_ou' class='float-end' checked>Create?&nbsp;</label>";
    $show_finish_button = false;
} else {
    print "$li_good The people OU (<strong>ou=people,{$LDAP['base_dn']}</strong>) is present.</li>";
}

# Check for global roles OU
$global_roles_filter = "(&(objectclass=organizationalUnit)(ou=roles))";
$global_roles_search = ldap_search($ldap_connection, $LDAP['base_dn'], $global_roles_filter);
if (ldap_count_entries($ldap_connection, $global_roles_search) == 0) {
    print "$li_fail The global roles OU (<strong>{$LDAP['roles_dn']}</strong>) doesn't exist. ";
    print "<label class='float-end'><input type='checkbox' name='setup_global_roles_ou' class='float-end' checked>Create?&nbsp;</label>";
    $show_finish_button = false;
} else {
    print "$li_good The global roles OU (<strong>{$LDAP['roles_dn']}</strong>) is present.</li>";
}

?>
       </ul>
      </div>
     </div>

     <div class="card">
      <div class="card-header">System roles and administrator setup</div>
      <div class="card-body">
       <ul class="list-group">
<?php

# First: Check if administrator role group exists
$admin_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_role']}))";
$ldap_admin_role_search = ldap_search($ldap_connection, $LDAP['roles_dn'], $admin_role_filter);

if (!$ldap_admin_role_search) {
    print "$li_fail Unable to search for administrator role. The global roles OU may not exist yet. ";
    print "<label class='float-end'><input type='checkbox' name='setup_global_roles_ou' class='float-end' checked>Create?&nbsp;</label>";
    $show_finish_button = false;
} else {
    if (ldap_count_entries($ldap_connection, $ldap_admin_role_search) == 0) {
            print "$li_info The administrator role (<strong>cn={$LDAP['admin_role']},{$LDAP['roles_dn']}</strong>) doesn't exist yet. ";
        print "<br><small class='text-muted'>ℹ️ <strong>Info:</strong> This will be created automatically when you create an admin user</small>";
        print "<br><label class='float-end'><input type='checkbox' name='setup_admin_user' class='float-end' checked>Create admin user?&nbsp;</label>";
        print "<br><small>Email: <input type='email' name='admin_email' placeholder='admin@example.com' value='admin@example.com' class='form-control input-sm' style='width: 250px; display: inline-block;'></small>";
        print "<br><small>Password: <input type='password' name='admin_password' placeholder='Enter admin password' class='form-control input-sm' style='width: 200px; display: inline-block;'></small>";
        $show_finish_button = false;
    } else {
            print "$li_good The administrator role (<strong>cn={$LDAP['admin_role']},{$LDAP['roles_dn']}</strong>) is present.</li>";

      # Second: Check if there's at least one user who is a member of the administrator role
        $admin_role_entries = ldap_get_entries($ldap_connection, $ldap_admin_role_search);
        if (isset($admin_role_entries[0]['member']) && $admin_role_entries[0]['member']['count'] > 0) {
            $admin_count = $admin_role_entries[0]['member']['count'];
            print "$li_good The administrator role has {$admin_count} member(s).</li>";
        } else {
            print "$li_fail The administrator role exists but has no members. ";
            print "<label class='float-end'><input type='checkbox' name='setup_admin_user' class='float-end' checked>Create admin user?&nbsp;</label>";
            print "<br><small class='text-muted'>✅ <strong>Step 2:</strong> Now create an admin user to assign to the role</small>";
            print "<br><small>Email: <input type='email' name='admin_email' placeholder='admin@example.com' value='admin@example.com' class='form-control input-sm' style='width: 250px; display: inline-block;'></small>";
            print "<br><small>Password: <input type='password' name='admin_password' placeholder='Enter admin password' class='form-control input-sm' style='width: 200px; display: inline-block;'></small>";
            $show_finish_button = false;
        }
    }
}

# Check for maintainer role (essential for system structure)
$maintainer_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['maintainer_role']}))";
$ldap_maintainer_role_search = ldap_search($ldap_connection, $LDAP['roles_dn'], $maintainer_role_filter);

if (!$ldap_maintainer_role_search) {
    print "$li_fail Unable to search for maintainer role. The global roles OU may not exist yet. ";
    print "<label class='float-end'><input type='checkbox' name='setup_global_roles_ou' class='float-end' checked>Create?&nbsp;</label>";
    $show_finish_button = false;
} else {
    if (ldap_count_entries($ldap_connection, $ldap_maintainer_role_search) == 0) {
            print "$li_info The maintainer role (<strong>cn={$LDAP['maintainer_role']},{$LDAP['roles_dn']}</strong>) doesn't exist yet. ";
        print "<br><small class='text-muted'>ℹ️ <strong>Info:</strong> This will be created automatically when you create a maintainer user</small>";
        print "<br><label class='float-end'><input type='checkbox' name='setup_maintainer_user' class='float-end'>Create maintainer user?&nbsp;</label>";
        print "<br><small>Email: <input type='email' name='maintainer_email' placeholder='maintainer@example.com' value='maintainer@example.com' class='form-control input-sm' style='width: 250px; display: inline-block;'></small>";
        print "<br><small>Password: <input type='password' name='maintainer_password' placeholder='Enter maintainer password' class='form-control input-sm' style='width: 200px; display: inline-block;'></small>";
    } else {
            print "$li_good The maintainer role (<strong>cn={$LDAP['maintainer_role']},{$LDAP['roles_dn']}</strong>) is present.</li>";

      # Check if maintainer role has members (optional - can be created during runtime)
        $maintainer_role_entries = ldap_get_entries($ldap_connection, $ldap_maintainer_role_search);
        if (isset($maintainer_role_entries[0]['member']) && $maintainer_role_entries[0]['member']['count'] > 0) {
            $maintainer_count = $maintainer_role_entries[0]['member']['count'];
            print "$li_good The maintainer role has {$maintainer_count} member(s).</li>";
        } else {
            print "$li_warn The maintainer role exists but has no members. ";
            print "<label class='float-end'><input type='checkbox' name='setup_maintainer_user' class='float-end'>Create maintainer user?&nbsp;</label>";
            print "<br><small class='text-muted'>✅ <strong>Step 2:</strong> Now create a maintainer user to assign to the role (optional)</small>";
        }
    }
}








# Note: Role membership verification now happens automatically during setup

?>
        </ul>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Example organization setup</div>
      <div class="card-body">
        <ul class="list-group">
<?php

# Check for example organization
$example_org_filter = "(&(objectclass=organization)(o=Example Company))";
$ldap_example_org_search = ldap_search($ldap_connection, $LDAP['org_dn'], $example_org_filter);

if ($ldap_example_org_search === false) {
    print "$li_warn Unable to search for example organization. The organizations OU may not exist yet. ";
    print "<a href='#' data-bs-toggle='popover' data-bs-title='Example Organization' data-bs-content='";
    print "This is a sample organization to demonstrate the system structure. It's optional but recommended for testing.";
    print "'>What's this?</a>";
    print "<label class='float-end'><input type='checkbox' name='setup_example_org' class='float-end' checked>Create?&nbsp;</label>";
    print "</li>\n";
} else {
    $example_org_result = ldap_get_entries($ldap_connection, $ldap_example_org_search);

    if ($example_org_result['count'] != 1) {
        print "$li_warn The example organization (<strong>o=Example Company,{$LDAP['org_dn']}</strong>) doesn't exist. ";
        print "<a href='#' data-bs-toggle='popover' data-bs-title='Example Organization' data-bs-content='";
        print "This is a sample organization to demonstrate the system structure. It's optional but recommended for testing.";
        print "'>What's this?</a>";
        print "<label class='float-end'><input type='checkbox' name='setup_example_org' class='float-end' checked>Create?&nbsp;</label>";
        print "</li>\n";
    } else {
        print "$li_good The example organization (<strong>o=Example Company,{$LDAP['org_dn']}</strong>) is present.</li>";
    }
}

?>
        </ul>
      </div>
    </div>
<?php

##############

# Setup debug logging
if ($SETUP_DEBUG == true) {
    error_log("$log_prefix SETUP_DEBUG: show_finish_button = " . ($show_finish_button ? 'TRUE' : 'FALSE'), 0);
}

if ($show_finish_button == true) {
    ?>
      </form>
      <div class="p-3 bg-light rounded">
        <div class="row">
          <div class="col-md-6">
            <form action="<?php print "{$SERVER_PATH}log_in"; ?>">
              <input type='submit' class="btn btn-success d-block mx-auto" value='Done'>
            </form>
          </div>
          <div class="col-md-6">
            <form action="<?php print $THIS_MODULE_PATH; ?>/verify.php">
              <input type='submit' class="btn btn-info d-block mx-auto" value='Verify Setup'>
            </form>
          </div>
        </div>
      </div>
    <?php
} else {
    ?>
      <div class="p-3 bg-light rounded">
        <input type='submit' class="btn btn-primary d-block mx-auto" value='Next >'>
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
