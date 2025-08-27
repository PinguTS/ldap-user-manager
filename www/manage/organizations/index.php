<?php
declare(strict_types=1);

set_include_path( ".:" . __DIR__ . "/../../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once dirname(__DIR__) . "/module_functions.inc.php";
include_once "organization_functions.inc.php";

// Use the enhanced access control function
set_page_access(["admin", "maintainer", "org_admin"]);

// Get user's access level for UI customization
$is_global_admin = currentUserIsGlobalAdmin();
$is_maintainer = currentUserIsMaintainer();
$user_organizations = [];

// If user is an organization admin, get their organizations
if (!$is_global_admin && !$is_maintainer) {
    // For organization admins, we need to find which organizations they manage
    // This is a bit complex since we need to search through all organizations
    $all_orgs = listOrganizations();
    foreach ($all_orgs as $org) {
        if (currentUserIsOrgManager($org['name'])) {
            $user_organizations[] = $org['name'];
        }
    }
}

render_header("$ORGANISATION_NAME - Organization Management");
render_submenu();

// Get all organizations for display
$organizations = listOrganizations();
if (!is_array($organizations)) {
    $organizations = [];
}

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>Organization Management</h2>
            
            <?php if (currentUserCanCreateOrganization()): ?>
            <div class="row mb-3">
                <div class="col-md-12">
                    <a href="/manage/organizations/add.php" class="btn btn-success">
                        <i class="glyphicon glyphicon-plus"></i> Add New Organization
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
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
                                    // Use the organization name directly
                                    $org_name = $org['name'] ?? 'Unknown';
                                    return in_array($org_name, $user_organizations);
                                });
                            }
                            
                            if (empty($display_organizations)): ?>
                                <p class="text-muted">No organizations found or you don't have permission to view any organizations.</p>
                            <?php else: ?>
                                <?php foreach ($display_organizations as $org): ?>
                                    <?php 
                                    // Use the organization name directly from the listOrganizations result
                                    $org_name = $org['name'] ?? 'Unknown Organization';
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
                                                <a href="/manage/organizations/show/index.php?uuid=<?php echo urlencode($org['entryUUID']); ?>" class="btn btn-info">View</a>
                                                <a href="/manage/organizations/users/index.php?uuid=<?php echo urlencode($org['entryUUID']); ?>" class="btn btn-primary">Users</a>
                                                <?php if (currentUserCanDeleteOrganization($org_name)): ?>
                                                    <button type="button" class="btn btn-danger" onclick="confirmDelete('<?php echo $org_name_safe; ?>', '<?php echo $org['entryUUID']; ?>')">Delete</button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <a href="/manage/organizations/show/index.php?org=<?php echo $org_name_url; ?>" class="btn btn-info">View</a>
                                                <a href="/manage/organizations/users/index.php?org=<?php echo $org_name_url; ?>" class="btn btn-primary">Users</a>
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
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">Confirm Organization Deletion</h4>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the organization "<span id="deleteOrgName"></span>"?</p>
                <p class="text-danger"><strong>Warning:</strong> This action cannot be undone and will remove all associated users and data.</p>
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

<script src="/js/jquery-3.6.0.min.js"></script>
<script src="/js/user_management.min.js"></script>
<script>
    // Initialize common user management page functionality
    document.addEventListener('DOMContentLoaded', function() {
        initializeUserManagementPage({
            searchInputId: 'org_search_input',
            tableId: 'org_table',
            messageId: 'msgbox'
        });
    });
</script>

<?php
render_footer();
?> 
