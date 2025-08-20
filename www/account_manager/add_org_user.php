<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";
include_once "organization_functions.inc.php";

// Ensure CSRF token is generated early
get_csrf_token();

// Check if organization parameter is provided (support both uuid and org)
$org_uuid = $_GET['uuid'] ?? null;
$org_name = $_GET['org'] ?? null;

if (!$org_uuid && !$org_name) {
    render_alert_banner("Organization identifier is required.", "warning");
    render_footer();
    exit(0);
}

// If UUID is provided, get organization by UUID
if ($org_uuid) {
    $ldap = open_ldap_connection();
    if (!$ldap) {
        render_alert_banner("Failed to connect to LDAP server.", "danger");
        render_footer();
        exit(0);
    }
    
    $organization = ldap_get_organization_by_uuid($ldap, $org_uuid);
    
    // Debug: log the organization data structure
    error_log("add_org_user.php: Organization data retrieved for UUID $org_uuid: " . print_r($organization, true));
    
    ldap_close($ldap);
    
    if (!$organization) {
        // Debug: log the UUID and search details
        error_log("add_org_user.php: Failed to find organization with UUID: $org_uuid");
        render_alert_banner("Organization with UUID '$org_uuid' not found. Check logs for details.", "danger");
        render_footer();
        exit(0);
    }
    
    // Try different possible organization name attributes
    $org_name = null;
    if (isset($organization['o']) && is_array($organization['o']) && count($organization['o']) > 0) {
        $org_name = $organization['o'][0];
        error_log("add_org_user.php: Using organization name from 'o' attribute: $org_name");
    } elseif (isset($organization['name']) && is_array($organization['name']) && count($organization['name']) > 0) {
        $org_name = $organization['name'][0];
        error_log("add_org_user.php: Using organization name from 'name' attribute: $org_name");
    } elseif (isset($organization['cn']) && is_array($organization['cn']) && count($organization['cn']) > 0) {
        $org_name = $organization['cn'][0];
        error_log("add_org_user.php: Using organization name from 'cn' attribute: $org_name");
    }
    
    if (!$org_name) {
        // Debug: log the organization structure to help troubleshoot
        error_log("add_org_user.php: Organization data structure: " . print_r($organization, true));
        render_alert_banner("Could not determine organization name from UUID. Check logs for details.", "danger");
        render_footer();
        exit(0);
    }
    
    error_log("add_org_user.php: Successfully determined organization name: $org_name");
}

// Check if user has appropriate permissions for this organization
if (!(currentUserIsGlobalAdmin() || currentUserIsMaintainer() || currentUserIsOrgManager($org_name))) {
    render_alert_banner("You do not have permission to add users to this organization.", "danger");
    render_footer();
    exit(0);
}

// Verify the organization exists (if not already verified by UUID)
if (!$organization) {
    $organizations = listOrganizations();
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
}

$attribute_map = $LDAP['default_attribute_map'];
if (isset($LDAP['account_additional_attributes'])) { 
    $attribute_map = ldap_complete_attribute_map($attribute_map, $LDAP['account_additional_attributes']); 
}

if (!array_key_exists($LDAP['account_attribute'], $attribute_map)) {
    $attribute_map = array_merge($attribute_map, array($LDAP['account_attribute'] => array("label" => "Account UID")));
}

$invalid_password = FALSE;
$mismatched_passwords = FALSE;
$invalid_username = FALSE;
$weak_password = FALSE;
$invalid_email = FALSE;
        // Common Name (cn) is auto-generated, no validation needed
$invalid_givenname = FALSE;
$invalid_sn = FALSE;
$invalid_account_identifier = FALSE;
$invalid_user_role = FALSE;

$new_account_r = array();
$account_attribute = $LDAP['account_attribute'];

// Available user roles for organization users
$available_user_roles = ['user', 'org_admin'];

// Handle form submission
if (isset($_POST['create_org_user'])) {
            if (!validate_csrf_token()) {
            render_alert_banner("Security validation failed. Please refresh the page and try again.", "danger");
    } else {
        // Process form data
        foreach ($attribute_map as $attribute => $attr_r) {
            if (isset($_FILES[$attribute]['size']) && $_FILES[$attribute]['size'] > 0) {
                // File upload validation
                global $FILE_UPLOAD_MAX_SIZE, $FILE_UPLOAD_ALLOWED_MIME_TYPES;
                $max_file_size = $FILE_UPLOAD_MAX_SIZE;
                $allowed_mime_types = $FILE_UPLOAD_ALLOWED_MIME_TYPES;
                $file_size = $_FILES[$attribute]['size'];
                $file_tmp = $_FILES[$attribute]['tmp_name'];
                $file_error = $_FILES[$attribute]['error'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file_tmp);
                finfo_close($finfo);
                
                if ($file_error !== UPLOAD_ERR_OK) {
                    render_alert_banner('File upload error for ' . htmlspecialchars($attribute) . '.', 'danger', 10000);
                    continue;
                }
                if ($file_size > $max_file_size) {
                    render_alert_banner('File for ' . htmlspecialchars($attribute) . ' is too large (max 2MB).', 'danger', 10000);
                    continue;
                }
                if (!in_array($mime_type, $allowed_mime_types)) {
                    render_alert_banner('Invalid file type for ' . htmlspecialchars($attribute) . '. Allowed: images, PDF, text.', 'danger', 10000);
                    continue;
                }
                
                $this_attribute = array();
                $this_attribute[0] = file_get_contents($file_tmp);
                $$attribute = $this_attribute;
                $new_account_r[$attribute] = $this_attribute;
            } else {
                // Regular form field
                if (isset($_POST[$attribute]) && !empty($_POST[$attribute])) {
                    $$attribute = array(0 => $_POST[$attribute]);
                    $new_account_r[$attribute] = array(0 => $_POST[$attribute]);
                } else {
                    $$attribute = array();
                }
            }
        }

        // Ensure Account UID is set from email if not already provided
        if (empty($new_account_r[$account_attribute][0]) && !empty($mail[0])) {
            $new_account_r[$account_attribute] = $mail;
        }
        
        // Auto-populate Common Name (cn) from givenname and sn if not provided
        if (empty($cn[0]) && (!empty($givenname[0]) || !empty($sn[0]))) {
            $cn_parts = [];
            if (!empty($givenname[0])) $cn_parts[] = $givenname[0];
            if (!empty($sn[0])) $cn_parts[] = $sn[0];
            $cn = array(0 => implode(' ', $cn_parts));
            $new_account_r['cn'] = $cn;
        }

        // Get password and validation
        $password = $_POST['password'] ?? '';
        $password_match = $_POST['password_match'] ?? '';
        $user_role = $_POST['user_role'] ?? 'user';
        $send_email = isset($_POST['send_email']) && $_POST['send_email'] == 'on';

        // Validation
        $account_identifier = $new_account_r[$account_attribute][0] ?? '';
        $this_cn = $cn[0] ?? '';
        $this_mail = $mail[0] ?? '';
        $this_givenname = $givenname[0] ?? '';
        $this_sn = $sn[0] ?? '';

        // Common Name (cn) is auto-generated from givenname + sn, so no validation needed
        if (empty($account_identifier)) { $invalid_account_identifier = TRUE; }
        if (empty($this_givenname)) { $invalid_givenname = TRUE; }
        if (empty($this_sn)) { $invalid_sn = TRUE; }
        if (empty($password)) { $invalid_password = TRUE; }
        if ($password !== $password_match) { $mismatched_passwords = TRUE; }
        if (!in_array($user_role, $available_user_roles)) { $invalid_user_role = TRUE; }
        if (empty($this_mail)) { $invalid_email = TRUE; }
        if (!empty($this_mail) && !is_valid_email($this_mail)) { $invalid_email = TRUE; }

        // Check if username already exists
        $ldap_connection = open_ldap_connection();
        $existing_user = ldap_search($ldap_connection, $LDAP['org_dn'], "({$LDAP['account_attribute']}=" . ldap_escape($account_identifier, "", LDAP_ESCAPE_FILTER) . ")");
        if ($existing_user && ldap_count_entries($ldap_connection, $existing_user) > 0) {
            $invalid_username = TRUE;
        }

        // If all validation passes, create the user
        if (!$invalid_account_identifier && !$invalid_givenname && !$invalid_sn && 
            !$invalid_password && !$mismatched_passwords && !$invalid_user_role && !$invalid_email && !$invalid_username) {
            
            // Add organization and role to the account data
            $new_account_r['organization'] = [$org_name];
            $new_account_r['description'] = [$user_role];
            $new_account_r['password'] = [$password];

            // Create the user account
            $new_account = ldap_new_account($ldap_connection, $new_account_r);

            if ($new_account) {
                $creation_message = "User account '$account_identifier' was created successfully in organization '$org_name'.";

                // Add user to organization admin role if selected
                if ($user_role === 'org_admin') {
                    $org_admin_add = addUserToOrgAdmin($org_name, $new_account);
                    if (!$org_admin_add) {
                        $creation_message .= " Warning: Failed to add user to organization admin role.";
                    }
                }

                // Send email if requested
                if ($send_email && isset($this_mail) && $EMAIL_SENDING_ENABLED == TRUE) {
                    include_once "mail_functions.inc.php";
                    $mail_body = parse_mail_text($new_account_mail_body, $password, $account_identifier, $this_givenname, $this_sn);
                    $mail_subject = parse_mail_text($new_account_mail_subject, $password, $account_identifier, $this_givenname, $this_sn);
                    
                    $sent_email = send_email($this_mail, "$this_givenname $this_sn", $mail_subject, $mail_body);
                    if ($sent_email) {
                        $creation_message .= " An email was sent to $this_mail.";
                    } else {
                        $creation_message .= " Email sending failed. Check the logs for more information.";
                    }
                }

                render_alert_banner($creation_message, "success");
                
                // Redirect back to organization users page
                // Redirect back to organization users page, preserving UUID if available
        $redirect_param = $org_uuid ? "uuid=" . urlencode($org_uuid) : "org=" . urlencode($org_name);
        header("Location: org_users.php?" . $redirect_param);
                exit(0);
            } else {
                render_alert_banner("Failed to create user account. Check the logs for more information.", "danger");
            }
            
            ldap_close($ldap_connection);
        }
    }
}

render_header("$ORGANISATION_NAME - Add User to Organization");
render_submenu();

// Display any validation errors
$errors = "";
if ($invalid_givenname) { $errors .= "<li>First Name is required</li>\n"; }
if ($invalid_sn) { $errors .= "<li>Last Name is required</li>\n"; }
if ($invalid_account_identifier) { $errors .= "<li>The email address (username) is invalid or already exists</li>\n"; }
if ($invalid_password) { $errors .= "<li>The password is required</li>\n"; }
if ($mismatched_passwords) { $errors .= "<li>The passwords do not match</li>\n"; }
if ($invalid_email) { $errors .= "<li>The email address is invalid</li>\n"; }
if ($invalid_user_role) { $errors .= "<li>Please select a valid user role</li>\n"; }

if ($errors != "") { ?>
    <div class="alert alert-warning">
        <p>There were issues with the form:</p>
        <ul><?php print strip_tags($errors, '<li>'); ?></ul>
    </div>
<?php } ?>

<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="organizations.php">Organizations</a></li>
            <li class="breadcrumb-item"><a href="show_organization.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>"><?php echo htmlspecialchars($org_name); ?></a></li>
            <li class="breadcrumb-item"><a href="org_users.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>">Users</a></li>
            <li class="breadcrumb-item active" aria-current="page">Add User</li>
        </ol>
    </nav>
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3>Add New User to Organization: <?php echo htmlspecialchars($org_name); ?></h3>
                </div>
                <div class="panel-body">
                    <div class="alert alert-info">
                        <strong>Note:</strong> The email address you enter will automatically be used as the username for login. Users will sign in using their email address and password.
                    </div>
                    
                    <form class="form-horizontal" action="" enctype="multipart/form-data" method="post">
                        <?= csrf_token_field() ?>
                        <input type="hidden" name="create_org_user" value="1">
                        
                        <!-- Organization (pre-selected and locked) -->
                        <div class="form-group">
                            <label class="col-sm-3 control-label">Organization</label>
                            <div class="col-sm-6">
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($org_name); ?>" readonly>
                                <small class="text-muted">Organization is pre-selected and cannot be changed</small>
                            </div>
                        </div>

                        <!-- User Role -->
                        <div class="form-group">
                            <label for="user_role" class="col-sm-3 control-label">User Role</label>
                            <div class="col-sm-6">
                                <select class="form-control" name="user_role" id="user_role" required>
                                    <option value="">Select a role...</option>
                                    <option value="user" <?php echo (isset($_POST['user_role']) && $_POST['user_role'] === 'user') ? 'selected' : ''; ?>>Regular User</option>
                                    <option value="org_admin" <?php echo (isset($_POST['user_role']) && $_POST['user_role'] === 'org_admin') ? 'selected' : ''; ?>>Organization Administrator</option>
                                </select>
                            </div>
                        </div>

                        <!-- Account Identifier (Auto-generated from Email) -->
                        <div class="form-group" style="display: none;">
                            <label for="<?php echo $account_attribute; ?>" class="col-sm-3 control-label">
                                <strong><?php echo htmlspecialchars($attribute_map[$account_attribute]['label']); ?></strong>
                            </label>
                            <div class="col-sm-6">
                                <input type="hidden" name="<?php echo $account_attribute; ?>" 
                                       id="<?php echo $account_attribute; ?>" 
                                       value="<?php echo htmlspecialchars($_POST[$account_attribute] ?? ''); ?>">
                                <small class="text-muted">Auto-generated from email address</small>
                            </div>
                        </div>

                        <!-- Common Name (Auto-generated from First Name + Last Name) -->
                        <div class="form-group" style="display: none;">
                            <label for="cn" class="col-sm-3 control-label">
                                <strong>Common Name</strong>
                            </label>
                            <div class="col-sm-6">
                                <input type="hidden" name="cn" id="cn" 
                                       value="<?php echo htmlspecialchars($_POST['cn'] ?? ''); ?>">
                                <small class="text-muted">Auto-generated from First Name + Last Name</small>
                            </div>
                        </div>

                        <!-- First Name -->
                        <div class="form-group">
                            <label for="givenname" class="col-sm-3 control-label">
                                <strong>First Name</strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="text" class="form-control" name="givenname" id="givenname" 
                                       value="<?php echo htmlspecialchars($_POST['givenname'] ?? ''); ?>" 
                                       onchange="updateCommonName()" required>
                            </div>
                        </div>

                        <!-- Last Name -->
                        <div class="form-group">
                            <label for="sn" class="col-sm-3 control-label">
                                <strong>Last Name</strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="text" class="form-control" name="sn" id="sn" 
                                       value="<?php echo htmlspecialchars($_POST['sn'] ?? ''); ?>" 
                                       onchange="updateCommonName()" required>
                            </div>
                        </div>

                        <!-- Email (Account UID) -->
                        <div class="form-group">
                            <label for="mail" class="col-sm-3 control-label">
                                <strong>Email (Username)</strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="email" class="form-control" name="mail" id="mail" 
                                       value="<?php echo htmlspecialchars($_POST['mail'] ?? ''); ?>" 
                                       onchange="updateAccountUid(this.value)" required>
                                <small class="text-muted">Email will be used as the username for login</small>
                            </div>
                        </div>

                        <!-- Password -->
                        <div class="form-group">
                            <label for="password" class="col-sm-3 control-label">
                                <strong>Password</strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="password" class="form-control" name="password" id="password" required>
                            </div>
                            <div class="col-sm-1">
                                <button type="button" class="btn btn-sm btn-default" onclick="generatePassword()">Generate</button>
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div class="form-group">
                            <label for="password_match" class="col-sm-3 control-label">
                                <strong>Confirm Password</strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="password" class="form-control" name="password_match" id="password_match" required>
                            </div>
                        </div>

                        <!-- Send Email Option -->
                        <?php if ($EMAIL_SENDING_ENABLED == TRUE): ?>
                        <div class="form-group">
                            <label class="col-sm-3 control-label"></label>
                            <div class="col-sm-6">
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="send_email" <?php echo (isset($_POST['send_email']) && $_POST['send_email'] == 'on') ? 'checked' : ''; ?>>
                                        Send credentials to user's email address
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Submit Buttons -->
                        <div class="form-group">
                            <div class="col-sm-6 col-sm-offset-3">
                                <button type="submit" class="btn btn-primary">Create User</button>
                                <a href="org_users.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>" class="btn btn-default">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function generatePassword() {
    // Simple password generator - you can enhance this
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    let password = '';
    for (let i = 0; i < 12; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('password').value = password;
}

function updateCommonName() {
    const givenname = document.getElementById('givenname').value;
    const sn = document.getElementById('sn').value;
    const cn = document.getElementById('cn');
    
    if (givenname && sn) {
        cn.value = givenname + ' ' + sn;
    } else if (givenname) {
        cn.value = givenname;
    } else if (sn) {
        cn.value = sn;
    } else {
        cn.value = '';
    }
}

// Auto-populate cn field on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCommonName();
});

function updateAccountUid(email) {
    // Automatically update the Account UID field when email is entered
    const accountUidField = document.getElementById('<?php echo $account_attribute; ?>');
    if (accountUidField && email) {
        accountUidField.value = email;
    }
}

// Auto-populate Account UID on page load if email is already filled
document.addEventListener('DOMContentLoaded', function() {
    const emailField = document.getElementById('mail');
    const accountUidField = document.getElementById('<?php echo $account_attribute; ?>');
    if (emailField && accountUidField && emailField.value) {
        accountUidField.value = emailField.value;
    }
});
</script>

<?php render_footer(); ?>
