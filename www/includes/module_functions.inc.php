<?php
declare(strict_types=1);

$li_good = "<li class='list-group-item list-group-item-success'>$GOOD_ICON";
$li_warn = "<li class='list-group-item list-group-item-warning'>$WARN_ICON";
$li_fail = "<li class='list-group-item list-group-item-danger'>$FAIL_ICON";
$li_info = "<li class='list-group-item list-group-item-info'>$INFO_ICON";

function render_submenu() {

    global $THIS_MODULE_PATH;
  
    // Define submodules based on user permissions
    $submodules = array();
    
    ?>
     <nav class="navbar navbar-default">
      <div class="container-fluid">
       <ul class="nav navbar-nav">
        <?php
        // Add Organizations link based on user permissions
        if (function_exists('currentUserIsGlobalAdmin') && function_exists('currentUserIsMaintainer') && function_exists('currentUserIsOrgAdmin')) {
          // System administrators can access everything
          if (currentUserIsGlobalAdmin()) {
            // System administrators and maintainers can access users
            $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/users/') !== false) ? " class='active'" : "";
            print "<li$active><a href='/manage/users/'>System users</a></li>\n";
            $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/organizations/') !== false) ? " class='active'" : "";
            print "<li$active><a href='/manage/organizations/'>Organizations</a></li>\n";
            // Add Role Management link (only for system administrators)
            $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/roles/') !== false) ? " class='active'" : "";
            print "<li$active><a href='/manage/roles/'>Role Management</a></li>\n";
          }
          // System maintainers can access users and organizations
          elseif (currentUserIsMaintainer()) {
            // System administrators and maintainers can access users
            $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/users/') !== false) ? " class='active'" : "";
            print "<li$active><a href='/manage/users/'>System users</a></li>\n";
            $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/organizations/') !== false) ? " class='active'" : "";
            print "<li$active><a href='/manage/organizations/'>Organizations</a></li>\n";
          }
          // Organization administrators can only access organizations
          elseif (currentUserIsOrgAdmin()) {
            $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/manage/organizations/') !== false) ? " class='active'" : "";
            print "<li$active><a href='/manage/organizations/'>Organizations</a></li>\n";
          }
        }
       ?>
       </ul>
      </div>
     </nav>
    <?php
   }
  
?>
