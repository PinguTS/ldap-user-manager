<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "module_functions.inc.php";
include_once __DIR__ . '/bootstrap_setup.inc.php';

validateSetupCookie();
setPageAccess(['setup', 'admin']);

renderHeader("$ORGANISATION_NAME account manager setup");

$show_finish_button = true;

$ldap_connection = open_ldap_connection();

// Optional cn=config admin bind (LDAP_CONFIG_BIND_PWD / LDAP_CONFIG_PASSWORD). When set,
// the ACL checks below can actually read cn=config; the admin bind (LDAP_ADMIN_BIND_DN)
// has no access to it by design. Falls back to the admin bind (current behavior) when unset.
$acl_config_connection = open_ldap_config_connection();

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

?>
    <div class="card">
      <div class="card-header">User-bound /manage (OpenLDAP olcAccess)</div>
      <div class="card-body">

        <h6 class="mt-1">Step 1 — Baseline (authenticated read + self password write)</h6>
        <ul class="list-group mb-3">
<?php

include_once __DIR__ . '/../includes/setup_acl_functions.inc.php';
// Use the cn=config admin bind when available (can actually read olcAccess); otherwise
// fall back to the app admin bind (will report "cannot read", as it has no cn=config access).
if ($ldap_connection !== false) {
    $acl_read_connection = ($acl_config_connection !== false) ? $acl_config_connection : $ldap_connection;
    $vb = setupVerifyUserBindAcls($acl_read_connection);
    if ($vb['ok']) {
        print "$li_good " . htmlspecialchars($vb['detail'], ENT_QUOTES, 'UTF-8') . " (olcAccess lines in MDB: " . (int) $vb['line_count'] . ")</li>\n";
    } else {
        print "$li_warn " . htmlspecialchars($vb['detail'], ENT_QUOTES, 'UTF-8') . " (olcAccess lines in MDB: " . (int) $vb['line_count'] . ")</li>\n";
        if ((int) $vb['line_count'] === 0) {
            $verifyCmd = "docker exec -i ldap-server sh -lc \"ldapsearch -Y EXTERNAL -H ldapi:/// -LLL -b '"
                . setupOlcMdbDn()
                . "' olcAccess\"";
            print "$li_info Verify directly with this command (copy/paste):<br><code>"
                . htmlspecialchars($verifyCmd, ENT_QUOTES, 'UTF-8')
                . "</code></li>\n";
            if ($acl_config_connection === false) {
                print "$li_info Or set <code>LDAP_CONFIG_BIND_PWD</code> (cn=config admin password) so this check and the apply helper can read/apply olcAccess automatically.</li>\n";
            }
        }
    }
} else {
    print "$li_info Baseline ACL check skipped: LDAP not connected.</li>\n";
}

?>
        </ul>

        <h6>Step 2 — Role-based write ACLs (administrators / maintainers / org admins)</h6>
        <ul class="list-group mb-3">
<?php

if ($ldap_connection !== false) {
    $acl_read_connection = ($acl_config_connection !== false) ? $acl_config_connection : $ldap_connection;
    $rb = setupVerifyRoleBasedAcls($acl_read_connection);
    if (!$rb['can_read_config']) {
        print "$li_warn Cannot read <code>" . htmlspecialchars(setupOlcMdbDn(), ENT_QUOTES, 'UTF-8') . "</code> olcAccess with the app bind. Apply manually — see the ACL helper page.</li>\n";
        if ($acl_config_connection === false) {
            print "$li_info Set <code>LDAP_CONFIG_BIND_PWD</code> (cn=config admin password) to let this check and the apply helper read/apply olcAccess automatically.</li>\n";
        }
    } elseif ($rb['all_present'] && $rb['reachable']) {
        print "$li_good All role-based olcAccess rules are present and reachable (" . count($rb['present']) . " rules).</li>\n";
    } elseif ($rb['all_present'] && !$rb['reachable']) {
        print "$li_warn All role-based rules are present, but one or more are <strong>unreachable</strong> because a legacy rule ending with <code>by&nbsp;*&nbsp;none</code> appears before them in the ACL chain. Use the helper below to restructure the rule set.</li>\n";
        if ($rb['has_legacy_blocking']) {
            print "$li_warn Legacy blocking rules detected (rules ending with <code>by&nbsp;*&nbsp;none</code> before LUM rules). These prevent user-bind reads and writes from being evaluated.</li>\n";
        }
    } else {
        $mc = count($rb['missing']);
        $pc = count($rb['present']);
        if ($rb['has_legacy_blocking']) {
            print "$li_warn Legacy blocking olcAccess rules detected (rules ending with <code>by&nbsp;*&nbsp;none</code>). These prevent any appended LUM rules from being reached. The apply helper will replace (not append) the entire rule set.</li>\n";
        }
        print "$li_warn " . (int) $mc . " role-based rule(s) missing, " . (int) $pc . " present. Apply via the helper below.</li>\n";
        foreach ($rb['missing'] as $m) {
            print "$li_info Missing: <code>" . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . "</code></li>\n";
        }
    }
} else {
    print "$li_info Role-based ACL check skipped: LDAP not connected.</li>\n";
}

?>
        </ul>

        <p class="mb-2">
          <a class="btn btn-sm btn-outline-primary" href="<?php print htmlspecialchars($THIS_MODULE_PATH . '/apply_user_bind_acls.php', ENT_QUOTES, 'UTF-8'); ?>">
            Open ACL apply helper (Step 1 &amp; Step 2)
          </a>
        </p>
        <p class="text-muted small mb-0">
          Re-run this page after any <code>cn=config</code> change. The apply helper uses
          <code>LDAP_OLC_MDB_DN</code> (default <code>olcDatabase={1}mdb,cn=config</code>).
          See <code>docs/ldap/userbind-acls.md</code> for explanation of the ACL structure,
          verification with <code>ldapsearch</code>, and the difference between app roles and LDAP identities.
        </p>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Organization change history (accesslog overlay)</div>
      <div class="card-body">
        <ul class="list-group">
<?php

$accesslog_env_enabled = strcasecmp((string) (getenv('LDAP_ACCESSLOG_ENABLED') ?: 'false'), 'true') === 0;
$show_accesslog_fix_guide = false;

if (!$accesslog_env_enabled) {
    print "$li_info Organization change history is <strong>disabled</strong>. "
        . "Set <code>LDAP_ACCESSLOG_ENABLED=true</code> in the <code>ldap-user-manager</code> service to enable. "
        . "The OpenLDAP accesslog overlay must also be active on the LDAP server. "
        . "See <code>docker/openldap/README.md</code> for setup instructions.</li>\n";
} else {
    $accesslog_check = @ldap_read($ldap_connection, 'cn=accesslog', '(objectClass=*)', ['objectClass'], 0, 1);
    if ($accesslog_check !== false && ldap_count_entries($ldap_connection, $accesslog_check) > 0) {
        print "$li_good The accesslog overlay is active and <strong>cn=accesslog</strong> is accessible.</li>\n";
    } else {
        $show_accesslog_fix_guide = true;
        print "$li_fail <code>LDAP_ACCESSLOG_ENABLED=true</code> is set but <strong>cn=accesslog</strong> is not accessible. "
            . "Enable the overlay on the LDAP server (see <code>docker/openldap/README.md</code>) and ensure "
            . "the bind DN has read access to <code>cn=accesslog</code>.</li>\n";
    }
}

?>
        </ul>
        <?php if ($show_accesslog_fix_guide) : ?>
        <div class="alert alert-warning mt-3 mb-2">
          <strong>Retroactive enablement (existing LDAP data)</strong>
          <p class="mb-2 mt-1">
            Since this LDAP instance was already initialized, setting
            <code>LDAP_BACKEND_OVERLAY_ACCESSLOG=true</code> alone is usually not enough.
            Apply the accesslog module/database/overlay manually against <code>cn=config</code>.
          </p>
          <ol class="mb-2">
            <li>Create the accesslog directory inside the LDAP container:</li>
          </ol>
<pre class="bg-light p-2 border rounded small mb-2"><code>docker exec -it ldap-server mkdir -p /var/lib/ldap/accesslog
docker exec -it ldap-server chown openldap:openldap /var/lib/ldap/accesslog</code></pre>
          <ol start="2" class="mb-2">
            <li>Run the manual post-init commands from <code>docker/openldap/README.md</code> to add accesslog to <code>cn=config</code>.</li>
            <li>Restart the web app container and re-run this setup check.</li>
          </ol>
          <p class="mb-2">
            <a class="btn btn-sm btn-outline-primary" href="<?php print htmlspecialchars($THIS_MODULE_PATH . '/accesslog_enable.php', ENT_QUOTES, 'UTF-8'); ?>">
              Open accesslog enablement helper
            </a>
          </p>
          <p class="mb-0">
            Verification command:
            <code>ldapsearch -x -H ldaps://localhost:636 -D "<?php print htmlspecialchars((string) $LDAP['bind_dn'], ENT_QUOTES, 'UTF-8'); ?>" -w "***" -b "cn=accesslog" -s base "(objectClass=*)" -o tls_reqcert=never</code>
          </p>
        </div>
        <?php endif; ?>
        <p class="text-muted small mt-2 mb-0">
          This feature is optional. A failing check here does not block setup completion.
          To re-run this check on an existing installation: set <code>APP_SETUP_LOCKED=false</code> and visit <code>/setup/</code>.
        </p>
      </div>
    </div>

<?php

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
            <form action="<?php print "{$SERVER_PATH}login/"; ?>">
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

renderFooter();
?>
