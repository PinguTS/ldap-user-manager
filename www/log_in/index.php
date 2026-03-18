<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";

// CRITICAL: Check for role configuration conflicts before allowing login
// This prevents authentication with broken access control configuration
if (function_exists('checkRuntimeRoleConflicts') && checkRuntimeRoleConflicts()) {
    displayMaintenanceMode();
}

// Handle login POST before any output
if (isset($_POST["user_id"]) && isset($_POST["password"])) {
  // Check rate limiting before attempting authentication
    if (is_rate_limited($_POST["user_id"])) {
        http_response_code(429); // Too Many Requests
        header("Location: " . get_base_url() . "login/?rate_limited");
        exit;
    }

    $ldap_connection = open_ldap_connection();
    $user_dn = ldap_auth_username($ldap_connection, $_POST["user_id"], $_POST["password"]);

    if ($user_dn === false) {
      // Record failed login attempt
        record_login_attempt($_POST["user_id"], false);

        ldap_close($ldap_connection);

      // If we get here, the login failed
        header("Location: " . get_base_url() . "login/?invalid");
        exit;
    }

  // Check if user account is administratively disabled (pwdAccountLockedTime = 000001010000Z)
    $user_entry_read = @ldap_read($ldap_connection, $user_dn, '(objectClass=*)', ['pwdAccountLockedTime']);
    if ($user_entry_read) {
        $user_entries = ldap_get_entries($ldap_connection, $user_entry_read);
        $first_entry = isset($user_entries[0]) && is_array($user_entries[0]) ? $user_entries[0] : [];
        if ($user_entries['count'] > 0 && $first_entry !== [] && function_exists('isUserAccountDisabled') && isUserAccountDisabled($first_entry)) {
            record_login_attempt($_POST["user_id"], false);
            ldap_close($ldap_connection);
            header("Location: " . get_base_url() . "login/?account_locked");
            exit;
        }
    }

  // Check if user account is locked/disabled (org lock or other lock)
    if (ldap_user_is_locked($ldap_connection, $user_dn)) {
      // Record failed login attempt for locked account
        record_login_attempt($_POST["user_id"], false);

        ldap_close($ldap_connection);

      // Redirect with locked account message
        header("Location: " . get_base_url() . "login/?account_locked");
        exit;
    }

  // Get user UUID for internal use
    $user_uuid = ldap_user_get_uuid($ldap_connection, $user_dn);
    if (!$user_uuid) {
      // Fallback: extract username from DN for backward compatibility
        if (preg_match('/uid=([^,]+),/', $user_dn, $matches)) {
            $username = $matches[1];
        } else {
            $username = $_POST["user_id"];
        }
    } else {
        $username = $user_uuid;
    }

  // Check if user is a administrator
    $is_admin = false;
  // IMPORTANT: Check global admin role independently, regardless of role value conflicts
    $is_admin = ldap_is_group_member($ldap_connection, $LDAP['roles_dn'], $LDAP['admin_role'], $user_dn);

  // Check if user is a maintainer
    $is_maintainer = false;
  // IMPORTANT: Check global maintainer role independently, regardless of role value conflicts
    $is_maintainer = ldap_is_group_member($ldap_connection, $LDAP['roles_dn'], $LDAP['maintainer_role'], $user_dn);

  // Get user organization information first
    $user_org_name = null;
    $user_org_name = ldap_user_get_organization($ldap_connection, $user_dn);

    if ($LDAP_DEBUG) {
        error_log("Login: User DN: $user_dn");
        error_log("Login: Extracted organization name: " . ($user_org_name ?: 'NULL'));
    }

  // Check if user is an organization admin (but not global admin or maintainer)
    $is_org_admin = false;
    if ($user_org_name && !$is_admin && !$is_maintainer) {
      // Search for org admin role within the user's specific organization
        $org_roles_dn = "ou=roles,o=" . ldap_escape($user_org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
        $org_admin_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['org_admin_role']})(member=$user_dn))";

        if ($LDAP_DEBUG) {
            error_log("Login: Checking org admin role in: $org_roles_dn");
            error_log("Login: Using filter: $org_admin_filter");
        }

        $org_admin_search = @ldap_search($ldap_connection, $org_roles_dn, $org_admin_filter, ['cn']);
        if ($org_admin_search) {
            $org_admin_result = ldap_get_entries($ldap_connection, $org_admin_search);
            $is_org_admin = ($org_admin_result['count'] > 0);
            if ($LDAP_DEBUG) {
                error_log("Login: Org admin search result: " . $org_admin_result['count'] . " entries");
            }
        } else {
            if ($LDAP_DEBUG) {
                error_log("Login: Org admin search failed: " . ldap_error($ldap_connection));
            }
        }
    }

  // IMPORTANT: Handle role conflicts by ensuring proper hierarchy
  // If a user has multiple roles, they get the highest privilege level
  // This works even when role values are the same due to independent checks
    if ($is_admin) {
      // Global admin overrides all other roles
        $is_maintainer = false;
        $is_org_admin = false;
        if ($LDAP_DEBUG) {
            error_log("Login: User is global admin - overriding other roles");
        }
    } elseif ($is_maintainer) {
      // Maintainer overrides org admin but not global admin
        $is_org_admin = false;
        if ($LDAP_DEBUG) {
            error_log("Login: User is maintainer - overriding org admin role");
        }
    }

  // Additional safety check: if roles are configured to be the same,
  // ensure we don't have conflicting privileges
  // This is now handled automatically by the independent role checks above
    if ($LDAP_DEBUG && $LDAP['admin_role'] === $LDAP['org_admin_role'] && $is_admin && $user_org_name) {
        error_log("Login: NOTE - admin_role and org_admin_role have the same value, but access control is working correctly due to independent checks");
    }

  // Get organization UUID for redirects (only if we have an organization name)
    $org_uuid = null;
    if ($user_org_name) {
        $org_uuid = ldap_organization_get_uuid($ldap_connection, $user_org_name);
        if ($LDAP_DEBUG) {
            error_log("Login: Organization '$user_org_name' UUID lookup result: " . ($org_uuid ?: 'FAILED'));
        }
    }

  // Resolve display name (mail or cn) for menu / one-time token
    $login_display_name = null;
    $read = @ldap_read($ldap_connection, $user_dn, '(objectClass=*)', ['mail', 'cn']);
    if ($read) {
        $entries = @ldap_get_entries($ldap_connection, $read);
        if (!empty($entries[0])) {
            $e = $entries[0];
            if (!empty($e['mail'][0])) {
                $login_display_name = $e['mail'][0];
            } elseif (!empty($e['cn'][0])) {
                $login_display_name = $e['cn'][0];
            }
        }
    }

    ldap_close($ldap_connection);

  // Record successful login attempt
    record_login_attempt($_POST["user_id"], true);

  // Use UUID for cookie data when available, fallback to username
    $cookie_user_id = $user_uuid ?: $_POST["user_id"];
    set_passkey_cookie($cookie_user_id, $is_admin, $is_maintainer, $is_org_admin, $user_org_name, $org_uuid);

    if (isset($_POST["redirect_to"])) {
        $validated_redirect = validate_redirect_url($_POST['redirect_to']);
        if ($validated_redirect !== false) {
            $redirect_url = get_base_url() . ltrim($validated_redirect, '/');
            $auth_tok = create_one_time_auth_token($cookie_user_id, $is_admin, $is_maintainer, $is_org_admin, $user_org_name, $org_uuid, $login_display_name);
            $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'auth_tok=' . $auth_tok;
            header("Content-Type: text/html; charset=utf-8");
            header("Cache-Control: no-store, no-cache, must-revalidate");
            echo '<!DOCTYPE html><html><head><meta http-equiv="Refresh" content="0;url=' . htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') . '"></head><body>Redirecting...</body></html>';
            exit;
        }
        exit;
    }

    if ($is_admin) {
        $default_module = "manage/users/index.php";
    } elseif ($is_maintainer) {
        $default_module = "manage/organizations/index.php";
    } elseif ($is_org_admin && $user_org_name && $org_uuid) {
      // Use UUID-based URL for better security
        $default_module = "manage/organizations/" . urlencode($org_uuid) . "/";
    } elseif ($is_org_admin && $user_org_name) {
      // Fallback to name-based URL if UUID not available
        $default_module = "manage/organizations/show/index.php?org=" . urlencode($user_org_name);
    } else {
        $default_module = "password/change/";
    }

    if ($LDAP_DEBUG) {
        error_log("Login: Redirecting to: $default_module");
        error_log("Login: User roles - Admin: " . ($is_admin ? 'YES' : 'NO') . ", Maintainer: " . ($is_maintainer ? 'YES' : 'NO') . ", Org Admin: " . ($is_org_admin ? 'YES' : 'NO'));
        error_log("Login: Organization info - Name: " . ($user_org_name ?: 'NULL') . ", UUID: " . ($org_uuid ?: 'NULL'));
        error_log("Login: SERVER_PATH: '$SERVER_PATH'");
        error_log("Login: HTTP_HOST: '{$_SERVER['HTTP_HOST']}'");
    }

  // Reconstruct URL using base URL (ensures valid redirect with slash between host and path)
    if (strpos($default_module, '?') !== false) {
        $redirect_url = get_base_url() . $default_module . "&logged_in";
        if ($LDAP_DEBUG) {
            error_log("Login: Using & separator for logged_in (module has existing query params)");
        }
    } else {
        $redirect_url = get_base_url() . $default_module . "?logged_in";
        if ($LDAP_DEBUG) {
            error_log("Login: Using ? separator for logged_in (module has no query params)");
        }
    }
    $auth_tok = create_one_time_auth_token($cookie_user_id, $is_admin, $is_maintainer, $is_org_admin, $user_org_name, $org_uuid, $login_display_name);
    $redirect_url .= (strpos($redirect_url, '?') !== false ? '&' : '?') . 'auth_tok=' . $auth_tok;

    if ($LDAP_DEBUG) {
        error_log("Login: Final redirect URL: $redirect_url");
        error_log("Login: SERVER_PATH after validation: '$SERVER_PATH'");
        error_log("Login: About to redirect to: $redirect_url");
    }

  // Final confirmation log
    if ($LDAP_DEBUG) {
        error_log("Login: EXECUTING REDIRECT to: $redirect_url");
    }

    // Use an HTML redirect instead of HTTP 302 so the browser stores the session cookie
    // before navigating (avoids cookie not sent on same-site redirect in some browsers)
    header("Content-Type: text/html; charset=utf-8");
    header("Cache-Control: no-store, no-cache, must-revalidate");
    $redirect_url_escaped = htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head><meta http-equiv="Refresh" content="0;url=' . $redirect_url_escaped . '"></head><body>Redirecting...</body></html>';
    exit;
}

// Only render HTML after all possible headers are sent

if (isset($_GET["unauthorised"])) {
    $display_unauth = true;
}
if (isset($_GET["session_timeout"])) {
    $display_logged_out = true;
}
if (isset($_GET["redirect_to"])) {
    $redirect_to = $_GET["redirect_to"];
}

render_header("$ORGANISATION_NAME account manager - log in");

?>
<div class="container">
 <div class="col-sm-8 offset-sm-2">

  <div class="card">
   <div class="card-header text-center">Log in</div>
   <div class="card-body text-center">

   <?php if (isset($_GET['logged_out'])) { ?>
   <div class="alert alert-warning">
   <p class="text-center">You've been automatically logged out because you've been inactive for
        <?php print $SESSION_TIMEOUT; ?> minutes. Click on the 'Log in' link to get back into the system.</p>
   </div>
   <?php } ?>

   <?php if (isset($display_unauth)) { ?>
   <div class="alert alert-warning">
    Please log in to continue
   </div>
   <?php } ?>

   <?php if (isset($display_logged_out)) { ?>
   <div class="alert alert-warning">
    You were logged out because your session expired. Log in again to continue.
   </div>
   <?php } ?>

   <?php if (isset($_GET['invalid'])) : ?>
            <div class="alert alert-danger">
                <strong>Login Failed:</strong> Invalid username or password. Please try again.
            </div>
   <?php endif; ?>
        
        <?php if (isset($_GET['account_locked'])) : ?>
            <div class="alert alert-danger">
                <strong>Account Disabled:</strong> Your account has been locked by an administrator. Please contact your system administrator for assistance.
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['rate_limited'])) : ?>
            <div class="alert alert-warning">
                <strong>Too Many Attempts:</strong> You have exceeded the maximum login attempts. Please wait before trying again.
            </div>
        <?php endif; ?>

   <form class="form-horizontal" action='' method='post'>
    <?php if (isset($redirect_to) and ($redirect_to != "")) {
        ?><input type="hidden" name="redirect_to" value="<?php print htmlspecialchars($redirect_to); ?>"><?php
    } ?>

    <div class="form-group">
     <label for="username" class="col-sm-4 form-label"><?php print $SITE_LOGIN_FIELD_LABEL; ?></label>
     <div class="col-sm-6">
      <input type="text" class="form-control" id="user_id" name="user_id">
     </div>
    </div>

    <div class="form-group">
     <label for="password" class="col-sm-4 form-label">Password</label>
     <div class="col-sm-6">
      <input type="password" class="form-control" id="confirm" name="password">
     </div>
    </div>

    <div class="form-group">
     <button type="submit" class="btn btn-secondary">Log in</button>
    </div>

   </form>
  </div>
 </div>
</div>
<?php
render_footer();
?>
