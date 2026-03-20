<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrap_manage(['ldap', 'organization']);

// Ensure session is started and CSRF token is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Refresh session activity to prevent timeout
$_SESSION['last_activity'] = time();



get_csrf_token();

$res = resolve_organization_from_request();
if ($res['error'] !== null) {
    render_alert_banner($res['error'], "warning");
    render_footer();
    exit(0);
}
$org_name = $res['org_name'] ?? '';
$org_uuid = $res['org_uuid'] ?? '';
$organization_by_uuid = $res['organization'];

// Use the enhanced access control function
set_page_access(["admin", "maintainer", "org_admin"]);

// Check if user can modify this organization
$can_modify_org = currentUserCanModifyOrganization($org_name);

render_header((string) $ORGANISATION_NAME . ' ' . t('manage.orgs.show.account_manager'));
render_submenu();

// Handle org deletion action
if (isset($_POST['action']) && $_POST['action'] === 'delete_organization') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        render_alert_banner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validate_csrf_token()) {
        render_alert_banner(t('manage.common.msg.security_validation_failed'), "danger");
    } else {
        $ldap_connection_action = open_ldap_connection();
        if ($ldap_connection_action === false) {
            render_alert_banner(t('manage.orgs.msg.ldap_fail'), "danger");
        } else {
            if (currentUserCanDeleteOrganization($org_name)) {
                $posted_uuid = isset($_POST['org_uuid']) ? trim((string) $_POST['org_uuid']) : '';
                if (ldap_delete_organization($ldap_connection_action, $org_name, $posted_uuid)) {
                    ldap_close($ldap_connection_action);
                    header('Location: /manage/organizations/');
                    exit;
                }
                render_alert_banner(
                    t('manage.orgs.show.msg.delete_fail', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                    "danger",
                    15000
                );
            } else {
                render_alert_banner(t('manage.orgs.show.msg.permission_delete_org'), "danger");
            }
            ldap_close($ldap_connection_action);
        }
    }
}

// Handle org lock/unlock actions (disable/enable org)
if (isset($_POST['action']) && ($_POST['action'] === 'lock_organization' || $_POST['action'] === 'unlock_organization')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        render_alert_banner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validate_csrf_token()) {
        render_alert_banner(t('manage.common.msg.security_validation_failed'), "danger");
    } else {
        $ldap_connection_action = open_ldap_connection();
        if ($ldap_connection_action === false) {
            render_alert_banner(t('manage.orgs.msg.ldap_fail'), "danger");
        } else {
            if ($_POST['action'] === 'lock_organization') {
                if (currentUserCanDisableOrganization($org_name) && ldap_lock_organization($ldap_connection_action, $org_name)) {
                    render_alert_banner(
                        t('manage.orgs.show.msg.lock_ok', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                        "success"
                    );
                } else {
                    render_alert_banner(
                        t('manage.orgs.show.msg.lock_fail', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                        "danger",
                        15000
                    );
                }
            } elseif ($_POST['action'] === 'unlock_organization') {
                if (currentUserCanEnableOrganization($org_name) && ldap_unlock_organization($ldap_connection_action, $org_name)) {
                    render_alert_banner(
                        t('manage.orgs.show.msg.unlock_ok', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                        "success"
                    );
                } else {
                    render_alert_banner(
                        t('manage.orgs.show.msg.unlock_fail', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                        "danger",
                        15000
                    );
                }
            }
            ldap_close($ldap_connection_action);
        }
    }
}

// Handle org membership actions (member/unmember org)
if (isset($_POST['action']) && ($_POST['action'] === 'member_organization' || $_POST['action'] === 'unmember_organization')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        render_alert_banner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validate_csrf_token()) {
        render_alert_banner(t('manage.common.msg.security_validation_failed'), "danger");
    } elseif (!(currentUserIsGlobalAdmin() || currentUserIsMaintainer())) {
        render_alert_banner(t('manage.orgs.show.msg.permission_modify_membership'), "danger");
    } else {
        $ldap_connection_action = open_ldap_connection();
        if ($ldap_connection_action === false) {
            render_alert_banner(t('manage.orgs.msg.ldap_fail'), "danger");
        } else {
            $base_dn = $LDAP['base_dn'] ?? '';
            $member_group_cn_post = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
            $org_dn_action = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];

            if ($base_dn === '' || !function_exists('addToStatusGroup') || !function_exists('removeFromStatusGroup')) {
                render_alert_banner(t('manage.orgs.show.msg.membership_status_config_missing'), "danger", 15000);
            } else {
                if ($_POST['action'] === 'member_organization') {
                    if (addToStatusGroup($ldap_connection_action, $org_dn_action, $member_group_cn_post, $base_dn)) {
                        render_alert_banner(
                            t('manage.orgs.show.msg.member_ok', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                            "success"
                        );
                    } else {
                        render_alert_banner(
                            t('manage.orgs.show.msg.member_fail', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                            "danger",
                            15000
                        );
                    }
                } else {
                    if (removeFromStatusGroup($ldap_connection_action, $org_dn_action, $member_group_cn_post, $base_dn)) {
                        render_alert_banner(
                            t('manage.orgs.show.msg.unmember_ok', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                            "success"
                        );
                    } else {
                        render_alert_banner(
                            t('manage.orgs.show.msg.unmember_fail', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                            "danger",
                            15000
                        );
                    }
                }
            }
            ldap_close($ldap_connection_action);
        }
    }
}

// Handle organization updates
if (isset($_POST['update_organization'])) {
    // Check if session is still valid
    if (session_status() !== PHP_SESSION_ACTIVE) {
        render_alert_banner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validate_csrf_token()) {
        render_alert_banner(t('manage.common.msg.security_validation_failed'), "danger");
    } elseif (!$can_modify_org) {
        render_alert_banner(t('manage.orgs.show.msg.permission_modify_org'), "danger");
    } else {
        $base_dn = $LDAP['base_dn'] ?? '';
        $can_edit_membership_meta = currentUserIsGlobalAdmin() || currentUserIsMaintainer();
        // Use the same field mapping logic as organization creation
        $org_data = [];

        // Map form fields to LDAP attributes using the configuration
        foreach ($LDAP['org_field_mappings'] as $form_field => $ldap_attr) {
            // Organization name (o) is handled separately: LDAP rename + permission checks
            if ($form_field === 'org_name') {
                continue;
            }
            if (isset($_POST[$form_field])) {
                $val = trim((string) $_POST[$form_field]);
                // Allow clearing optional fields by sending an empty value
                if ($val !== '' || in_array($ldap_attr, $LDAP['org_optional_fields'], true)) {
                    $org_data[$ldap_attr] = $val;
                }
            }
        }

        if (!$can_edit_membership_meta) {
            unset($org_data['memberNumber'], $org_data['memberSince'], $org_data['memberUntil']);
        }
        // Special handling for postalAddress from individual address fields
        // These fields are not in the LDAP schema but are used for form input
        $address_fields = ['org_address', 'org_zip', 'org_city', 'org_state', 'org_country'];
        $address_in_post = false;
        foreach ($address_fields as $field) {
            if (isset($_POST[$field])) {
                $address_in_post = true;
                break;
            }
        }
        if ($address_in_post) {
            $postal_parts = [
                trim($_POST['org_address'] ?? ''),
                trim($_POST['org_zip'] ?? ''),
                trim($_POST['org_city'] ?? ''),
                trim($_POST['org_state'] ?? ''),
                trim($_POST['org_country'] ?? '')
            ];
            $postal_address = implode('$', $postal_parts);
            if (trim(str_replace('$', '', $postal_address)) !== '') {
                $org_data['postalAddress'] = $postal_address;
            } else {
                $org_data['postalAddress'] = '';
            }
        }

        // Membership metadata mapping:
        // Store member number / member since in LDAP's `documentIdentifier` attribute.
        // We intentionally do NOT write `memberNumber` / `memberSince` attributes anymore.
        if ($can_edit_membership_meta) {
            $member_number = isset($_POST['org_member_number']) ? trim((string) $_POST['org_member_number']) : '';
            $member_since = isset($_POST['org_member_since']) ? trim((string) $_POST['org_member_since']) : '';
            $member_until = isset($_POST['org_member_until']) ? trim((string) $_POST['org_member_until']) : '';
            $docIdentifiers = buildDocumentIdentifierMembership(
                $member_number !== '' ? $member_number : null,
                $member_since !== '' ? $member_since : null,
                $member_until !== '' ? $member_until : null
            );
            // Always set so an empty list removes membership metadata from LDAP
            $org_data['documentIdentifier'] = $docIdentifiers;
            unset($org_data['memberNumber'], $org_data['memberSince'], $org_data['memberUntil']);
        }

        $can_rename_org = currentUserIsGlobalAdmin() || currentUserIsMaintainer();
        $posted_org_name = isset($_POST['org_name']) ? trim((string) $_POST['org_name']) : '';
        $skip_org_save = false;

        if (!$can_rename_org) {
            if ($posted_org_name !== '' && $posted_org_name !== (string) $org_name) {
                render_alert_banner(t('manage.orgs.show.msg.org_rename_denied'), "danger");
                $skip_org_save = true;
            }
        } elseif ($posted_org_name === '') {
            render_alert_banner(t('manage.orgs.show.msg.org_name_required'), "danger");
            $skip_org_save = true;
        }

        unset($org_data['o']);

        if (!$skip_org_save) {
            // Per-org user limit (stored under ou=config as cn=userLimit); uses current org name (before rename)
            if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) {
                $raw_limit = trim((string) ($_POST['org_user_limit'] ?? ''));
                $limit_val = null;
                if ($raw_limit !== '') {
                    if (ctype_digit($raw_limit)) {
                        $limit_val = (int) $raw_limit;
                    } else {
                        render_alert_banner(t('manage.orgs.show.msg.user_limit_invalid'), "warning", 15000);
                    }
                }
                $limit_ldap = open_ldap_connection();
                if ($limit_ldap !== false) {
                    if (!function_exists('ldap_org_set_user_limit') || !ldap_org_set_user_limit($limit_ldap, $org_name, $limit_val)) {
                        render_alert_banner(t('manage.orgs.show.msg.user_limit_update_fail'), "danger", 15000);
                    }
                    ldap_close($limit_ldap);
                }
            }

            // Use UUID for update if available, otherwise fall back to name
            $update_identifier = $org_uuid !== '' ? $org_uuid : $org_name;
            $result = updateOrganization($update_identifier, $org_data);

            $rename_ok = true;
            if (
                $result
                && $can_rename_org
                && $posted_org_name !== ''
                && $posted_org_name !== (string) $org_name
            ) {
                $rename_ok = renameOrganization($update_identifier, $posted_org_name);
                if ($rename_ok) {
                    $org_name = $posted_org_name;
                    if (isset($_GET['org']) && (string) $_GET['org'] !== '' && (string) $_GET['org'] !== (string) $org_name) {
                        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
                        if (!is_string($path) || $path === '') {
                            $path = '/manage/organizations/show/';
                        }
                        header('Location: ' . $path . '?org=' . rawurlencode((string) $org_name));
                        exit;
                    }
                } else {
                    render_alert_banner(t('manage.orgs.show.msg.org_rename_fail'), "danger", 15000);
                }
            }

            if ($result && $rename_ok) {
                render_alert_banner(
                    t('manage.orgs.show.msg.org_update_ok', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
                    "success"
                );
            } elseif (!$result) {
                render_alert_banner(t('manage.orgs.show.msg.org_update_fail'), "danger");
            }
        }
    }
}

// Refresh UUID-backed organization data after any POST handling so
// the detail section reflects updates immediately in the same response.
if ($org_uuid !== '') {
    $ldap_refresh = open_ldap_connection();
    if ($ldap_refresh !== false) {
        $refreshed_org = ldap_get_organization_by_uuid($ldap_refresh, $org_uuid);
        if ($refreshed_org !== false) {
            $organization_by_uuid = $refreshed_org;
        }
        ldap_close($ldap_refresh);
    }
}

// Get organization details
if ($org_uuid) {
    // Use the organization data we already retrieved by UUID
    $organization = $organization_by_uuid;
    // Convert to the format expected by the rest of the code
    $organization = [
        'name' => $organization['o'][0],
        'o' => $organization['o'][0],
        'entryUUID' => $org_uuid,
        'description' => isset($organization['description'][0]) ? $organization['description'][0] : '',
        'mail' => isset($organization['mail'][0]) ? $organization['mail'][0] : '',
        'telephoneNumber' => isset($organization['telephonenumber'][0]) ? $organization['telephonenumber'][0] : '',
        'facsimileTelephoneNumber' => isset($organization['facsimiletelephonenumber'][0]) ? $organization['facsimiletelephonenumber'][0] : '',
        'labeledURI' => isset($organization['labeleduri'][0]) ? $organization['labeleduri'][0] : '',
        'postalAddress' => isset($organization['postaladdress'][0]) ? $organization['postaladdress'][0] : '',
        // Membership metadata is stored in LDAP's `documentIdentifier` attribute
        'memberNumber' => '',
        'memberSince' => '',
        'memberUntil' => '',
        // Add individual address fields for backward compatibility
        'street' => '',
        'city' => '',
        'state' => '',
        'postalCode' => '',
        'country' => ''
    ];

    $docIdentifiers = [];
    if (isset($organization_by_uuid['documentidentifier'])) {
        $docIdentifiersRaw = $organization_by_uuid['documentidentifier'];
        $docIdentifiers = is_array($docIdentifiersRaw) ? $docIdentifiersRaw : [$docIdentifiersRaw];
    } elseif (isset($organization['documentidentifier'])) {
        $docIdentifiersRaw = $organization['documentidentifier'];
        $docIdentifiers = is_array($docIdentifiersRaw) ? $docIdentifiersRaw : [$docIdentifiersRaw];
    }
    $decodedMembership = parseDocumentIdentifierMembership($docIdentifiers);

    // Backward compatible fallback for older installs
    $legacyMemberNumber = isset($organization['membernumber'][0]) ? $organization['membernumber'][0] : '';
    $legacyMemberSince = isset($organization['membersince'][0]) ? $organization['membersince'][0] : '';
    $legacyMemberUntil = isset($organization['memberuntil'][0]) ? $organization['memberuntil'][0] : '';

    $organization['memberNumber'] = $decodedMembership['memberNumber'] !== '' ? $decodedMembership['memberNumber'] : $legacyMemberNumber;
    $organization['memberSince'] = $decodedMembership['memberSince'] !== '' ? $decodedMembership['memberSince'] : $legacyMemberSince;
    $organization['memberUntil'] = $decodedMembership['memberUntil'] !== '' ? $decodedMembership['memberUntil'] : $legacyMemberUntil;

    // Parse postalAddress if available (format: Street$ZIP$City$State$Country)
    if (!empty($organization['postalAddress'])) {
        $address_parts = explode('$', $organization['postalAddress']);
        // Store parsed parts for form display if needed
        if (count($address_parts) >= 5) {
            $organization['_parsed_street'] = $address_parts[0];
            $organization['_parsed_postalCode'] = $address_parts[1];
            $organization['_parsed_city'] = $address_parts[2];
            $organization['_parsed_state'] = $address_parts[3];
            $organization['_parsed_country'] = $address_parts[4];
        }
    }
} else {
    // Legacy name-based lookup
    $organizations = listOrganizations();
    $organization = null;

    foreach ($organizations as $org) {
        if ($org['name'] === $org_name) {
            $organization = $org;
                    // Parse postalAddress if available for backward compatibility
            if (!empty($organization['postalAddress'])) {
                $address_parts = explode('$', $organization['postalAddress']);
                if (count($address_parts) >= 5) {
                    $organization['_parsed_street'] = $address_parts[0];
                    $organization['_parsed_postalCode'] = $address_parts[1];
                    $organization['_parsed_city'] = $address_parts[2];
                    $organization['_parsed_state'] = $address_parts[3];
                    $organization['_parsed_country'] = $address_parts[4];
                }
            }
            break;
        }
    }

    if (!$organization) {
        render_alert_banner(
            t('manage.orgs.show.msg.org_not_found', ['org' => htmlspecialchars((string) $org_name, ENT_QUOTES, 'UTF-8')]),
            "danger"
        );
        render_footer();
        exit(0);
    }
}

// Ensure membership metadata keys exist (legacy path may not have them)
if (!isset($organization['memberNumber'])) {
    $organization['memberNumber'] = '';
}
if (!isset($organization['memberSince'])) {
    $organization['memberSince'] = '';
}
if (!isset($organization['memberUntil'])) {
    $organization['memberUntil'] = '';
}
if (!isset($organization['facsimileTelephoneNumber'])) {
    $organization['facsimileTelephoneNumber'] = '';
}

// Get organization users
$org_users = getOrganizationUsers($org_name);

// Get organization roles and status group flags
$org_roles = [];
$ldap_connection = open_ldap_connection();
$org_dn = "o=" . ldap_escape($org_name, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
$base_dn = $LDAP['base_dn'] ?? '';
$member_group_cn = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
$disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';
$is_member_org = ($ldap_connection !== false && $base_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $member_group_cn, $base_dn));
$is_disabled_org = ($ldap_connection !== false && $base_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $disabled_group_cn, $base_dn));
$is_locked_org = ($ldap_connection !== false && ldap_organization_is_locked($ldap_connection, $org_name));
$org_user_limit = ($ldap_connection !== false && function_exists('ldap_org_get_user_limit')) ? ldap_org_get_user_limit($ldap_connection, $org_name) : null;

// First check if the organization DN exists before searching for roles
$orgExists = @ldap_read($ldap_connection, $org_dn, '(objectClass=*)', ['dn']);
if ($orgExists) {
    // Search for roles under ou=roles within the organization
    $org_roles_dn = "ou=roles,o=" . ldap_escape($org_name, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];

    // Check if the roles directory exists
    $rolesDirExists = @ldap_read($ldap_connection, $org_roles_dn, '(objectClass=*)', ['dn']);
    if ($rolesDirExists) {
        $roles_search = @ldap_search($ldap_connection, $org_roles_dn, "(objectClass=groupOfNames)");
        if ($roles_search) {
            $roles_entries = ldap_get_entries($ldap_connection, $roles_search);
            if ($roles_entries && isset($roles_entries['count'])) {
                for ($i = 0; $i < $roles_entries['count']; $i++) {
                    $role_name = $roles_entries[$i]['cn'][0];
                    $member_count = isset($roles_entries[$i]['member']) ? $roles_entries[$i]['member']['count'] : 0;
                    $org_roles[] = [
                        'name' => $role_name,
                        'member_count' => $member_count,
                        'description' => isset($roles_entries[$i]['description']) ? $roles_entries[$i]['description'][0] : ''
                    ];
                }
            }
        }
    } else {
        // Roles directory doesn't exist yet, which means no roles have been created
        error_log("show_organization: Roles directory not found: $org_roles_dn");
    }
} else {
    // Log the error but don't show it to the user
    error_log("show_organization: Organization DN not found: $org_dn");
}

?>

<div class="container">
 <div class="col-sm-12">

  <nav aria-label="breadcrumb">
   <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.dashboard.breadcrumb_dashboard'), ENT_QUOTES, 'UTF-8'); ?></a></li>
    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.organizations'), ENT_QUOTES, 'UTF-8'); ?></a></li>
    <li class="breadcrumb-item active" aria-current="page"><?php print htmlspecialchars($org_name); ?></li>
   </ol>
  </nav>

  <div class="card">
   <div class="card-header clearfix">
    <span class="card-title mb-0 float-start"><h3 class="h5 mb-0"><?php print htmlspecialchars($org_name); ?></h3></span>
    <a href="<?php print $THIS_MODULE_PATH; ?>/organizations" class="btn btn-secondary float-end"><?php echo htmlspecialchars(t('manage.orgs.show.back_to_organizations'), ENT_QUOTES, 'UTF-8'); ?></a>
   </div>
   <div class="card-body">
    
    <div class="row">
     <div class="col-sm-6">
     <h4><?php echo htmlspecialchars(t('manage.orgs.show.information_heading'), ENT_QUOTES, 'UTF-8'); ?></h4>
      <table class="table table-striped">
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.name_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td><?php print htmlspecialchars($org_name); ?></td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.description_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td><?php print htmlspecialchars($organization['description'] ?? t('manage.orgs.show.no_description')); ?></td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.address_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td>
         <?php if (!empty($organization['postalAddress'])) { ?>
          <div>
                <?php
                $address_parts = explode('$', $organization['postalAddress']);
                if (count($address_parts) >= 5) {
                    // Format: Street$ZIP$City$State$Country
                    if (!empty($address_parts[0])) { ?>
                <div><?php print htmlspecialchars($address_parts[0]); ?></div>
                    <?php } ?>
                    <?php if (!empty($address_parts[1]) || !empty($address_parts[2]) || !empty($address_parts[3])) { ?>
                <div>
                        <?php
                        $city_state_zip = [];
                        if (!empty($address_parts[2])) {
                            $city_state_zip[] = $address_parts[2]; // City
                        }
                        if (!empty($address_parts[3])) {
                            $city_state_zip[] = $address_parts[3]; // State
                        }
                        if (!empty($address_parts[1])) {
                            $city_state_zip[] = $address_parts[1]; // ZIP
                        }
                        print htmlspecialchars(implode(', ', $city_state_zip));
                        ?>
                </div>
                    <?php } ?>
                    <?php if (!empty($address_parts[4])) { ?>
                <div>
                        <?php
                        $country_raw = trim((string) $address_parts[4]);
                        $country_code = strtoupper($country_raw);
                        $country_options = getLocalizedCountryOptions();
                        if (isset($country_options[$country_code])) {
                            print htmlspecialchars($country_options[$country_code] . ' (' . $country_code . ')');
                        } else {
                            print htmlspecialchars($country_raw);
                        }
                        ?>
                </div>
                    <?php } ?>
                <?php } else { ?>
            <div><?php print htmlspecialchars($organization['postalAddress']); ?></div>
                <?php } ?>
          </div>
         <?php } else { ?>
          <em><?php echo htmlspecialchars(t('manage.orgs.show.no_address'), ENT_QUOTES, 'UTF-8'); ?></em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.phone_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td>
         <?php if (!empty($organization['telephoneNumber'])) { ?>
          <?php print htmlspecialchars((string) $organization['telephoneNumber']); ?>
         <?php } else { ?>
          <em><?php echo htmlspecialchars(t('manage.orgs.show.no_phone'), ENT_QUOTES, 'UTF-8'); ?></em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.fax_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td>
         <?php if (!empty($organization['facsimileTelephoneNumber'])) { ?>
          <?php print htmlspecialchars((string) $organization['facsimileTelephoneNumber']); ?>
         <?php } else { ?>
          <em><?php echo htmlspecialchars(t('manage.orgs.show.no_fax'), ENT_QUOTES, 'UTF-8'); ?></em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.website_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td>
         <?php if (!empty($organization['labeledURI'])) { ?>
          <a href="<?php print htmlspecialchars($organization['labeledURI']); ?>" target="_blank"><?php print htmlspecialchars($organization['labeledURI']); ?></a>
         <?php } else { ?>
          <em><?php echo htmlspecialchars(t('manage.orgs.show.no_website'), ENT_QUOTES, 'UTF-8'); ?></em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.email_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td>
         <?php if (!empty($organization['mail'])) { ?>
          <a href="mailto:<?php print htmlspecialchars($organization['mail']); ?>"><?php print htmlspecialchars($organization['mail']); ?></a>
         <?php } else { ?>
          <em><?php echo htmlspecialchars(t('manage.orgs.show.no_email'), ENT_QUOTES, 'UTF-8'); ?></em>
         <?php } ?>
        </td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.membership_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td>
         <?php if ($is_member_org) {
                ?><span class="badge bg-primary"><?php echo htmlspecialchars(t('manage.orgs.show.badge_member'), ENT_QUOTES, 'UTF-8'); ?></span><?php
         } ?>
         <?php if ($is_disabled_org) {
                ?><span class="badge bg-danger"><?php echo htmlspecialchars(t('manage.orgs.show.badge_disabled'), ENT_QUOTES, 'UTF-8'); ?></span><?php
         } ?>
         <?php if (!$is_member_org && !$is_disabled_org) {
                ?><em><?php echo htmlspecialchars(t('manage.orgs.show.em_dash'), ENT_QUOTES, 'UTF-8'); ?></em><?php
         } ?>
        </td>
       </tr>
       <?php if ((currentUserIsGlobalAdmin() || currentUserIsMaintainer()) && (!empty($organization['memberNumber']) || !empty($organization['memberSince']) || !empty($organization['memberUntil']))) : ?>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.member_details_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td>
            <?php if (!empty($organization['memberNumber'])) {
                ?><?php echo htmlspecialchars(t('manage.orgs.show.member_number_label'), ENT_QUOTES, 'UTF-8'); ?><?php print htmlspecialchars($organization['memberNumber']); ?><br><?php
            } ?>
            <?php if (!empty($organization['memberSince'])) {
                ?><?php echo htmlspecialchars(t('manage.orgs.show.member_since_label'), ENT_QUOTES, 'UTF-8'); ?><?php print htmlspecialchars($organization['memberSince']); ?><br><?php
            } ?>
            <?php if (!empty($organization['memberUntil'])) {
                ?><?php echo htmlspecialchars(t('manage.orgs.show.member_until_label'), ENT_QUOTES, 'UTF-8'); ?><?php print htmlspecialchars($organization['memberUntil']); ?><br><?php
            } ?>
        </td>
       </tr>
       <?php endif; ?>
      </table>
     </div>
     
     <div class="col-sm-6">
      <h4><?php echo htmlspecialchars(t('manage.orgs.show.statistics_heading'), ENT_QUOTES, 'UTF-8'); ?></h4>
      <table class="table table-striped">
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.total_users_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td><?php print count($org_users); ?></td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.user_limit_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td>
            <?php if ($org_user_limit === null) : ?>
                <em><?php echo htmlspecialchars(t('manage.orgs.show.unlimited'), ENT_QUOTES, 'UTF-8'); ?></em>
            <?php else : ?>
                <?php print (int) $org_user_limit; ?>
            <?php endif; ?>
        </td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.total_roles_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td><?php print count($org_roles); ?></td>
       </tr>
       <tr>
        <th><?php echo htmlspecialchars(t('manage.orgs.show.created_header'), ENT_QUOTES, 'UTF-8'); ?></th>
        <td><?php print isset($organization['created']) ? htmlspecialchars($organization['created']) : htmlspecialchars(t('manage.orgs.show.unknown')); ?></td>
       </tr>
      </table>
      
      <h4><?php echo htmlspecialchars(t('manage.orgs.show.actions_heading'), ENT_QUOTES, 'UTF-8'); ?></h4>
        <?php
        $can_membership = currentUserIsGlobalAdmin() || currentUserIsMaintainer();
        $can_lock = currentUserCanDisableOrganization($org_name) || currentUserCanEnableOrganization($org_name);
        $can_delete = currentUserCanDeleteOrganization($org_name);
        ?>
        <div class="d-flex align-items-center justify-content-start flex-wrap gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.common.user_actions_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($org_uuid !== '') : ?>
                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info"><?php echo htmlspecialchars(t('manage.orgs.show.view_users'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/users/new/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success"><?php echo htmlspecialchars(t('manage.orgs.show.add_user'), ENT_QUOTES, 'UTF-8'); ?></a>
                <?php endif; ?>
            </div>

            <?php if ($can_modify_org) : ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="showEditForm()"><?php echo htmlspecialchars(t('manage.orgs.show.edit_organization'), ENT_QUOTES, 'UTF-8'); ?></button>
            <?php endif; ?>

            <?php if ($can_membership) : ?>
                <div class="vr"></div>
                <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_membership_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($is_member_org) : ?>
                        <button type="button" class="btn btn-secondary" onclick="confirmUnmemberOrganization('<?php echo htmlspecialchars($org_name); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.unmember'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php else : ?>
                        <button type="button" class="btn btn-secondary" onclick="confirmMemberOrganization('<?php echo htmlspecialchars($org_name); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.member'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($can_lock) : ?>
                <div class="vr"></div>
                <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_lock_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($is_locked_org) : ?>
                        <?php if (currentUserCanEnableOrganization($org_name)) : ?>
                            <button type="button" class="btn btn-success" onclick="confirmUnlockOrganization('<?php echo htmlspecialchars($org_name); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.unlock'), ENT_QUOTES, 'UTF-8'); ?></button>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php if (currentUserCanDisableOrganization($org_name)) : ?>
                            <button type="button" class="btn btn-warning" onclick="confirmLockOrganization('<?php echo htmlspecialchars($org_name); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.lock'), ENT_QUOTES, 'UTF-8'); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($can_delete) : ?>
                <div class="vr"></div>
                <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_delete_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteOrganization('<?php echo htmlspecialchars($org_name); ?>', '<?php echo htmlspecialchars($org_uuid); ?>')"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                </div>
            <?php endif; ?>
        </div>
     </div>
    </div>
    
    <hr>
    
    <div class="row">
     <div class="col-sm-6">
     <h4><?php echo htmlspecialchars(t('manage.orgs.show.recent_users_heading'), ENT_QUOTES, 'UTF-8'); ?></h4>
      <?php if (empty($org_users)) { ?>
      <p><?php echo htmlspecialchars(t('manage.orgs.show.no_users_in_org'), ENT_QUOTES, 'UTF-8'); ?></p>
      <?php } else { ?>
                           <div class="table-responsive">
                     <table class="table table-striped table-hover">
                      <thead>
                       <tr>
                        <th><?php echo htmlspecialchars(t('manage.orgs.show.recent_name_header'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.email'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.orgs.show.recent_role_header'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.actions'), ENT_QUOTES, 'UTF-8'); ?></th>
                       </tr>
                      </thead>
                      <tbody>
                       <?php
                        $recent_users = array_slice($org_users, 0, 5); // Show only first 5 users
                        foreach ($recent_users as $user) :
                            ?>
                        <tr>
                         <td><?php print safe_display_name($user, 'cn', 'givenName', 'sn'); ?></td>
                         <td><?php print htmlspecialchars($user['mail']); ?></td>
                         <td><?php print htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role'] ?? 'user'))); ?></td>
                         <td>
                                <div class="d-inline-flex align-items-center flex-wrap gap-1">
                                    <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.common.user_actions_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php if ($org_uuid !== '' && isset($user['entryUUID'])) : ?>
                                            <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/users/?edit_user=' . urlencode((string) $user['entryUUID']), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary btn-sm"><?php echo htmlspecialchars(t('manage.common.edit'), ENT_QUOTES, 'UTF-8'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="vr"></div>
                                    <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteUser('<?php echo htmlspecialchars($user['entryUUID'] ?? $user['mail'] ?? $user['cn']); ?>')"><?php echo htmlspecialchars(t('manage.common.delete'), ENT_QUOTES, 'UTF-8'); ?></button>
                                    </div>
                                </div>
                         </td>
                       </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                    </div>
                    <div class="text-center mt-3">
                        <?php if ($org_uuid !== '') : ?>
                            <a href="<?php echo htmlspecialchars(get_base_url() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.orgs.show.view_all_users'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                    </div>
          <?php if (count($org_users) > 5) { ?>
                <?php $shown_users_count = min(5, count($org_users)); ?>
        <p><em><?php echo htmlspecialchars(t('manage.orgs.show.showing_users_summary', ['shown' => (string) $shown_users_count, 'total' => (string) count($org_users)]), ENT_QUOTES, 'UTF-8'); ?>
            <a href="<?php print $THIS_MODULE_PATH; ?>/org_users.php?<?php echo $org_uuid ? 'uuid=' . urlencode($org_uuid) : 'org=' . urlencode($org_name); ?>"><?php echo htmlspecialchars(t('manage.orgs.show.view_all_users'), ENT_QUOTES, 'UTF-8'); ?></a>
        </em></p>
          <?php } ?>
      <?php } ?>
     </div>
     
     <div class="col-sm-6">
      <h4><?php echo htmlspecialchars(t('manage.orgs.show.organization_roles_heading'), ENT_QUOTES, 'UTF-8'); ?></h4>
      <?php if (empty($org_roles)) { ?>
       <p><?php echo htmlspecialchars(t('manage.orgs.show.no_roles_defined'), ENT_QUOTES, 'UTF-8'); ?></p>
      <?php } else { ?>
       <div class="table-responsive">
        <table class="table table-striped table-hover">
         <thead>
          <tr>
           <th><?php echo htmlspecialchars(t('manage.orgs.show.role_name_header'), ENT_QUOTES, 'UTF-8'); ?></th>
           <th><?php echo htmlspecialchars(t('manage.orgs.show.members_header'), ENT_QUOTES, 'UTF-8'); ?></th>
           <th><?php echo htmlspecialchars(t('manage.orgs.show.role_description_header'), ENT_QUOTES, 'UTF-8'); ?></th>
          </tr>
         </thead>
         <tbody>
          <?php foreach ($org_roles as $role) { ?>
           <tr>
            <td><strong><?php print htmlspecialchars($role['name']); ?></strong></td>
            <td><?php print $role['member_count']; ?></td>
            <td><?php print htmlspecialchars($role['description'] ?? ''); ?></td>
           </tr>
          <?php } ?>
         </tbody>
        </table>
       </div>
      <?php } ?>
     </div>
    </div>
    
   </div>
  </div>

  <!-- Edit Organization Form (Hidden by default) -->
  <?php if ($can_modify_org) : ?>
  <div class="card" id="editForm" style="display: none;">
   <div class="card-header text-center"><?php echo htmlspecialchars(t('manage.orgs.show.edit_organization_form_heading'), ENT_QUOTES, 'UTF-8'); ?></div>
   <div class="card-body text-center">
    <form class="form-horizontal" action="" method="post">
        <?= csrf_token_field() ?>
     <input type="hidden" name="update_organization">
     
        <?php
        $can_rename_org_form = currentUserIsGlobalAdmin() || currentUserIsMaintainer();
        $org_display_name = (string) ($organization['o'] ?? $organization['name'] ?? $org_name);
        $org_name_field_label = $LDAP['org_field_labels']['org_name'] ?? 'Organization Name';
        ?>
     <div class="row">
      <div class="col-sm-6">
       <div class="form-group">
        <label for="org_name" class="col-sm-4 form-label"><?php echo htmlspecialchars($org_name_field_label, ENT_QUOTES, 'UTF-8'); ?> <sup>*</sup></label>
        <div class="col-sm-8">
         <?php if ($can_rename_org_form) : ?>
          <input type="text" class="form-control" id="org_name" name="org_name" required value="<?php echo htmlspecialchars($org_display_name, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="organization">
         <?php else : ?>
          <input type="hidden" name="org_name" value="<?php echo htmlspecialchars($org_display_name, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="text" class="form-control" id="org_name" value="<?php echo htmlspecialchars($org_display_name, ENT_QUOTES, 'UTF-8'); ?>" disabled autocomplete="organization" aria-readonly="true">
         <?php endif; ?>
        </div>
       </div>
      </div>
     </div>
        <?php
     // Generate form fields dynamically using configuration
        $col_count = 0;

     // Get the list of form fields to display (org_name is rendered above)
        $form_fields_to_display = array_keys($LDAP['org_field_mappings']);
        $on = array_search('org_name', $form_fields_to_display, true);
        if ($on !== false) {
            unset($form_fields_to_display[$on]);
        }
        $form_fields_to_display = array_values($form_fields_to_display);

        foreach ($form_fields_to_display as $form_field) {
            // Membership metadata fields are rendered in the admin section only.
            if (in_array($form_field, ['org_member_number', 'org_member_since', 'org_member_until'], true)) {
                continue;
            }
            if (isset($LDAP['org_field_labels'][$form_field]) && isset($LDAP['org_field_types'][$form_field])) {
                $label = $LDAP['org_field_labels'][$form_field];
                $field_type = $LDAP['org_field_types'][$form_field];
                $ldap_attr = $LDAP['org_field_mappings'][$form_field];

                // Check if field is required based on configuration
                $is_required = in_array($ldap_attr, $LDAP['org_optional_fields']) ? false : true;

                // Start new row every 2 fields
                if ($col_count % 2 === 0) {
                    if ($col_count > 0) {
                        echo '</div>';
                    }
                    echo '<div class="row">';
                }

                $is_membership_date_field = ($form_field === 'org_member_since' || $form_field === 'org_member_until');
                $column_class = $is_membership_date_field ? 'col-sm-3' : 'col-sm-6';
                $label_class = $is_membership_date_field ? 'col-sm-12 form-label' : 'col-sm-4 form-label';
                $input_wrapper_class = $is_membership_date_field ? 'col-sm-12' : 'col-sm-8';

                echo '<div class="' . $column_class . '">';
                echo '<div class="form-group">';

                // Add required indicator if field is required
                if ($is_required) {
                    $label .= ' <sup>*</sup>';
                }

                echo '<label for="' . $form_field . '" class="' . $label_class . '">' . $label . '</label>';
                echo '<div class="' . $input_wrapper_class . '">';

                // Get current value from organization data
                $current_value = '';
                if (isset($organization[$ldap_attr])) {
                    $current_value = $organization[$ldap_attr];
                } elseif (isset($organization[$form_field])) {
                    $current_value = $organization[$form_field];
                } else {
                    // Try to get value from the mapped LDAP attribute
                    $current_value = $organization[$ldap_attr] ?? '';
                }

                // Add required attribute if field is required
                $required_attr = $is_required ? ' required' : '';

                if ($field_type === 'textarea') {
                    echo '<textarea class="form-control" id="' . $form_field . '" name="' . $form_field . '" rows="2"' . $required_attr . '>' . htmlspecialchars($current_value) . '</textarea>';
                } else {
                    echo '<input type="' . $field_type . '" class="form-control" id="' . $form_field . '" name="' . $form_field . '" value="' . htmlspecialchars($current_value) . '"' . $required_attr . '>';
                }

                echo '</div></div></div>';
                $col_count++;
            }
        }

     // Close the last row if needed
        if ($col_count > 0) {
            echo '</div>';
        }
        ?>
     
     <!-- Address Fields (dynamically generated from configuration) -->
        <?php
     // Generate address fields dynamically using configuration
        $address_col_count = 0;
        foreach ($LDAP['org_address_fields'] as $field_name => $field_config) {
            // Start new row every 2 fields
            if ($address_col_count % 2 === 0) {
                if ($address_col_count > 0) {
                    echo '</div>';
                }
                echo '<div class="row">';
            }

            echo '<div class="col-sm-6">';
            echo '<div class="form-group">';

            // Get current value from parsed address data
            $current_value = '';
            switch ($field_name) {
                case 'org_address':
                    $current_value = $organization['_parsed_street'] ?? '';
                    break;
                case 'org_zip':
                    $current_value = $organization['_parsed_postalCode'] ?? '';
                    break;
                case 'org_city':
                    $current_value = $organization['_parsed_city'] ?? '';
                    break;
                case 'org_state':
                    $current_value = $organization['_parsed_state'] ?? '';
                    break;
                case 'org_country':
                    $current_value = $organization['_parsed_country'] ?? '';
                    break;
            }

            // Generate label with required indicator if needed
            $label = $field_config['label'];
            if ($field_config['required']) {
                $label .= ' <sup>*</sup>';
            }

            echo '<label for="' . $field_name . '" class="col-sm-4 form-label">' . $label . '</label>';
            echo '<div class="col-sm-8">';

            // Generate input field (country picker as select)
            $required_attr = $field_config['required'] ? ' required' : '';
            if ($field_name === 'org_country') {
                $country_options = getLocalizedCountryOptions();
                $selected_value = strtoupper((string) $current_value);
                if ($selected_value !== '' && !isset($country_options[$selected_value])) {
                    $selected_value = '';
                }
                echo '<select class="form-select" id="' . $field_name . '" name="' . $field_name . '"' . $required_attr . '>';
                echo '<option value=""></option>';
                foreach ($country_options as $country_code => $country_name) {
                    $selected = ($selected_value === $country_code) ? ' selected' : '';
                    echo '<option value="' . htmlspecialchars($country_code, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' .
                        htmlspecialchars($country_name . ' (' . $country_code . ')', ENT_QUOTES, 'UTF-8') . '</option>';
                }
                echo '</select>';
            } else {
                echo '<input type="' . $field_config['type'] . '" class="form-control" id="' . $field_name . '" name="' . $field_name . '" ' .
                  'value="' . htmlspecialchars($current_value) . '"' . $required_attr . '>';
            }

            echo '</div></div></div>';
            $address_col_count++;
        }

     // Close the last address row if needed
        if ($address_col_count > 0) {
            echo '</div>';
        }
        ?>

     <!-- Admin/maintainer-only settings -->
        <?php if (currentUserIsGlobalAdmin() || currentUserIsMaintainer()) : ?>
     <div class="row mt-3">
      <div class="col-sm-12">
       <h5 class="mb-2"><?php echo htmlspecialchars(t('manage.orgs.show.admin_settings_heading'), ENT_QUOTES, 'UTF-8'); ?></h5>
       <div class="row mt-2">
        <div class="col-sm-6 mb-2">
          <label for="org_member_number" class="form-label"><?php echo htmlspecialchars($LDAP['org_field_labels']['org_member_number'] ?? 'Member number', ENT_QUOTES, 'UTF-8'); ?></label>
          <input type="text" class="form-control" id="org_member_number" name="org_member_number" value="<?php echo htmlspecialchars((string) ($organization['memberNumber'] ?? '')); ?>">
        </div>
        <div class="col-sm-3 mb-2">
          <label for="org_member_since" class="form-label"><?php echo htmlspecialchars($LDAP['org_field_labels']['org_member_since'] ?? 'Member since', ENT_QUOTES, 'UTF-8'); ?></label>
          <input type="date" class="form-control" id="org_member_since" name="org_member_since" value="<?php echo htmlspecialchars((string) ($organization['memberSince'] ?? '')); ?>">
        </div>
        <div class="col-sm-3 mb-2">
          <label for="org_member_until" class="form-label"><?php echo htmlspecialchars($LDAP['org_field_labels']['org_member_until'] ?? 'Member until', ENT_QUOTES, 'UTF-8'); ?></label>
          <input type="date" class="form-control" id="org_member_until" name="org_member_until" value="<?php echo htmlspecialchars((string) ($organization['memberUntil'] ?? '')); ?>">
        </div>
        <div class="col-sm-6 mb-2">
          <label for="org_user_limit" class="form-label"><?php echo htmlspecialchars(t('manage.orgs.show.max_users_label'), ENT_QUOTES, 'UTF-8'); ?></label>
          <input type="text" class="form-control" id="org_user_limit" name="org_user_limit" value="<?php echo htmlspecialchars($org_user_limit !== null ? (string) $org_user_limit : ''); ?>">
        </div>
       </div>
      </div>
     </div>
        <?php endif; ?>
     
     <div class="form-group">
      <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('manage.orgs.show.update_organization'), ENT_QUOTES, 'UTF-8'); ?></button>
      <button type="button" class="btn btn-secondary" onclick="hideEditForm()"><?php echo htmlspecialchars(t('modal.cancel'), ENT_QUOTES, 'UTF-8'); ?></button>
     </div>
    </form>
   </div>
  </div>
  <?php endif; ?>

 </div>
</div>

<script>
function showEditForm() {
    document.getElementById('editForm').style.display = 'block';
    document.getElementById('editForm').scrollIntoView({ behavior: 'smooth' });
}

function hideEditForm() {
    document.getElementById('editForm').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
});
</script>

<?php
// Lock/Unlock Confirmation Modals
render_confirm_modal(
    'lockModal',
    t('manage.orgs.show.modal.lock_title'),
    t('manage.orgs.show.modal.lock_body'),
    [
        ['name' => 'action', 'value' => 'lock_organization'],
        ['name' => 'org_name', 'id' => 'lockOrgNameInput'],
    ],
    t('manage.orgs.show.modal.lock_submit'),
    'btn-warning'
);
render_confirm_modal(
    'unlockModal',
    t('manage.orgs.show.modal.unlock_title'),
    t('manage.orgs.show.modal.unlock_body'),
    [
        ['name' => 'action', 'value' => 'unlock_organization'],
        ['name' => 'org_name', 'id' => 'unlockOrgNameInput'],
    ],
    t('manage.orgs.show.modal.unlock_submit'),
    'btn-success'
);

render_confirm_modal(
    'memberModal',
    t('manage.orgs.show.modal.member_title'),
    t('manage.orgs.show.modal.member_body'),
    [
        ['name' => 'action', 'value' => 'member_organization'],
        ['name' => 'org_name', 'id' => 'memberOrgNameInput'],
    ],
    t('manage.orgs.show.modal.member_submit'),
    'btn-secondary'
);

render_confirm_modal(
    'unmemberModal',
    t('manage.orgs.show.modal.unmember_title'),
    t('manage.orgs.show.modal.unmember_body'),
    [
        ['name' => 'action', 'value' => 'unmember_organization'],
        ['name' => 'org_name', 'id' => 'unmemberOrgNameInput'],
    ],
    t('manage.orgs.show.modal.unmember_submit'),
    'btn-secondary'
);

render_confirm_modal(
    'deleteModal',
    t('manage.orgs.show.modal.delete_title'),
    t('manage.orgs.show.modal.delete_body'),
    [
        ['name' => 'action', 'value' => 'delete_organization'],
        ['name' => 'org_name', 'id' => 'deleteOrgNameInput'],
        ['name' => 'org_uuid', 'id' => 'deleteOrgUuidInput'],
    ],
    t('manage.orgs.show.modal.delete_submit'),
    'btn-danger'
);
?>

<script src="<?php print get_asset_base(); ?>js/modals.js"></script>
<script>
function confirmLockOrganization(orgName) {
    confirmAction('lockModal', { lockOrgName: orgName, lockOrgNameInput: orgName });
}
function confirmUnlockOrganization(orgName) {
    confirmAction('unlockModal', { unlockOrgName: orgName, unlockOrgNameInput: orgName });
}
function confirmMemberOrganization(orgName) {
    confirmAction('memberModal', { memberOrgName: orgName, memberOrgNameInput: orgName });
}
function confirmUnmemberOrganization(orgName) {
    confirmAction('unmemberModal', { unmemberOrgName: orgName, unmemberOrgNameInput: orgName });
}
function confirmDeleteOrganization(orgName, orgUuid) {
    confirmAction('deleteModal', { deleteOrgName: orgName, deleteOrgNameInput: orgName, deleteOrgUuidInput: orgUuid || '' });
}
</script>

<?php
render_footer();

?> 