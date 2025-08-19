<?php
require_once "../includes/config.inc.php";
require_once "../includes/ldap_functions.inc.php";
require_once "../includes/organization_functions.inc.php";
require_once "../includes/access_functions.inc.php";
require_once "../includes/web_functions.inc.php";

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_dn'])) {
    header("Location: ../log_in/");
    exit;
}

// Check if user is administrator or maintainer
if (!currentUserIsAdministrator() && !currentUserIsMaintainer()) {
    header("Location: ../index.php");
    exit;
}

$message = '';
$message_type = '';

// Handle organization creation
if ($_POST['action'] == 'create_organization') {
    if (!validate_csrf_token()) {
        $message = 'Invalid CSRF token. Please try again.';
        $message_type = 'danger';
    } else {
        // Build organization data using field mappings
        $org_data = [];
        foreach ($LDAP['org_field_mappings'] as $form_field => $ldap_attr) {
            if (isset($_POST[$form_field]) && !empty($_POST[$form_field])) {
                $org_data[$ldap_attr] = $_POST[$form_field];
            }
        }
        
        // Add creator DN if user is administrator
        if (currentUserIsAdministrator()) {
            $org_data['creatorDN'] = $_SESSION['user_dn'];
        }
        
        $result = createOrganization($org_data);
        if ($result[0]) {
            $message = 'Organization created successfully!';
            $message_type = 'success';
        } else {
            $message = 'Error creating organization: ' . $result[1];
            $message_type = 'danger';
        }
    }
}

// Handle organization deletion
if ($_POST['action'] == 'delete_organization') {
    if (!validate_csrf_token()) {
        $message = 'Invalid CSRF token. Please try again.';
        $message_type = 'danger';
    } else {
        $org_name = $_POST['org_name'];
        $result = deleteOrganization($org_name);
        if ($result[0]) {
            $message = 'Organization deleted successfully!';
            $message_type = 'success';
        } else {
            $message = 'Error deleting organization: ' . $result[1];
            $message_type = 'danger';
        }
    }
}

// Get list of organizations
$organizations = listOrganizations();

render_header("Organization Management");
?>

<div class="container">
    <h1>Organization Management</h1>
    
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible" role="alert">
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">Create New Organization</h3>
                </div>
                <div class="panel-body">
                    <form method="post" action="" id="createOrgForm">
                        <?php echo csrf_token_field(); ?>
                        <input type="hidden" name="action" value="create_organization">
                        
                        <?php
                        // Generate required fields first
                        foreach ($LDAP['org_required_fields'] as $ldap_attr) {
                            $form_field = array_search($ldap_attr, $LDAP['org_field_mappings']);
                            if ($form_field !== false) {
                                $label = $LDAP['org_field_labels'][$form_field];
                                $field_type = $LDAP['org_field_types'][$form_field];
                                $required = 'required';
                                
                                echo "<div class='form-group'>";
                                echo "<label for='{$form_field}'>{$label} *</label>";
                                
                                if ($field_type === 'textarea') {
                                    echo "<textarea class='form-control' id='{$form_field}' name='{$form_field}' {$required} rows='3'></textarea>";
                                } else {
                                    echo "<input type='{$field_type}' class='form-control' id='{$form_field}' name='{$form_field}' {$required}>";
                                }
                                echo "</div>";
                            }
                        }
                        
                        // Generate optional fields
                        foreach ($LDAP['org_optional_fields'] as $ldap_attr) {
                            $form_field = array_search($ldap_attr, $LDAP['org_field_mappings']);
                            if ($form_field !== false) {
                                $label = $LDAP['org_field_labels'][$form_field];
                                $field_type = $LDAP['org_field_types'][$form_field];
                                
                                echo "<div class='form-group'>";
                                echo "<label for='{$form_field}'>{$label}</label>";
                                
                                if ($field_type === 'textarea') {
                                    echo "<textarea class='form-control' id='{$form_field}' name='{$form_field}' rows='3'></textarea>";
                                } else {
                                    echo "<input type='{$field_type}' class='form-control' id='{$form_field}' name='{$form_field}'>";
                                }
                                echo "</div>";
                            }
                        }
                        ?>
                        
                        <button type="submit" class="btn btn-success">Create Organization</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Existing Organizations</h3>
                </div>
                <div class="panel-body">
                    <?php if (empty($organizations)): ?>
                        <p class="text-muted">No organizations found.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($organizations as $org): ?>
                                <div class="list-group-item">
                                    <h4 class="list-group-item-heading"><?php echo htmlspecialchars($org['o']); ?></h4>
                                    <p class="list-group-item-text">
                                        <?php if (isset($org['mail'])): ?>
                                            <strong>Email:</strong> <?php echo htmlspecialchars($org['mail']); ?><br>
                                        <?php endif; ?>
                                        <?php if (isset($org['telephoneNumber'])): ?>
                                            <strong>Phone:</strong> <?php echo htmlspecialchars($org['telephoneNumber']); ?><br>
                                        <?php endif; ?>
                                        <?php if (isset($org['description'])): ?>
                                            <strong>Status:</strong> <?php echo htmlspecialchars($org['description']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <div class="btn-group btn-group-sm">
                                        <a href="show_organization.php?org=<?php echo urlencode($org['o']); ?>" class="btn btn-info">View</a>
                                        <a href="org_users.php?org=<?php echo urlencode($org['o']); ?>" class="btn btn-primary">Users</a>
                                        <button type="button" class="btn btn-danger" onclick="confirmDelete('<?php echo htmlspecialchars($org['o']); ?>')">Delete</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">Confirm Deletion</h4>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the organization "<span id="deleteOrgName"></span>"?</p>
                <p class="text-danger"><strong>Warning:</strong> This will also delete all users and roles associated with this organization!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <form method="post" action="" style="display: inline;">
                    <?php echo csrf_token_field(); ?>
                    <input type="hidden" name="action" value="delete_organization">
                    <input type="hidden" name="org_name" id="deleteOrgNameInput">
                    <button type="submit" class="btn btn-danger">Delete Organization</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(orgName) {
    document.getElementById('deleteOrgName').textContent = orgName;
    document.getElementById('deleteOrgNameInput').value = orgName;
    $('#deleteModal').modal('show');
}

// Form validation
document.getElementById('createOrgForm').addEventListener('submit', function(e) {
    var requiredFields = document.querySelectorAll('[required]');
    var isValid = true;
    
    requiredFields.forEach(function(field) {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });
    
    if (!isValid) {
        e.preventDefault();
        alert('Please fill in all required fields.');
    }
});
</script>

<?php render_footer(); ?> 