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
function currentUserIsGlobalAdmin(): bool
{
    global $LDAP, $LDAP_DEBUG, $USER_DN, $USER_ID, $IS_ADMIN;

    // First check if we already have this information from the session
    if (isset($IS_ADMIN) && $IS_ADMIN === true) {
        return true;
    }

    // Ensure we have the user DN
    if (!$USER_DN && $USER_ID) {
        $USER_DN = resolveUserDn($USER_ID);
    }

    if (!$USER_DN) {
        error_log("currentUserIsGlobalAdmin: USER_DN not set for user {$USER_ID}");
        return false;
    }

    // Debug: Log what roles the user actually has (only if debug is enabled)
    if (isset($LDAP_DEBUG) && $LDAP_DEBUG === true) {
        debugUserRoles($USER_ID);
    }

    return checkUserAdminRole($USER_DN);
}

/**
 * Resolves a user's DN from their ID
 *
 * @param string $userId User ID to resolve
 * @return string|null User DN or null if not found
 */
function resolveUserDn(string $userId): ?string
{
    global $LDAP;

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return null;
    }

    try {
        $userFilter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}={$userId}))";

        // Search in organizations first
        $search = @ldap_search($ldap, $LDAP['org_dn'], $userFilter, ['dn']);
        if ($search) {
            $result = @ldap_get_entries($ldap, $search);
            if ($result['count'] > 0) {
                return $result[0]['dn'];
            }
        }

        // If not found in organizations, search in system users
        $search = @ldap_search($ldap, $LDAP['people_dn'], $userFilter, ['dn']);
        if ($search) {
            $result = @ldap_get_entries($ldap, $search);
            if ($result['count'] > 0) {
                return $result[0]['dn'];
            }
        }

        return null;
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Checks if a user has the admin role
 *
 * @param string $userDn User DN to check
 * @return bool True if user has admin role
 */
function checkUserAdminRole(string $userDn): bool
{
    global $LDAP;

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }

    try {
        // Check if user is in the administrator role using config variable
        // IMPORTANT: Check global roles only, regardless of role value conflicts
        $adminRoleFilter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_role']})(member={$userDn}))";
        $search = @ldap_search($ldap, $LDAP['roles_dn'], $adminRoleFilter, ['cn']);

        if ($search) {
            $result = ldap_get_entries($ldap, $search);
            if ($result['count'] > 0) {
                error_log("currentUserIsGlobalAdmin: User has admin role via global role group: " . $result[0]['cn'][0]);
                return true;
            }
        }

        return false;
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Checks if the current user is a maintainer
 * This function handles role conflicts by checking global roles independently
 *
 * @return bool True if user is maintainer, false otherwise
 */
function currentUserIsMaintainer(): bool
{
    global $LDAP, $USER_DN, $USER_ID, $IS_MAINTAINER;

    // First check if we already have this information from the session
    if (isset($IS_MAINTAINER) && $IS_MAINTAINER === true) {
        return true;
    }

    // Ensure we have the user DN
    if (!$USER_DN && $USER_ID) {
        $USER_DN = resolveUserDn($USER_ID);
    }

    if (!$USER_DN) {
        error_log("currentUserIsMaintainer: USER_DN not set for user {$USER_ID}");
        return false;
    }

    return checkUserMaintainerRole($USER_DN);
}

/**
 * Checks if a user has the maintainer role
 *
 * @param string $userDn User DN to check
 * @return bool True if user has maintainer role
 */
function checkUserMaintainerRole(string $userDn): bool
{
    global $LDAP;

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }

    try {
        // Check if user is in the maintainer role using config variable
        // IMPORTANT: Check global roles only, regardless of role value conflicts
        $maintainerRoleFilter = "(&(objectclass=groupOfNames)(cn={$LDAP['maintainer_role']})(member={$userDn}))";
        $search = @ldap_search($ldap, $LDAP['roles_dn'], $maintainerRoleFilter, ['cn']);

        if ($search) {
            $result = ldap_get_entries($ldap, $search);
            if ($result['count'] > 0) {
                error_log("currentUserIsMaintainer: User has maintainer role via global role group: " . $result[0]['cn'][0]);
                return true;
            }
        }

        return false;
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Checks if the current user is an organization manager for a specific organization
 *
 * @param string $orgName Organization name to check
 * @return bool True if user is organization manager, false otherwise
 */
function currentUserIsOrgManager(string $orgName): bool
{
    global $LDAP, $USER_DN, $IS_ORG_ADMIN, $USER_ORG_NAME;

    // First check if we already have this information from the session
    if (isset($IS_ORG_ADMIN) && $IS_ORG_ADMIN === true && isset($USER_ORG_NAME) && $USER_ORG_NAME === $orgName) {
        return true;
    }

    if (empty($orgName) || !$USER_DN) {
        return false;
    }

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }

    try {
        $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);

        // Check if user is in the org_admin role for this organization
        // The org_admin role is stored under ou=roles within the organization
        $orgAdminFilter = "(&(objectclass=groupOfNames)(cn={$LDAP['org_admin_role']})(member={$USER_DN}))";
        $orgRolesDn = "ou=roles,o={$orgRDN}," . $LDAP['org_dn'];
        $search = @ldap_search($ldap, $orgRolesDn, $orgAdminFilter, ['cn']);

        if ($search) {
            $result = ldap_get_entries($ldap, $search);
            return ($result['count'] > 0);
        }

        return false;
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Checks if the current user is an organization administrator
 * This function handles role conflicts by checking organization roles independently
 *
 * @return bool True if user is organization administrator, false otherwise
 */
function currentUserIsOrgAdmin(): bool
{
    global $IS_ORG_ADMIN, $USER_ORG_NAME, $USER_DN, $LDAP;

    // Check if we have this information from the session
    if (isset($IS_ORG_ADMIN) && $IS_ORG_ADMIN === true && isset($USER_ORG_NAME) && !empty($USER_ORG_NAME)) {
        return true;
    }

    // If we don't have session data, check directly
    if (!$USER_DN || !$USER_ORG_NAME) {
        return false;
    }

    // IMPORTANT: Check organization roles independently, regardless of role value conflicts
    // This ensures org admin works even if global admin role has the same value
    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }

    try {
        // Check if user is in the organization admin role within their specific organization
        $orgRolesDn = "ou=roles,o=" . ldap_escape($USER_ORG_NAME, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
        $orgAdminFilter = "(&(objectclass=groupOfNames)(cn={$LDAP['org_admin_role']})(member={$USER_DN}))";

        $search = @ldap_search($ldap, $orgRolesDn, $orgAdminFilter, ['cn']);
        if ($search) {
            $result = ldap_get_entries($ldap, $search);

            if ($result['count'] > 0) {
                error_log("currentUserIsOrgAdmin: User is org admin via organization role: " . $result[0]['cn'][0]);
                return true;
            }
        }

        return false;
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Gets the current user's organization name from session data
 *
 * @return string|null Organization name or null if not set
 */
function currentUserGetOrgName(): ?string
{
    global $USER_ORG_NAME;

    // Return the organization name from session data
    if (isset($USER_ORG_NAME) && !empty($USER_ORG_NAME)) {
        return $USER_ORG_NAME;
    }

    return null;
}

/**
 * Gets the current user's organization UUID from session data
 *
 * @return string|null Organization UUID or null if not set
 */
function currentUserGetOrgUuid(): ?string
{
    global $USER_ORG_UUID;

    // Return the organization UUID from session data
    if (isset($USER_ORG_UUID) && !empty($USER_ORG_UUID)) {
        return $USER_ORG_UUID;
    }

    return null;
}

/**
 * Checks if the current user can modify a specific user
 *
 * @param string $targetUserDN Target user DN to check permissions for
 * @return bool True if user can modify the target user, false otherwise
 */
function currentUserCanModifyUser(string $targetUserDN): bool
{
    global $LDAP, $USER_DN;

    if (!$USER_DN) {
        return false;
    }

    // Global administrators can modify anyone
    if (currentUserIsGlobalAdmin()) {
        return true;
    }

    // Maintainers can modify anyone except administrators
    if (currentUserIsMaintainer()) {
        $ldap = open_ldap_connection();
        if (!$ldap) {
            return false;
        }

        try {
            // IMPORTANT: Check global admin role independently, regardless of role value conflicts
            $adminRoleFilter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_role']})(member={$targetUserDN}))";
            $search = @ldap_search($ldap, $LDAP['roles_dn'], $adminRoleFilter, ['cn']);

            if ($search) {
                $result = ldap_get_entries($ldap, $search);
                return ($result['count'] == 0); // Can't modify administrators
            }

            return true;
        } finally {
            ldap_close($ldap);
        }
    }

    // Organization managers can modify users in their organization
    $targetUserOrg = getUserOrganization($targetUserDN);
    if ($targetUserOrg && currentUserIsOrgManager($targetUserOrg)) {
        return true;
    }

    return false;
}

/**
 * Checks if the current user can modify a specific organization
 *
 * @param string $orgName Organization name to check permissions for
 * @return bool True if user can modify the organization, false otherwise
 */
function currentUserCanModifyOrganization(string $orgName): bool
{
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

/**
 * Gets the organization name for a user by their DN
 *
 * @param string $userDN User DN to get organization for
 * @return string|null Organization name or null if not found
 */
function getUserOrganization(string $userDN): ?string
{
    global $LDAP;

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return null;
    }

    try {
        $search = ldap_read($ldap, $userDN, '(objectClass=*)', ['organization']);
        if (!$search) {
            return null;
        }

        $entries = ldap_get_entries($ldap, $search);

        if ($entries['count'] > 0 && isset($entries[0]['organization'][0])) {
            return $entries[0]['organization'][0];
        }

        // If no organization attribute, try to extract from DN
        if (preg_match('/o=([^,]+),' . preg_quote($LDAP['org_ou'], '/') . ',/', $userDN, $matches)) {
            return $matches[1];
        }

        return null;
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Checks if the current user can create organizations
 *
 * @return bool True if user can create organizations, false otherwise
 */
function currentUserCanCreateOrganization(): bool
{
    // Only global administrators and maintainers can create organizations
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer());
}

/**
 * Checks if the current user can delete a specific organization
 *
 * @param string $orgName Organization name to check permissions for
 * @return bool True if user can delete the organization, false otherwise
 */
function currentUserCanDeleteOrganization(string $orgName): bool
{
    // Only global administrators and maintainers can delete organizations
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer());
}

/**
 * Checks if the current user can create users
 *
 * @param string|null $orgName Organization name to check permissions for (optional)
 * @return bool True if user can create users, false otherwise
 */
function currentUserCanCreateUser(?string $orgName = null): bool
{
    // Global administrators can create users anywhere
    // Maintainers can create users in any organization
    // Organization managers can create users in their organization
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer() || ($orgName && currentUserIsOrgManager($orgName)));
}

/**
 * Checks if the current user can delete a specific user
 *
 * @param string $targetUserDN Target user DN to check permissions for
 * @return bool True if user can delete the target user, false otherwise
 */
function currentUserCanDeleteUser(string $targetUserDN): bool
{
    global $LDAP;

    // Global administrators can delete anyone
    if (currentUserIsGlobalAdmin()) {
        return true;
    }

    // Maintainers can delete anyone except administrators
    if (currentUserIsMaintainer()) {
        $ldap = open_ldap_connection();
        if (!$ldap) {
            return false;
        }

        try {
            // IMPORTANT: Check global admin role independently, regardless of role value conflicts
            $adminRoleFilter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_role']})(member={$targetUserDN}))";
            $search = @ldap_search($ldap, $LDAP['roles_dn'], $adminRoleFilter, ['cn']);

            if ($search) {
                $result = ldap_get_entries($ldap, $search);
                return ($result['count'] == 0); // Can't delete administrators
            }

            return true;
        } finally {
            ldap_close($ldap);
        }
    }

    // Organization managers can delete users in their organization
    $targetUserOrg = getUserOrganization($targetUserDN);
    if ($targetUserOrg && currentUserIsOrgManager($targetUserOrg)) {
        return true;
    }

    // Users cannot delete themselves (safety measure)
    return false;
}

/**
 * Debug function to log user roles for troubleshooting
 *
 * @param string|null $username Username to debug (defaults to current user)
 * @return bool True if debug completed successfully, false otherwise
 */
function debugUserRoles(?string $username = null): bool
{
    global $LDAP, $USER_DN, $USER_ID;

    if (!$username) {
        $username = $USER_ID;
    }

    if (!$username) {
        error_log("debugUserRoles: No username provided");
        return false;
    }

    $ldap = open_ldap_connection();
    if (!$ldap) {
        error_log("debugUserRoles: Could not open LDAP connection");
        return false;
    }

    try {
        // Get user DN
        $userDn = null;
        $userFilter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}={$username}))";

        // Search in organizations first
        $search = @ldap_search($ldap, $LDAP['org_dn'], $userFilter, ['dn']);
        if ($search) {
            $result = @ldap_get_entries($ldap, $search);
            if ($result['count'] > 0) {
                $userDn = $result[0]['dn'];
            }
        }

        // If not found in organizations, search in system users
        if (!$userDn) {
            $search = @ldap_search($ldap, $LDAP['people_dn'], $userFilter, ['dn']);
            if ($search) {
                $result = @ldap_get_entries($ldap, $search);
                if ($result['count'] > 0) {
                    $userDn = $result[0]['dn'];
                }
            }
        }

        if (!$userDn) {
            error_log("debugUserRoles: User {$username} not found");
            return false;
        }

        error_log("debugUserRoles: User {$username} has DN: {$userDn}");

        // Check global roles
        $globalRolesFilter = "(&(objectclass=groupOfNames)(member={$userDn}))";
        $search = @ldap_search($ldap, $LDAP['roles_dn'], $globalRolesFilter, ['cn']);
        if ($search) {
            $result = @ldap_get_entries($ldap, $search);
            if ($result['count'] > 0) {
                error_log("debugUserRoles: User {$username} has global roles:");
                for ($i = 0; $i < $result['count']; $i++) {
                    error_log("  - " . $result[$i]['cn'][0]);
                }
            } else {
                error_log("debugUserRoles: User {$username} has no global roles");
            }
        }

        // Check organization-specific roles (only if user is in an organization)
        if (strpos($userDn, $LDAP['org_dn']) === 0) {
            // Extract organization name from DN
            if (preg_match('/o=([^,]+),/', $userDn, $matches)) {
                $orgName = $matches[1];
                $orgRolesDn = "ou=roles,o=" . ldap_escape($orgName, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];

                $orgRolesFilter = "(&(objectclass=groupOfNames)(member={$userDn}))";
                $search = @ldap_search($ldap, $orgRolesDn, $orgRolesFilter, ['cn']);
                if ($search) {
                    $result = ldap_get_entries($ldap, $search);
                    if ($result['count'] > 0) {
                        error_log("debugUserRoles: User {$username} has organization roles:");
                        for ($i = 0; $i < $result['count']; $i++) {
                            error_log("  - " . $result[$i]['cn'][0]);
                        }
                    } else {
                        error_log("debugUserRoles: User {$username} has no organization roles");
                    }
                }
            }
        }

        return true;
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Gets the highest role level for the current user
 * This function handles role conflicts by returning the highest privilege level
 *
 * @return string The highest role level: 'global_admin', 'maintainer', 'org_admin', or 'user'
 */
function currentUserGetHighestRole()
{
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
function currentUserHasRoleLevel($requiredLevel)
{
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
 * @return array{isValid: bool, conflicts: array<string>} Validation result and any conflicts found
 */
function validateRoleConfiguration(): array
{
    global $LDAP;

    $conflicts = [];

    // Define role configuration to validate
    $roleConfig = [
        'roles' => [
            'admin_role' => $LDAP['admin_role'] ?? null,
            'maintainer_role' => $LDAP['maintainer_role'] ?? null,
            'org_admin_role' => $LDAP['org_admin_role'] ?? null,
            'user_role' => $LDAP['user_role'] ?? null
        ],
        'groups' => [
            'admin_group_name' => $LDAP['admin_role'] ?? null,
            'maintainer_group_name' => $LDAP['maintainer_role'] ?? null
        ]
    ];

    // Validate required configuration values
    $conflicts = array_merge($conflicts, validateRequiredConfiguration($roleConfig));

    // Check for duplicate role values
    $conflicts = array_merge($conflicts, checkDuplicateValues($roleConfig['roles'], 'role'));

    // Check for duplicate group names
    $conflicts = array_merge($conflicts, checkDuplicateValues($roleConfig['groups'], 'group'));

    // Check for role value conflicts with group names
    $conflicts = array_merge($conflicts, checkRoleGroupConflicts($roleConfig['roles'], $roleConfig['groups']));

    return [
        'isValid' => empty($conflicts),
        'conflicts' => $conflicts
    ];
}

/**
 * Validates that all required configuration values are present
 *
 * @param array<string, array<string, mixed>> $config Configuration to validate
 * @return array<string> List of validation errors
 */
function validateRequiredConfiguration(array $config): array
{
    $errors = [];

    foreach ($config as $section => $values) {
        foreach ($values as $key => $value) {
            if (empty($value) && $value !== '0') {
                $errors[] = "Missing required configuration: {$section}.{$key}";
            }
        }
    }

    return $errors;
}

/**
 * Checks for duplicate values within a configuration section
 *
 * @param array<string, mixed> $values Values to check for duplicates
 * @param string $type Type of configuration (for error messages)
 * @return array<string> List of duplicate conflicts
 */
function checkDuplicateValues(array $values, string $type): array
{
    $conflicts = [];
    $uniqueValues = array_unique($values);

    if (count($values) !== count($uniqueValues)) {
        $duplicates = array_diff_assoc($values, array_unique($values));
        $duplicateKeys = array_keys($duplicates);
        $conflicts[] = "Duplicate {$type} values: " . implode(', ', $duplicateKeys);
    }

    return $conflicts;
}

/**
 * Checks for conflicts between role values and group names
 *
 * @param array<string, mixed> $roles Role configuration
 * @param array<string, mixed> $groups Group configuration
 * @return array<string> List of role-group conflicts
 */
function checkRoleGroupConflicts(array $roles, array $groups): array
{
    $conflicts = [];

    foreach ($roles as $roleKey => $roleValue) {
        foreach ($groups as $groupKey => $groupValue) {
            if ($roleValue === $groupValue) {
                $conflicts[] = "Role value '{$roleValue}' conflicts with group name '{$groupValue}'";
            }
        }
    }

    return $conflicts;
}

/**
 * Checks if the current user can disable a specific user account
 *
 * @param string $targetUserIdentifier User identifier to check permissions for
 * @return bool True if user can disable the target user, false otherwise
 */
function currentUserCanDisableUser(string $targetUserIdentifier): bool
{
    // Global administrators can disable any user
    if (currentUserIsGlobalAdmin()) {
        return true;
    }

    // Maintainers can disable users (except administrators)
    if (currentUserIsMaintainer()) {
        return canMaintainerDisableUser($targetUserIdentifier);
    }

    // Organization administrators can disable users in their organization
    if (currentUserIsOrgAdmin()) {
        return canOrgAdminDisableUser($targetUserIdentifier);
    }

    return false;
}

/**
 * Helper function for maintainer user disable permissions
 *
 * @param string $targetUserIdentifier User identifier to check
 * @return bool True if maintainer can disable the user
 */
function canMaintainerDisableUser(string $targetUserIdentifier): bool
{
    global $LDAP;

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }

    try {
        $targetUserDn = findUserDn($ldap, $targetUserIdentifier);
        if (!$targetUserDn) {
            return false;
        }

        // Maintainers cannot disable administrators
        $isAdmin = ldap_is_group_member($ldap, $LDAP['roles_dn'], $LDAP['admin_role'], $targetUserDn);
        return !$isAdmin;
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Helper function for organization admin user disable permissions
 *
 * @param string $targetUserIdentifier User identifier to check
 * @return bool True if org admin can disable the user
 */
function canOrgAdminDisableUser(string $targetUserIdentifier): bool
{
    global $LDAP, $USER_DN;

    $ldap = open_ldap_connection();
    if (!$ldap) {
        return false;
    }

    try {
        $currentUserOrg = currentUserGetOrgName();
        $targetUserOrg = getUserOrganizationByIdentifier($ldap, $targetUserIdentifier);
        $targetUserDn = findUserDn($ldap, $targetUserIdentifier);

        // Organization admins must never disable/enable/delete themselves.
        if (!empty($USER_DN) && !empty($targetUserDn) && strcasecmp($targetUserDn, $USER_DN) === 0) {
            return false;
        }

        // Organization admins can only disable users in their own organization
        return ($targetUserOrg === $currentUserOrg);
    } finally {
        ldap_close($ldap);
    }
}

/**
 * Finds a user's DN by their identifier
 *
 * @param resource $ldap LDAP connection resource
 * @param string $userIdentifier User identifier to search for
 * @return string|null User DN or null if not found
 */
function findUserDn($ldap, string $userIdentifier): ?string
{
    global $LDAP;

    // UUID-based lookup (preferred when identifier is a UUID)
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userIdentifier)) {
        $uuidAttr = $LDAP['uuid_attribute'] ?? 'entryUUID';
        $uuidFilter = '(' . $uuidAttr . '=' . ldap_escape($userIdentifier, '', LDAP_ESCAPE_FILTER) . ')';
        $uuidSearch = @ldap_search($ldap, $LDAP['base_dn'], $uuidFilter, ['dn']);
        if ($uuidSearch) {
            $uuidResult = ldap_get_entries($ldap, $uuidSearch);
            if ($uuidResult['count'] > 0) {
                return $uuidResult[0]['dn'];
            }
        }
    }

    // Search in system users first
    $search = @ldap_search(
        $ldap,
        $LDAP['people_dn'],
        "({$LDAP['account_attribute']}=" . ldap_escape($userIdentifier, '', LDAP_ESCAPE_FILTER) . ")",
        ['dn']
    );

    if ($search) {
        $result = ldap_get_entries($ldap, $search);
        if ($result['count'] > 0) {
            return $result[0]['dn'];
        }
    }

    // If not found in system users, search in organizations
    $search = @ldap_search(
        $ldap,
        $LDAP['org_dn'],
        "({$LDAP['account_attribute']}=" . ldap_escape($userIdentifier, '', LDAP_ESCAPE_FILTER) . ")",
        ['dn']
    );

    if ($search) {
        $result = ldap_get_entries($ldap, $search);
        if ($result['count'] > 0) {
            return $result[0]['dn'];
        }
    }

    return null;
}

/**
 * Checks if the current user can enable a specific user account
 *
 * @param string $targetUserIdentifier User identifier to check permissions for
 * @return bool True if user can enable the target user, false otherwise
 */
function currentUserCanEnableUser(string $targetUserIdentifier): bool
{
    // Same permissions as disable - if you can disable, you can enable
    return currentUserCanDisableUser($targetUserIdentifier);
}

/**
 * Checks if the current user can disable a specific organization
 *
 * @param string $orgName Organization name to check permissions for
 * @return bool True if user can disable the organization, false otherwise
 */
function currentUserCanDisableOrganization(string $orgName): bool
{
    // Global administrators can disable any organization
    if (currentUserIsGlobalAdmin()) {
        return true;
    }

    // Maintainers can disable organizations
    if (currentUserIsMaintainer()) {
        return true;
    }

    // Organization administrators cannot disable their own organization
    if (currentUserIsOrgAdmin()) {
        $currentUserOrg = currentUserGetOrgName();
        return ($orgName !== $currentUserOrg);
    }

    return false;
}

/**
 * Checks if the current user can enable a specific organization
 *
 * @param string $orgName Organization name to check permissions for
 * @return bool True if user can enable the organization, false otherwise
 */
function currentUserCanEnableOrganization(string $orgName): bool
{
    // Same permissions as disable - if you can disable, you can enable
    return currentUserCanDisableOrganization($orgName);
}

/**
 * Checks if the current user can view disable status information
 *
 * @return bool True if user can view disable status, false otherwise
 */
function currentUserCanViewDisableStatus(): bool
{
    // Administrators, maintainers, and organization admins can view disable status
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer() || currentUserIsOrgAdmin());
}

/**
 * Checks if the current user can perform bulk disable/enable operations
 *
 * @return bool True if user can perform bulk operations, false otherwise
 */
function currentUserCanPerformBulkDisableOperations(): bool
{
    // Only global administrators and maintainers can perform bulk operations
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer());
}

/**
 * Gets the organization name for a user by their identifier
 *
 * @param resource $ldap LDAP connection resource
 * @param string $userIdentifier User identifier
 * @return string|null Organization name or null if not found
 */
function getUserOrganizationByIdentifier($ldap, string $userIdentifier): ?string
{
    $targetUserDn = findUserDn($ldap, $userIdentifier);
    if (!$targetUserDn) {
        return null;
    }
    return ldap_user_get_organization($ldap, $targetUserDn);
}
