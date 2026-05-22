<?php

declare(strict_types=1);

set_include_path('.:' . __DIR__ . '/../includes/');

include_once 'web_functions.inc.php';
include_once 'ldap_functions.inc.php';
include_once __DIR__ . '/../includes/setup_acl_functions.inc.php';
include_once __DIR__ . '/bootstrap_setup.inc.php';

validateSetupCookie();
setPageAccess(['setup', 'admin']);

$mdbDn  = setupOlcMdbDn();
$baseDn = (string) ($LDAP['base_dn'] ?? '');
$orgDn  = (string) ($LDAP['org_dn'] ?? '');

$baselineDone         = false;
$baselineError        = '';
$baselineInsufficient = false;

$roleDone         = false;
$roleError        = '';
$roleInsufficient = false;

// Read the current ACL state for informational display (before any POST action)
$connForVerify        = open_ldap_connection();
$currentAclVerify     = ($connForVerify !== false)
    ? setupVerifyRoleBasedAcls($connForVerify)
    : null;

if (isset($_POST['apply_lum_user_bind_acls']) && $baseDn !== '' && $mdbDn !== '') {
    $conn = open_ldap_connection();
    if ($conn !== false) {
        $baselineDone = setupApplyUserBindAclsToMdb($conn, $mdbDn, $baseDn, $orgDn);
        if ($baselineDone) {
            if (function_exists('auditLog')) {
                auditLog('INFO', 'apply_user_bind_acls: baseline ACLs applied or already present', ['mdbDn' => $mdbDn]);
            }
        } else {
            $baselineError        = (is_resource($conn) || (is_object($conn) && $conn instanceof \LDAP\Connection)) ? ldap_error($conn) : 'LDAP error';
            $baselineInsufficient = stripos($baselineError, 'insufficient access') !== false;
        }
    }
}

if (isset($_POST['apply_lum_role_acls']) && $baseDn !== '' && $mdbDn !== '') {
    $conn = open_ldap_connection();
    if ($conn !== false) {
        $roleDone = setupApplyRoleBasedAclsToMdb($conn, $mdbDn);
        if ($roleDone) {
            if (function_exists('auditLog')) {
                auditLog('INFO', 'apply_user_bind_acls: role-based ACLs applied or already present', ['mdbDn' => $mdbDn]);
            }
        } else {
            $roleError        = (is_resource($conn) || (is_object($conn) && $conn instanceof \LDAP\Connection)) ? ldap_error($conn) : 'LDAP error';
            $roleInsufficient = stripos($roleError, 'insufficient access') !== false;
        }
    }
}

// --- build ldapmodify copy/paste blocks ---

// baseline ldif
$orgExtraLdif = '';
if ($orgDn !== '' && $baseDn !== '' && setupShouldAddExplicitOrgSubtreeUserRead($baseDn, $orgDn)) {
    $orgExtraLdif = "-\nadd: olcAccess\nolcAccess: to dn.subtree=\"{$orgDn}\" by users read by * break\n";
}
$baselineLdifBody = "dn: {$mdbDn}\nchangetype: modify\nadd: olcAccess\nolcAccess: to attrs=userPassword,shadowLastChange by self write by anonymous auth by * break\n-\nadd: olcAccess\nolcAccess: to dn.subtree=\"{$baseDn}\" by users read by * break\n{$orgExtraLdif}";
$externalBaselineCmd    = "docker exec -it ldap-server sh -lc 'ldapmodify -Y EXTERNAL -H ldapi:/// <<EOF\n{$baselineLdifBody}EOF'";
$configAdminBaselineCmd = "ldapmodify -x -H ldaps://ldap-server:636 -D \"cn=admin,cn=config\" -w \"<LDAP_CONFIG_PASSWORD>\" <<EOF\n{$baselineLdifBody}EOF";

// role-based ldif
$roleAclLdifBody    = setupBuildRoleBasedAclLdif($mdbDn);
$externalRoleCmd    = "docker exec -it ldap-server sh -lc 'ldapmodify -Y EXTERNAL -H ldapi:/// <<'\"'\"'EOF'\"'\"'\n{$roleAclLdifBody}EOF'";
$configAdminRoleCmd = "ldapmodify -x -H ldaps://ldap-server:636 -D \"cn=admin,cn=config\" -w \"<LDAP_CONFIG_PASSWORD>\" <<'EOF'\n{$roleAclLdifBody}EOF";

renderHeader("{$ORGANISATION_NAME} — user-bind ACLs");

?>
<div class="container">

  <!-- ================================================================ -->
  <!-- SECTION 1: Baseline (read + self password)                       -->
  <!-- ================================================================ -->
  <div class="card mb-4">
    <div class="card-header">
      Step 1 — Baseline olcAccess: authenticated read + self password write
    </div>
    <div class="card-body">
      <p>
        Appends (if missing) two <code>olcAccess</code> rules on
        <code><?php echo htmlspecialchars($mdbDn, ENT_QUOTES, 'UTF-8'); ?></code>:
      </p>
      <ul class="mb-2">
        <li>Every user can write their own <code>userPassword</code> and <code>shadowLastChange</code>.</li>
        <li>Every authenticated bind can <em>read</em> the entire base subtree (required for login and role resolution).</li>
        <?php if ($orgExtraLdif !== '') : ?>
        <li>Same read rule explicitly for
          <code><?php echo htmlspecialchars($orgDn, ENT_QUOTES, 'UTF-8'); ?></code>
          (specificity helper for your installation).
        </li>
        <?php endif; ?>
      </ul>
      <p class="text-muted small mb-2">
        These rules are a safe starting point. They do not grant role-specific write access — apply
        Step&nbsp;2 for that. Set <code>LDAP_OLC_MDB_DN</code> if your database is not
        <code>olcDatabase={1}mdb</code>.
      </p>

      <?php if ($baselineDone) : ?>
        <div class="alert alert-success">Baseline rules applied (or already present).
          <a href="run_checks.php">Re-run checks</a>.
        </div>
      <?php elseif (isset($_POST['apply_lum_user_bind_acls']) && $baselineError !== '') : ?>
        <div class="alert alert-danger">ldap_mod_add failed: <?php echo htmlspecialchars($baselineError, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php if ($baselineInsufficient) : ?>
          <div class="alert alert-warning">
            <p class="mb-2"><strong>App bind cannot modify <code>cn=config</code>.</strong> Use one of the commands below instead, then <a href="run_checks.php">re-run checks</a>.</p>
            <p class="mb-1"><strong>Option A — EXTERNAL (osixia/openldap, recommended)</strong></p>
            <pre class="bg-light p-2 border rounded small"><code><?php echo htmlspecialchars($externalBaselineCmd, ENT_QUOTES, 'UTF-8'); ?></code></pre>
            <p class="mb-1"><strong>Option B — config admin over LDAPS</strong></p>
            <pre class="bg-light p-2 border rounded small"><code><?php echo htmlspecialchars($configAdminBaselineCmd, ENT_QUOTES, 'UTF-8'); ?></code></pre>
          </div>
          <?php endif; ?>
      <?php endif; ?>

      <form method="post" action="">
        <input type="hidden" name="apply_lum_user_bind_acls" value="1">
        <button type="submit" class="btn btn-primary" <?php echo $baseDn === '' ? 'disabled' : ''; ?>>Apply baseline (idempotent)</button>
        <a class="btn btn-link" href="run_checks.php">Back to run checks</a>
      </form>

      <div class="mt-3">
        <details>
          <summary class="text-muted small">Show copy/paste LDIF (Option A — EXTERNAL)</summary>
          <pre class="bg-light p-2 border rounded small mt-2"><code><?php echo htmlspecialchars($externalBaselineCmd, ENT_QUOTES, 'UTF-8'); ?></code></pre>
        </details>
        <details class="mt-1">
          <summary class="text-muted small">Show copy/paste LDIF (Option B — config admin)</summary>
          <pre class="bg-light p-2 border rounded small mt-2"><code><?php echo htmlspecialchars($configAdminBaselineCmd, ENT_QUOTES, 'UTF-8'); ?></code></pre>
        </details>
      </div>
    </div>
  </div>

  <!-- ================================================================ -->
  <!-- SECTION 2: Role-based write ACLs                                 -->
  <!-- ================================================================ -->
  <div class="card mb-4">
    <div class="card-header">
      Step 2 — Role-based olcAccess: write by group membership
    </div>
    <div class="card-body">
      <?php
        $hasLegacyBlocking = $currentAclVerify['has_legacy_blocking'] ?? false;
        $isReachable       = $currentAclVerify['reachable'] ?? false;
        $allPresent        = $currentAclVerify['all_present'] ?? false;
        if ($hasLegacyBlocking) : ?>
        <div class="alert alert-warning">
          <strong>Legacy blocking rules detected.</strong>
          Your current <code>olcAccess</code> configuration contains one or more rules ending
          with <code>by&nbsp;*&nbsp;none</code> (the default osixia/openldap rules). These
          terminate the ACL chain and prevent any LUM rules appended after them from ever being
          evaluated — which is why reads/writes with user-bind appear to fail silently.
          <br><br>
          Clicking <strong>Apply role-based ACLs</strong> will <strong>replace</strong>
          (not append to) the entire <code>olcAccess</code> attribute, removing the legacy
          blocking rules and installing the correct LUM rule set. The UNIX-socket EXTERNAL
          manage rule is preserved automatically.
        </div>
        <?php elseif ($allPresent && !$isReachable) : ?>
        <div class="alert alert-warning">
          <strong>All LUM rules are present, but some are unreachable.</strong>
          A rule earlier in the list ends with <code>by&nbsp;*&nbsp;none</code> and shadows the
          LUM rules. Click <strong>Apply role-based ACLs</strong> to restructure the rule set
          into the correct order.
        </div>
        <?php endif; ?>
      <p>
        Applies <code>olcAccess</code> rules that grant write access based on LDAP
        <code>groupOfNames</code> membership — so each role can bind as itself and the
        directory enforces what it may change, without relying on the app service account for writes.
      </p>
      <p>Rules applied (filled from current environment):</p>
      <ol class="small mb-2">
        <?php
        $roleRules = ($baseDn !== '')
            ? setupBuildRoleBasedAclSet(
                $baseDn,
                (string) ($LDAP['org_ou'] ?? 'organizations'),
                (string) ($LDAP['admin_role'] ?? 'administrators'),
                (string) ($LDAP['maintainer_role'] ?? 'maintainers'),
                (string) ($LDAP['org_admin_role'] ?? 'org_admin')
            )
            : [];
        foreach ($roleRules as $rule) :
            echo '<li><code>' . htmlspecialchars($rule, ENT_QUOTES, 'UTF-8') . '</code></li>' . "\n";
        endforeach;
        ?>
      </ol>
      <p class="text-muted small mb-2">
        Uses <code>group/groupOfNames/member</code> for global roles and
        <code>group/groupOfNames/member.expand</code> with <code>dn.regex</code> for per-org admins
        (OpenLDAP 2.3+). Rules 1–5 use <code>by&nbsp;*&nbsp;break</code>; rule&nbsp;6 ends with
        <code>by&nbsp;*&nbsp;none</code> (terminal deny for anonymous/non-authenticated access).
        The LDAP rootDN (<code>cn=admin,...</code>) bypasses ACLs entirely — it does not need an
        explicit grant. See <code>docs/ldap/userbind-acls.md</code> for full explanation.
      </p>
      <?php if ($hasLegacyBlocking || (!$allPresent && $currentAclVerify !== null && count($currentAclVerify['present']) > 0)) : ?>
      <p class="text-muted small mb-2">
        <strong>Note:</strong> the <em>Apply</em> button will use <code>replace: olcAccess</code>
        (not individual <code>add:</code> operations) because legacy or partial rules are present.
        The copy/paste LDIF below also uses <code>replace:</code>.
      </p>
      <?php endif; ?>


      <?php if ($roleDone) : ?>
        <div class="alert alert-success">Role-based rules applied (or already present).
          <a href="run_checks.php">Re-run checks</a>.
        </div>
      <?php elseif (isset($_POST['apply_lum_role_acls']) && $roleError !== '') : ?>
        <div class="alert alert-danger">ldap_mod_add failed: <?php echo htmlspecialchars($roleError, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php if ($roleInsufficient) : ?>
          <div class="alert alert-warning">
            <p class="mb-2"><strong>App bind cannot modify <code>cn=config</code>.</strong> Use one of the commands below instead, then <a href="run_checks.php">re-run checks</a>.</p>
            <p class="mb-1"><strong>Option A — EXTERNAL (osixia/openldap, recommended)</strong></p>
            <pre class="bg-light p-2 border rounded small"><code><?php echo htmlspecialchars($externalRoleCmd, ENT_QUOTES, 'UTF-8'); ?></code></pre>
            <p class="mb-1"><strong>Option B — config admin over LDAPS</strong></p>
            <pre class="bg-light p-2 border rounded small"><code><?php echo htmlspecialchars($configAdminRoleCmd, ENT_QUOTES, 'UTF-8'); ?></code></pre>
          </div>
          <?php endif; ?>
      <?php endif; ?>

      <form method="post" action="">
        <input type="hidden" name="apply_lum_role_acls" value="1">
        <button type="submit" class="btn btn-primary" <?php echo $baseDn === '' ? 'disabled' : ''; ?>>Apply role-based ACLs (idempotent)</button>
        <a class="btn btn-link" href="run_checks.php">Back to run checks</a>
      </form>

      <div class="mt-3">
        <details>
          <summary class="text-muted small">Show copy/paste LDIF (Option A — EXTERNAL)</summary>
          <pre class="bg-light p-2 border rounded small mt-2"><code><?php echo htmlspecialchars($externalRoleCmd, ENT_QUOTES, 'UTF-8'); ?></code></pre>
        </details>
        <details class="mt-1">
          <summary class="text-muted small">Show copy/paste LDIF (Option B — config admin)</summary>
          <pre class="bg-light p-2 border rounded small mt-2"><code><?php echo htmlspecialchars($configAdminRoleCmd, ENT_QUOTES, 'UTF-8'); ?></code></pre>
        </details>
      </div>
    </div>
  </div>

</div>
<?php
renderFooter();
