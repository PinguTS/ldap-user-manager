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
                                    <div class="password-strength-meter mt-2">
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar" id="passwordStrengthBar" role="progressbar" style="width: 0%;"></div>
                                        </div>
                                        <small class="text-muted" id="passwordStrengthText">Password strength: Very Weak</small>
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

<script>
// Auto-fill display name from given name and surname
function updateDisplayName() {
    const givenName = document.getElementById('givenName').value.trim();
    const surname = document.getElementById('sn').value.trim();
    const displayName = document.getElementById('cn');
    
    if (givenName && surname) {
        displayName.value = givenName + ' ' + surname;
    } else if (givenName) {
        displayName.value = givenName;
    } else if (surname) {
        displayName.value = surname;
    } else {
        displayName.value = '';
    }
}

// Add event listeners for auto-fill
document.getElementById('givenName').addEventListener('input', updateDisplayName);
document.getElementById('sn').addEventListener('input', updateDisplayName);

// Password strength assessment using zxcvbn algorithm
function assessPasswordStrength(password) {
    if (!password) {
        return { score: 0, feedback: 'Very Weak' };
    }
    
    // Simple strength assessment (you can integrate zxcvbn library for more sophisticated analysis)
    let score = 0;
    let feedback = '';
    
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (/[a-z]/.test(password)) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    
    // Bonus for mixed case and numbers
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password) && /[^0-9]/.test(password)) score++;
    
    // Penalty for common patterns
    if (/(.)\1{2,}/.test(password)) score = Math.max(0, score - 1);
    if (/123|abc|qwe|password|admin/i.test(password)) score = Math.max(0, score - 2);
    
    switch (score) {
        case 0:
        case 1:
            feedback = 'Very Weak';
            break;
        case 2:
            feedback = 'Weak';
            break;
        case 3:
            feedback = 'Fair';
            break;
        case 4:
            feedback = 'Good';
            break;
        case 5:
            feedback = 'Strong';
            break;
        default:
            feedback = 'Very Strong';
    }
    
    return { score: Math.min(score, 5), feedback: feedback };
}

// Update password strength meter
function updatePasswordStrength() {
    const password = document.getElementById('userPassword').value;
    const strengthBar = document.getElementById('passwordStrengthBar');
    const strengthText = document.getElementById('passwordStrengthText');
    
    const strength = assessPasswordStrength(password);
    const percentage = (strength.score / 5) * 100;
    
    // Update progress bar
    strengthBar.style.width = percentage + '%';
    
    // Update colors based on strength
    strengthBar.className = 'progress-bar';
    if (strength.score <= 1) {
        strengthBar.classList.add('progress-bar-danger');
    } else if (strength.score <= 2) {
        strengthBar.classList.add('progress-bar-warning');
    } else if (strength.score <= 3) {
        strengthBar.classList.add('progress-bar-info');
    } else if (strength.score <= 4) {
        strengthBar.classList.add('progress-bar-success');
    } else {
        strengthBar.classList.add('progress-bar-success');
    }
    
    // Update text
    strengthText.textContent = `Password strength: ${strength.feedback}`;
    
    // Update confirm password validation
    const confirm = document.getElementById('confirm_password');
    if (confirm.value) {
        confirm.dispatchEvent(new Event('input'));
    }
}

// Secure password generation using established standards
function generateSecurePassword() {
    // Word-based password generation (more memorable than random characters)
    const adjectives = [
        'Swift', 'Bright', 'Calm', 'Brave', 'Wise', 'Gentle', 'Noble', 'Pure',
        'Bold', 'Clear', 'Deep', 'Fair', 'Fresh', 'Grand', 'Happy', 'Kind'
    ];
    
    const nouns = [
        'River', 'Mountain', 'Ocean', 'Forest', 'Valley', 'Meadow', 'Spring', 'Star',
        'Moon', 'Sun', 'Cloud', 'Wind', 'Rain', 'Snow', 'Flower', 'Tree'
    ];
    
    const numbers = '0123456789';
    const symbols = '!@#$%^&*';
    
    // Generate password: Adjective + Noun + Number + Symbol
    const adjective = adjectives[Math.floor(Math.random() * adjectives.length)];
    const noun = nouns[Math.floor(Math.random() * nouns.length)];
    const number = numbers[Math.floor(Math.random() * numbers.length)];
    const symbol = symbols[Math.floor(Math.random() * symbols.length)];
    
    const password = adjective + noun + number + symbol;
    
    // Set the generated password
    document.getElementById('userPassword').value = password;
    document.getElementById('confirm_password').value = password;
    
    // Update strength meter
    updatePasswordStrength();
    
    // Show success message
    const generateBtn = document.getElementById('generatePassword');
    const originalText = generateBtn.innerHTML;
    generateBtn.innerHTML = '<i class="glyphicon glyphicon-ok"></i>';
    generateBtn.classList.remove('btn-info');
    generateBtn.classList.add('btn-success');
    
    setTimeout(() => {
        generateBtn.innerHTML = originalText;
        generateBtn.classList.remove('btn-success');
        generateBtn.classList.add('btn-info');
    }, 2000);
}

// Password confirmation validation
document.getElementById('confirm_password').addEventListener('input', function() {
    const password = document.getElementById('userPassword').value;
    const confirm = this.value;
    
    if (password !== confirm) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});

// Update password strength on input
document.getElementById('userPassword').addEventListener('input', updatePasswordStrength);

// Generate password button
document.getElementById('generatePassword').addEventListener('click', generateSecurePassword);

// Initialize display name if fields have values
document.addEventListener('DOMContentLoaded', function() {
    updateDisplayName();
    updatePasswordStrength();
});
</script>

<?php
render_footer();
?>
