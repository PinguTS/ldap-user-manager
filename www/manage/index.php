<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage([]);

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
        <?php if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) : ?>
        <div class="col-md-4">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title mb-0 h5">User Management</h3>
                </div>
                <div class="card-body">
                    <p>Manage system users, roles, and permissions.</p>
                    <a href="/manage/users/" class="btn btn-primary">Manage Users</a>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h3 class="card-title mb-0 h5">Role Management</h3>
                </div>
                <div class="card-body">
                    <p>Manage system roles and user assignments.</p>
                    <a href="/manage/roles/" class="btn btn-success">Manage Roles</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="col-md-4">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h3 class="card-title mb-0 h5">Organization Management</h3>
                </div>
                <div class="card-body">
                    <p>Manage organizations and their users.</p>
                    <a href="/manage/organizations/" class="btn btn-info">Manage Organizations</a>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (currentUserIsOrgAdmin()) : ?>
    <div class="row">
        <div class="col-md-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h3 class="card-title mb-0 h5">Quick Access</h3>
                </div>
                <div class="card-body">
                    <p>As an organization administrator, you can quickly access your organization's management area:</p>
                    <?php
                    $org_name = currentUserGetOrgName();
                    $org_uuid = currentUserGetOrgUuid();

                    if ($org_uuid) {
                        echo '<a href="/manage/organizations/' . urlencode($org_uuid) . '/" class="btn btn-warning">View My Organization</a>';
                    } elseif ($org_name) {
                        // Canonical routing is UUID-only; if UUID is missing, do not link by name.
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
