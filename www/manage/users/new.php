<?php

set_include_path( ".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "../module_functions.inc.php";
include_once "organization_functions.inc.php";

// Ensure CSRF token is generated early
get_csrf_token();

$attribute_map = $LDAP['default_attribute_map'];
if (isset($LDAP['account_additional_attributes'])) { $attribute_map = ldap_complete_attribute_map($attribute_map,$LDAP['account_additional_attributes']); }

// Ensure account_attribute is properly set
$account_attribute = $LDAP['account_attribute'];
if (! array_key_exists($account_attribute, $attribute_map)) {
  $attribute_map[$account_attribute] = array("label" => "Account UID");
}

if (! array_key_exists($LDAP['account_attribute'], $attribute_map)) {
  $attribute_r = array_merge($attribute_map, array($LDAP['account_attribute'] => array("label" => "Account UID")));
}

set_page_access(["admin", "maintainer"]);

$completed_action="{$SERVER_PATH}manage/users/";
$page_title="New account";
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
$invalid_account_identifier = FALSE;
$invalid_organization = FALSE;
$invalid_user_role = FALSE;
$account_attribute = $LDAP['account_attribute'];

$new_account_r = array();

// Get available organizations for selection
$available_organizations = listOrganizations();

// Get available user roles
$available_user_roles = ['user', 'org_admin'];

foreach ($attribute_map as $attribute => $attr_r) {

  if (isset($_FILES[$attribute]['size']) and $_FILES[$attribute]['size'] > 0) {
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
    
    // Read file content and store in new_account_r
    $file_content = file_get_contents($file_tmp);
    if ($file_content !== false) {
      $new_account_r[$attribute] = $file_content;
    }
  } elseif (isset($_POST[$attribute]) and !empty(trim($_POST[$attribute]))) {
    $new_account_r[$attribute] = trim($_POST[$attribute]);
  }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {
  validate_csrf_token();
  
  // Validate required fields
  $errors = [];
  
  if (empty($new_account_r[$account_attribute])) {
    $invalid_account_identifier = TRUE;
    $errors[] = "Account identifier is required.";
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
  
  if (empty($new_account_r['o'])) {
    $invalid_organization = TRUE;
    $errors[] = "Organization is required.";
  }
  
  if (empty($new_account_r['userRole'])) {
    $invalid_user_role = TRUE;
    $errors[] = "User role is required.";
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
            <h2>Create New User Account</h2>
            
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">New User Information</h3>
                </div>
                <div class="panel-body">
                    <form method="post" action="" enctype="multipart/form-data" id="newUserForm">
                        <?php echo csrf_token_field(); ?>
                        
                        <!-- Account Information -->
                        <h4>Account Information</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_account_identifier ? 'has-error' : ''; ?>">
                                    <label for="<?php echo $account_attribute; ?>"><?php echo $attribute_map[$account_attribute]['label'] ?? 'Account ID'; ?> *</label>
                                    <input type="text" class="form-control" id="<?php echo $account_attribute; ?>" name="<?php echo $account_attribute; ?>" 
                                           value="<?php echo htmlspecialchars($new_account_r[$account_attribute] ?? ''); ?>" required>
                                    <?php if ($invalid_account_identifier): ?>
                                        <span class="help-block">Account identifier is required.</span>
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
                                                <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
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
                                    <label for="cn">Display Name *</label>
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
                                <div class="form-group <?php echo $invalid_email ? 'has-error' : ''; ?>">
                                    <label for="mail">Email Address</label>
                                    <input type="email" class="form-control" id="mail" name="mail" 
                                           value="<?php echo htmlspecialchars($new_account_r['mail'] ?? ''); ?>">
                                    <?php if ($invalid_email): ?>
                                        <span class="help-block">Please enter a valid email address.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="telephoneNumber">Phone Number</label>
                                    <input type="tel" class="form-control" id="telephoneNumber" name="telephoneNumber" 
                                           value="<?php echo htmlspecialchars($new_account_r['telephoneNumber'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Organization -->
                        <h4>Organization</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_organization ? 'has-error' : ''; ?>">
                                    <label for="o">Organization *</label>
                                    <select class="form-control" id="o" name="o" required>
                                        <option value="">Select Organization</option>
                                        <?php foreach ($available_organizations as $org): ?>
                                            <?php 
                                            $org_name = '';
                                            if (isset($org['o']) && !empty($org['o'])) {
                                                if (strpos($org['o'], ',') !== false) {
                                                    $org_name = preg_replace('/^o=([^,]+).*$/', '$1', $org['o']);
                                                } else {
                                                    $org_name = $org['o'];
                                                }
                                            } elseif (isset($org['dn']) && !empty($org['dn'])) {
                                                $org_name = preg_replace('/^o=([^,]+).*$/', '$1', $org['dn']);
                                            }
                                            ?>
                                            <option value="<?php echo htmlspecialchars($org_name); ?>" <?php echo ($new_account_r['o'] ?? '') === $org_name ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($org_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($invalid_organization): ?>
                                        <span class="help-block">Organization is required.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Security -->
                        <h4>Security</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $invalid_password ? 'has-error' : ''; ?>">
                                    <label for="userPassword">Password *</label>
                                    <input type="password" class="form-control" id="userPassword" name="userPassword" required>
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
                            <a href="index.php" class="btn btn-default">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Password confirmation validation
document.getElementById('confirm_password').addEventListener('input', function() {
    var password = document.getElementById('userPassword').value;
    var confirm = this.value;
    
    if (password !== confirm) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});

document.getElementById('userPassword').addEventListener('input', function() {
    var confirm = document.getElementById('confirm_password');
    if (confirm.value) {
        confirm.dispatchEvent(new Event('input'));
    }
});
</script>

<?php
render_footer();
?>
