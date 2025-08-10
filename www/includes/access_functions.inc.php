<?php

function currentUserIsGlobalAdmin() {
    global $LDAP, $USER_DN;
    
    if (!$USER_DN) {
        return false;
    }
    
    $ldap = open_ldap_connection();
    
    # Check if user is in the administrator role
    $admin_role_filter = "(&(objectclass=groupOfNames)(cn=administrator)(member=$USER_DN))";
    $ldap_search = @ ldap_search($ldap, $LDAP['roles_dn'], $admin_role_filter, array('cn'));
    if ($ldap_search) {
        $result = ldap_get_entries($ldap, $ldap_search);
        ldap_close($ldap);
        return ($result['count'] > 0);
    }
    
    ldap_close($ldap);
    return false;
}

function currentUserIsMaintainer() {
    global $LDAP, $USER_DN;
    
    if (!$USER_DN) {
        return false;
    }
    
    $ldap = open_ldap_connection();
    
    # Check if user is in the maintainer role
    $maintainer_role_filter = "(&(objectclass=groupOfNames)(cn=maintainer)(member=$USER_DN))";
    $ldap_search = @ ldap_search($ldap, $LDAP['roles_dn'], $maintainer_role_filter, array('cn'));
    if ($ldap_search) {
        $result = ldap_get_entries($ldap, $ldap_search);
        ldap_close($ldap);
        return ($result['count'] > 0);
    }
    
    ldap_close($ldap);
    return false;
}

function currentUserIsOrgManager($orgName) {
    global $LDAP, $USER_DN;
    
    if (empty($orgName) || !$USER_DN) {
        return false;
    }
    
    $ldap = open_ldap_connection();
    $orgRDN = ldap_escape($orgName, '', LDAP_ESCAPE_DN);
    $orgDN = "o={$orgRDN},ou=organizations," . $LDAP['base_dn'];
    
    # Check if user is in the org_admin role for this organization
    $org_admin_filter = "(&(objectclass=groupOfNames)(cn=org_admin)(member=$USER_DN))";
    $ldap_search = @ ldap_search($ldap, $orgDN, $org_admin_filter, array('cn'));
    if ($ldap_search) {
        $result = ldap_get_entries($ldap, $ldap_search);
        ldap_close($ldap);
        return ($result['count'] > 0);
    }
    
    ldap_close($ldap);
    return false;
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
        $admin_role_filter = "(&(objectclass=groupOfNames)(cn=administrator)(member=$targetUserDN))";
        $ldap_search = @ ldap_search($ldap, $LDAP['roles_dn'], $admin_role_filter, array('cn'));
        if ($ldap_search) {
            $result = ldap_get_entries($ldap, $ldap_search);
            ldap_close($ldap);
            return ($result['count'] == 0); // Can't modify administrators
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
    if (preg_match('/o=([^,]+),ou=organizations,/', $userDN, $matches)) {
        return $matches[1];
    }
    
    return null;
}

function currentUserCanCreateOrganization() {
    // Only global administrators and maintainers can create organizations
    return (currentUserIsGlobalAdmin() || currentUserIsMaintainer());
}

function currentUserCanDeleteOrganization($orgName) {
    // Only global administrators can delete organizations
    if (currentUserIsGlobalAdmin()) {
        return true;
    }
    
    // Maintainers cannot delete organizations
    if (currentUserIsMaintainer()) {
        return false;
    }
    
    // Organization managers cannot delete their own organization
    return false;
}

function currentUserCanCreateUser($orgName = null) {
    // Global administrators can create users anywhere
    if (currentUserIsGlobalAdmin()) {
        return true;
    }
    
    // Maintainers can create users in any organization
    if (currentUserIsMaintainer()) {
        return true;
    }
    
    // Organization managers can create users in their organization
    if ($orgName && currentUserIsOrgManager($orgName)) {
        return true;
    }
    
    return false;
}

function currentUserCanDeleteUser($targetUserDN) {
    // Global administrators can delete anyone
    if (currentUserIsGlobalAdmin()) {
        return true;
    }
    
    // Maintainers can delete anyone except administrators
    if (currentUserIsMaintainer()) {
        $ldap = open_ldap_connection();
        $search = ldap_read($ldap, $targetUserDN, '(objectClass=*)', ['userRole']);
        if ($search) {
            $entries = ldap_get_entries($ldap, $search);
            if ($entries['count'] > 0 && isset($entries[0]['userrole'][0])) {
                $targetUserRole = $entries[0]['userrole'][0];
                ldap_close($ldap);
                return ($targetUserRole !== 'administrator');
            }
        }
        ldap_close($ldap);
        return true; // If we can't determine role, allow deletion
    }
    
    // Organization managers can delete users in their organization
    $targetUserOrg = getUserOrganization($targetUserDN);
    if ($targetUserOrg && currentUserIsOrgManager($targetUserOrg)) {
        return true;
    }
    
    // Users cannot delete themselves (safety measure)
    return false;
}
