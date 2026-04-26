<?php

/**
 * Load shared manage-area includes. Call after set_include_path() to includes directory.
 * Config and modules are loaded at file level so they run in the caller's (global) scope;
 * otherwise they would be loaded inside bootstrapManage() and $LDAP etc. would be local only.
 */

declare(strict_types=1);

include_once __DIR__ . '/config.inc.php';
include_once __DIR__ . '/modules.inc.php';

/**
 * @param array $modules Optional list of modules to load: 'ldap', 'organization', 'user', 'mail'
 */
function bootstrapManage(array $modules = []): void
{
    if (!defined('LUM_MANAGE_CONTEXT')) {
        define('LUM_MANAGE_CONTEXT', true);
    }
    $inc = __DIR__ . '/';
    include_once $inc . 'web_functions.inc.php';
    include_once $inc . 'access_functions.inc.php';
    include_once $inc . 'user_table_helpers.inc.php';
    include_once $inc . 'module_functions.inc.php';
    if (in_array('password_reset', $modules, true)) {
        include_once $inc . 'password_reset_functions.inc.php';
    }
    if (in_array('ldap', $modules, true)) {
        include_once $inc . 'ldap_functions.inc.php';
    }
    if (in_array('organization', $modules, true)) {
        include_once $inc . 'organization_functions.inc.php';
        include_once $inc . 'org_config_functions.inc.php';
        include_once $inc . 'org_user_helpers.inc.php';
        include_once $inc . 'org_audit_functions.inc.php';
    }
    if (in_array('user', $modules, true)) {
        include_once $inc . 'user_functions.inc.php';
    }
    if (in_array('mail', $modules, true)) {
        include_once $inc . 'mail_functions.inc.php';
    }
    if (in_array('ldap', $modules, true) && function_exists('lumEnforceManageSessionAccountActive')) {
        lumEnforceManageSessionAccountActive();
    }
}
