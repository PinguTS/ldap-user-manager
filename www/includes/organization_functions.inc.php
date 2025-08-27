<?php
declare(strict_types=1);

include_once "ldap_functions.inc.php";
include_once "config.inc.php";

function createOrganization($orgData) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    // Check that required field 'o' (organization name) is present
    if (empty($orgData['o'])) {
        error_log("createOrganization: Missing required field 'o' (organization name).");
        return [false, "Missing required field: organization name"];
    }
    
    $orgRDN = ldap_escape($orgData['o'], '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN}," . $LDAP['org_dn'];

    // Check that parent DN exists
    $parentSearch = ldap_read($ldap, $LDAP['org_dn'], '(objectClass=*)', ['dn']);
    if (!$parentSearch) {
        error_log("createOrganization: Parent DN {$LDAP['org_dn']} does not exist.");
        return [false, "Parent DN does not exist: {$LDAP['org_dn']}"];
    }

    // Build organization entry with proper object classes for extended attributes
    $orgEntry = [
        'objectClass' => ['top', 'organization', 'labeledURIObject', 'extensibleObject']
    ];
    
    // Add the organization name (required)
    $orgEntry['o'] = $orgData['o'];
    
    // Add optional fields that are present in the input data
    foreach ($LDAP['org_optional_fields'] as $ldap_attr) {
        if (isset($orgData[$ldap_attr]) && !empty($orgData[$ldap_attr])) {
            $orgEntry[$ldap_attr] = $orgData[$ldap_attr];
        }
    }
    
    // Special handling for postalAddress from individual address fields
    // This handles both direct postalAddress input and composite from individual fields
    if (isset($orgData['postalAddress']) && !empty($orgData['postalAddress'])) {
        // Direct postalAddress input
        $orgEntry['postalAddress'] = $orgData['postalAddress'];
    } elseif (isset($orgData['street']) || isset($orgData['city']) || isset($orgData['state']) || isset($orgData['postalCode']) || isset($orgData['country'])) {
        // Build postalAddress from individual fields (format: Street$ZIP$City$State$Country)
        $postal_parts = [
            $orgData['street'] ?? '',
            $orgData['postalCode'] ?? '',
            $orgData['city'] ?? '',
            $orgData['state'] ?? '',
            $orgData['country'] ?? ''
        ];
        $postal_address = implode('$', $postal_parts);
        if (!empty(trim($postal_address, '$'))) {
            $orgEntry['postalAddress'] = $postal_address;
        }
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
        ['o', 'postalAddress', 'telephoneNumber', 'labeledURI', 'mail', 'description', 'entryUUID']);
    
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
            'entryUUID' => isset($org['entryuuid'][0]) ? $org['entryuuid'][0] : '',
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
    $groupDN = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    
    // First, check if the roles directory exists, if not create it
    $rolesDN = "ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    $rolesDirExists = @ldap_read($ldap, $rolesDN, '(objectClass=*)', ['dn']);
    if (!$rolesDirExists) {
        // Create the ou=roles directory under the organization
        $rolesDirEntry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou' => 'roles',
            'description' => 'Roles for organization ' . $orgName
        ];
        
        $createRolesDir = @ldap_add($ldap, $rolesDN, $rolesDirEntry);
        if (!$createRolesDir) {
            $err = ldap_error($ldap);
            error_log("addUserToOrgAdmin: Failed to create roles directory: $err");
            ldap_close($ldap);
            return [false, "Failed to create roles directory: $err"];
        }
    }
    
    // First, check if the group exists, if not create it
    $search = @ldap_search($ldap, $groupDN, '(objectClass=*)', ['dn']);
    if (!$search || ldap_count_entries($ldap, $search) == 0) {
        // Create the organization admin group with the user as the first member
        $groupData = [
            'objectClass' => ['top', 'groupOfNames'],
            'cn' => $LDAP['org_admin_role'],
            'description' => "Organization {$LDAP['role_display_labels']['org_admin_role']}s for {$orgName}",
            'member' => [$userDn]
        ];
        $result = @ldap_add($ldap, $groupDN, $groupData);
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

/**
 * Update organization attributes
 * @param string $orgIdentifier Organization name or UUID
 * @param array $orgData Organization data to update
 * @return bool Success status
 */
function updateOrganization($orgIdentifier, $orgData) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    // Determine if we're using UUID or name-based lookup
    if ($LDAP['use_uuid_identification'] && is_valid_uuid($orgIdentifier)) {
        // UUID-based lookup
        $org_entry = ldap_get_organization_by_uuid($ldap, $orgIdentifier);
        if (!$org_entry) {
            ldap_close($ldap);
            return false;
        }
        $org_dn = $org_entry['dn'];
    } else {
        // Name-based lookup
        $org_dn = "o=" . ldap_escape($orgIdentifier, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    }
    
    // Prepare modifications
    $modifications = [];
    foreach ($orgData as $attr => $value) {
        if ($attr !== 'o' && !empty($value)) { // Don't modify the organization name
            $modifications[$attr] = $value;
        }
    }
    
    if (empty($modifications)) {
        ldap_close($ldap);
        return true; // Nothing to update
    }
    
    // Perform the update
    $result = @ldap_modify($ldap, $org_dn, $modifications);
    
    if ($result) {
        ldap_close($ldap);
        return true;
    } else {
        // Get error message before closing the connection
        $error_msg = ldap_error($ldap);
        ldap_close($ldap);
        error_log("updateOrganization: Failed to update organization $orgIdentifier: " . $error_msg);
        return false;
    }
}

function removeUserFromOrgAdmin($orgName, $userDn) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDN = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    
    $modifications = ['member' => $userDn];
    $result = ldap_mod_del($ldap, $groupDN, $modifications);
    
    if ($result) {
        ldap_close($ldap);
        return [true, "User removed from organization admin group"];
    } else {
        // Get error message before closing the connection
        $err = ldap_error($ldap);
        ldap_close($ldap);
        error_log("removeUserFromOrgAdmin: Failed to remove user from group: $err");
        return [false, "Failed to remove user from organization admin group: $err"];
    }
}

function getOrganizationUsers($orgName) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $usersDN = "ou=people,o={$orgRDN}," . $LDAP['org_dn'];
    
    // First check if the users DN exists before searching
    $dnExists = @ldap_read($ldap, $usersDN, '(objectClass=*)', ['dn']);
    if (!$dnExists) {
        // The users DN doesn't exist, which means no users have been created yet
        ldap_close($ldap);
        return [];
    }
    
    $search = @ldap_search($ldap, $usersDN, '(objectClass=inetOrgPerson)', 
        ['uid', 'cn', 'sn', 'givenName', 'mail', 'description', 'organization', 'entryUUID']);
    
    if (!$search) {
        // Log the error but don't show it to the user
        $error_msg = ldap_error($ldap);
        ldap_close($ldap);
        error_log("getOrganizationUsers: LDAP search failed for DN: $usersDN. Error: " . $error_msg);
        return [];
    }
    
    $entries = ldap_get_entries($ldap, $search);
    
    // Get all organization admin members in one query
    $org_admin_members = [];
    $org_admin_group_dn = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    
    // Check if the org admin group exists
    $group_exists = @ldap_read($ldap, $org_admin_group_dn, '(objectClass=groupOfNames)', ['member']);
    if ($group_exists) {
        $group_entries = ldap_get_entries($ldap, $group_exists);
        if ($group_entries && isset($group_entries[0]['member'])) {
            for ($j = 0; $j < $group_entries[0]['member']['count']; $j++) {
                $org_admin_members[] = $group_entries[0]['member'][$j];
            }
        }
    }
    
    ldap_close($ldap);
    
    $users = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $user = $entries[$i];
        
        // Determine the actual role by checking if user is in the admin group
        $actual_role = 'user'; // Default role
        if (in_array($user['dn'], $org_admin_members)) {
            $actual_role = $LDAP['org_admin_role'];
        }
        
        $users[] = [
            'dn' => $user['dn'],
            'uid' => isset($user['uid'][0]) ? $user['uid'][0] : '',
            'cn' => isset($user['cn'][0]) ? $user['cn'][0] : '',
            'sn' => isset($user['sn'][0]) ? $user['sn'][0] : '',
            'givenName' => isset($user['givenname'][0]) ? $user['givenname'][0] : '',
            'mail' => isset($user['mail'][0]) ? $user['mail'][0] : '',
            'role' => $actual_role,
            'organization' => isset($user['organization'][0]) ? $user['organization'][0] : $orgName,
            'entryUUID' => isset($user['entryuuid'][0]) ? $user['entryuuid'][0] : ''
        ];
    }
    
    return $users;
}



function isUserOrgAdmin($orgName, $userDn) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $groupDN = "cn={$LDAP['org_admin_role']},ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    
    // First check if the group DN exists before searching
    $dnExists = @ldap_read($ldap, $groupDN, '(objectClass=*)', ['dn']);
    if (!$dnExists) {
        // The group DN doesn't exist, which means no org admin group has been created yet
        ldap_close($ldap);
        return false;
    }
    
    $search = @ldap_search($ldap, $groupDN, '(member=' . ldap_escape($userDn, '', LDAP_ESCAPE_FILTER) . ')', ['dn']);
    $isMember = $search && ldap_count_entries($ldap, $search) > 0;
    
    ldap_close($ldap);
    return $isMember;
}

function isUserOrgManager($orgName, $userDn) {
    // Alias for isUserOrgAdmin - both refer to the same role
    return isUserOrgAdmin($orgName, $userDn);
}

/**
 * Update user attributes
 * @param string $userIdentifier User UUID or DN
 * @param array $userData User data to update
 * @return bool Success status
 */
function updateUser($userIdentifier, $userData) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    // Determine if we're using UUID or DN-based lookup
    if ($LDAP['use_uuid_identification'] && is_valid_uuid($userIdentifier)) {
        // UUID-based lookup
        $user_entry = ldap_get_user_by_uuid($ldap, $userIdentifier);
        if (!$user_entry) {
            ldap_close($ldap);
            return false;
        }
        $user_dn = $user_entry['dn'];
    } else {
        // DN-based lookup
        $user_dn = $userIdentifier;
    }
    
    // Prepare modifications
    $modifications = [];
    foreach ($userData as $attr => $value) {
        if ($attr !== 'uid' && $attr !== 'dn' && !empty($value)) { // Don't modify critical fields
            $modifications[$attr] = $value;
        }
    }
    
    if (empty($modifications)) {
        ldap_close($ldap);
        return true; // Nothing to update
    }
    
    // Perform the update
    $result = @ldap_modify($ldap, $user_dn, $modifications);
    
    if ($result) {
        ldap_close($ldap);
        return true;
    } else {
        // Get error message before closing the connection
        $error_msg = ldap_error($ldap);
        ldap_close($ldap);
        error_log("updateUser: Failed to update user $userIdentifier: " . $error_msg);
        return false;
    }
}

/**
 * Delete user
 * @param string $userIdentifier User UUID or DN
 * @return bool Success status
 */
function deleteUser($userIdentifier) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    // Determine if we're using UUID or DN-based lookup
    if ($LDAP['use_uuid_identification'] && is_valid_uuid($userIdentifier)) {
        // UUID-based lookup
        $user_entry = ldap_get_user_by_uuid($ldap, $userIdentifier);
        if (!$user_entry) {
            ldap_close($ldap);
            return false;
        }
        $user_dn = $user_entry['dn'];
    } else {
        // DN-based lookup
        $user_dn = $userIdentifier;
    }
    
    // Perform the deletion
    $result = @ldap_delete($ldap, $user_dn);
    
    if ($result) {
        ldap_close($ldap);
        return true;
    } else {
        // Get error message before closing the connection
        $error_msg = ldap_error($ldap);
        ldap_close($ldap);
        error_log("deleteUser: Failed to delete user $userIdentifier: " . $error_msg);
        return false;
    }
}

/**
 * Create a new user account (system user or organization user)
 * @param array $userData User data from the form
 * @return array [success, message]
 */
function createUserAccount($userData) {
    global $LDAP;
    $ldap = open_ldap_connection();
    
    try {
        // Determine if this is a system user or organization user
        $is_system_user = in_array($userData['userRole'], [$LDAP['admin_role'], 'maintainer']);
        
        if ($is_system_user) {
            // Create system user (administrator/maintainer)
            return createSystemUser($ldap, $userData);
        } else {
            // Create organization user
            return createOrganizationUser($ldap, $userData);
        }
    } catch (Exception $e) {
        ldap_close($ldap);
        return [false, "Error creating user account: " . $e->getMessage()];
    }
}

/**
 * Create a system user (administrator/maintainer)
 * @param resource $ldap LDAP connection
 * @param array $userData User data
 * @return array [success, message]
 */
function createSystemUser($ldap, $userData) {
    global $LDAP;
    
    // System users go directly under the people DN
    $user_dn = "uid=" . ldap_escape($userData['mail'], '', LDAP_ESCAPE_DN) . "," . $LDAP['people_dn'];
    
    // Check if user already exists
    $search = @ldap_search($ldap, $LDAP['people_dn'], "(uid=" . ldap_escape($userData['mail'], '', LDAP_ESCAPE_FILTER) . ")");
    if ($search && ldap_count_entries($ldap, $search) > 0) {
        return [false, "User with email {$userData['mail']} already exists"];
    }
    
    // Prepare user attributes
    $user_attributes = [
        'objectclass' => ['top', 'person', 'organizationalPerson', 'inetOrgPerson'],
        'uid' => $userData['mail'],
        'mail' => $userData['mail'],
        'cn' => $userData['cn'],
        'givenName' => $userData['givenName'],
        'sn' => $userData['sn'],
        'userPassword' => ldap_hashed_password($userData['userPassword']),
        'description' => $userData['userRole'] // Role is stored in description
    ];
    
    // Add optional fields
    if (!empty($userData['telephoneNumber'])) {
        $user_attributes['telephoneNumber'] = $userData['telephoneNumber'];
    }
    
    // Create the user
    $result = @ldap_add($ldap, $user_dn, $user_attributes);
    if (!$result) {
        $error = ldap_error($ldap);
        return [false, "Failed to create system user: $error"];
    }
    
    // Add user to the appropriate role group
    $role_group_dn = "cn={$userData['userRole']}," . $LDAP['roles_dn'];
    $modify = @ldap_modify($ldap, $role_group_dn, ['member' => $user_dn]);
    if (!$modify) {
        // Log the warning but don't fail the user creation
        error_log("Warning: Failed to add user to role group {$userData['userRole']}: " . ldap_error($ldap));
    }
    
    return [true, "System user created successfully"];
}

/**
 * Create an organization user
 * @param resource $ldap LDAP connection
 * @param array $userData User data
 * @return array [success, message]
 */
function createOrganizationUser($ldap, $userData) {
    global $LDAP;
    
    // Organization users go under their organization
    $org_name = $userData['o'];
    $user_dn = "uid=" . ldap_escape($userData['mail'], '', LDAP_ESCAPE_DN) . ",ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    
    // Check if organization exists
    $org_search = @ldap_search($ldap, $LDAP['org_dn'], "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_FILTER));
    if (!$org_search || ldap_count_entries($ldap, $org_search) == 0) {
        return [false, "Organization '$org_name' does not exist"];
    }
    
    // Check if user already exists in this organization
    $user_search = @ldap_search($ldap, "ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'], 
                               "(uid=" . ldap_escape($userData['mail'], '', LDAP_ESCAPE_FILTER) . ")");
    if ($user_search && ldap_count_entries($ldap, $user_search) > 0) {
        return [false, "User with email {$userData['mail']} already exists in organization '$org_name'"];
    }
    
    // Ensure the users directory exists
    $users_dn = "ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $usersDirExists = @ldap_read($ldap, $users_dn, '(objectClass=*)', ['dn']);
    if (!$usersDirExists) {
        $usersDirEntry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou' => 'people',
            'description' => "Users for organization $org_name"
        ];
        
        $createUsersDir = @ldap_add($ldap, $users_dn, $usersDirEntry);
        if (!$createUsersDir) {
            return [false, "Failed to create users directory for organization '$org_name'"];
        }
    }
    
    // Prepare user attributes
    $user_attributes = [
        'objectclass' => ['top', 'person', 'organizationalPerson', 'inetOrgPerson'],
        'uid' => $userData['mail'],
        'mail' => $userData['mail'],
        'cn' => $userData['cn'],
        'givenName' => $userData['givenName'],
        'sn' => $userData['sn'],
        'userPassword' => ldap_hashed_password($userData['userPassword']),
        'o' => $org_name,
        'description' => $userData['userRole'] // Role is stored in description
    ];
    
    // Add optional fields
    if (!empty($userData['telephoneNumber'])) {
        $user_attributes['telephoneNumber'] = $userData['telephoneNumber'];
    }
    
    // Create the user
    $result = @ldap_add($ldap, $user_dn, $user_attributes);
    if (!$result) {
        $error = ldap_error($ldap);
        return [false, "Failed to create organization user: $error"];
    }
    
    // If user is an organization admin, add them to the org_admin role
    // IMPORTANT: Check organization admin role independently, regardless of role value conflicts
    if ($userData['userRole'] === $LDAP['org_admin_role']) {
        // Add user to organization admin role
        $org_roles_dn = "ou=roles,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
        $org_admin_group_dn = "cn={$LDAP['org_admin_role']},$org_roles_dn";
        
        // Ensure the org_admin group exists
        $groupExists = @ldap_read($ldap, $org_admin_group_dn, '(objectClass=*)', ['dn']);
        if (!$groupExists) {
            // Create the org_admin group if it doesn't exist
            $groupEntry = [
                'objectClass' => ['top', 'groupOfNames'],
                'cn' => $LDAP['org_admin_role'],
                'description' => "Organization {$LDAP['role_display_labels']['org_admin_role']}s for $org_name",
                'member' => [$user_dn]
            ];
            @ldap_add($ldap, $org_admin_group_dn, $groupEntry);
        } else {
            // Add user to existing org_admin group
            @ldap_modify($ldap, $org_admin_group_dn, ['member' => $user_dn]);
        }
    }
    
    return [true, "Organization user created successfully"];
}

