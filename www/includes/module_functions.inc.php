<?php

declare(strict_types=1);

$li_good = "<li class='list-group-item list-group-item-success'>$GOOD_ICON";
$li_warn = "<li class='list-group-item list-group-item-warning'>$WARN_ICON";
$li_fail = "<li class='list-group-item list-group-item-danger'>$FAIL_ICON";
$li_info = "<li class='list-group-item list-group-item-info'>$INFO_ICON";

function render_submenu()
{

    global $THIS_MODULE_PATH, $EMAIL_SENDING_ENABLED;

    // Define submodules based on user permissions
    $submodules = array();

    if (($EMAIL_SENDING_ENABLED ?? false) !== true) {
        ?>
     <div class="alert alert-warning alert-dismissible fade show mb-0" role="alert">
      <p class="text-center mb-0"><?php echo htmlspecialchars(t('manage.submenu.email_disabled'), ENT_QUOTES, 'UTF-8'); ?></p>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></button>
     </div>
        <?php
    }
    ?>
     <nav class="navbar navbar-expand-lg navbar-light bg-light">
      <div class="container-fluid">
       <ul class="navbar-nav">
        <?php
        // Add Organizations link based on user permissions
        if (function_exists('currentUserIsGlobalAdmin') && function_exists('currentUserIsMaintainer') && function_exists('currentUserIsOrgAdmin')) {
          // System administrators can access everything
            if (currentUserIsGlobalAdmin()) {
              // System administrators and maintainers can access users
                $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/users/') !== false) ? ' active' : '';
                print "<li class=\"nav-item\"><a class=\"nav-link$active\" href='/manage/users/'>" . htmlspecialchars(t('manage.submenu.system_users'), ENT_QUOTES, 'UTF-8') . "</a></li>\n";
                $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/organizations/') !== false) ? ' active' : '';
                print "<li class=\"nav-item\"><a class=\"nav-link$active\" href='/manage/organizations/'>" . htmlspecialchars(t('manage.submenu.organizations'), ENT_QUOTES, 'UTF-8') . "</a></li>\n";
              // Add Role Management link (only for system administrators)
                $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/roles/') !== false) ? ' active' : '';
                print "<li class=\"nav-item\"><a class=\"nav-link$active\" href='/manage/roles/'>" . htmlspecialchars(t('manage.submenu.role_management'), ENT_QUOTES, 'UTF-8') . "</a></li>\n";
          // System maintainers can access users and organizations
            } elseif (currentUserIsMaintainer()) {
              // System administrators and maintainers can access users
                $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/users/') !== false) ? ' active' : '';
                print "<li class=\"nav-item\"><a class=\"nav-link$active\" href='/manage/users/'>" . htmlspecialchars(t('manage.submenu.system_users'), ENT_QUOTES, 'UTF-8') . "</a></li>\n";
                $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/organizations/') !== false) ? ' active' : '';
                print "<li class=\"nav-item\"><a class=\"nav-link$active\" href='/manage/organizations/'>" . htmlspecialchars(t('manage.submenu.organizations'), ENT_QUOTES, 'UTF-8') . "</a></li>\n";
          // Organization administrators can only access organizations
            } elseif (currentUserIsOrgAdmin()) {
                $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/organizations/') !== false) ? ' active' : '';
                print "<li class=\"nav-item\"><a class=\"nav-link$active\" href='/manage/organizations/'>" . htmlspecialchars(t('manage.submenu.organizations'), ENT_QUOTES, 'UTF-8') . "</a></li>\n";
            }
        }
        ?>
       </ul>
      </div>
     </nav>
    <?php
}

?>
