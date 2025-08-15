<?php

include_once "ldap_functions.inc.php";
include_once "config.inc.php";

function createOrganization($orgData) {
    global $LDAP;
    $ldap = open_ldap_connection();

    // Validate required fields
    $required = ['o', 'street', 'city', 'state', 'postalCode', 'country', 'telephoneNumber', 'labeledURI', 'mail', 'creatorDN'];
    foreach ($required as $field) {
        if (empty($orgData[$field])) {
            error_log("createOrganization: Missing required field '$field'.");
            return [false, "Missing required field: $field"];
        }
    }

    $orgRDN = ldap_escape($orgData['o'], '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN},ou=organizations," . $LDAP['base_dn'];

    // Check that parent DN exists
    $parentSearch = ldap_read($ldap, "ou=organizations," . $LDAP['base_dn'], '(objectClass=*)', ['dn']);
    if (!$parentSearch) {
        error_log("createOrganization: Parent DN ou=organizations,{$LDAP['base_dn']} does not exist.");
        return [false, "Parent DN does not exist: ou=organizations,{$LDAP['base_dn']}"];
    }

    // Create postalAddress in standard format: Street$City$State$ZIP$Country
    $postalAddress = $orgData['street'] . '$' . $orgData['city'] . '$' . $orgData['state'] . '$' . $orgData['postalCode'] . '$' . $orgData['country'];

    // Organization entry with standard attributes
    $orgEntry = [
        'objectClass' => ['top', 'organization'],
        'o' => $orgData['o'],
        'postalAddress' => $postalAddress,
        'telephoneNumber' => $orgData['telephoneNumber'],
        'labeledURI' => $orgData['labeledURI'],
        'mail' => $orgData['mail']
    ];

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

    // Create Organization Admin role
    $orgAdminGroup = [
        'objectClass' => ['top', 'groupOfNames'],
        'cn' => 'org_admin',
        'description' => 'Organization administrators for ' . $orgData['o'],
        'member' => [$orgData['creatorDN']]
    ];

    $resultAdminGroup = ldap_add($ldap, "cn=org_admin,ou=roles,{$orgDN}", $orgAdminGroup);
    if (!$resultAdminGroup) {
        $err = ldap_error($ldap);
        error_log("createOrganization: Failed to add org admin group: $err");
        return [false, "Failed to add organization admin group: $err"];
    }

    ldap_close($ldap);
    return [true, "Organization '{$orgData['o']}' created successfully"];
}

function deleteOrganization($orgName) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN},ou=organizations," . $LDAP['base_dn'];
    
    // Recursively delete the organization
    $result = ldap_delete_recursive($ldap, $orgDN);
    ldap_close($ldap);
    
    return $result;
}

function setOrganizationStatus($orgName, $status) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN},ou=organizations," . $LDAP['base_dn'];
    
    $modifications = ['description' => $status];
    $result = ldap_modify($ldap, $orgDN, $modifications);
    ldap_close($ldap);
    
    return $result;
}

function listOrganizations() {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $search = ldap_search($ldap, "ou=organizations," . $LDAP['base_dn'], '(objectClass=organization)', 
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

function addUserToOrgManagers($orgName, $userDn) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDN = "cn=org_admin,ou=roles,o={$orgRDN},ou=organizations," . $LDAP['base_dn'];
    
    $modifications = ['member' => $userDn];
    $result = ldap_mod_add($ldap, $groupDN, $modifications);
    ldap_close($ldap);
    
    return $result;
}

function removeUserFromOrgManagers($orgName, $userDn) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDN = "cn=org_admin,ou=roles,o={$orgRDN},ou=organizations," . $LDAP['base_dn'];
    
    $modifications = ['member' => $userDn];
    $result = ldap_mod_del($ldap, $groupDN, $modifications);
    ldap_close($ldap);
    
    return $result;
}

function getOrganizationUsers($orgName) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDN = "ou=users,o={$orgRDN},ou=organizations," . $LDAP['base_dn'];
    
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

