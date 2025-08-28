<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once dirname(__DIR__) . "/module_functions.inc.php";
include_once "organization_functions.inc.php";

// Ensure CSRF token is generated early
get_csrf_token();



set_page_access(["admin", "maintainer"]);

$completed_action="{$SERVER_PATH}manage/users/";
$page_title = "New System User";
$admin_setup = FALSE;

render_header("$ORGANISATION_NAME - New User Account");
render_submenu();

$invalid_password = FALSE;
$mismatched_passwords = FALSE;
$invalid_username = FALSE;
$weak_password = FALSE;
$invalid_email = FALSE;
$disabled_email_tickbox = TRUE;
$invalid_cn = FALSE;
$invalid_givenname = FALSE;
$invalid_sn = FALSE;

$invalid_user_role = FALSE;

$new_account_r = array();



// Get available user roles based on current user's permissions
$available_user_roles = [];
if (currentUserIsGlobalAdmin()) {
    // System administrators can create users with any system role
    $available_user_roles = [$LDAP['admin_role'], 'maintainer'];
} elseif (currentUserIsMaintainer()) {
    // System maintainers can only create users with maintainer role
    $available_user_roles = ['maintainer'];
} else {
    // Other users cannot create system users
    $available_user_roles = [];
}

// Process form data directly for system user creation
if (isset($_POST['mail']) && !empty(trim($_POST['mail']))) {
    $new_account_r['mail'] = trim($_POST['mail']);
}
if (isset($_POST['cn']) && !empty(trim($_POST['cn']))) {
    $new_account_r['cn'] = trim($_POST['cn']);
}
if (isset($_POST['givenName']) && !empty(trim($_POST['givenName']))) {
    $new_account_r['givenName'] = trim($_POST['givenName']);
}
if (isset($_POST['sn']) && !empty(trim($_POST['sn']))) {
    $new_account_r['sn'] = trim($_POST['sn']);
}
if (isset($_POST['userPassword']) && !empty(trim($_POST['userPassword']))) {
    $new_account_r['userPassword'] = trim($_POST['userPassword']);
}
if (isset($_POST['userRole']) && !empty(trim($_POST['userRole']))) {
    $new_account_r['userRole'] = trim($_POST['userRole']);
}
if (isset($_POST['telephoneNumber']) && !empty(trim($_POST['telephoneNumber']))) {
    $new_account_r['telephoneNumber'] = trim($_POST['telephoneNumber']);
}

// Debug: Log what was submitted (remove this in production)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {
    error_log("Form submitted with data: " . print_r($_POST, true));
    error_log("Processed data: " . print_r($new_account_r, true));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {
  validate_csrf_token();
  
  // Validate required fields
  $errors = [];
  
  if (empty($new_account_r['mail'])) {
    $invalid_email = TRUE;
    $errors[] = "Email address is required and will be used as the account ID.";
  }
  
  if (empty($new_account_r['cn'])) {
    $invalid_cn = TRUE;
    $errors[] = "Common name is required.";
  }
  
  if (empty($new_account_r['givenName'])) {
    $invalid_givenname = TRUE;
    $errors[] = "Given name is required.";
  }
  
  if (empty($new_account_r['sn'])) {
    $invalid_sn = TRUE;
    $errors[] = "Surname is required.";
  }
  
  if (empty($new_account_r['userPassword'])) {
    $invalid_password = TRUE;
    $errors[] = "Password is required.";
  }
  
  if (isset($new_account_r['userPassword']) && isset($_POST['confirm_password']) && $new_account_r['userPassword'] !== $_POST['confirm_password']) {
    $mismatched_passwords = TRUE;
    $errors[] = "Passwords do not match.";
  }
  
  // Organization is not required for system users
  
  if (empty($new_account_r['userRole'])) {
    $invalid_user_role = TRUE;
    $errors[] = "User role is required.";
  }
  
  // Validate role permissions
  if (!empty($new_account_r['userRole'])) {
    // Validate that maintainers cannot create administrator roles
    if (currentUserIsMaintainer() && $new_account_r['userRole'] === $LDAP['admin_role']) {
      $invalid_user_role = TRUE;
      $errors[] = $LDAP['error_messages']['maintainer_cannot_create_admin'];
    }
    
    if (!in_array($new_account_r['userRole'], $available_user_roles)) {
      $invalid_user_role = TRUE;
      $errors[] = "You do not have permission to create users with this role.";
    }
  }
  
  // If no errors, create the account
  if (empty($errors)) {
    // Hash the password before passing it to createUserAccount for security
    $new_account_r['userPassword'] = ldap_hashed_password($new_account_r['userPassword']);
    
    $result = createUserAccount($new_account_r);
    if ($result[0]) {
      render_alert_banner('User account created successfully!', 'success', 10000);
      // Clear form data
      $new_account_r = array();
    } else {
      render_alert_banner('Error creating user account: ' . $result[1], 'danger', 10000);
    }
  } else {
    render_alert_banner('Please correct the following errors: ' . implode(', ', $errors), 'danger', 10000);
  }
}

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>Create New System User</h2>
            <div class="alert alert-info">
                <strong>System User:</strong> This user will have system-wide access and will not be associated with any specific organization. 
                System users can manage the entire system and all organizations.
            </div>
            
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">System User Information</h3>
                </div>
                <div class="panel-body">
                    <form method="post" action="" enctype="multipart/form-data" id="newUserForm">
                        <?php echo csrf_token_field(); ?>
                        
                        <style>
                        .password-strength-meter {
                            margin-top: 8px;
                        }
                        .password-strength-meter .progress {
                            margin-bottom: 4px;
                        }
                        .progress-bar-danger { background-color: #d9534f; }
                        .progress-bar-warning { background-color: #f0ad4e; }
                        .progress-bar-info { background-color: #5bc0de; }
                        .progress-bar-success { background-color: #5cb85c; }
                        .input-group-btn .btn {
                            border-left: 0;
                        }
                        .mt-2 { margin-top: 8px; }
                        </style>
                        
                        <!-- Account Information -->
                        <h4>Account Information</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_email ? 'has-error' : ''; ?>">
                                    <label for="mail">Email Address (Account ID) *</label>
                                    <input type="email" class="form-control" id="mail" name="mail" 
                                           value="<?php echo htmlspecialchars($new_account_r['mail'] ?? ''); ?>" required>
                                    <?php if ($invalid_email): ?>
                                        <span class="help-block">Valid email address is required and will be used as the account ID.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_user_role ? 'has-error' : ''; ?>">
                                    <label for="userRole">User Role *</label>
                                    <select class="form-control" id="userRole" name="userRole" required>
                                        <option value="">Select Role</option>
                                        <?php foreach ($available_user_roles as $role): ?>
                                            <option value="<?php echo htmlspecialchars($role); ?>" <?php echo ($new_account_r['userRole'] ?? '') === $role ? 'selected' : ''; ?>>
                                                <?php 
                                                $role_label = match($role) {
                                                    $LDAP['admin_role'] => $LDAP['role_display_labels']['admin_role'],
                                                    'maintainer' => $LDAP['role_display_labels']['maintainer_role'],
                                                    default => ucfirst(str_replace('_', ' ', $role))
                                                };
                                                echo htmlspecialchars($role_label);
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($invalid_user_role): ?>
                                        <span class="help-block">User role is required.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Personal Information -->
                        <h4>Personal Information</h4>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group <?php echo $invalid_givenname ? 'has-error' : ''; ?>">
                                    <label for="givenName">Given Name *</label>
                                    <input type="text" class="form-control" id="givenName" name="givenName" 
                                           value="<?php echo htmlspecialchars($new_account_r['givenName'] ?? ''); ?>" required>
                                    <?php if ($invalid_givenname): ?>
                                        <span class="help-block">Given name is required.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group <?php echo $invalid_sn ? 'has-error' : ''; ?>">
                                    <label for="sn">Surname *</label>
                                    <input type="text" class="form-control" id="sn" name="sn" 
                                           value="<?php echo htmlspecialchars($new_account_r['sn'] ?? ''); ?>" required>
                                    <?php if ($invalid_sn): ?>
                                        <span class="help-block">Surname is required.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group <?php echo $invalid_cn ? 'has-error' : ''; ?>">
                                    <label for="cn">Display Name * <small class="text-muted">(auto-filled from name)</small></label>
                                    <input type="text" class="form-control" id="cn" name="cn" 
                                           value="<?php echo htmlspecialchars($new_account_r['cn'] ?? ''); ?>" required>
                                    <?php if ($invalid_cn): ?>
                                        <span class="help-block">Display name is required.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Information -->
                        <h4>Contact Information</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="telephoneNumber">Phone Number</label>
                                    <input type="tel" class="form-control" id="telephoneNumber" name="telephoneNumber" 
                                           value="<?php echo htmlspecialchars($new_account_r['telephoneNumber'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        

                        
                        <!-- Security -->
                        <h4>Security</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_password ? 'has-error' : ''; ?>">
                                    <label for="userPassword">Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="userPassword" name="userPassword" required>
                                        <span class="input-group-btn">
                                            <button type="button" class="btn btn-info" id="generatePassword" title="Generate secure password">
                                                <i class="glyphicon glyphicon-refresh"></i>
                                            </button>
                                        </span>
                                    </div>
                                    <?php if ($invalid_password): ?>
                                        <span class="help-block">Password is required.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group <?php echo $mismatched_passwords ? 'has-error' : ''; ?>">
                                    <label for="confirm_password">Confirm Password *</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    <?php if ($mismatched_passwords): ?>
                                        <span class="help-block">Passwords do not match.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Attributes -->
                        <?php if (isset($LDAP['account_additional_attributes'])): ?>
                        <h4>Additional Information</h4>
                        <div class="row">
                            <?php foreach ($LDAP['account_additional_attributes'] as $attr_name => $attr_config): ?>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="<?php echo htmlspecialchars($attr_name); ?>"><?php echo htmlspecialchars($attr_config['label'] ?? ucfirst(str_replace('_', ' ', $attr_name))); ?></label>
                                        <?php if (isset($attr_config['type']) && $attr_config['type'] === 'textarea'): ?>
                                            <textarea class="form-control" id="<?php echo htmlspecialchars($attr_name); ?>" name="<?php echo htmlspecialchars($attr_name); ?>" rows="3"><?php echo htmlspecialchars($new_account_r[$attr_name] ?? ''); ?></textarea>
                                        <?php else: ?>
                                            <input type="<?php echo htmlspecialchars($attr_config['type'] ?? 'text'); ?>" class="form-control" id="<?php echo htmlspecialchars($attr_name); ?>" name="<?php echo htmlspecialchars($attr_name); ?>" 
                                                   value="<?php echo htmlspecialchars($new_account_r[$attr_name] ?? ''); ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <button type="submit" name="create_account" class="btn btn-success">Create User Account</button>
                            <a href="/manage/users/" class="btn btn-default">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php print $SERVER_PATH; ?>js/zxcvbn.min.js"></script>
<script src="<?php print $SERVER_PATH; ?>js/password_utils.min.js"></script>
<script type="text/javascript">
$(document).ready(function(){
    // Get password strength configuration from server
    const passwordConfig = <?php echo get_password_strength_config_js(); ?>;
    
    // Initialize unified password strength checking with dynamic config
    initializePasswordStrength({
        passwordFieldId: 'userPassword',
        confirmFieldId: 'confirm_password',
        config: passwordConfig
    });
    
    // Initialize form enhancements
    initializeUserManagementForms({
        givenNameField: 'givenName',
        surnameField: 'sn',
        displayField: 'cn',
        emailField: 'mail',
        uidField: 'uid',
        passwordField: 'userPassword',
        confirmField: 'confirm_password'
    });
});
</script>

<?php
render_footer();
?>
