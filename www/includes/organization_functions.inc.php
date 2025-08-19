<?php

include_once "ldap_functions.inc.php";
include_once "config.inc.php";

function createOrganization($orgData) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    // Use configurable required fields
    $required = $LDAP['org_required_fields'];
    
    // Check that all required fields are present
    foreach ($required as $field) {
        if (empty($orgData[$field])) {
            error_log("createOrganization: Missing required field '$field'.");
            return [false, "Missing required field: $field"];
        }
    }
    
    $orgRDN = ldap_escape($orgData['o'], '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN}," . $LDAP['org_dn'];

    // Check that parent DN exists
    $parentSearch = ldap_read($ldap, $LDAP['org_dn'], '(objectClass=*)', ['dn']);
    if (!$parentSearch) {
        error_log("createOrganization: Parent DN {$LDAP['org_dn']} does not exist.");
        return [false, "Parent DN does not exist: {$LDAP['org_dn']}"];
    }

    // Build organization entry dynamically based on configuration
    $orgEntry = [
        'objectClass' => ['top', 'organization']
    ];
    
    // Add all configured fields (required + optional) that are present in the input data
    $all_configured_fields = array_merge($LDAP['org_required_fields'], $LDAP['org_optional_fields']);
    
    foreach ($all_configured_fields as $ldap_attr) {
        if (isset($orgData[$ldap_attr]) && !empty($orgData[$ldap_attr])) {
            $orgEntry[$ldap_attr] = $orgData[$ldap_attr];
        }
    }
    
    // Special handling for postalAddress if both street and city are present
    if (isset($orgData['street']) && isset($orgData['city']) && isset($orgData['state']) && isset($orgData['postalCode']) && isset($orgData['country'])) {
        $postalAddress = $orgData['street'] . '$' . $orgData['city'] . '$' . $orgData['state'] . '$' . $orgData['postalCode'] . '$' . $orgData['country'];
        $orgEntry['postalAddress'] = $postalAddress;
    }
    
    // Debug logging
    error_log("createOrganization: Building entry for org '{$orgData['o']}' with fields: " . implode(', ', array_keys($orgEntry)));
    
    $result = ldap_add($ldap, $orgDN, $orgEntry);
    if (!$result) {
        $err = ldap_error($ldap);
        error_log("createOrganization: Failed to add org entry: $err");
        return [false, "Failed to add organization: $err"];
    }

    // Create Users OU
    $usersOU = [
        'objectClass' => ['top', 'organizationalUnit'],
        'ou' => 'users'
    ];

    $resultUsers = ldap_add($ldap, "ou=users,{$orgDN}", $usersOU);
    if (!$resultUsers) {
        $err = ldap_error($ldap);
        error_log("createOrganization: Failed to add Users OU: $err");
        return [false, "Failed to add Users OU: $err"];
    }

    // Create Roles OU
    $rolesOU = [
        'objectClass' => ['top', 'organizationalUnit'],
        'ou' => 'roles'
    ];

    $resultRoles = ldap_add($ldap, "ou=roles,{$orgDN}", $rolesOU);
    if (!$resultRoles) {
        $err = ldap_error($ldap);
        error_log("createOrganization: Failed to add Roles OU: $err");
        return [false, "Failed to add Roles OU: $err"];
    }

    // Note: org_admin role will be created dynamically when users are assigned to it
    // This prevents creating empty groups and ensures proper role management

    ldap_close($ldap);
    return [true, "Organization '{$orgData['o']}' created successfully"];
}

function deleteOrganization($orgName) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN}," . $LDAP['org_dn'];
    
    // Recursively delete the organization
    $result = ldap_delete_recursive($ldap, $orgDN);
    ldap_close($ldap);
    
    if ($result) {
        return [true, "Organization '$orgName' deleted successfully"];
    } else {
        $err = ldap_error($ldap);
        error_log("deleteOrganization: Failed to delete organization '$orgName': $err");
        return [false, "Failed to delete organization: $err"];
    }
}

function setOrganizationStatus($orgName, $status) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN}," . $LDAP['org_dn'];
    
    $modifications = ['description' => $status];
    $result = ldap_modify($ldap, $orgDN, $modifications);
    ldap_close($ldap);
    
    if ($result) {
        return [true, "Organization '$orgName' status updated successfully"];
    } else {
        $err = ldap_error($ldap);
        error_log("setOrganizationStatus: Failed to update organization '$orgName' status: $err");
        return [false, "Failed to update organization status: $err"];
    }
}

function listOrganizations() {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $search = ldap_search($ldap, $LDAP['org_dn'], '(objectClass=organization)', 
        ['o', 'postalAddress', 'telephoneNumber', 'labeledURI', 'mail', 'description']);
    
    if (!$search) {
        ldap_close($ldap);
        return [];
    }
    
    $entries = ldap_get_entries($ldap, $search);
    ldap_close($ldap);
    
    $organizations = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $org = $entries[$i];
        $postalAddress = isset($org['postaladdress'][0]) ? $org['postaladdress'][0] : '';
        
        // Parse postalAddress: Street$City$State$ZIP$Country
        $addressParts = explode('$', $postalAddress);
        $organizations[] = [
            'dn' => $org['dn'],
            'name' => $org['o'][0],
            'street' => isset($addressParts[0]) ? $addressParts[0] : '',
            'city' => isset($addressParts[1]) ? $addressParts[1] : '',
            'state' => isset($addressParts[2]) ? $addressParts[2] : '',
            'postalCode' => isset($addressParts[3]) ? $addressParts[3] : '',
            'country' => isset($addressParts[4]) ? $addressParts[4] : '',
            'telephoneNumber' => isset($org['telephonenumber'][0]) ? $org['telephonenumber'][0] : '',
            'labeledURI' => isset($org['labeleduri'][0]) ? $org['labeleduri'][0] : '',
            'mail' => isset($org['mail'][0]) ? $org['mail'][0] : '',
            'status' => isset($org['description'][0]) ? $org['description'][0] : 'enabled'
        ];
    }
    
    return $organizations;
}

function ldap_delete_recursive($ldap, $dn) {
    // Search for all children
    $search = ldap_list($ldap, $dn, '(objectClass=*)', ['dn']);
    if ($search) {
        $entries = ldap_get_entries($ldap, $search);
        for ($i = 0; $i < $entries['count']; $i++) {
            ldap_delete_recursive($ldap, $entries[$i]['dn']);
        }
    }
    
    // Delete the entry itself
    return ldap_delete($ldap, $dn);
}

function addUserToOrgAdmin($orgName, $userDn) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDN = "cn={$LDAP['org_admin_role']},o={$orgRDN}," . $LDAP['org_dn'];
    
    // First, check if the group exists, if not create it
    $search = ldap_search($ldap, $groupDN, '(objectClass=*)', ['dn']);
    if (!$search || ldap_count_entries($ldap, $search) == 0) {
        // Create the organization admin group with the user as the first member
        $groupData = [
            'objectClass' => ['top', 'groupOfNames'],
            'cn' => $LDAP['org_admin_role'],
            'description' => "Organization administrators for {$orgName}",
            'member' => [$userDn]
        ];
        $result = ldap_add($ldap, $groupDN, $groupData);
        if (!$result) {
            $err = ldap_error($ldap);
            error_log("addUserToOrgAdmin: Failed to create org_admin group: $err");
            ldap_close($ldap);
            return [false, "Failed to create organization admin group: $err"];
        }
        ldap_close($ldap);
        return [true, "User added to organization admin group"];
    }
    
    // Add user to existing group
    $modifications = ['member' => $userDn];
    $result = ldap_mod_add($ldap, $groupDN, $modifications);
    ldap_close($ldap);
    
    if ($result) {
        return [true, "User added to organization admin group"];
    } else {
        $err = ldap_error($ldap);
        error_log("addUserToOrgAdmin: Failed to add user to group: $err");
        return [false, "Failed to add user to organization admin group: $err"];
    }
}

function removeUserFromOrgAdmin($orgName, $userDn) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDN = "cn={$LDAP['org_admin_role']},o={$orgRDN}," . $LDAP['org_dn'];
    
    $modifications = ['member' => $userDn];
    $result = ldap_mod_del($ldap, $groupDN, $modifications);
    ldap_close($ldap);
    
    if ($result) {
        return [true, "User removed from organization admin group"];
    } else {
        $err = ldap_error($ldap);
        error_log("removeUserFromOrgAdmin: Failed to remove user from group: $err");
        return [false, "Failed to remove user from organization admin group: $err"];
    }
}

function getOrganizationUsers($orgName) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDN = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    
    $search = ldap_search($ldap, $usersDN, '(objectClass=inetOrgPerson)', 
        ['uid', 'cn', 'sn', 'givenName', 'mail', 'description', 'organization']);
    
    if (!$search) {
        ldap_close($ldap);
        return [];
    }
    
    $entries = ldap_get_entries($ldap, $search);
    ldap_close($ldap);
    
    $users = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $user = $entries[$i];
        $users[] = [
            'dn' => $user['dn'],
            'uid' => isset($user['uid'][0]) ? $user['uid'][0] : '',
            'cn' => isset($user['cn'][0]) ? $user['cn'][0] : '',
            'sn' => isset($user['sn'][0]) ? $user['sn'][0] : '',
            'givenName' => isset($user['givenname'][0]) ? $user['givenname'][0] : '',
            'mail' => isset($user['mail'][0]) ? $user['mail'][0] : '',
            'role' => isset($user['description'][0]) ? $user['description'][0] : 'user',
            'organization' => isset($user['organization'][0]) ? $user['organization'][0] : $orgName
        ];
    }
    
    return $users;
}



function isUserOrgAdmin($orgName, $userDn) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDN = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    
    $search = ldap_search($ldap, $groupDN, '(member=' . ldap_escape($userDn, '', LDAP_ESCAPE_FILTER) . ')', ['dn']);
    $isMember = $search && ldap_count_entries($ldap, $search) > 0;
    
    ldap_close($ldap);
    return $isMember;
}

function isUserOrgManager($orgName, $userDn) {
    // Alias for isUserOrgAdmin - both refer to the same role
    return isUserOrgAdmin($orgName, $userDn);
}

