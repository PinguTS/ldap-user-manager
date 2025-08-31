<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";
include_once "organization_functions.inc.php";
include_once "user_functions.inc.php";

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
set_page_access(["admin", "maintainer", "org_admin"]);

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
        $available_user_roles = [$LDAP['user_role'], $LDAP['org_admin_role']];

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
        
        // Auto-populate Common Name (cn) from givenName and sn if not provided
        if (empty($cn[0]) && (!empty($givenName[0]) || !empty($sn[0]))) {
            $cn_parts = [];
            if (!empty($givenName[0])) $cn_parts[] = $givenName[0];
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
        $this_givenName = $givenName[0] ?? '';
        $this_sn = $sn[0] ?? '';

        // Common Name (cn) is auto-generated from givenName + sn, so no validation needed
        if (empty($account_identifier)) { $invalid_account_identifier = TRUE; }
        if (empty($this_givenName)) { $invalid_givenname = TRUE; }
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
                if ($user_role === $LDAP['org_admin_role']) {
                    $org_admin_add = addUserToOrgAdmin($org_name, $new_account);
                    if (!$org_admin_add) {
                        $creation_message .= " Warning: Failed to add user to organization admin role.";
                    }
                }

                // Send email if requested
                if ($send_email && isset($this_mail) && $EMAIL_SENDING_ENABLED == TRUE) {
                    include_once "mail_functions.inc.php";
                    $mail_body = parse_mail_text($new_account_mail_body, $password, $account_identifier, $this_givenName, $this_sn);
                    $mail_subject = parse_mail_text($new_account_mail_subject, $password, $account_identifier, $this_givenName, $this_sn);
                    
                    $sent_email = send_email($this_mail, "$this_givenName $this_sn", $mail_subject, $mail_body);
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
if ($invalid_givenname) { $errors .= "<li>First Name (givenName) is required</li>\n"; }
if ($invalid_sn) { $errors .= "<li>Last Name (sn) is required</li>\n"; }
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
            <li class="breadcrumb-item"><a href="/manage/">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/manage/organizations/">Organizations</a></li>
            <li class="breadcrumb-item"><a href="/manage/organizations/show/index.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>"><?php echo htmlspecialchars($org_name); ?></a></li>
            <li class="breadcrumb-item"><a href="/manage/organizations/users/index.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>">Users</a></li>
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
                                    <option value="<?php echo $LDAP['user_role']; ?>" <?php echo (isset($_POST['user_role']) && $_POST['user_role'] === $LDAP['user_role']) ? 'selected' : ''; ?>><?php echo $LDAP['role_display_labels']['user_role']; ?></option>
                                    <option value="<?php echo $LDAP['org_admin_role']; ?>" <?php echo (isset($_POST['user_role']) && $_POST['user_role'] === $LDAP['org_admin_role']) ? 'selected' : ''; ?>><?php echo $LDAP['role_display_labels']['org_admin_role']; ?></option>
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
                            <label for="givenName" class="col-sm-3 control-label">
                                <strong>First Name</strong><sup>*</sup>
                            </label>
                            <div class="col-sm-6">
                                <input type="text" class="form-control" name="givenName" id="givenName" 
                                       value="<?php echo htmlspecialchars($_POST['givenName'] ?? ''); ?>" 
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
                                <button type="button" class="btn btn-sm btn-info" onclick="generateSecurePassword({
                                    type: 'word',
                                    words: 4,
                                    separator: ' ',
                                    passwordFieldId: 'password',
                                    confirmFieldId: 'password_match'
                                })">Generate</button>
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
                                <button type="submit" name="create_user" class="btn btn-success">Create User</button>
                                <a href="/manage/organizations/users/index.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>" class="btn btn-default">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/js/zxcvbn.min.js"></script>
<script src="/bootstrap/js/bootstrap.min.js"></script>
<script>
    // Debug: Check what's loaded
    console.log('jQuery loaded:', typeof $ !== 'undefined');
    console.log('Bootstrap loaded:', typeof $.fn !== 'undefined' && typeof $.fn.modal !== 'undefined');
    console.log('jQuery version:', typeof $ !== 'undefined' ? $.fn.jquery : 'not loaded');
    
    // Initialize form enhancements when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize form enhancements
        const givenNameField = document.getElementById('givenName');
        const surnameField = document.getElementById('sn');
        const displayField = document.getElementById('cn');
        const emailField = document.getElementById('mail');
        const passwordField = document.getElementById('password');
        
        // Auto-generate display name from first and last name
        if (givenNameField && surnameField && displayField) {
            function updateDisplayName() {
                const givenName = givenNameField.value.trim();
                const surname = surnameField.value.trim();
                if (givenName && surname) {
                    displayField.value = givenName + ' ' + surname;
                }
            }
            
            givenNameField.addEventListener('input', updateDisplayName);
            surnameField.addEventListener('input', updateDisplayName);
        }
        
        // Auto-generate account ID from email
        if (emailField && document.getElementById('<?php echo $LDAP["account_attribute"]; ?>')) {
            emailField.addEventListener('input', function() {
                const accountField = document.getElementById('<?php echo $LDAP["account_attribute"]; ?>');
                if (accountField) {
                    accountField.value = this.value.trim();
                }
            });
        }
        
        // Password strength checking
        const passwordField = document.getElementById('password');
        const confirmField = document.getElementById('password_match');
        
        if (passwordField && confirmField) {
            passwordField.addEventListener('input', function() {
                const password = this.value;
                const strength = zxcvbn(password);
                
                // Update password strength indicator
                const strengthBar = document.getElementById('password_strength');
                if (strengthBar) {
                    const score = strength.score;
                    const colors = ['#d9534f', '#f0ad4e', '#f0ad4e', '#5bc0de', '#5cb85c'];
                    const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
                    
                    strengthBar.style.width = ((score + 1) * 20) + '%';
                    strengthBar.className = 'progress-bar';
                    strengthBar.style.backgroundColor = colors[score];
                    strengthBar.textContent = labels[score];
                }
                
                // Check if passwords match
                if (confirmField.value) {
                    if (password === confirmField.value) {
                        confirmField.setCustomValidity('');
                    } else {
                        confirmField.setCustomValidity('Passwords do not match');
                    }
                }
            });
            
            confirmField.addEventListener('input', function() {
                if (passwordField.value === this.value) {
                    this.setCustomValidity('');
                } else {
                    this.setCustomValidity('Passwords do not match');
                }
            });
        }
    });
    
    // Generate secure password function
    function generateSecurePassword(options) {
        const words = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet', 'kilo', 'lima', 'mike', 'november', 'oscar', 'papa', 'quebec', 'romeo', 'sierra', 'tango', 'uniform', 'victor', 'whiskey', 'xray', 'yankee', 'zulu'];
        
        let password = '';
        for (let i = 0; i < options.words; i++) {
            if (i > 0) password += options.separator;
            password += words[Math.floor(Math.random() * words.length)];
        }
        
        // Add a random number
        password += Math.floor(Math.random() * 100);
        
        // Set the password
        const passwordField = document.getElementById(options.passwordFieldId);
        if (passwordField) {
            passwordField.value = password;
            passwordField.dispatchEvent(new Event('input'));
        }
        
        // Set the confirm field if it exists
        if (options.confirmFieldId) {
            const confirmField = document.getElementById(options.confirmFieldId);
            if (confirmField) {
                confirmField.value = password;
                confirmField.dispatchEvent(new Event('input'));
            }
        }
    }
    
    // Update account UID function
    function updateAccountUid(email) {
        const accountField = document.getElementById('<?php echo $LDAP["account_attribute"]; ?>');
        if (accountField) {
            accountField.value = email.trim();
        }
    }
</script>

<?php render_footer(); ?>
