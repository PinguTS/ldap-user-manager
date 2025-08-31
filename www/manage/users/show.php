<?php
declare(strict_types=1);

// Define LDAP escape constants for PHP < 7.3 compatibility
if (!defined('LDAP_ESCAPE_FILTER')) {
    define('LDAP_ESCAPE_FILTER', 0);
}
if (!defined('LDAP_ESCAPE_DN')) {
    define('LDAP_ESCAPE_DN', 0);
}

set_include_path( ".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once dirname(__DIR__) . "/module_functions.inc.php";
include_once "user_functions.inc.php";

// Ensure CSRF token is generated early
get_csrf_token();

set_page_access(["admin", "user"]); // Allow both admin and user roles

render_header("$ORGANISATION_NAME - User Profile");
render_submenu();

$invalid_password = FALSE;
$mismatched_passwords = FALSE;
$invalid_username = FALSE;
$weak_password = FALSE;
$to_update = array();

if ($SMTP['host'] != "") { $can_send_email = TRUE; } else { $can_send_email = FALSE; }

$LDAP['default_attribute_map']["mail"]  = array("label" => "Email", "onkeyup" => "check_if_we_should_enable_sending_email();");

$attribute_map = $LDAP['default_attribute_map'];
if (isset($LDAP['account_additional_attributes'])) { $attribute_map = ldap_complete_attribute_map($attribute_map,$LDAP['account_additional_attributes']); }
if (! array_key_exists($LDAP['account_attribute'], $attribute_map)) {
  $attribute_r = array_merge($attribute_map, array($LDAP['account_attribute'] => array("label" => "Account UID")));
}

// Check if user parameter is provided (support both UUID and legacy account_identifier)
$account_identifier = null;
$user_uuid = null;

if (isset($_GET['uuid']) && !empty($_GET['uuid'])) {
    // UUID-based lookup
    $user_uuid = $_GET['uuid'];
    if (!is_valid_uuid($user_uuid)) {
        render_alert_banner("Invalid user UUID provided.", "warning");
        render_footer();
        exit(0);
    }
    
    // Get user by UUID
    $ldap_connection = open_ldap_connection();
    $user_by_uuid = ldap_get_user_by_uuid($ldap_connection, $user_uuid);
    ldap_close($ldap_connection);
    
    if (!$user_by_uuid) {
        render_alert_banner("User with UUID '$user_uuid' not found.", "warning");
        error_log("show_user.php: UUID lookup failed for UUID: $user_uuid");
        render_footer();
        exit(0);
    }
    
    // Use the primary identifier from the user entry
    $account_identifier = $user_by_uuid[$LDAP['account_attribute']][0] ?? $user_by_uuid['mail'][0] ?? $user_by_uuid['uid'][0];
    
} elseif (isset($_POST['account_identifier']) || isset($_GET['account_identifier'])) {
    // Legacy account_identifier lookup
    $account_identifier = (isset($_POST['account_identifier']) ? $_POST['account_identifier'] : $_GET['account_identifier']);
    $account_identifier = urldecode($account_identifier);
} else {
    render_alert_banner("User identifier (UUID or account identifier) is required.", "warning");
    render_footer();
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_token();
}

$ldap_connection = open_ldap_connection();

// Search for user across both organizations and system users
if ($user_uuid) {
    // UUID-based lookup - we already have the user data
    // Convert single user entry to expected format
    $user = [];
    $user[0] = $user_by_uuid;
    $user['count'] = 1;
    
    // Determine user location based on DN
    if (strpos($user_by_uuid['dn'], $LDAP['org_dn']) !== false) {
        $user_location = 'organization';
    } else {
        $user_location = 'system';
    }
} else {
    // Legacy account_identifier-based lookup
    $ldap_search_query="({$LDAP['account_attribute']}=". ldap_escape($account_identifier, "", LDAP_ESCAPE_FILTER) . ")";

    // First try to find in organizations
    $ldap_search = ldap_search( $ldap_connection, $LDAP['org_dn'], $ldap_search_query);
    $user = null;
    $user_location = '';
    
    if ($ldap_search && ldap_count_entries($ldap_connection, $ldap_search) > 0) {
        $user = ldap_get_entries($ldap_connection, $ldap_search);
        $user_location = 'organization';
    } else {
        // Try system users
        $ldap_search = ldap_search( $ldap_connection, $LDAP['people_dn'], $ldap_search_query);
        if ($ldap_search && ldap_count_entries($ldap_connection, $ldap_search) > 0) {
            $user = ldap_get_entries($ldap_connection, $ldap_search);
            $user_location = 'system';
        }
    }
}

if (!$user || $user['count'] == 0) {
    render_alert_banner("User not found.", "warning");
    render_footer();
    exit(0);
}

$user_data = $user[0];

// Check if current user can edit this profile
$can_edit = false;
if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) {
    $can_edit = true;
} elseif (currentUserIsOrgAdmin()) {
    // Check if user belongs to current user's organization
    $user_org = $user_data['o'][0] ?? '';
    if ($user_org && currentUserIsOrgManager($user_org)) {
        $can_edit = true;
    }
} elseif ($VALIDATED && $USER_ID === $account_identifier) {
    // User can edit their own profile
    $can_edit = true;
}

// Handle form submission for profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && $can_edit) {
    $update_data = [];
    $errors = [];
    
    // Collect form data
    foreach ($attribute_map as $attr => $config) {
        if (isset($_POST[$attr]) && !empty(trim($_POST[$attr]))) {
            $update_data[$attr] = trim($_POST[$attr]);
        }
    }
    
    // Handle password change
    if (!empty($_POST['new_password'])) {
        if ($_POST['new_password'] !== $_POST['confirm_new_password']) {
            $errors[] = "New passwords do not match.";
        } else {
            // Hash the password before storing it
            $update_data['userPassword'] = ldap_hashed_password($_POST['new_password']);
        }
    }
    
    // If no errors, update the user
    if (empty($errors)) {
        $result = updateUser($user_data['dn'], $update_data);
        if ($result) {
            render_alert_banner('User profile updated successfully!', 'success', 10000);
            // Refresh user data
            $ldap_search = ldap_read($ldap_connection, $user_data['dn'], '(objectClass=*)');
            if ($ldap_search) {
                $updated_user = ldap_get_entries($ldap_connection, $ldap_search);
                if ($updated_user['count'] > 0) {
                    $user_data = $updated_user[0];
                }
            }
        } else {
            render_alert_banner('Error updating user profile. Please check the logs for details.', 'danger', 10000);
        }
    } else {
        render_alert_banner('Please correct the following errors: ' . implode(', ', $errors), 'danger', 10000);
    }
}

ldap_close($ldap_connection);

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>User Profile: <?php echo htmlspecialchars(get_ldap_attribute($user_data, 'cn') ?: get_ldap_attribute($user_data, $LDAP['account_attribute']) ?: 'Unknown User'); ?></h2>
            
            <?php if ($can_edit): ?>
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">Edit User Profile</h3>
                </div>
                <div class="panel-body">
                    <form method="post" action="" enctype="multipart/form-data">
                        <?php echo csrf_token_field(); ?>
                        
                        <!-- Account Information -->
                        <h4>Account Information</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="<?php echo $LDAP['account_attribute']; ?>"><?php echo $attribute_map[$LDAP['account_attribute']]['label'] ?? 'Account ID'; ?></label>
                                    <input type="text" class="form-control" id="<?php echo $LDAP['account_attribute']; ?>" name="<?php echo $LDAP['account_attribute']; ?>" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, $LDAP['account_attribute'])); ?>" readonly>
                                    <small class="form-text text-muted">Account ID cannot be changed.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="userRole">User Role</label>
                                    <input type="text" class="form-control" id="userRole" name="userRole" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'userRole')); ?>" readonly>
                                    <small class="form-text text-muted">User role cannot be changed from this interface.</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Personal Information -->
                        <h4>Personal Information</h4>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="givenName">Given Name *</label>
                                    <input type="text" class="form-control" id="givenName" name="givenName" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'givenName')); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="sn">Surname *</label>
                                    <input type="text" class="form-control" id="sn" name="sn" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'sn')); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="cn">Display Name *</label>
                                    <input type="text" class="form-control" id="cn" name="cn" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'cn')); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Information -->
                        <h4>Contact Information</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="mail">Email Address</label>
                                    <input type="email" class="form-control" id="mail" name="mail" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'mail')); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="telephoneNumber">Phone Number</label>
                                    <input type="tel" class="form-control" id="telephoneNumber" name="telephoneNumber" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'telephoneNumber')); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Organization -->
                        <h4>Organization</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="o">Organization</label>
                                    <input type="text" class="form-control" id="o" name="o" 
                                           value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, 'o')); ?>" readonly>
                                    <small class="form-text text-muted">Organization cannot be changed from this interface.</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Password Change -->
                        <h4>Change Password</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group <?php echo $mismatched_passwords ? 'has-error' : ''; ?>">
                                    <label for="new_password">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password">
                                    <?php if ($mismatched_passwords): ?>
                                        <span class="help-block">Passwords do not match.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="confirm_new_password">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password">
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
                                            <textarea class="form-control" id="<?php echo htmlspecialchars($attr_name); ?>" name="<?php echo htmlspecialchars($attr_name); ?>" rows="3"><?php echo htmlspecialchars(get_ldap_attribute($user_data, $attr_name)); ?></textarea>
                                        <?php else: ?>
                                            <input type="<?php echo htmlspecialchars($attr_config['type'] ?? 'text'); ?>" class="form-control" id="<?php echo htmlspecialchars($attr_name); ?>" name="<?php echo htmlspecialchars($attr_name); ?>" 
                                                   value="<?php echo htmlspecialchars(get_ldap_attribute($user_data, $attr_name)); ?>">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <button type="submit" name="update_profile" class="btn btn-success">Update Profile</button>
                            <a href="/manage/users/" class="btn btn-default">Back to Users</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">User Information (Read Only)</h3>
                </div>
                <div class="panel-body">
                    <p class="text-muted">You do not have permission to edit this user profile.</p>
                    <a href="/manage/users/" class="btn btn-default">Back to Users</a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- User Details Display -->
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h3 class="panel-title">User Details</h3>
                </div>
                <div class="panel-body">
                    <div class="row">
                        <div class="col-md-6">
                            <dl class="dl-horizontal">
                                <dt>Account ID:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, $LDAP['account_attribute'])); ?></dd>
                                
                                <dt>Display Name:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'cn')); ?></dd>
                                
                                <dt>Given Name:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'givenName')); ?></dd>
                                
                                <dt>Surname:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'sn')); ?></dd>
                                
                                <dt>User Role:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'userRole')); ?></dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <dl class="dl-horizontal">
                                <dt>Email:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'mail')); ?></dd>
                                
                                <dt>Phone:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'telephoneNumber')); ?></dd>
                                
                                <dt>Organization:</dt>
                                <dd><?php echo htmlspecialchars(get_ldap_attribute($user_data, 'o')); ?></dd>
                                
                                <dt>Location:</dt>
                                <dd><?php echo ucfirst($user_location); ?> User</dd>
                                
                                <dt>DN:</dt>
                                <dd><code><?php echo htmlspecialchars($user_data['dn']); ?></code></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Password confirmation validation
document.getElementById('confirm_new_password').addEventListener('input', function() {
    var password = document.getElementById('new_password').value;
    var confirm = this.value;
    
    if (password !== confirm) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});

document.getElementById('new_password').addEventListener('input', function() {
    var confirm = document.getElementById('confirm_new_password');
    if (confirm.value) {
        confirm.dispatchEvent(new Event('input'));
    }
});
</script>

<?php
render_footer();
?>
