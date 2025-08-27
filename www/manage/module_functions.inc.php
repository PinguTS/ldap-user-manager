<?php
declare(strict_types=1);

##################################

function render_submenu() {

  global $THIS_MODULE_PATH;

  // Define submodules based on user permissions
  $submodules = array();
  
  // System administrators and maintainers can access users
  if (function_exists('currentUserIsGlobalAdmin') && function_exists('currentUserIsMaintainer') && 
      (currentUserIsGlobalAdmin() || currentUserIsMaintainer())) {
    $submodules['users'] = 'users/';
  }
  
  ?>
   <nav class="navbar navbar-default">
    <div class="container-fluid">
     <ul class="nav navbar-nav">
      <?php
      foreach (
        $submodules as $submodule => $path) {

       if (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/users/') !== false) {
        print "<li class='active'>";
       }
       else {
        print '<li>';
       }
       print "<a href='/manage/{$path}'>" . ucwords($submodule) . "</a></li>\n";

      }
      // Add Organizations link based on user permissions
      if (function_exists('currentUserIsGlobalAdmin') && function_exists('currentUserIsMaintainer') && function_exists('currentUserIsOrgAdmin')) {
        // System administrators can access everything
        if (currentUserIsGlobalAdmin()) {
          $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/organizations/') !== false) ? " class='active'" : "";
          print "<li$active><a href='/manage/organizations/'>Organizations</a></li>\n";
          // Add Role Management link (only for system administrators)
          $active_roles = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/roles/') !== false) ? " class='active'" : "";
          print "<li$active_roles><a href='/manage/roles/'>Role Management</a></li>\n";
        }
        // System maintainers can access users and organizations
        elseif (currentUserIsMaintainer()) {
          $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/organizations/') !== false) ? " class='active'" : "";
          print "<li$active><a href='/manage/organizations/'>Organizations</a></li>\n";
        }
        // Organization administrators can only access organizations
        elseif (currentUserIsOrgAdmin()) {
          $active = (basename($_SERVER['SCRIPT_FILENAME']) == 'index.php' && strpos($_SERVER['SCRIPT_FILENAME'], '/organizations/') !== false) ? " class='active'" : "";
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
