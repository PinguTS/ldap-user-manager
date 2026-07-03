<?php

/**
 * User Functions Include File
 *
 * This file contains consolidated user-related helper functions that were previously
 * duplicated across multiple files. These functions provide consistent behavior
 * for user management, display, and LDAP attribute handling.
 *
 * @package LDAP User Manager
 * @version 1.0
 */

/**
 * Get LDAP attribute value regardless of case and type
 *
 * @param array<string, mixed>|null $user_data User data array from LDAP
 * @param string $attribute_name Attribute name to retrieve
 * @return string Attribute value or empty string if not found
 */
function get_ldap_attribute($user_data, $attribute_name)
{
    if ($user_data === null || !is_array($user_data)) {
        return '';
    }

    // Try exact match first
    if (isset($user_data[$attribute_name])) {
        if (is_array($user_data[$attribute_name])) {
            return $user_data[$attribute_name][0] ?? '';
        } else {
            $value = $user_data[$attribute_name];
            return is_scalar($value) ? (string) $value : '';
        }
    }

    // Try case-insensitive match
    foreach (array_keys($user_data) as $key) {
        if (is_string($key) && strcasecmp($key, $attribute_name) === 0) {
            if (is_array($user_data[$key])) {
                return $user_data[$key][0] ?? '';
            } else {
                $value = $user_data[$key];
                return is_scalar($value) ? (string) $value : '';
            }
        }
    }

    return '';
}

/**
 * Extract UUID from user data robustly
 * @param array $user_data User data array from LDAP
 * @return string UUID if valid, empty string otherwise
 */
function get_user_uuid($user_data)
{
    $uuid = get_ldap_attribute($user_data, 'entryUUID');
    if ($uuid && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
        return $uuid;
    }
    return '';
}

/**
 * Prefer entryUUID for URLs/forms; log and fall back to uid when UUID is missing.
 */
function get_user_identifier_for_url(array $user_data, string $fallback = ''): string
{
    $uuid = get_user_uuid($user_data);
    if ($uuid !== '') {
        return $uuid;
    }
    $uid = get_ldap_attribute($user_data, 'uid');
    if ($uid !== '') {
        error_log('get_user_identifier_for_url: WARNING - no entryUUID, using uid fallback');
        return $uid;
    }
    if ($fallback !== '') {
        error_log('get_user_identifier_for_url: WARNING - no entryUUID, using provided fallback');
        return $fallback;
    }
    return '';
}

/**
 * Get user identifier (UUID or email) for form processing
 * @param array $user_attribs User attributes from LDAP
 * @param string $username Username/email (fallback)
 * @return string User identifier (UUID preferred, email fallback)
 */
function get_user_identifier($user_attribs, $username)
{
    global $LDAP;

    // Use UUID if available and enabled
    if ($LDAP['use_uuid_identification']) {
        $uuid = get_user_uuid($user_attribs);
        if ($uuid) {
            return $uuid;
        }
    }

    // Fallback to username/email
    return $username;
}

/**
 * Get username from user identifier (UUID or email)
 * @param resource|\LDAP\Connection $ldap_connection LDAP connection
 * @param string $user_identifier UUID or email
 * @return string Username/email for display purposes
 */
function get_username_from_identifier($ldap_connection, $user_identifier)
{
    global $LDAP;

    // Check if this is a UUID
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $user_identifier)) {
        // UUID-based lookup
        $user_entry = ldap_get_entry_by_uuid($ldap_connection, $user_identifier, $LDAP['people_dn']);
        if ($user_entry && isset($user_entry['mail'][0])) {
            return $user_entry['mail'][0];
        }
    }

    // Return as-is if not a UUID or lookup failed
    return $user_identifier;
}

/**
 * Get user display name from DN for role memberships
 * @param resource|\LDAP\Connection $ldap_connection LDAP connection
 * @param string $user_dn User DN
 * @return string Formatted display name
 */
function get_user_display_from_dn($ldap_connection, $user_dn)
{
    // Extract username from DN (uid=username,ou=people,dc=example,dc=com)
    if (preg_match('/uid=([^,]+)/', $user_dn, $matches)) {
        $username = $matches[1];

        // Try to get user attributes for better display
        $user_search = @ldap_read($ldap_connection, $user_dn, '(objectClass=*)', ['sn', 'mail', 'cn']);
        if ($user_search) {
            $user_attribs = ldap_get_entries($ldap_connection, $user_search);
            if ($user_attribs && $user_attribs['count'] > 0) {
                $user_data = $user_attribs[0];
                return format_user_display_name($username, $user_data);
            }
        }

        // Fallback to username if attributes can't be retrieved
        return htmlspecialchars($username);
    }

    // Fallback to DN if parsing fails
    return htmlspecialchars($user_dn);
}

/**
 * Format user display name consistently
 * @param string $username Username/email
 * @param array $user_attribs User attributes from LDAP
 * @return string Formatted display name
 */
function format_user_display_name($username, $user_attribs = null)
{
    if ($user_attribs && isset($user_attribs['cn']) && isset($user_attribs['mail'])) {
        // Check if these are arrays or single values
        $cn = get_ldap_attribute($user_attribs, 'cn');
        $mail = get_ldap_attribute($user_attribs, 'mail');

        if ($cn && $mail) {
            return htmlspecialchars($cn) . ' (' . htmlspecialchars($mail) . ')';
        }
    }

    if ($user_attribs && isset($user_attribs['sn']) && isset($user_attribs['mail'])) {
        // Check if these are arrays or single values
        $sn = get_ldap_attribute($user_attribs, 'sn');
        $mail = get_ldap_attribute($user_attribs, 'mail');

        if ($sn && $mail) {
            return htmlspecialchars($sn) . ' (' . htmlspecialchars($mail) . ')';
        }
    }

    if ($user_attribs && isset($user_attribs['cn'])) {
        $cn = get_ldap_attribute($user_attribs, 'cn');
        if ($cn) {
            return htmlspecialchars($cn) . ' (' . htmlspecialchars($username) . ')';
        }
    }

    // Final fallback to just username
    return htmlspecialchars($username);
}
