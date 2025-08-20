<?php

set_include_path( ".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";
include_once "organization_functions.inc.php";

// Check if user has appropriate permissions
if (!(currentUserIsGlobalAdmin() || currentUserIsMaintainer() || currentUserIsOrgManager(''))) {
    // Redirect unauthorized users
    header("Location: ../index.php");
    exit;
}

// Determine user's access level
$is_global_admin = currentUserIsGlobalAdmin();
$is_maintainer = currentUserIsMaintainer();
$user_organizations = [];

// If user is an organization admin, get their organizations
if (!$is_global_admin && !$is_maintainer) {
    // For organization admins, we need to find which organizations they manage
    // This is a bit complex since we need to search through all organizations
    $all_orgs = listOrganizations();
    foreach ($all_orgs as $org) {
        if (currentUserIsOrgManager($org['o'])) {
            $user_organizations[] = $org['o'];
        }
    }
}



$message = '';
$message_type = '';

// Handle organization creation
if (isset($_POST['action']) && $_POST['action'] == 'create_organization') {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        $message = 'Security validation failed. Please refresh the page and try again.';
        $message_type = 'danger';
    } elseif (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Security validation failed. Please refresh the page and try again.';
        $message_type = 'danger';
    } elseif (!currentUserCanCreateOrganization()) {
        $message = 'You do not have permission to create organizations.';
        $message_type = 'danger';
    } else {
        // Build organization data using field mappings
        $org_data = [];
        
        // Debug: Check LDAP configuration
        if (!isset($LDAP['org_field_mappings'])) {
            $message = 'Organization field mappings not configured. Please check LDAP configuration.';
            $message_type = 'danger';
        } else {
            // Map form fields to LDAP attributes using the configuration
            foreach ($LDAP['org_field_mappings'] as $form_field => $ldap_attr) {
                if (isset($_POST[$form_field]) && !empty(trim($_POST[$form_field]))) {
                    $org_data[$ldap_attr] = trim($_POST[$form_field]);
                }
            }
            

            
            // Ensure required field 'o' (organization name) is present
            if (!isset($org_data['o']) || empty($org_data['o'])) {
                $message = "Required field 'organization name' is missing.";
                $message_type = 'danger';
            }
            
            // Special handling for postalAddress from individual address fields
            if (isset($_POST['org_address']) || isset($_POST['org_zip']) || isset($_POST['org_city']) || isset($_POST['org_state']) || isset($_POST['org_country'])) {
                $postal_parts = [
                    trim($_POST['org_address'] ?? ''),
                    trim($_POST['org_zip'] ?? ''),
                    trim($_POST['org_city'] ?? ''),
                    trim($_POST['org_state'] ?? ''),
                    trim($_POST['org_country'] ?? '')
                ];
                $postal_address = implode('$', $postal_parts);
                if (!empty(trim($postal_address, '$'))) {
                    $org_data['postalAddress'] = $postal_address;
                }
            }
            
            // Create organization using the createOrganization function
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
}

// Handle organization deletion
if (isset($_POST['action']) && $_POST['action'] == 'delete_organization') {
    if (!validate_csrf_token()) {
        $message = 'Security validation failed. Please refresh the page and try again.';
        $message_type = 'danger';
    } elseif (!isset($_POST['org_name']) || empty($_POST['org_name'])) {
        $message = 'Organization name is required for deletion.';
        $message_type = 'danger';
    } else {
        $org_name = $_POST['org_name'];
        
        // If UUID is provided, use it for additional validation
        if (isset($_POST['org_uuid']) && !empty($_POST['org_uuid']) && $LDAP['use_uuid_identification']) {
            $ldap_connection = open_ldap_connection();
            $org_by_uuid = ldap_get_organization_by_uuid($ldap_connection, $_POST['org_uuid']);
            ldap_close($ldap_connection);
            
            if (!$org_by_uuid) {
                $message = 'Invalid organization UUID provided.';
                $message_type = 'danger';
            } elseif (!currentUserCanDeleteOrganization($org_name)) {
                $message = 'You do not have permission to delete this organization.';
                $message_type = 'danger';
            } else {
                $result = deleteOrganization($org_name);
                if ($result[0]) {
                    $message = 'Organization deleted successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error deleting organization: ' . $result[1];
                    $message_type = 'danger';
                }
            }
        } else {
            // Fallback to name-based deletion (legacy support)
            if (!currentUserCanDeleteOrganization($org_name)) {
                $message = 'You do not have permission to delete this organization.';
                $message_type = 'danger';
            } else {
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
    }
}

// Get list of organizations
$organizations = listOrganizations();

// Ensure CSRF token is generated early
get_csrf_token();



render_header("Organization Management");
render_submenu();

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
        <?php if (currentUserCanCreateOrganization()): ?>
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
                            // Find the form field name for this LDAP attribute
                            $form_field = null;
                            foreach ($LDAP['org_field_mappings'] as $form_name => $ldap_name) {
                                if ($ldap_name === $ldap_attr) {
                                    $form_field = $form_name;
                                    break;
                                }
                            }
                            
                            if ($form_field !== null && isset($LDAP['org_field_labels'][$form_field])) {
                                $label = $LDAP['org_field_labels'][$form_field];
                                $field_type = $LDAP['org_field_types'][$form_field] ?? 'text';
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
                            // Find the form field name for this LDAP attribute
                            $form_field = null;
                            foreach ($LDAP['org_field_mappings'] as $form_name => $ldap_name) {
                                if ($ldap_name === $ldap_attr) {
                                    $form_field = $form_name;
                                    break;
                                }
                            }
                            
                            if ($form_field !== null && isset($LDAP['org_field_labels'][$form_field])) {
                                $label = $LDAP['org_field_labels'][$form_field];
                                $field_type = $LDAP['org_field_types'][$form_field] ?? 'text';
                                
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
                        
                        <!-- Address Fields (dynamically generated from configuration) -->
                        <?php
                        // Generate address fields dynamically using configuration
                        foreach ($LDAP['org_address_fields'] as $field_name => $field_config) {
                            echo "<div class='form-group'>";
                            
                            // Generate label with required indicator if needed
                            $label = $field_config['label'];
                            if ($field_config['required']) {
                                $label .= ' <sup>*</sup>';
                            }
                            
                            echo "<label for='{$field_name}'>{$label}</label>";
                            
                            // Generate input field
                            $required_attr = $field_config['required'] ? ' required' : '';
                            echo "<input type='{$field_config['type']}' class='form-control' id='{$field_name}' name='{$field_name}'{$required_attr}>";
                            
                            echo "</div>";
                        }
                        ?>
                        
                        <button type="submit" class="btn btn-success">Create Organization</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="<?php echo currentUserCanCreateOrganization() ? 'col-md-6' : 'col-md-12'; ?>">
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Existing Organizations</h3>
                </div>
                <div class="panel-body">
                    <?php if (empty($organizations)): ?>
                        <p class="text-muted">No organizations found.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php 
                            // Filter organizations based on user permissions
                            $display_organizations = $organizations;
                            if (!$is_global_admin && !$is_maintainer) {
                                // Organization admins can only see their own organizations
                                $display_organizations = array_filter($organizations, function($org) use ($user_organizations) {
                                    // Extract organization name from DN or use 'o' attribute
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
                                    return in_array($org_name, $user_organizations);
                                });
                            }
                            
                            if (empty($display_organizations)): ?>
                                <p class="text-muted">No organizations found or you don't have permission to view any organizations.</p>
                            <?php else: ?>
                                <?php foreach ($display_organizations as $org): ?>
                                    <?php 
                                    // Extract organization name from DN or use 'o' attribute
                                    $org_name = '';
                                    if (isset($org['o']) && !empty($org['o'])) {
                                        // If 'o' is a DN, extract just the organization name
                                        if (strpos($org['o'], ',') !== false) {
                                            // Extract the organization name from DN like "o=OrgName,ou=organizations,dc=pingu,dc=info"
                                            $org_name = preg_replace('/^o=([^,]+).*$/', '$1', $org['o']);
                                        } else {
                                            $org_name = $org['o'];
                                        }
                                    } elseif (isset($org['dn']) && !empty($org['dn'])) {
                                        // Extract from DN attribute
                                        $org_name = preg_replace('/^o=([^,]+).*$/', '$1', $org['dn']);
                                    } else {
                                        $org_name = 'Unknown Organization';
                                    }
                                    
                                    $org_name_safe = htmlspecialchars($org_name);
                                    $org_name_url = urlencode($org_name);
                                    ?>
                                    <div class="list-group-item">
                                        <h4 class="list-group-item-heading"><?php echo $org_name_safe; ?></h4>
                                        <p class="list-group-item-text">
                                            <?php if (isset($org['mail']) && !empty($org['mail'])): ?>
                                                <strong>Email:</strong> <?php echo htmlspecialchars($org['mail']); ?><br>
                                            <?php endif; ?>
                                            <?php if (isset($org['telephoneNumber']) && !empty($org['telephoneNumber'])): ?>
                                                <strong>Phone:</strong> <?php echo htmlspecialchars($org['telephoneNumber']); ?><br>
                                            <?php endif; ?>
                                            <?php if (isset($org['description']) && !empty($org['description'])): ?>
                                                <strong>Status:</strong> <?php echo htmlspecialchars($org['description']); ?>
                                            <?php endif; ?>
                                        </p>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($LDAP['use_uuid_identification'] && isset($org['entryUUID'])): ?>
                                                <a href="show_organization.php?uuid=<?php echo urlencode($org['entryUUID']); ?>" class="btn btn-info">View</a>
                                                <a href="org_users.php?uuid=<?php echo urlencode($org['entryUUID']); ?>" class="btn btn-primary">Users</a>
                                                <?php if (currentUserCanDeleteOrganization($org_name)): ?>
                                                    <button type="button" class="btn btn-danger" onclick="confirmDelete('<?php echo $org_name_safe; ?>', '<?php echo $org['entryUUID']; ?>')">Delete</button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <a href="show_organization.php?org=<?php echo $org_name_url; ?>" class="btn btn-info">View</a>
                                                <a href="org_users.php?org=<?php echo $org_name_url; ?>" class="btn btn-primary">Users</a>
                                                <?php if (currentUserCanDeleteOrganization($org_name)): ?>
                                                    <button type="button" class="btn btn-danger" onclick="confirmDelete('<?php echo $org_name_safe; ?>')">Delete</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
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
                    <input type="hidden" name="org_uuid" id="deleteOrgUuidInput">
                    <button type="submit" class="btn btn-danger">Delete Organization</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(orgName, orgUuid = null) {
    document.getElementById('deleteOrgName').textContent = orgName;
    document.getElementById('deleteOrgNameInput').value = orgName;
    if (orgUuid) {
        document.getElementById('deleteOrgUuidInput').value = orgUuid;
    }
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