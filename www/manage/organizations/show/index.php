<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage(['ldap', 'organization']);

// Ensure session is started and CSRF token is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Refresh session activity to prevent timeout
$_SESSION['last_activity'] = time();



getCsrfToken();

$res = resolve_organization_from_request();
if ($res['error'] !== null) {
    renderAlertBanner($res['error'], "warning");
    renderFooter();
    exit(0);
}
$org_name = $res['org_name'] ?? '';
$org_uuid = $res['org_uuid'] ?? '';
$organization_by_uuid = $res['organization'];

// Use the enhanced access control function
setPageAccess(["admin", "maintainer", "org_admin"]);

// Check if user can modify this organization
$can_modify_org = currentUserCanModifyOrganization($org_name);

// Handle org deletion before any output (redirect requires clean headers)
if (isset($_POST['action']) && $_POST['action'] === 'delete_organization') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        setFlash(t('manage.orgs.show.msg.session_expired'), 'danger');
    } elseif (!validateCsrfToken()) {
        setFlash(t('manage.common.msg.security_validation_failed'), 'danger');
    } else {
        $ldap_connection_action = lum_ldap_data_connection();
        if ($ldap_connection_action === false) {
            setFlash(t('manage.orgs.msg.ldap_fail'), 'danger');
        } else {
            if (currentUserCanDeleteOrganization($org_name)) {
                $posted_uuid = isset($_POST['org_uuid']) ? trim((string) $_POST['org_uuid']) : '';
                if (ldap_delete_organization($ldap_connection_action, $org_name, $posted_uuid)) {
                    lum_close_ldap_if_not_manage($ldap_connection_action);
                    setFlash(t('manage.orgs.msg.delete_ok', ['org' => (string) $org_name]), 'success');
                    header('Location: ' . getBaseUrl() . 'manage/organizations/');
                    exit;
                }
                setFlash(t('manage.orgs.show.msg.delete_fail', ['org' => (string) $org_name]), 'danger');
            } else {
                setFlash(t('manage.orgs.show.msg.permission_delete_org'), 'danger');
            }
            lum_close_ldap_if_not_manage($ldap_connection_action);
        }
    }
}

renderHeader((string) $ORGANISATION_NAME . ' ' . t('manage.orgs.show.account_manager'));
render_submenu();
renderFlash();

if ($can_modify_org && (empty($_SESSION['lum_ldap_pwd_enc'] ?? null)) && $VALIDATED === true) {
    ?>
    <div class="alert alert-info alert-dismissible fade show container mt-2" role="alert">
        <p class="mb-0 text-center"><?php echo htmlspecialchars(t('manage.orgs.show.notice_directory_service_account'), ENT_QUOTES, 'UTF-8'); ?></p>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></button>
    </div>
    <?php
}

// Handle org disable/enable actions
if (isset($_POST['action']) && ($_POST['action'] === 'disable_organization' || $_POST['action'] === 'enable_organization')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        renderAlertBanner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validateCsrfToken()) {
        renderAlertBanner(t('manage.common.msg.security_validation_failed'), "danger");
    } else {
        $ldap_connection_action = lum_ldap_data_connection();
        if ($ldap_connection_action === false) {
            renderAlertBanner(t('manage.orgs.msg.ldap_fail'), "danger");
        } else {
            if ($_POST['action'] === 'disable_organization') {
                if (currentUserCanDisableOrganization($org_name) && ldap_disable_organization($ldap_connection_action, $org_name)) {
                    renderAlertBanner(
                        t('manage.orgs.show.msg.deactivate_ok', ['org' => (string) $org_name]),
                        "success"
                    );
                } else {
                    renderAlertBanner(
                        t('manage.orgs.show.msg.deactivate_fail', ['org' => (string) $org_name]),
                        "danger",
                        15000
                    );
                }
            } elseif ($_POST['action'] === 'enable_organization') {
                if (!currentUserCanEnableOrganization($org_name)) {
                    renderAlertBanner(
                        t('manage.orgs.show.msg.activate_fail', ['org' => (string) $org_name]),
                        "danger",
                        15000
                    );
                } else {
                    $enable_result = ldap_enable_organization($ldap_connection_action, $org_name);
                    if ($enable_result !== false && $enable_result['ok']) {
                        $msg = t('manage.orgs.show.msg.activate_ok', ['org' => (string) $org_name]);
                        if ($enable_result['still_disabled'] > 0) {
                            $msg .= ' ' . t('manage.orgs.show.msg.activate_summary', [
                                'activated' => $enable_result['enabled'],
                                'still_inactive' => $enable_result['still_disabled'],
                            ]);
                        }
                        renderAlertBanner($msg, "success");
                    } else {
                        renderAlertBanner(
                            t('manage.orgs.show.msg.activate_fail', ['org' => (string) $org_name]),
                            "danger",
                            15000
                        );
                    }
                }
            }
            lum_close_ldap_if_not_manage($ldap_connection_action);
        }
    }
}

// Handle recent users org-manager toggle
if (isset($_POST['action']) && $_POST['action'] === 'toggle_recent_user_manager') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        renderAlertBanner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validateCsrfToken()) {
        renderAlertBanner(t('manage.common.msg.security_validation_failed'), "danger");
    } elseif (!$can_modify_org) {
        renderAlertBanner(t('manage.orgs.show.msg.permission_modify_org'), "danger");
    } else {
        $userIdentifier = trim((string) ($_POST['user_identifier'] ?? ''));
        $userDn = $userIdentifier !== '' ? org_resolve_user_dn($org_name, $userIdentifier) : null;
        if ($userDn === null || $userDn === '') {
            renderAlertBanner(t('manage.users.msg.user_not_found'), "danger");
        } else {
            $userDisplay = org_get_user_display($userDn, $userIdentifier);
            $orgManagerDns = org_get_manager_dns($org_name);
            if (org_dn_in_list($userDn, $orgManagerDns)) {
                $result = removeUserFromOrgAdmin($org_name, $userDn);
                if ($result[0]) {
                    renderAlertBanner(t('manage.org_users.msg.removed_org_manager', ['user' => $userDisplay]), "warning");
                } else {
                    error_log("show/org toggle_manager: removeUserFromOrgAdmin failed: " . $result[1]);
                    renderAlertBanner(t('manage.org_users.msg.update_org_manager_fail'), "danger");
                }
            } else {
                $result = addUserToOrgAdmin($org_name, $userDn);
                if ($result[0]) {
                    renderAlertBanner(t('manage.org_users.msg.assigned_org_manager', ['user' => $userDisplay]), "success");
                } else {
                    error_log("show/org toggle_manager: addUserToOrgAdmin failed: " . $result[1]);
                    renderAlertBanner(t('manage.org_users.msg.update_org_manager_fail'), "danger");
                }
            }
        }
    }
}

// Handle recent users active status toggle
if (isset($_POST['action']) && $_POST['action'] === 'toggle_recent_user_active') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        renderAlertBanner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validateCsrfToken()) {
        renderAlertBanner(t('manage.common.msg.security_validation_failed'), "danger");
    } else {
        $userIdentifier = trim((string) ($_POST['user_identifier'] ?? ''));
        $targetState = trim((string) ($_POST['target_state'] ?? ''));
        $canToggle = $targetState === 'enable' ? currentUserCanEnableUser($userIdentifier) : currentUserCanDisableUser($userIdentifier);
        if (!$canToggle) {
            renderAlertBanner(t('manage.users.msg.permission_denied_invalid_user'), "danger");
        } else {
            $userDn = $userIdentifier !== '' ? org_resolve_user_dn($org_name, $userIdentifier) : null;
            if ($userDn === null || $userDn === '') {
                renderAlertBanner(t('manage.users.msg.user_not_found'), "danger");
            } else {
                $userDisplay = org_get_user_display($userDn, $userIdentifier);
                $ldapConnection = lum_ldap_data_connection();
                if ($ldapConnection === false) {
                    renderAlertBanner(t('manage.orgs.msg.ldap_fail'), "danger");
                } else {
                    if ($targetState === 'enable') {
                        if (ldap_enable_user_account($ldapConnection, $userDn)) {
                            renderAlertBanner(t('manage.users.msg.activate_ok', ['user' => $userDisplay]), "success");
                        } else {
                            error_log("show/org enable_user failed for $userDn: " . ldap_error($ldapConnection));
                            renderAlertBanner(t('manage.users.msg.activate_fail', ['user' => $userDisplay]), "danger");
                        }
                    } elseif ($targetState === 'disable') {
                        if (ldap_disable_user_account($ldapConnection, $userDn)) {
                            renderAlertBanner(t('manage.users.msg.deactivate_ok', ['user' => $userDisplay]), "success");
                        } else {
                            error_log("show/org disable_user failed for $userDn: " . ldap_error($ldapConnection));
                            renderAlertBanner(t('manage.users.msg.deactivate_fail', ['user' => $userDisplay]), "danger");
                        }
                    }
                    lum_close_ldap_if_not_manage($ldapConnection);
                }
            }
        }
    }
}

// Handle org membership actions (member/unmember org)
if (isset($_POST['action']) && ($_POST['action'] === 'member_organization' || $_POST['action'] === 'unmember_organization')) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        renderAlertBanner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validateCsrfToken()) {
        renderAlertBanner(t('manage.common.msg.security_validation_failed'), "danger");
    } elseif (!(currentUserIsGlobalAdmin() || currentUserIsMaintainer())) {
        renderAlertBanner(t('manage.orgs.show.msg.permission_modify_membership'), "danger");
    } else {
        $ldap_connection_action = lum_ldap_data_connection();
        if ($ldap_connection_action === false) {
            renderAlertBanner(t('manage.orgs.msg.ldap_fail'), "danger");
        } else {
            $base_dn = $LDAP['base_dn'] ?? '';
            $member_group_cn_post = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
            $org_dn_action = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];

            if ($base_dn === '' || !function_exists('add_to_status_group') || !function_exists('remove_from_status_group')) {
                renderAlertBanner(t('manage.orgs.show.msg.membership_status_config_missing'), "danger", 15000);
            } else {
                if ($_POST['action'] === 'member_organization') {
                    if (add_to_status_group($ldap_connection_action, $org_dn_action, $member_group_cn_post, $base_dn)) {
                        renderAlertBanner(
                            t('manage.orgs.show.msg.member_ok', ['org' => (string) $org_name]),
                            "success"
                        );
                    } else {
                        renderAlertBanner(
                            t('manage.orgs.show.msg.member_fail', ['org' => (string) $org_name]),
                            "danger",
                            15000
                        );
                    }
                } else {
                    if (remove_from_status_group($ldap_connection_action, $org_dn_action, $member_group_cn_post, $base_dn)) {
                        renderAlertBanner(
                            t('manage.orgs.show.msg.unmember_ok', ['org' => (string) $org_name]),
                            "success"
                        );
                    } else {
                        renderAlertBanner(
                            t('manage.orgs.show.msg.unmember_fail', ['org' => (string) $org_name]),
                            "danger",
                            15000
                        );
                    }
                }
            }
            lum_close_ldap_if_not_manage($ldap_connection_action);
        }
    }
}

// Handle organization updates
if (isset($_POST['update_organization'])) {
    // Check if session is still valid
    if (session_status() !== PHP_SESSION_ACTIVE) {
        renderAlertBanner(t('manage.orgs.show.msg.session_expired'), "danger");
    } elseif (!validateCsrfToken()) {
        renderAlertBanner(t('manage.common.msg.security_validation_failed'), "danger");
    } elseif (!$can_modify_org) {
        renderAlertBanner(t('manage.orgs.show.msg.permission_modify_org'), "danger");
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
                renderAlertBanner(t('manage.orgs.show.msg.org_rename_denied'), "danger");
                $skip_org_save = true;
            }
        } elseif ($posted_org_name === '') {
            renderAlertBanner(t('manage.orgs.show.msg.org_name_required'), "danger");
            $skip_org_save = true;
        }

        if (!$skip_org_save && array_key_exists('labeledURI', $org_data)) {
            $websiteError = applyWebsiteUrlNormalization($org_data);
            if ($websiteError !== null) {
                renderAlertBanner($websiteError, 'danger');
                $skip_org_save = true;
            }
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
                        renderAlertBanner(t('manage.orgs.show.msg.user_limit_invalid'), "warning", 15000);
                    }
                }
                $limit_ldap = lum_ldap_data_connection();
                if ($limit_ldap !== false) {
                    if (!function_exists('ldap_org_set_user_limit') || !ldap_org_set_user_limit($limit_ldap, $org_name, $limit_val)) {
                        renderAlertBanner(t('manage.orgs.show.msg.user_limit_update_fail'), "danger", 15000);
                    }
                    lum_close_ldap_if_not_manage($limit_ldap);
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
                    if ($org_uuid !== '') {
                        header('Location: ' . getBaseUrl() . 'manage/organizations/' . urlencode((string) $org_uuid) . '/');
                        exit;
                    }
                } else {
                    renderAlertBanner(t('manage.orgs.show.msg.org_rename_fail'), "danger", 15000);
                }
            }

            if ($result && $rename_ok) {
                renderAlertBanner(
                    t('manage.orgs.show.msg.org_update_ok', ['org' => (string) $org_name]),
                    "success"
                );
            } elseif (!$result) {
                renderAlertBanner(t('manage.orgs.show.msg.org_update_fail'), "danger");
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
        lum_close_ldap_if_not_manage($ldap_refresh);
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
    }
    $decodedMembership = parseDocumentIdentifierMembership($docIdentifiers);

    // Backward compatible fallback for older installs (raw LDAP entry)
    $legacyMemberNumber = isset($organization_by_uuid['membernumber'][0]) ? $organization_by_uuid['membernumber'][0] : '';
    $legacyMemberSince = isset($organization_by_uuid['membersince'][0]) ? $organization_by_uuid['membersince'][0] : '';
    $legacyMemberUntil = isset($organization_by_uuid['memberuntil'][0]) ? $organization_by_uuid['memberuntil'][0] : '';

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
        renderAlertBanner(
            t('manage.orgs.show.msg.org_not_found', ['org' => (string) $org_name]),
            "danger"
        );
        renderFooter();
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

$is_global_admin = currentUserIsGlobalAdmin();
$is_maintainer   = currentUserIsMaintainer();

// Get organization roles and status group flags
// Use admin bind for all reads — same reliability pattern as listOrganizations().
$org_roles = [];
$ldap_connection = open_ldap_connection();
$org_dn = "o=" . ldap_escape($org_name, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
$base_dn = $LDAP['base_dn'] ?? '';
$member_group_cn = getenv('LDAP_GROUP_MEMBER_ORGS') ?: 'memberOrganizations';
$disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';
$is_member_org = ($ldap_connection !== false && $base_dn !== '' && function_exists('is_in_status_group') && is_in_status_group($ldap_connection, $org_dn, $member_group_cn, $base_dn));
$is_disabled_org = ($ldap_connection !== false && $base_dn !== '' && function_exists('is_in_status_group') && is_in_status_group($ldap_connection, $org_dn, $disabled_group_cn, $base_dn));
$org_disabled = ($ldap_connection !== false && ldap_organization_is_disabled($ldap_connection, $org_name));
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
    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.dashboard.breadcrumb_dashboard'), ENT_QUOTES, 'UTF-8'); ?></a></li>
    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.common.organizations'), ENT_QUOTES, 'UTF-8'); ?></a></li>
    <li class="breadcrumb-item active" aria-current="page"><?php print htmlspecialchars($org_name); ?></li>
   </ol>
  </nav>

  <div class="card">
   <div class="card-header clearfix">
    <span class="card-title mb-0 float-start"><h3 class="h5 mb-0"><?php print htmlspecialchars($org_name); ?></h3></span>
    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/', ENT_QUOTES, 'UTF-8');?>" class="btn btn-secondary float-end"><?php echo htmlspecialchars(t('manage.orgs.show.back_to_organizations'), ENT_QUOTES, 'UTF-8'); ?></a>
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
                        $country_name = getLocalizedCountryName($country_code);
                        if ($country_name !== '' && strcasecmp($country_name, $country_code) !== 0) {
                            print htmlspecialchars($country_name . ' (' . $country_code . ')');
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
                ?><span class="badge bg-danger"><?php echo htmlspecialchars(t('manage.orgs.show.badge_inactive'), ENT_QUOTES, 'UTF-8'); ?></span><?php
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
      
      <h4><?php echo htmlspecialchars(t('manage.common.actions'), ENT_QUOTES, 'UTF-8'); ?></h4>
        <?php
        $can_membership = currentUserIsGlobalAdmin() || currentUserIsMaintainer();
        $can_disable = currentUserCanDisableOrganization($org_name) || currentUserCanEnableOrganization($org_name);
        $can_delete = currentUserCanDeleteOrganization($org_name);
        ?>
        <div class="d-flex align-items-center justify-content-start flex-wrap gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.common.user_actions_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($org_uuid !== '') : ?>
                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-info"><?php echo htmlspecialchars(t('manage.orgs.show.view_users'), ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/users/new/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success"><?php echo htmlspecialchars(t('manage.orgs.show.add_user'), ENT_QUOTES, 'UTF-8'); ?></a>
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

            <?php if ($can_disable) : ?>
                <div class="vr"></div>
                <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo htmlspecialchars(t('manage.orgs.show.organization_status_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($org_disabled) : ?>
                        <?php if (currentUserCanEnableOrganization($org_name)) : ?>
                            <button type="button" class="btn btn-success" onclick="confirmEnableOrganization('<?php echo htmlspecialchars($org_name); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.activate'), ENT_QUOTES, 'UTF-8'); ?></button>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php if (currentUserCanDisableOrganization($org_name)) : ?>
                            <button type="button" class="btn btn-warning" onclick="confirmDisableOrganization('<?php echo htmlspecialchars($org_name); ?>')"><?php echo htmlspecialchars(t('manage.orgs.show.deactivate'), ENT_QUOTES, 'UTF-8'); ?></button>
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
                        <th><?php echo htmlspecialchars(t('manage.common.name'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.email'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th class="text-center"><?php echo htmlspecialchars(t('manage.common.status'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th class="text-center"><?php echo htmlspecialchars(t('manage.common.manager'), ENT_QUOTES, 'UTF-8'); ?></th>
                        <th><?php echo htmlspecialchars(t('manage.common.actions'), ENT_QUOTES, 'UTF-8'); ?></th>
                       </tr>
                      </thead>
                      <tbody>
                       <?php
                        $recent_users = array_slice($org_users, 0, 5); // Show only first 5 users
                        $orgManagerDns = org_get_manager_dns($org_name);
                        $orgManagerCount = org_get_scoped_manager_count($org_name, $orgManagerDns);
                        foreach ($recent_users as $user) :
                            $userIdentifier = (string) ($user['entryUUID'] ?? $user['uid'] ?? '');
                            $isManager = isset($user['dn']) && org_dn_in_list((string) $user['dn'], $orgManagerDns);
                            $isLastManager = $isManager && $orgManagerCount <= 1;
                            ?>
                        <tr>
                         <td><?php print safeDisplayName($user, 'cn', 'givenName', 'sn'); ?></td>
                         <td><?php print htmlspecialchars((string) ($user['mail'] ?? '')); ?></td>
                         <td class="text-center">
                            <?php
                            if ($ldap_connection !== false && isset($user['dn'])) {
                                renderOrgShowRecentStatusCell($ldap_connection, (string) $user['dn'], $userIdentifier, $org_disabled);
                            } elseif ($org_disabled) {
                                echo '<span class="badge bg-danger">' . htmlspecialchars(t('manage.common.inactive'), ENT_QUOTES, 'UTF-8') . '</span>';
                            }
                            ?>
                         </td>
                         <td class="text-center">
                            <?php renderOrgShowRecentManagerToggle($userIdentifier, $isManager, $isLastManager); ?>
                         </td>
                         <td>
                            <?php renderOrgShowRecentUserActions($org_uuid, $user, $isManager); ?>
                         </td>
                       </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                    </div>
                    <div class="text-center mt-3">
                        <?php if ($org_uuid !== '') : ?>
                            <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.orgs.show.view_all_users'), ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php endif; ?>
                    </div>
          <?php if (count($org_users) > 5) { ?>
                <?php $shown_users_count = min(5, count($org_users)); ?>
        <p><em><?php echo htmlspecialchars(t('manage.orgs.show.showing_users_summary', ['shown' => (string) $shown_users_count, 'total' => (string) count($org_users)]), ENT_QUOTES, 'UTF-8'); ?>
            <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/' . urlencode($org_uuid) . '/users/', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(t('manage.orgs.show.view_all_users'), ENT_QUOTES, 'UTF-8'); ?></a>
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

  <!-- Change History Section (admin and maintainer only) -->
  <?php if (($is_global_admin || $is_maintainer) && function_exists('is_org_accesslog_available') && $ldap_connection !== false) : ?>
        <?php
        $auditLdap = open_ldap_connection();
        if ($auditLdap === false) {
            $accesslog_available = false;
        } else {
            $accesslog_available = is_org_accesslog_available($auditLdap);
        }
        $change_history      = [];
        $changes_by_role     = ['admin' => null, 'maintainer' => null, 'org_admin' => null];
        $show_history        = false;

        if ($auditLdap !== false && $accesslog_available && $org_dn !== '') {
            $change_history  = get_org_accesslog_history($auditLdap, $org_dn, 20);
            $show_history    = true;

            // Fetch system role members once for role classification
            $admin_role_group_dn      = 'cn=' . ldap_escape($LDAP['admin_role'] ?? 'administrators', '', LDAP_ESCAPE_DN) . ',' . ($LDAP['roles_dn'] ?? '');
            $maintainer_role_group_dn = 'cn=' . ldap_escape($LDAP['maintainer_role'] ?? 'maintainers', '', LDAP_ESCAPE_DN) . ',' . ($LDAP['roles_dn'] ?? '');
            $admin_member_dns         = get_group_member_dns($auditLdap, $admin_role_group_dn);
            $maintainer_member_dns    = get_group_member_dns($auditLdap, $maintainer_role_group_dn);
            $changes_by_role          = get_org_changes_by_role($auditLdap, $org_dn, $admin_member_dns, $maintainer_member_dns, 50);
        }
        if ($auditLdap !== false && (is_resource($auditLdap) || (is_object($auditLdap) && $auditLdap instanceof \LDAP\Connection))) {
            @ldap_close($auditLdap);
        }
        if (!$accesslog_available && $org_dn !== '') {
            // Fallback: use modifyTimestamp / modifiersName from the org entry itself
            $fallback_ts   = (string) ($organization_by_uuid['modifytimestamp'][0] ?? '');
            $fallback_mod  = (string) ($organization_by_uuid['modifiersname'][0] ?? '');
            if ($fallback_ts !== '') {
                $show_history   = true;
                $change_history = [
                    [
                  'timestamp'     => parse_accesslog_timestamp($fallback_ts),
                  'actor_dn'      => $fallback_mod,
                  'actor_display' => extract_actor_display_name($fallback_mod),
                  'changed_attrs' => [],
                    ],
                ];
            }
        }
        ?>

  <div class="card mt-3">
   <div class="card-header">
    <h4 class="h5 mb-0"><?php echo htmlspecialchars(t('manage.orgs.show.change_history_heading'), ENT_QUOTES, 'UTF-8'); ?></h4>
   </div>
   <div class="card-body">
        <?php if (!$accesslog_available) : ?>
     <p class="text-muted small">
            <?php echo htmlspecialchars(t('manage.orgs.show.change_history_no_accesslog'), ENT_QUOTES, 'UTF-8'); ?>
     </p>
        <?php endif; ?>

        <?php if ($accesslog_available && array_filter($changes_by_role) !== []) : ?>
    <!-- Role-class summary: latest change per role -->
    <div class="row mb-3">
            <?php
            $role_configs = [
            'admin'       => ['label' => t('manage.orgs.show.change_history_role_admin'),       'badge' => 'danger'],
            'maintainer'  => ['label' => t('manage.orgs.show.change_history_role_maintainer'),  'badge' => 'warning'],
            'org_admin'   => ['label' => t('manage.orgs.show.change_history_role_org_admin'),   'badge' => 'info'],
            ];
     // Determine which role has the most recent change (for highlighting)
            $latest_role = null;
            $latest_ts   = 0;
            foreach ($changes_by_role as $role_key => $role_change) {
                if ($role_change !== null && $role_change['timestamp'] > $latest_ts) {
                    $latest_ts   = $role_change['timestamp'];
                    $latest_role = $role_key;
                }
            }
            foreach ($role_configs as $role_key => $role_cfg) :
                $role_change = $changes_by_role[$role_key];
                $is_latest   = ($role_key === $latest_role);
                ?>
      <div class="col-sm-4 mb-2">
       <div class="card h-100<?php echo $is_latest ? ' border-primary' : ''; ?>">
        <div class="card-body p-2">
         <div class="d-flex align-items-center gap-1 mb-1">
          <span class="badge bg-<?php echo htmlspecialchars($role_cfg['badge'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($role_cfg['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($is_latest) : ?>
           <span class="badge bg-primary"><?php echo htmlspecialchars(t('manage.orgs.show.change_history_latest'), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
         </div>
                <?php if ($role_change !== null) : ?>
          <small class="d-block" title="<?php echo htmlspecialchars(date('Y-m-d H:i:s', $role_change['timestamp']), ENT_QUOTES, 'UTF-8'); ?>">
           <strong><?php echo htmlspecialchars($role_change['actor_display'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                    <?php echo htmlspecialchars(format_relative_time($role_change['timestamp']), ENT_QUOTES, 'UTF-8'); ?>
          </small>
                <?php else : ?>
          <small class="text-muted"><?php echo htmlspecialchars(t('manage.orgs.show.change_history_no_changes_role'), ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
        </div>
       </div>
      </div>
            <?php endforeach; ?>
    </div>
        <?php endif; ?>

        <?php if (!$show_history || empty($change_history)) : ?>
     <p class="text-muted"><?php echo htmlspecialchars(t('manage.orgs.show.change_history_empty'), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else : ?>
            <?php if (!$accesslog_available) : ?>
      <p class="text-muted small"><?php echo htmlspecialchars(t('manage.orgs.show.change_history_fallback_note'), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
     <div class="table-responsive">
      <table class="table table-sm table-striped">
       <thead>
        <tr>
         <th><?php echo htmlspecialchars(t('manage.orgs.show.change_history_col_time'), ENT_QUOTES, 'UTF-8'); ?></th>
         <th><?php echo htmlspecialchars(t('manage.orgs.show.change_history_col_by'), ENT_QUOTES, 'UTF-8'); ?></th>
            <?php if ($accesslog_available) : ?>
          <th><?php echo htmlspecialchars(t('manage.orgs.show.change_history_col_attrs'), ENT_QUOTES, 'UTF-8'); ?></th>
            <?php endif; ?>
        </tr>
       </thead>
       <tbody>
            <?php foreach ($change_history as $change_entry) : ?>
         <tr>
          <td>
           <span title="<?php echo htmlspecialchars(date('Y-m-d H:i:s T', $change_entry['timestamp']), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars(date('Y-m-d H:i', $change_entry['timestamp']), ENT_QUOTES, 'UTF-8'); ?>
           </span>
           <br>
           <small class="text-muted"><?php echo htmlspecialchars(format_relative_time($change_entry['timestamp']), ENT_QUOTES, 'UTF-8'); ?></small>
          </td>
          <td><?php echo htmlspecialchars($change_entry['actor_display'], ENT_QUOTES, 'UTF-8'); ?></td>
                <?php if ($accesslog_available) : ?>
           <td>
                    <?php if (!empty($change_entry['changed_attrs'])) : ?>
             <small><?php echo htmlspecialchars(implode(', ', $change_entry['changed_attrs']), ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php else : ?>
             <small class="text-muted">—</small>
                    <?php endif; ?>
           </td>
                <?php endif; ?>
         </tr>
            <?php endforeach; ?>
       </tbody>
      </table>
     </div>
        <?php endif; ?>
   </div>
  </div>
  <?php endif; ?>

  <!-- Edit Organization Form (Hidden by default) -->
  <?php if ($can_modify_org) : ?>
  <div class="card" id="editForm" style="display: none;">
   <div class="card-header text-center"><?php echo htmlspecialchars(t('manage.orgs.show.edit_organization_form_heading'), ENT_QUOTES, 'UTF-8'); ?></div>
   <div class="card-body text-center">
    <form class="form-horizontal" action="" method="post">
        <?= csrfTokenField() ?>
     <input type="hidden" name="update_organization">
     
        <?php
        $can_rename_org_form = currentUserIsGlobalAdmin() || currentUserIsMaintainer();
        $org_display_name = (string) ($organization['o'] ?? $organization['name'] ?? $org_name);
        $org_name_field_label = $LDAP['org_field_labels']['org_name'] ?? t('manage.fields.org_name');
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

                $widget = $LDAP['org_field_widgets'][$form_field] ?? null;
                $label_for = ($widget === 'website') ? $form_field . '_host' : $form_field;

                echo '<label for="' . $label_for . '" class="' . $label_class . '">' . $label . '</label>';
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

                if ($widget === 'website') {
                    renderWebsiteUrlField((string) $form_field, $label, (string) $current_value, $is_required, '', false);
                } elseif ($field_type === 'textarea') {
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
                $country_picker_attrs = ' data-country-picker'
                    . ' data-placeholder="' . htmlspecialchars(t('manage.orgs.form.country_placeholder'), ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-no-results="' . htmlspecialchars(t('manage.orgs.form.country_no_results'), ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-assistive-hint="' . htmlspecialchars(t('manage.orgs.form.country_assistive_hint'), ENT_QUOTES, 'UTF-8') . '"';
                echo '<select class="form-select" id="' . $field_name . '" name="' . $field_name . '"' . $required_attr . $country_picker_attrs . '>';
                echo '<option value=""></option>';
                if ($selected_value !== '' && !isset($country_options[$selected_value])) {
                    $grandfather_label = getLocalizedCountryName($selected_value);
                    echo '<option value="' . htmlspecialchars($selected_value, ENT_QUOTES, 'UTF-8') . '" selected>' .
                        htmlspecialchars($grandfather_label . ' (' . $selected_value . ')', ENT_QUOTES, 'UTF-8') . '</option>';
                }
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
          <label for="org_member_number" class="form-label"><?php echo htmlspecialchars($LDAP['org_field_labels']['org_member_number'] ?? t('manage.fields.member_number'), ENT_QUOTES, 'UTF-8'); ?></label>
          <input type="text" class="form-control" id="org_member_number" name="org_member_number" value="<?php echo htmlspecialchars((string) ($organization['memberNumber'] ?? '')); ?>">
        </div>
        <div class="col-sm-3 mb-2">
          <label for="org_member_since" class="form-label"><?php echo htmlspecialchars($LDAP['org_field_labels']['org_member_since'] ?? t('manage.fields.member_since'), ENT_QUOTES, 'UTF-8'); ?></label>
          <input type="date" class="form-control" id="org_member_since" name="org_member_since" value="<?php echo htmlspecialchars((string) ($organization['memberSince'] ?? '')); ?>">
        </div>
        <div class="col-sm-3 mb-2">
          <label for="org_member_until" class="form-label"><?php echo htmlspecialchars($LDAP['org_field_labels']['org_member_until'] ?? t('manage.fields.member_until'), ENT_QUOTES, 'UTF-8'); ?></label>
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
// Disable/Enable Confirmation Modals
renderConfirmModal(
    'disableModal',
    t('manage.orgs.show.modal.deactivate_title'),
    t('manage.orgs.show.modal.deactivate_body'),
    [
        ['name' => 'action', 'value' => 'disable_organization'],
        ['name' => 'org_name', 'id' => 'disableOrgNameInput'],
    ],
    t('manage.orgs.show.modal.deactivate_submit'),
    'btn-warning'
);
renderConfirmModal(
    'enableModal',
    t('manage.orgs.show.modal.activate_title'),
    t('manage.orgs.show.modal.activate_body'),
    [
        ['name' => 'action', 'value' => 'enable_organization'],
        ['name' => 'org_name', 'id' => 'enableOrgNameInput'],
    ],
    t('manage.orgs.show.modal.activate_submit'),
    'btn-success'
);

renderConfirmModal(
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

renderConfirmModal(
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

renderConfirmModal(
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

<link rel="stylesheet" href="<?php print getAssetBase(); ?>css/accessible-autocomplete.min.css">
<link rel="stylesheet" href="<?php print getAssetBase(); ?>css/country-picker.min.css">
<script src="<?php print getAssetBase(); ?>js/org.min.js"></script>
<script src="<?php print getAssetBase(); ?>js/modals.min.js"></script>
<script>
function confirmDisableOrganization(orgName) {
    confirmAction('disableModal', { disableOrgName: orgName, disableOrgNameInput: orgName });
}
function confirmEnableOrganization(orgName) {
    confirmAction('enableModal', { enableOrgName: orgName, enableOrgNameInput: orgName });
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
renderFooter();

?> 