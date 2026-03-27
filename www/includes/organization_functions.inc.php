<?php

declare(strict_types=1);

include_once "ldap_functions.inc.php";
include_once "config.inc.php";

/**
 * Parse LDAP postalAddress into components (format: Street$ZIP$City$Country).
 *
 * @param string $postalAddress Raw LDAP postalAddress value
 * @return array{street: string, zip: string, city: string, country: string}
 */
function parsePostalAddress(string $postalAddress): array
{
    $parts = explode('$', $postalAddress);

    return [
        'street' => trim($parts[0] ?? ''),
        'zip' => trim($parts[1] ?? ''),
        'city' => trim($parts[2] ?? ''),
        'country' => trim($parts[3] ?? ''),
    ];
}

/**
 * Build LDAP postalAddress from individual components (Street$ZIP$City$Country).
 *
 * @param string $street  Street address
 * @param string $zip     Postal/ZIP code
 * @param string $city    City
 * @param string $country Country
 * @return string
 */
function buildPostalAddress(string $street, string $zip, string $city, string $country): string
{
    return implode('$', [$street, $zip, $city, $country]);
}

/**
 * ISO 3166-1 alpha-2 country picker options (code => display name).
 *
 * @return array<string, string>
 */
function getCountryOptions(): array
{
    return [
        'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra', 'AO' => 'Angola',
        'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria',
        'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados',
        'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan',
        'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil', 'BN' => 'Brunei',
        'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'CV' => 'Cabo Verde', 'KH' => 'Cambodia',
        'CM' => 'Cameroon', 'CA' => 'Canada', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile',
        'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CR' => 'Costa Rica',
        'CI' => 'Cote d\'Ivoire', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
        'CD' => 'Democratic Republic of the Congo', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica',
        'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador',
        'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia',
        'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GA' => 'Gabon', 'GM' => 'Gambia',
        'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GR' => 'Greece', 'GD' => 'Grenada',
        'GT' => 'Guatemala', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti',
        'HN' => 'Honduras', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia',
        'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy',
        'JM' => 'Jamaica', 'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya',
        'KI' => 'Kiribati', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia',
        'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein',
        'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia',
        'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MR' => 'Mauritania',
        'MU' => 'Mauritius', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco',
        'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar',
        'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NZ' => 'New Zealand',
        'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'KP' => 'North Korea', 'MK' => 'North Macedonia',
        'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan', 'PW' => 'Palau', 'PA' => 'Panama',
        'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland',
        'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda',
        'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia', 'VC' => 'Saint Vincent and the Grenadines',
        'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia',
        'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore',
        'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa',
        'KR' => 'South Korea', 'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan',
        'SR' => 'Suriname', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria', 'TJ' => 'Tajikistan',
        'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TO' => 'Tonga',
        'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey', 'TM' => 'Turkmenistan', 'TV' => 'Tuvalu',
        'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom',
        'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu',
        'VA' => 'Vatican City', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
    ];
}

/**
 * Return a localized country name for an ISO 3166-1 alpha-2 code.
 * Falls back to the built-in English label when intl is unavailable.
 */
function getLocalizedCountryName(string $countryCode): string
{
    $code = strtoupper(trim($countryCode));
    $fallback = getCountryOptions()[$code] ?? $code;

    if ($code === '') {
        return '';
    }

    if (!class_exists('Locale')) {
        return $fallback;
    }

    $locale = function_exists('lum_current_locale') ? (string) lum_current_locale() : 'en';
    $locale = str_replace('-', '_', $locale);

    $localized = Locale::getDisplayRegion('-' . $code, $locale);
    if (!is_string($localized) || trim($localized) === '') {
        return $fallback;
    }

    return $localized;
}

/**
 * ISO country picker options localized to active UI language.
 *
 * @return array<string, string>
 */
function getLocalizedCountryOptions(): array
{
    $out = [];
    foreach (getCountryOptions() as $code => $name) {
        $out[$code] = getLocalizedCountryName($code) ?: $name;
    }
    return $out;
}

/**
 * Decode membership metadata stored in LDAP's `documentIdentifier` attribute.
 *
 * Expected (app-encoded) values:
 * - `ref:<memberNumber>`
 * - `validFrom:<YYYY-MM-DD>`
 * - `validUntil:<YYYY-MM-DD>`
 *
 * @param array<int|string, string> $documentIdentifiers Values of `documentIdentifier`
 * @return array{memberNumber: string, memberSince: string, memberUntil: string}
 */
function parseDocumentIdentifierMembership(array $documentIdentifiers): array
{
    $memberNumber = '';
    $memberSince = '';
    $memberUntil = '';

    foreach ($documentIdentifiers as $raw) {
        $val = trim((string) $raw);
        if ($val === '') {
            continue;
        }
        if (str_starts_with($val, 'ref:')) {
            $memberNumber = trim(substr($val, 4));
        } elseif (str_starts_with($val, 'validFrom:')) {
            $memberSince = trim(substr($val, strlen('validFrom:')));
        } elseif (str_starts_with($val, 'validUntil:')) {
            $memberUntil = trim(substr($val, strlen('validUntil:')));
        }
    }

    return [
        'memberNumber' => $memberNumber,
        'memberSince' => $memberSince,
        'memberUntil' => $memberUntil,
    ];
}

/**
 * Encode membership metadata into values for LDAP's `documentIdentifier`.
 *
 * @param string|null $memberNumber
 * @param string|null $memberSince
 * @param string|null $memberUntil
 * @return array<int, string>
 */
function buildDocumentIdentifierMembership(?string $memberNumber, ?string $memberSince, ?string $memberUntil = null): array
{
    $vals = [];

    $mn = trim((string) ($memberNumber ?? ''));
    if ($mn !== '') {
        $vals[] = 'ref:' . $mn;
    }

    $ms = trim((string) ($memberSince ?? ''));
    if ($ms !== '') {
        $vals[] = 'validFrom:' . $ms;
    }

    $mu = trim((string) ($memberUntil ?? ''));
    if ($mu !== '') {
        $vals[] = 'validUntil:' . $mu;
    }

    return $vals;
}

/**
 * Resolve organization from request parameters (uuid or org).
 * Uses $_GET['uuid'] or $_GET['org']; opens/closes LDAP when resolving by UUID.
 *
 * @return array{org_name: string|null, org_uuid: string|null, organization: array|null, error: string|null}
 */
function resolve_organization_from_request(): array
{
    if (isset($_GET['uuid']) && $_GET['uuid'] !== '') {
        $org_uuid = $_GET['uuid'];
        if (!is_valid_uuid($org_uuid)) {
            return [
                'org_name' => null,
                'org_uuid' => null,
                'organization' => null,
                'error' => t('manage.common.org_not_found'),
            ];
        }
        $ldap = open_ldap_connection();
        $organization = ldap_get_organization_by_uuid($ldap, $org_uuid);
        ldap_close($ldap);
        if (!$organization) {
            return [
                'org_name' => null,
                'org_uuid' => $org_uuid,
                'organization' => null,
                'error' => t('manage.common.org_not_found'),
            ];
        }
        $org_name = $organization['o'][0] ?? null;
        return [
            'org_name' => $org_name,
            'org_uuid' => $org_uuid,
            'organization' => $organization,
            'error' => null,
        ];
    }
    if (isset($_GET['org']) && $_GET['org'] !== '') {
        return [
            'org_name' => $_GET['org'],
            'org_uuid' => null,
            'organization' => null,
            'error' => null,
        ];
    }
    return [
        'org_name' => null,
        'org_uuid' => null,
        'organization' => null,
        'error' => 'Organization identifier (UUID or name) is required.',
    ];
}

function createOrganization($orgData)
{
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

    // Membership metadata:
    // Persist member number / since information via LDAP's `documentIdentifier` attribute.
    // The app encodes values as:
    // - `ref:<memberNumber>`
    // - `validFrom:<memberSince>` (YYYY-MM-DD)
    if (isset($orgData['memberNumber']) || isset($orgData['memberSince']) || isset($orgData['memberUntil'])) {
        $docVals = buildDocumentIdentifierMembership(
            $orgData['memberNumber'] ?? null,
            $orgData['memberSince'] ?? null,
            $orgData['memberUntil'] ?? null
        );
        if (!empty($docVals)) {
            $orgEntry['documentIdentifier'] = $docVals;
        }
        unset($orgData['memberNumber'], $orgData['memberSince'], $orgData['memberUntil']);
    }

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

function deleteOrganization($orgName)
{
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

function listOrganizations()
{
    global $LDAP;
    $ldap = open_ldap_connection();

    $search = ldap_search(
        $ldap,
        $LDAP['org_dn'],
        '(objectClass=organization)',
        ['o', 'postalAddress', 'telephoneNumber', 'facsimileTelephoneNumber', 'labeledURI', 'mail', 'description', 'entryUUID']
    );

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
            'facsimileTelephoneNumber' => isset($org['facsimiletelephonenumber'][0]) ? $org['facsimiletelephonenumber'][0] : '',
            'labeledURI' => isset($org['labeleduri'][0]) ? $org['labeleduri'][0] : '',
            'mail' => isset($org['mail'][0]) ? $org['mail'][0] : '',
        ];
    }

    return $organizations;
}

function ldap_delete_recursive($ldap, $dn)
{
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

function addUserToOrgAdmin($orgName, $userDn)
{
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
 * Remove all values for an LDAP attribute; treat "no such attribute" as success.
 *
 * @param resource $ldap Open LDAP connection
 */
function ldap_organization_attribute_delete_all($ldap, string $dn, string $attr): bool
{
    $ok = @ldap_mod_del($ldap, $dn, [$attr => []]);
    if ($ok) {
        return true;
    }
    $err = ldap_error($ldap);
    if (preg_match('/no such attribute|nosuchattribute|no attribute/i', (string) $err)) {
        return true;
    }
    if (stripos((string) $err, 'Undefined attribute type') !== false) {
        return true;
    }
    error_log("ldap_organization_attribute_delete_all: cannot remove attribute '$attr' on $dn: $err");
    return false;
}

/**
 * Update organization attributes
 * @param string $orgIdentifier Organization name or UUID
 * @param array $orgData Organization data to update
 * @return bool Success status
 */
function updateOrganization($orgIdentifier, $orgData)
{
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

    $optional = $LDAP['org_optional_fields'] ?? [];
    $modifications = [];
    $attrsToDelete = [];

    foreach ($orgData as $attr => $value) {
        if ($attr === 'o') {
            continue;
        }

        if ($attr === 'documentIdentifier') {
            if (!is_array($value)) {
                continue;
            }
            if (count($value) === 0) {
                $attrsToDelete[] = 'documentIdentifier';
            } else {
                $modifications[$attr] = $value;
            }
            continue;
        }

        if (in_array($attr, $optional, true)) {
            if (is_array($value)) {
                if (count($value) === 0) {
                    $attrsToDelete[] = $attr;
                } else {
                    $modifications[$attr] = $value;
                }
            } elseif (trim((string) $value) === '') {
                $attrsToDelete[] = $attr;
            } else {
                $modifications[$attr] = is_string($value) ? trim($value) : $value;
            }
            continue;
        }

        if (is_array($value)) {
            if (!empty($value)) {
                $modifications[$attr] = $value;
            }
        } elseif ($value !== null && trim((string) $value) !== '') {
            $modifications[$attr] = $value;
        }
    }

    $attrsToDelete = array_values(array_unique($attrsToDelete));

    foreach ($attrsToDelete as $attr) {
        if (!ldap_organization_attribute_delete_all($ldap, $org_dn, $attr)) {
            ldap_close($ldap);
            return false;
        }
    }

    if (empty($modifications)) {
        ldap_close($ldap);
        return true;
    }

    // Perform the update
    $result = @ldap_modify($ldap, $org_dn, $modifications);

    if ($result) {
        ldap_close($ldap);
        return true;
    }

    // Get error message before closing the connection
    $error_msg = ldap_error($ldap);
    if (stripos((string) $error_msg, 'Undefined attribute type') !== false) {
        $successful_attrs = [];
        $failed_attrs = [];
        foreach ($modifications as $attr => $value) {
            $single_mod = [$attr => $value];
            $single_ok = @ldap_modify($ldap, $org_dn, $single_mod);
            if ($single_ok) {
                $successful_attrs[] = $attr;
                continue;
            }
            $failed_attrs[$attr] = ldap_error($ldap);
        }

        $non_schema_failures = [];
        foreach ($failed_attrs as $attr => $single_error) {
            if (stripos((string) $single_error, 'Undefined attribute type') === false) {
                $non_schema_failures[$attr] = $single_error;
            }
        }

        if (!empty($successful_attrs) && empty($non_schema_failures)) {
            ldap_close($ldap);
            return true;
        }
    }
    ldap_close($ldap);
    error_log("updateOrganization: Failed to update organization $orgIdentifier: " . $error_msg);
    return false;
}

/**
 * Rename an organization by changing its naming RDN (o=). The entire subtree moves with the entry.
 *
 * @param string $orgIdentifier Current organization entryUUID or organization name (o)
 * @param string $newOrgName    New organization name (must be non-empty and not already in use)
 */
function renameOrganization(string $orgIdentifier, string $newOrgName): bool
{
    global $LDAP;

    $newOrgName = trim($newOrgName);
    if ($newOrgName === '') {
        return false;
    }

    $ldap = open_ldap_connection();
    if ($ldap === false) {
        return false;
    }

    if ($LDAP['use_uuid_identification'] && is_valid_uuid($orgIdentifier)) {
        $org_entry = ldap_get_organization_by_uuid($ldap, $orgIdentifier);
        if (!$org_entry) {
            ldap_close($ldap);
            return false;
        }
        $org_dn = $org_entry['dn'];
        $current_o = (string) ($org_entry['o'][0] ?? '');
    } else {
        $org_dn = 'o=' . ldap_escape($orgIdentifier, '', LDAP_ESCAPE_DN) . ',' . $LDAP['org_dn'];
        $read = @ldap_read($ldap, $org_dn, '(objectClass=organization)', ['o']);
        if (!$read) {
            ldap_close($ldap);
            return false;
        }
        $entries = ldap_get_entries($ldap, $read);
        if (!is_array($entries) || ($entries['count'] ?? 0) < 1) {
            ldap_close($ldap);
            return false;
        }
        $current_o = (string) ($entries[0]['o'][0] ?? '');
    }

    if ($current_o === $newOrgName) {
        ldap_close($ldap);
        return true;
    }

    $new_dn = 'o=' . ldap_escape($newOrgName, '', LDAP_ESCAPE_DN) . ',' . $LDAP['org_dn'];
    $collision = @ldap_read($ldap, $new_dn, '(objectClass=*)', ['dn']);
    if ($collision) {
        error_log("renameOrganization: Target organization DN already exists: $new_dn");
        ldap_close($ldap);
        return false;
    }

    $new_rdn = 'o=' . ldap_escape($newOrgName, '', LDAP_ESCAPE_DN);
    $ok = @ldap_rename($ldap, $org_dn, $new_rdn, $LDAP['org_dn'], true);
    if (!$ok) {
        error_log('renameOrganization: ldap_rename failed for ' . $org_dn . ': ' . ldap_error($ldap));
        ldap_close($ldap);
        return false;
    }

    ldap_close($ldap);
    return true;
}

function removeUserFromOrgAdmin($orgName, $userDn)
{
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

function getOrganizationUsers($orgName)
{
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

    $search = @ldap_search(
        $ldap,
        $usersDN,
        '(objectClass=inetOrgPerson)',
        ['uid', 'cn', 'sn', 'givenName', 'mail', 'description', 'organization', 'entryUUID']
    );

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



function isUserOrgAdmin($orgName, $userDn)
{
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

function isUserOrgManager($orgName, $userDn)
{
    // Alias for isUserOrgAdmin - both refer to the same role
    return isUserOrgAdmin($orgName, $userDn);
}

/**
 * Update user attributes
 * @param string $userIdentifier User UUID or DN
 * @param array $userData User data to update
 * @return bool Success status
 */
function updateUser($userIdentifier, $userData)
{
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
function deleteUser($userIdentifier)
{
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

    // Remove user from all groups before deleting the user account
    $group_cleanup_success = ldap_remove_user_from_all_groups($ldap, $user_dn);
    if (!$group_cleanup_success) {
        error_log("deleteUser: Warning: Failed to remove user $userIdentifier from some groups");
        // Continue with deletion even if group cleanup failed
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
function createUserAccount($userData)
{
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
function createSystemUser($ldap, $userData)
{
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
        'userPassword' => $userData['userPassword'], // Password is already hashed
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
    $modify = @ldap_mod_add($ldap, $role_group_dn, ['member' => $user_dn]);
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
function createOrganizationUser($ldap, $userData)
{
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
    $user_search = @ldap_search(
        $ldap,
        "ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'],
        "(uid=" . ldap_escape($userData['mail'], '', LDAP_ESCAPE_FILTER) . ")"
    );
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
            @ldap_mod_add($ldap, $org_admin_group_dn, ['member' => $user_dn]);
        }
    }

    return [true, "Organization user created successfully"];
}
