<?php
declare(strict_types=1);

/**
 * Access control and user permission functions
 * 
 * This file contains functions for checking user permissions,
 * roles, and access levels throughout the system.
 */

/**
 * Checks if the current user is a global administrator
 * This function handles role conflicts by checking global roles independently
 * 
 * @return bool True if user is global administrator, false otherwise
 */
function currentUserIsGlobalAdmin() {
    global $LDAP, $USER_DN, $USER_ID, $IS_ADMIN;
    
    // First check if we already have this information from the session
    if (isset($IS_ADMIN) && $IS_ADMIN === TRUE) {
        return true;
    }
    
    // If USER_DN is not set, try to construct it from USER_ID
    if (!$USER_DN && $USER_ID) {
        $ldap = open_ldap_connection();
        
        // Search for the user across all locations
        $user_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=$USER_ID))";
        
        // Search in organizations first
        $ldap_search = @ldap_search($ldap, $LDAP['org_dn'], $user_filter, ['dn']);
        if ($ldap_search) {
            $result = @ldap_get_entries($ldap, $ldap_search);
            if ($result['count'] > 0) {
                $USER_DN = $result[0]['dn'];
            }
        }
        
        // If not found in organizations, search in system users
        if (!$USER_DN) {
            $ldap_search = @ldap_search($ldap, $LDAP['people_dn'], $user_filter, ['dn']);
            if ($ldap_search) {
                $result = @ldap_get_entries($ldap, $ldap_search);
                if ($result['count'] > 0) {
                    $USER_DN = $result[0]['dn'];
                }
            }
        }
        
        ldap_close($ldap);
    }
    
    if (!$USER_DN) {
        error_log("currentUserIsGlobalAdmin: USER_DN not set for user $USER_ID");
        return false;
    }
    
    // Debug: Log what roles the user actually has (only if debug is enabled)
    if (isset($LDAP_DEBUG) && $LDAP_DEBUG === TRUE) {
        debugUserRoles($USER_ID);
    }
    
    $ldap = open_ldap_connection();
    
    # Check if user is in the administrator role using config variable
    # IMPORTANT: Check global roles only, regardless of role value conflicts
    $admin_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_group_name']})(member=$USER_DN))";
    $ldap_search = @ldap_search($ldap, $LDAP['roles_dn'], $admin_role_filter, ['cn']);
    if ($ldap_search) {
        $result = ldap_get_entries($ldap, $ldap_search);
        if ($result['count'] > 0) {
            error_log("currentUserIsGlobalAdmin: User $USER_ID is admin via global role group: " . $result[0]['cn'][0]);
            ldap_close($ldap);
            return true;
        }
    }
    
    ldap_close($ldap);
    error_log("currentUserIsGlobalAdmin: User $USER_ID is NOT admin");
    return false;
}

/**
 * Checks if the current user is a maintainer
 * This function handles role conflicts by checking global roles independently
 * 
 * @return bool True if user is maintainer, false otherwise
 */
function currentUserIsMaintainer() {
    global $LDAP, $USER_DN, $USER_ID, $IS_MAINTAINER;
    
    // First check if we already have this information from the session
    if (isset($IS_MAINTAINER) && $IS_MAINTAINER === TRUE) {
        return true;
    }
    
    // If USER_DN is not set, try to construct it from USER_ID
    if (!$USER_DN && $USER_ID) {
        $ldap = open_ldap_connection();
        
        // Search for the user across all locations
        $user_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=$USER_ID))";
        
        // Search in organizations first
        $ldap_search = @ldap_search($ldap, $LDAP['org_dn'], $user_filter, ['dn']);
        if ($ldap_search) {
            $result = @ldap_get_entries($ldap, $ldap_search);
            if ($result['count'] > 0) {
                $USER_DN = $result[0]['dn'];
            }
        }
        
        // If not found in organizations, search in system users
        if (!$USER_DN) {
            $ldap_search = @ldap_search($ldap, $LDAP['people_dn'], $user_filter, ['dn']);
            if ($ldap_search) {
                $result = @ldap_get_entries($ldap, $ldap_search);
                if ($result['count'] > 0) {
                    $USER_DN = $result[0]['dn'];
                }
            }
        }
        
        ldap_close($ldap);
    }
    
    if (!$USER_DN) {
        error_log("currentUserIsMaintainer: USER_DN not set for user $USER_ID");
        return false;
    }
    
    $ldap = open_ldap_connection();
    
    # Check if user is in the maintainer role using config variable
    # IMPORTANT: Check global roles only, regardless of role value conflicts
    $maintainer_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['maintainer_group_name']})(member=$USER_DN))";
    $ldap_search = @ldap_search($ldap, $LDAP['roles_dn'], $maintainer_role_filter, ['cn']);
    if ($ldap_search) {
        $result = ldap_get_entries($ldap, $ldap_search);
        if ($result['count'] > 0) {
            error_log("currentUserIsMaintainer: User $USER_ID is maintainer via global role group: " . $result[0]['cn'][0]);
            ldap_close($ldap);
            return true;
        }
    }
    
    ldap_close($ldap);
    error_log("currentUserIsMaintainer: User $USER_ID is NOT maintainer");
    return false;
}

function currentUserIsOrgManager($orgName) {
    global $LDAP, $USER_DN, $IS_ORG_ADMIN, $USER_ORG_NAME;
    
    // First check if we already have this information from the session
    if (isset($IS_ORG_ADMIN) && $IS_ORG_ADMIN === TRUE && isset($USER_ORG_NAME) && $USER_ORG_NAME === $orgName) {
        return true;
    }
    
    if (empty($orgName) || !$USER_DN) {
        return false;
    }
    
    $ldap = open_ldap_connection();
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN}," . $LDAP['org_dn'];
    
    # Check if user is in the org_admin role for this organization
    # The org_admin role is stored under ou=roles within the organization
    $org_admin_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['org_admin_role']})(member=$USER_DN))";
    $org_roles_dn = "ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
    $ldap_search = @ ldap_search($ldap, $org_roles_dn, $org_admin_filter, ['cn']);
    if ($ldap_search) {
        $result = ldap_get_entries($ldap, $ldap_search);
        ldap_close($ldap);
        return ($result['count'] > 0);
    }
    
    ldap_close($ldap);
    return false;
}

/**
 * Checks if the current user is an organization administrator
 * This function handles role conflicts by checking organization roles independently
 * 
 * @return bool True if user is organization administrator, false otherwise
 */
function currentUserIsOrgAdmin() {
    global $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_DN, $LDAP;
    
    // Check if we have this information from the session
    if (isset($IS_ORG_ADMIN) && $IS_ORG_ADMIN === TRUE && isset($USER_ORG_NAME) && !empty($USER_ORG_NAME)) {
        return true;
    }
    
    // If we don't have session data, check directly
    if (!$USER_DN || !$USER_ORG_NAME) {
        return false;
    }
    
    // IMPORTANT: Check organization roles independently, regardless of role value conflicts
    // This ensures org admin works even if global admin role has the same value
    $ldap = open_ldap_connection();
    
    // Check if user is in the organization admin role within their specific organization
    $org_roles_dn = "ou=roles,o=" . ldap_escape($USER_ORG_NAME, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $org_admin_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['org_admin_role']})(member=$USER_DN))";
    
    $org_admin_search = @ldap_search($ldap, $org_roles_dn, $org_admin_filter, ['cn']);
    if ($org_admin_search) {
        $result = ldap_get_entries($ldap, $org_admin_search);
        ldap_close($ldap);
        
        if ($result['count'] > 0) {
            error_log("currentUserIsOrgAdmin: User is org admin via organization role: " . $result[0]['cn'][0]);
            return true;
        }
    }
    
    ldap_close($ldap);
    return false;
}

function currentUserGetOrgName() {
    global $USER_ORG_NAME;
    
    // Return the organization name from session data
    if (isset($USER_ORG_NAME) && !empty($USER_ORG_NAME)) {
        return $USER_ORG_NAME;
    }
    
    return null;
}

function currentUserGetOrgUuid() {
    global $USER_ORG_UUID;
    
    // Return the organization UUID from session data
    if (isset($USER_ORG_UUID) && !empty($USER_ORG_UUID)) {
        return $USER_ORG_UUID;
    }
    
    return null;
}

function currentUserCanModifyUser($targetUserDN) {
    global $LDAP, $USER_DN;
    
    if (!$USER_DN) {
        return false;
    }
    
    # Global administrators can modify anyone
    if (currentUserIsGlobalAdmin()) {
        return true;
    }
    
    # Maintainers can modify anyone except administrators
    if (currentUserIsMaintainer()) {
        $ldap = open_ldap_connection();
        # IMPORTANT: Check global admin role independently, regardless of role value conflicts
        $admin_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_group_name']})(member=$targetUserDN))";
        $ldap_search = @ ldap_search($ldap, $LDAP['roles_dn'], $admin_role_filter, ['cn']);
        if ($ldap_search) {
            $result = ldap_get_entries($ldap, $ldap_search);
            ldap_close($ldap);
            return ($result['count'] == 0); // Can't modify {$LDAP['role_display_labels']['admin_role']}s
        }
        ldap_close($ldap);
        return true;
    }
    
    # Organization managers can modify users in their organization
    $targetUserOrg = getUserOrganization($targetUserDN);
    if ($targetUserOrg && currentUserIsOrgManager($targetUserOrg)) {
        return true;
    }
    
    return false;
}

function currentUserCanModifyOrganization($orgName) {
    // Global administrators can modify any organization
    if (currentUserIsGlobalAdmin()) {
        return true;
    }
    
    // Maintainers can modify any organization
    if (currentUserIsMaintainer()) {
        return true;
    }
    
    // Organization managers can modify their own organization
    if (currentUserIsOrgManager($orgName)) {
        return true;
    }
    
    return false;
}

function getUserOrganization($userDN) {
    global $LDAP;
    
    $ldap = open_ldap_connection();
    $search = ldap_read($ldap, $userDN, '(objectClass=*)', ['organization']);
    if (!$search) {
        ldap_close($ldap);
        return null;
    }
    
    $entries = ldap_get_entries($ldap, $search);
    ldap_close($ldap);
    
    if ($entries['count'] > 0 && isset($entries[0]['organization'][0])) {
        return $entries[0]['organization'][0];
    }
    
    // If no organization attribute, try to extract from DN
            if (preg_match('/o=([^,]+),' . preg_quote($LDAP['org_ou'], '/') . ',/', $userDN, $matches)) {
        return $matches[1];
    }
    
    return null;
}

function currentUserCanCreateOrganization() {
    // Only global administrators and maintainers can create organizations
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer());
}

function currentUserCanDeleteOrganization($orgName) {
    // Only global administrators and maintainers can delete organizations
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer());
}

function currentUserCanCreateUser($orgName = null) {
    // Global administrators can create users anywhere
    // Maintainers can create users in any organization
    // Organization managers can create users in their organization
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer() || ($orgName && currentUserIsOrgManager($orgName)));
}

function currentUserCanDeleteUser($targetUserDN) {
    // Global administrators can delete anyone
    if (currentUserIsGlobalAdmin()) {
        return true;
    }
    
    # Maintainers can delete anyone except administrators
    if (currentUserIsMaintainer()) {
        $ldap = open_ldap_connection();
        # IMPORTANT: Check global admin role independently, regardless of role value conflicts
        $admin_role_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_group_name']})(member=$targetUserDN))";
        $ldap_search = @ldap_search($ldap, $LDAP['roles_dn'], $admin_role_filter, ['cn']);
        if ($ldap_search) {
            $result = ldap_get_entries($ldap, $ldap_search);
            ldap_close($ldap);
            return ($result['count'] == 0); // Can't delete administrators
        }
        ldap_close($ldap);
        return true;
    }
    
    // Organization managers can delete users in their organization
    $targetUserOrg = getUserOrganization($targetUserDN);
    if ($targetUserOrg && currentUserIsOrgManager($targetUserOrg)) {
        return true;
    }
    
    // Users cannot delete themselves (safety measure)
    return false;
}

function debugUserRoles($username = null) {
    global $LDAP, $USER_DN, $USER_ID;
    
    if (!$username) {
        $username = $USER_ID;
    }
    
    if (!$username) {
        error_log("debugUserRoles: No username provided");
        return false;
    }
    
    $ldap = open_ldap_connection();
    
    // Get user DN
    $user_dn = null;
    $user_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=$username))";
    
    // Search in organizations first
    $ldap_search = @ ldap_search($ldap, $LDAP['org_dn'], $user_filter, ['dn']);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap, $ldap_search);
        if ($result['count'] > 0) {
            $user_dn = $result[0]['dn'];
        }
    }
    
    // If not found in organizations, search in system users
    if (!$user_dn) {
        $ldap_search = @ ldap_search($ldap, $LDAP['people_dn'], $user_filter, ['dn']);
        if ($ldap_search) {
            $result = @ ldap_get_entries($ldap, $ldap_search);
            if ($result['count'] > 0) {
                $user_dn = $result[0]['dn'];
            }
        }
    }
    
    if (!$user_dn) {
        error_log("debugUserRoles: User $username not found");
        ldap_close($ldap);
        return false;
    }
    
    error_log("debugUserRoles: User $username has DN: $user_dn");
    
    // Check global roles
    $global_roles_filter = "(&(objectclass=groupOfNames)(member=$user_dn))";
    $ldap_search = @ ldap_search($ldap, $LDAP['roles_dn'], $global_roles_filter, ['cn']);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap, $ldap_search);
        if ($result['count'] > 0) {
            error_log("debugUserRoles: User $username has global roles:");
            for ($i = 0; $i < $result['count']; $i++) {
                error_log("  - " . $result[$i]['cn'][0]);
            }
        } else {
            error_log("debugUserRoles: User $username has no global roles");
        }
    }
    
    // Check organization-specific roles (only if user is in an organization)
    if (strpos($user_dn, $LDAP['org_dn']) === 0) {
        // Extract organization name from DN
        if (preg_match('/o=([^,]+),/', $user_dn, $matches)) {
            $org_name = $matches[1];
            $org_roles_dn = "ou=roles,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
            
            $org_roles_filter = "(&(objectclass=groupOfNames)(member=$user_dn))";
            $ldap_search = @ ldap_search($ldap, $org_roles_dn, $org_roles_filter, ['cn']);
            if ($ldap_search) {
                $result = @ ldap_get_entries($ldap, $ldap_search);
                if ($result['count'] > 0) {
                    error_log("debugUserRoles: User $username has organization roles:");
                    for ($i = 0; $i < $result['count']; $i++) {
                        error_log("  - " . $result[$i]['cn'][0]);
                    }
                } else {
                    error_log("debugUserRoles: User $username has no organization roles");
                }
            }
        }
    }
    
    ldap_close($ldap);
    return true;
}

/**
 * Gets the highest role level for the current user
 * This function handles role conflicts by returning the highest privilege level
 * 
 * @return string The highest role level: 'global_admin', 'maintainer', 'org_admin', or 'user'
 */
function currentUserGetHighestRole() {
    if (currentUserIsGlobalAdmin()) {
        return 'global_admin';
    } elseif (currentUserIsMaintainer()) {
        return 'maintainer';
    } elseif (currentUserIsOrgAdmin()) {
        return 'org_admin';
    } else {
        return 'user';
    }
}

/**
 * Checks if the current user has a role at or above the specified level
 * 
 * @param string $requiredLevel The required role level
 * @return bool True if user has sufficient privileges, false otherwise
 */
function currentUserHasRoleLevel($requiredLevel) {
    global $LDAP;
    
    $userLevel = currentUserGetHighestRole();
    $userLevelValue = $LDAP['role_hierarchy'][$userLevel] ?? 0;
    $requiredLevelValue = $LDAP['role_hierarchy'][$requiredLevel] ?? 0;
    
    return $userLevelValue >= $requiredLevelValue;
}

/**
 * Validates that role configuration doesn't create conflicts
 * This function should be called during system initialization
 * 
 * @return array [isValid, conflicts] - validation result and any conflicts found
 */
function validateRoleConfiguration() {
    global $LDAP;
    
    $conflicts = [];
    
    // Check for duplicate role values
    $role_values = [
        'admin_role' => $LDAP['admin_role'],
        'maintainer_role' => $LDAP['maintainer_role'],
        'org_admin_role' => $LDAP['org_admin_role'],
        'user_role' => $LDAP['user_role']
    ];
    
    $unique_values = array_unique($role_values);
    if (count($role_values) !== count($unique_values)) {
        $duplicates = array_diff_assoc($role_values, array_unique($role_values));
        $conflicts[] = "Duplicate role values: " . implode(', ', array_keys($duplicates));
    }
    
    // Check for duplicate group names
    $group_values = [
        'admin_group_name' => $LDAP['admin_group_name'],
        'maintainer_group_name' => $LDAP['maintainer_group_name']
    ];
    
    $unique_groups = array_unique($group_values);
    if (count($group_values) !== count($unique_groups)) {
        $duplicates = array_diff_assoc($group_values, array_unique($unique_groups));
        $conflicts[] = "Duplicate group names: " . implode(', ', array_keys($duplicates));
    }
    
    // Check for role value conflicts with group names
    foreach ($role_values as $role_key => $role_value) {
        foreach ($group_values as $group_key => $group_value) {
            if ($role_value === $group_value) {
                $conflicts[] = "Role value '$role_value' conflicts with group name '$group_value'";
            }
        }
    }
    
    return [empty($conflicts), $conflicts];
}
