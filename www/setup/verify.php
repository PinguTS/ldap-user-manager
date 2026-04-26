<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "setup_verify.inc.php";
include_once __DIR__ . '/../includes/email_verify.inc.php';
include_once __DIR__ . '/../includes/email_status.inc.php';
include_once __DIR__ . '/bootstrap_setup.inc.php';

validateSetupCookie();
setPageAccess(['setup', 'admin']);

renderHeader("$ORGANISATION_NAME account manager setup verification");

$ldap_connection = open_ldap_connection();
if ($ldap_connection === false) {
    print "<div class='alert alert-danger'>✗ Cannot connect to LDAP server</div>";
    renderFooter();
    exit;
}

# Setup debug logging
if ($SETUP_DEBUG == true) {
    error_log("$log_prefix SETUP_DEBUG: Starting verification process", 0);
}

$result = run_setup_verification($ldap_connection);
$missing_components = $result['missing_components'];

?>
<div class='container'>
  <div class="card">
    <div class="card-header">LDAP Setup Verification</div>
    <div class="card-body">
      <ul class="list-group">

<?php

# Test 1: Organizational Units
print "<li class='list-group-item'><strong>Test 1: Organizational Units</strong></li>\n";
foreach ($result['ou_results'] as $ou_name => $ok) {
    if ($ok) {
        print "<li class='list-group-item list-group-item-success'>✓ {$ou_name} exists</li>\n";
    } else {
        print "<li class='list-group-item list-group-item-danger'>✗ {$ou_name} missing</li>\n";
    }
}

# Test 2: System Users
print "<li class='list-group-item'><strong>Test 2: System Users</strong></li>\n";
if ($result['admin_exists']) {
    $admin_attrs = $result['admin_attrs'];
    $admin_mail = $admin_attrs['mail'][0] ?? null;
    $admin_uid = $admin_attrs['uid'][0] ?? null;
    $label = $admin_mail ?: ($admin_uid ?: $result['admin_member_dn']);
    $label = htmlspecialchars((string) $label);
    print "<li class='list-group-item list-group-item-success'>✓ Administrator User exists ({$label})</li>\n";
} else {
    print "<li class='list-group-item list-group-item-danger'>✗ Administrator User missing</li>\n";
}
if ($result['maintainer_exists']) {
    $maintainer_attrs = $result['maintainer_attrs'];
    $maintainer_mail = $maintainer_attrs['mail'][0] ?? null;
    $maintainer_uid = $maintainer_attrs['uid'][0] ?? null;
    $label = $maintainer_mail ?: ($maintainer_uid ?: $result['maintainer_member_dn']);
    $label = htmlspecialchars((string) $label);
    print "<li class='list-group-item list-group-item-success'>✓ Maintainer User exists ({$label})</li>\n";
} else {
    print "<li class='list-group-item list-group-item-warning'>⚠ Maintainer User missing (optional)</li>\n";
}

# Test 3: Role Groups
print "<li class='list-group-item'><strong>Test 3: Role Groups</strong></li>\n";
$admin_group_ok = $result['admin_group'] !== null;
$maintainer_group_ok = $result['maintainer_group'] !== null;
print $admin_group_ok ? "<li class='list-group-item list-group-item-success'>✓ Administrators Group exists</li>\n" : "<li class='list-group-item list-group-item-danger'>✗ Administrators Group missing</li>\n";
print $maintainer_group_ok ? "<li class='list-group-item list-group-item-success'>✓ Maintainers Group exists</li>\n" : "<li class='list-group-item list-group-item-danger'>✗ Maintainers Group missing</li>\n";

# Test 4: Role Memberships
print "<li class='list-group-item'><strong>Test 4: Role Memberships</strong></li>\n";
if ($result['admin_group'] !== null && isset($result['admin_group']['member'])) {
    $member_count = $result['admin_group']['member']['count'];
    print "<li class='list-group-item list-group-item-success'>✓ Administrators group has {$member_count} member(s)</li>\n";
    for ($i = 0; $i < $member_count; $i++) {
        $member_dn = $result['admin_group']['member'][$i];
        print "<li class='list-group-item list-group-item-info'>  - {$member_dn}</li>\n";
    }
} else {
    print $result['admin_group'] !== null ? "<li class='list-group-item list-group-item-warning'>⚠ Administrators group has no members</li>\n" : "<li class='list-group-item list-group-item-danger'>✗ Cannot find administrators group</li>\n";
}
if ($result['maintainer_group'] !== null && isset($result['maintainer_group']['member'])) {
    $member_count = $result['maintainer_group']['member']['count'];
    print "<li class='list-group-item list-group-item-success'>✓ Maintainers group has {$member_count} member(s)</li>\n";
    for ($i = 0; $i < $member_count; $i++) {
        $member_dn = $result['maintainer_group']['member'][$i];
        print "<li class='list-group-item list-group-item-info'>  - {$member_dn}</li>\n";
    }
} else {
    print $result['maintainer_group'] !== null ? "<li class='list-group-item list-group-item-warning'>⚠ Maintainers group has no members</li>\n" : "<li class='list-group-item list-group-item-danger'>✗ Cannot find maintainers group</li>\n";
}

# Test 5: Authentication Test
print "<li class='list-group-item'><strong>Test 5: Authentication Test</strong></li>\n";
if ($result['admin_exists'] && !empty($result['admin_member_dn'])) {
    $admin_dn = $result['admin_member_dn'];
    $user_read = @ldap_read($ldap_connection, $admin_dn, "(objectClass=*)", array("uid", "cn", "mail"));
    if ($user_read && ldap_count_entries($ldap_connection, $user_read) > 0) {
        print "<li class='list-group-item list-group-item-success'>✓ Administrator user entry is valid and readable</li>\n";
    } else {
        print "<li class='list-group-item list-group-item-warning'>⚠ Administrator user entry exists but may have issues</li>\n";
    }
} else {
    print "<li class='list-group-item list-group-item-danger'>✗ Cannot find administrator user for authentication test</li>\n";
}

# Test 6: Email (SMTP)
$email_verification_passed = null;
$smtp_host_set = isset($SMTP['host']) && trim((string) $SMTP['host']) !== '';
if ($smtp_host_set) {
    $email_result = run_email_verification();
    $email_verification_passed = $email_result['passed'];
    $email_message = htmlspecialchars($email_result['message'], ENT_QUOTES, 'UTF-8');
    print "<li class='list-group-item'><strong>Test 6: Email (SMTP)</strong></li>\n";
    if ($email_verification_passed) {
        print "<li class='list-group-item list-group-item-success'>✓ SMTP connection and authentication OK</li>\n";
    } else {
        print "<li class='list-group-item list-group-item-danger'>✗ SMTP verification failed: {$email_message}</li>\n";
    }
}

?>
      </ul>
    </div>
  </div>

<?php
if (!empty($missing_components)) {
    if ($SETUP_DEBUG == true) {
        error_log("$log_prefix SETUP_DEBUG: Missing components detected: " . implode(', ', array_unique($missing_components)), 0);
    }

    print "<div class='card border-warning'>\n";
    print "  <div class='card-header'>Missing Components Detected</div>\n";
    print "  <div class='card-body'>\n";
    print "    <p>The verification found missing components that need to be created:</p>\n";

    if (in_array('ou', $missing_components)) {
        print "    <p>• <strong>Organizational Units</strong> (people, organizations, or roles)</p>\n";
    }
    if (in_array('user', $missing_components)) {
        print "    <p>• <strong>System Users</strong> (administrator and/or maintainer)</p>\n";
    }
    if (in_array('role', $missing_components)) {
        print "    <p>• <strong>Role Groups</strong> (administrators and/or maintainers)</p>\n";
    }

    print "    <p>You need to go back to the setup process to create these missing components.</p>\n";
    print "  </div>\n";
    print "</div>\n";

    print "<div class='p-3 bg-light rounded'>\n";
    print "  <div class='row'>\n";
    print "    <div class='col-md-6'>\n";
    print "      <form action='{$THIS_MODULE_PATH}/run_checks.php'>\n";
    print "        <input type='submit' class='btn btn-warning d-block mx-auto' value='Go to Setup'>\n";
    print "      </form>\n";
    print "    </div>\n";
    print "    <div class='col-md-6'>\n";
    print "      <form action='{$THIS_MODULE_PATH}'>\n";
    print "        <input type='submit' class='btn btn-secondary d-block mx-auto' value='Back to Setup Menu'>\n";
    print "      </form>\n";
    print "    </div>\n";
    print "  </div>\n";
    print "</div>\n";
} else {
    if ($SETUP_DEBUG == true) {
        error_log("$log_prefix SETUP_DEBUG: All components verified successfully", 0);
    }

    set_setup_locked();
    if ($smtp_host_set && $email_verification_passed !== null) {
        set_email_verified($email_verification_passed);
    }

    print "<div class='card border-success'>\n";
    print "  <div class='card-header'>Setup Complete!</div>\n";
    print "  <div class='card-body'>\n";
    print "    <p>All LDAP components have been verified and are working correctly.</p>\n";
    print "    <p>You can now proceed to use the system or log in with your administrator account.</p>\n";
    print "  </div>\n";
    print "</div>\n";

    print "<div class='p-3 bg-light rounded'>\n";
    print "  <div class='row'>\n";
    print "    <div class='col-md-6'>\n";
    print "      <form action='{$SERVER_PATH}login/'>\n";
    print "        <input type='submit' class='btn btn-success d-block mx-auto' value='Go to Login'>\n";
    print "      </form>\n";
    print "    </div>\n";
    print "    <div class='col-md-6'>\n";
    print "      <form action='{$THIS_MODULE_PATH}'>\n";
    print "        <input type='submit' class='btn btn-secondary d-block mx-auto' value='Back to Setup Menu'>\n";
    print "      </form>\n";
    print "    </div>\n";
    print "  </div>\n";
    print "</div>\n";
}
?>
  </div>

<?php

renderFooter();

?>
