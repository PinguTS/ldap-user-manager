<?php

/**
 * Setup bootstrap: when setup is locked (LDAP configured successfully), show
 * only a minimal "Setup complete" page and exit. No LDAP connection, no
 * verification details, no links to run_checks/ldap/verify.
 *
 * Must be included after web_functions.inc.php (so renderHeader, renderFooter,
 * getBaseUrl / $SERVER_PATH are available).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/setup_lock.inc.php';

if (!is_setup_locked()) {
    return;
}

renderHeader('Account manager – Setup');
?>
<div class="container">
  <div class="card border-success">
    <div class="card-header">Setup complete</div>
    <div class="card-body">
      <p class="mb-0">Setup was successful. Use the application login to access the system.</p>
    </div>
  </div>
  <div class="p-3 bg-light rounded">
    <form action="<?php print getBaseUrl(); ?>login/">
      <input type="submit" class="btn btn-success d-block mx-auto" value="Go to Login">
    </form>
  </div>
</div>
<?php
renderFooter();
exit;
