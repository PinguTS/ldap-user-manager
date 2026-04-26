<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once __DIR__ . '/bootstrap_setup.inc.php';

validateSetupCookie();
setPageAccess(['setup', 'admin']);

$base_dn = (string) ($LDAP['base_dn'] ?? '');
$backend = (string) ($LDAP['backend'] ?? 'mdb');
$bind_dn = (string) ($LDAP['bind_dn'] ?? '');

renderHeader("$ORGANISATION_NAME setup - accesslog enablement");

?>
<div class="container">
  <div class="card">
    <div class="card-header">Accesslog overlay enablement helper (existing LDAP)</div>
    <div class="card-body">
      <p>
        Use this guide when LDAP was initialized before <code>LDAP_BACKEND_OVERLAY_ACCESSLOG=true</code>
        was configured. For existing data, add accesslog retroactively via <code>cn=config</code>.
      </p>

      <div class="alert alert-info">
        <strong>Current setup values</strong><br>
        Base DN: <code><?php print htmlspecialchars($base_dn, ENT_QUOTES, 'UTF-8'); ?></code><br>
        Backend: <code><?php print htmlspecialchars($backend, ENT_QUOTES, 'UTF-8'); ?></code><br>
        App bind DN: <code><?php print htmlspecialchars($bind_dn, ENT_QUOTES, 'UTF-8'); ?></code>
      </div>

      <h5 class="d-flex justify-content-between align-items-center">
        <span>1) Prepare accesslog directory in LDAP container</span>
        <button type="button" class="btn btn-sm btn-outline-secondary js-copy-command" data-target="cmd-step-1">Copy</button>
      </h5>
<pre id="cmd-step-1" class="bg-light p-2 border rounded"><code>docker exec -it ldap-server mkdir -p /var/lib/ldap/accesslog
docker exec -it ldap-server chown openldap:openldap /var/lib/ldap/accesslog</code></pre>

      <h5 class="d-flex justify-content-between align-items-center">
        <span>2) Load accesslog module</span>
        <button type="button" class="btn btn-sm btn-outline-secondary js-copy-command" data-target="cmd-step-2">Copy</button>
      </h5>
<pre id="cmd-step-2" class="bg-light p-2 border rounded"><code>ldapmodify -H ldapi:/// -Y EXTERNAL <<'EOF'
dn: cn=module{0},cn=config
changetype: modify
add: olcModuleLoad
olcModuleLoad: accesslog
EOF</code></pre>

      <h5 class="d-flex justify-content-between align-items-center">
        <span>3) Create accesslog database</span>
        <button type="button" class="btn btn-sm btn-outline-secondary js-copy-command" data-target="cmd-step-3">Copy</button>
      </h5>
<pre id="cmd-step-3" class="bg-light p-2 border rounded"><code>ldapadd -H ldapi:/// -Y EXTERNAL <<'EOF'
dn: olcDatabase={2}mdb,cn=config
objectClass: olcDatabaseConfig
objectClass: olcMdbConfig
olcDatabase: {2}mdb
olcDbDirectory: /var/lib/ldap/accesslog
olcSuffix: cn=accesslog
olcRootDN: cn=admin,cn=accesslog
olcDbIndex: default eq
olcDbIndex: entryCSN,objectClass,reqEnd,reqResult,reqStart
olcAccess: to * by dn.base="cn=admin,<?php print htmlspecialchars($base_dn, ENT_QUOTES, 'UTF-8'); ?>" manage by * none
EOF</code></pre>

      <h5 class="d-flex justify-content-between align-items-center">
        <span>4) Attach accesslog overlay to main database</span>
        <button type="button" class="btn btn-sm btn-outline-secondary js-copy-command" data-target="cmd-step-4">Copy</button>
      </h5>
<pre id="cmd-step-4" class="bg-light p-2 border rounded"><code>ldapadd -H ldapi:/// -Y EXTERNAL <<'EOF'
dn: olcOverlay=accesslog,olcDatabase={1}<?php print htmlspecialchars($backend, ENT_QUOTES, 'UTF-8'); ?>,cn=config
objectClass: olcOverlayConfig
objectClass: olcAccessLogConfig
olcOverlay: accesslog
olcAccessLogDB: cn=accesslog
olcAccessLogOps: writes
olcAccessLogSuccess: TRUE
olcAccessLogPurge: 90+00:00 1+00:00
EOF</code></pre>

      <h5 class="d-flex justify-content-between align-items-center">
        <span>5) Verify access from app bind account</span>
        <button type="button" class="btn btn-sm btn-outline-secondary js-copy-command" data-target="cmd-step-5">Copy</button>
      </h5>
<pre id="cmd-step-5" class="bg-light p-2 border rounded"><code>ldapsearch -x -H ldaps://localhost:636 \
  -D "<?php print htmlspecialchars($bind_dn, ENT_QUOTES, 'UTF-8'); ?>" \
  -w "***" \
  -b "cn=accesslog" -s base "(objectClass=*)" dn \
  -o tls_reqcert=never</code></pre>
      <div id="copy-feedback" class="small text-success mb-3" style="display:none;"></div>

      <div class="alert alert-warning mb-0">
        After applying these commands, restart the web app container and go back to
        <code>/setup/check/</code>. The accesslog check should switch to green.
      </div>
    </div>
  </div>

  <div class="p-3 bg-light rounded mt-3">
    <div class="row">
      <div class="col-md-6">
        <form action="<?php print htmlspecialchars($THIS_MODULE_PATH . '/run_checks.php', ENT_QUOTES, 'UTF-8'); ?>">
          <input type='submit' class="btn btn-primary d-block mx-auto" value='Back to Setup Checks'>
        </form>
      </div>
      <div class="col-md-6">
        <form action="<?php print htmlspecialchars($THIS_MODULE_PATH . '/verify.php', ENT_QUOTES, 'UTF-8'); ?>">
          <input type='submit' class="btn btn-info d-block mx-auto" value='Verify Setup'>
        </form>
      </div>
    </div>
  </div>
</div>
<?php

renderFooter();

?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var buttons = document.querySelectorAll('.js-copy-command');
  var feedback = document.getElementById('copy-feedback');

  function setFeedback(message, isError) {
    if (!feedback) {
      return;
    }
    feedback.textContent = message;
    feedback.classList.remove('text-success', 'text-danger');
    feedback.classList.add(isError ? 'text-danger' : 'text-success');
    feedback.style.display = 'block';
    window.setTimeout(function () {
      feedback.style.display = 'none';
    }, 1800);
  }

  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      var targetId = button.getAttribute('data-target');
      if (!targetId) {
        setFeedback('Copy failed.', true);
        return;
      }
      var block = document.getElementById(targetId);
      if (!block) {
        setFeedback('Copy failed.', true);
        return;
      }
      var text = block.innerText || block.textContent || '';
      navigator.clipboard.writeText(text).then(function () {
        setFeedback('Copied to clipboard.', false);
      }).catch(function () {
        setFeedback('Copy failed. Please copy manually.', true);
      });
    });
  });
});
</script>
<?php

