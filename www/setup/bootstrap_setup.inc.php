<?php

/**
 * Setup bootstrap: when setup is locked (LDAP configured successfully), show
 * only a minimal "Setup complete" page and exit. No LDAP connection, no
 * verification details, no links to run_checks/ldap/verify.
 *
 * Must be included after web_functions.inc.php (so render_header, render_footer,
 * get_base_url / $SERVER_PATH are available).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/setup_lock.inc.php';

if (!is_setup_locked()) {
    return;
}

render_header('Account manager – Setup');
?>
<div class="container">
  <div class="card border-success">
    <div class="card-header">Setup complete</div>
    <div class="card-body">
      <p class="mb-0">Setup was successful. Use the application login to access the system.</p>
    </div>
  </div>
  <div class="p-3 bg-light rounded">
    <form action="<?php print get_base_url(); ?>log_in">
      <input type="submit" class="btn btn-success d-block mx-auto" value="Go to Login">
    </form>
  </div>
</div>
<?php
render_footer();
exit;
