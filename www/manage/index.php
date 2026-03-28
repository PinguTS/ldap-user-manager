<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage([]);

// Use the enhanced access control function
setPageAccess(["admin", "maintainer", "org_admin"]);

renderHeader(t('manage.dashboard.page_title', ['org' => $ORGANISATION_NAME]));
render_submenu();

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2><?php echo htmlspecialchars(t('manage.dashboard.heading'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="lead"><?php echo htmlspecialchars(t('manage.dashboard.lead'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>
    
    <div class="row">
        <?php if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) : ?>
        <div class="col-md-4">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title mb-0 h5"><?php echo htmlspecialchars(t('manage.dashboard.user_mgmt_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <p><?php echo htmlspecialchars(t('manage.dashboard.user_mgmt_desc'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary"><?php echo htmlspecialchars(t('manage.dashboard.manage_users'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <h3 class="card-title mb-0 h5"><?php echo htmlspecialchars(t('manage.dashboard.role_mgmt_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <p><?php echo htmlspecialchars(t('manage.dashboard.role_mgmt_desc'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/roles/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success"><?php echo htmlspecialchars(t('manage.dashboard.manage_roles'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="col-md-4">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h3 class="card-title mb-0 h5"><?php echo htmlspecialchars(t('manage.dashboard.org_mgmt_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <p><?php echo htmlspecialchars(t('manage.dashboard.org_mgmt_desc'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info"><?php echo htmlspecialchars(t('manage.dashboard.manage_orgs'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (currentUserIsOrgAdmin()) : ?>
    <div class="row">
        <div class="col-md-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-dark">
                    <h3 class="card-title mb-0 h5"><?php echo htmlspecialchars(t('manage.dashboard.quick_access_title'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <p><?php echo htmlspecialchars(t('manage.dashboard.quick_access_lead'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php
                    $org_name = currentUserGetOrgName();
                    $org_uuid = currentUserGetOrgUuid();

                    if ($org_uuid) {
                        echo '<a href="' . htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/', ENT_QUOTES, 'UTF-8') . '" class="btn btn-warning">' . htmlspecialchars(t('manage.dashboard.view_my_org'), ENT_QUOTES, 'UTF-8') . '</a>';
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
renderFooter();
?>
