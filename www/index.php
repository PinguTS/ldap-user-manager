<?php

set_include_path( ".:" . __DIR__ . "/includes/");
include_once "web_functions.inc.php";
include_once "access_functions.inc.php";

// Use the enhanced access control function
// The main index should be accessible to all authenticated users
// but the function will automatically redirect users to appropriate default views
set_page_access("user");

render_header();

 if (isset($_GET['logged_in'])) {
 ?>
 <div class="alert alert-success">
 <p class="text-center">You're logged in. Select from the menu above.</p>
 </div>
 <?php
 }

render_footer();
?>
