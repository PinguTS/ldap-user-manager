<?php

set_include_path( ".:" . __DIR__ . "/../includes/");
include_once "web_functions.inc.php";
include_once "access_functions.inc.php";
include_once "module_functions.inc.php";

// Use the enhanced access control function
set_page_access(["admin", "maintainer", "org_admin"]);

render_header("$ORGANISATION_NAME - Management Dashboard");
render_submenu();

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>Management Dashboard</h2>
            <p class="lead">Welcome to the management dashboard. Select an area to manage:</p>
        </div>
    </div>
    
    <div class="row">
        <?php if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()): ?>
        <div class="col-md-4">
            <div class="panel panel-primary">
                <div class="panel-heading">
                    <h3 class="panel-title">User Management</h3>
                </div>
                <div class="panel-body">
                    <p>Manage system users, roles, and permissions.</p>
                    <a href="users/" class="btn btn-primary">Manage Users</a>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="panel panel-success">
                <div class="panel-heading">
                    <h3 class="panel-title">Role Management</h3>
                </div>
                <div class="panel-body">
                    <p>Manage system roles and user assignments.</p>
                    <a href="roles/" class="btn btn-success">Manage Roles</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="col-md-4">
            <div class="panel panel-info">
                <div class="panel-heading">
                    <h3 class="panel-title">Organization Management</h3>
                </div>
                <div class="panel-body">
                    <p>Manage organizations and their users.</p>
                    <a href="organizations/" class="btn btn-info">Manage Organizations</a>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (currentUserIsOrgAdmin()): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-warning">
                <div class="panel-heading">
                    <h3 class="panel-title">Quick Access</h3>
                </div>
                <div class="panel-body">
                    <p>As an organization administrator, you can quickly access your organization's management area:</p>
                    <?php
                    $org_name = currentUserGetOrgName();
                    $org_uuid = currentUserGetOrgUuid();
                    
                    if ($org_uuid) {
                        echo '<a href="organizations/show/index.php?uuid=' . urlencode($org_uuid) . '" class="btn btn-warning">View My Organization</a>';
                    } elseif ($org_name) {
                        echo '<a href="organizations/show/index.php?org=' . urlencode($org_name) . '" class="btn btn-warning">View My Organization</a>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
render_footer();
?>
