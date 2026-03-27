<?php

declare(strict_types=1);

// Define LDAP escape constants for PHP < 7.3 compatibility
if (!defined('LDAP_ESCAPE_FILTER')) {
    define('LDAP_ESCAPE_FILTER', 0);
}
if (!defined('LDAP_ESCAPE_DN')) {
    define('LDAP_ESCAPE_DN', 0);
}

/**
 * LDAP connection and authentication functions
 *
 * This file contains core LDAP functionality for user management,
 * authentication, and directory operations.
 */

###################################

/**
 * Opens a connection to the LDAP server with optional binding
 *
 * @param bool $ldap_bind Whether to bind as admin user
 * @return resource|false LDAP connection resource or false on failure
 * @throws Exception When connection fails in production environment
 */
function open_ldap_connection($ldap_bind = true)
{

    global $log_prefix, $LDAP, $SENT_HEADERS, $LDAP_DEBUG, $LDAP_VERBOSE_CONNECTION_LOGS;

    if (!is_array($LDAP) || empty($LDAP['uri'])) {
        error_log("$log_prefix LDAP config not loaded or missing URI. Ensure config.inc.php is loaded.");
        if ($SENT_HEADERS) {
            return false;
        }
        print "Problem: LDAP configuration is not available.";
        exit(1);
    }

    // Enforce TLS in production environments
    if (getenv('ENVIRONMENT') !== 'development' && getenv('ENVIRONMENT') !== 'test') {
        if ($LDAP['ignore_cert_errors'] === true) {
            error_log("$log_prefix WARNING: Certificate errors are being ignored in production environment", 0);
        }
    }

    if ($LDAP['ignore_cert_errors'] === true) {
        putenv('LDAPTLS_REQCERT=never');
    }
    $ldap_connection = @ldap_connect($LDAP['uri']);

    if (!$ldap_connection) {
        print "Problem: Can't connect to the LDAP server at {$LDAP['uri']}";
        die("Can't connect to the LDAP server at {$LDAP['uri']}");
    }

    ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
    if ($LDAP_VERBOSE_CONNECTION_LOGS === true) {
        ldap_set_option(null, LDAP_OPT_DEBUG_LEVEL, 7);
    }

    // Enforce TLS for non-localhost connections in production
    $is_localhost = preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri']) ||
                    preg_match('/^ldap:\/\/localhost(:[0-9]+)?$/', $LDAP['uri']);

    if (!preg_match("/^ldaps:/", $LDAP['uri'])) {
        $tls_result = @ldap_start_tls($ldap_connection);

        if ($tls_result !== true) {
            if (!preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri'])) {
                error_log("$log_prefix Failed to start STARTTLS connection to {$LDAP['uri']}: " . ldap_error($ldap_connection), 0);
            }

            if ($LDAP["require_starttls"] === true || (!$is_localhost && getenv('ENVIRONMENT') !== 'development')) {
                print "<div style='position: fixed;bottom: 0;width: 100%;' class='alert alert-danger'>Fatal:  Couldn't create a secure connection to {$LDAP['uri']} and LDAP_REQUIRE_STARTTLS is TRUE.</div>";
                exit(0);
            } else {
                if ($SENT_HEADERS === true and !preg_match('/^ldap:\/\/localhost(:[0-9]+)?$/', $LDAP['uri']) and !preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri'])) {
                    print "<div style='position: fixed;bottom: 0px;width: 100%;height: 20px;border-bottom:solid 20px yellow;'>WARNING: Insecure LDAP connection to {$LDAP['uri']}</div>";
                }
                ldap_close($ldap_connection);
                $ldap_connection = @ldap_connect($LDAP['uri']);
                ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            }
        } else {
            if ($LDAP_DEBUG === true) {
                error_log("$log_prefix Start STARTTLS connection to {$LDAP['uri']}", 0);
            }
            $LDAP['connection_type'] = "StartTLS";
        }
    } else {
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix Using an LDAPS encrypted connection to {$LDAP['uri']}", 0);
        }
        $LDAP['connection_type'] = 'LDAPS';
    }

    if ($ldap_bind === true) {
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix Attempting to bind to {$LDAP['uri']} as {$LDAP['admin_bind_dn']}", 0);
        }
        $bind_result = @ldap_bind($ldap_connection, $LDAP['admin_bind_dn'], $LDAP['admin_bind_pwd']);

        if ($bind_result !== true) {
            $this_error = "Failed to bind to {$LDAP['uri']} as {$LDAP['admin_bind_dn']}";
            if ($LDAP_DEBUG === true) {
                $this_error .= " with password {$LDAP['admin_bind_pwd']}";
            }
            $this_error .= ": " . ldap_error($ldap_connection);
            print "Problem: Failed to bind as {$LDAP['admin_bind_dn']}";
            error_log("$log_prefix $this_error", 0);

            exit(1);
        } elseif ($LDAP_DEBUG === true) {
            error_log("$log_prefix Bound successfully as {$LDAP['admin_bind_dn']}", 0);
        }
    }

    return $ldap_connection;
}

###################################

/**
 * Authenticates a username and password against LDAP
 *
 * @param resource $ldap_connection LDAP connection resource
 * @param string $username Username to authenticate
 * @param string $password Password to authenticate
 * @return string|false DN string if authentication succeeds, false otherwise
 */
function ldap_auth_username($ldap_connection, $username, $password): string|false
{

  # Search for the DN for the given username across all organizations.  If found, try binding with the DN and user's password.
  # If the binding succeeds, return the DN.

    global $log_prefix, $LDAP, $SITE_LOGIN_LDAP_ATTRIBUTE, $LDAP_DEBUG;

    $ldap_search_query = "{$SITE_LOGIN_LDAP_ATTRIBUTE}=" . ldap_escape(($username === null ? '' : $username), "", LDAP_ESCAPE_FILTER);
    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix Running LDAP search for: $ldap_search_query");
    }

  # Search across all organizations for the user
    $ldap_search = @ldap_search($ldap_connection, $LDAP['org_dn'], $ldap_search_query);
    if (!$ldap_search) {
        error_log("$log_prefix Couldn't search for $ldap_search_query: " . ldap_error($ldap_connection), 0);
        return false;
    }

    $result = @ldap_get_entries($ldap_connection, $ldap_search);
    if (!$result) {
        error_log("$log_prefix Couldn't get LDAP entries for {$username}: " . ldap_error($ldap_connection), 0);
        return false;
    }

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix LDAP search returned " . $result["count"] . " records for $ldap_search_query", 0);
        for ($i = 1; $i == $result["count"]; $i++) {
            error_log("$log_prefix " . "Entry {$i}: " . $result[$i - 1]['dn'], 0);
        }
    }

    if ($result["count"] > 1) {
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix There was more than one entry for {$ldap_search_query} so it wasn't possible to determine which user to log in as.");
        }
        return false;
    }

    if ($result["count"] == 1) {
        $this_dn = $result[0]['dn'];
    }

  # If not found in organizations, search in system users
    if ($result["count"] == 0) {
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix User not found in organizations, searching in system users");
        }

        $ldap_search = @ldap_search($ldap_connection, $LDAP['people_dn'], $ldap_search_query);
        if (!$ldap_search) {
            error_log("$log_prefix Couldn't search for $ldap_search_query in system users: " . ldap_error($ldap_connection), 0);
            return false;
        }

        $result = @ldap_get_entries($ldap_connection, $ldap_search);
        if (!$result) {
            error_log("$log_prefix Couldn't get LDAP entries for {$username} in system users: " . ldap_error($ldap_connection), 0);
            return false;
        }

        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix LDAP search in system users returned " . $result["count"] . " records for $ldap_search_query", 0);
            for ($i = 1; $i == $result["count"]; $i++) {
                error_log("$log_prefix " . "Entry {$i}: " . $result[$i - 1]['dn'], 0);
            }
        }

        if ($result["count"] > 1) {
            if ($LDAP_DEBUG === true) {
                error_log("$log_prefix There was more than one entry for {$username} in system users so it wasn't possible to determine which user to log in as.");
            }
            return false;
        }

        if ($result["count"] == 1) {
            $this_dn = $result[0]['dn'];
        }

        if ($result["count"] == 0) {
            if ($LDAP_DEBUG === true) {
                error_log("$log_prefix There was no entry for {$username} in system users so it wasn't possible to determine which user to log in as.");
            }
            return false;
        }
    }

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix Attempting authenticate as $username by binding with {$this_dn} ", 0);
    }

    $auth_ldap_connection = open_ldap_connection(false);

    $can_bind =  @ldap_bind($auth_ldap_connection, $this_dn, $password);
    if ($can_bind) {
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix Able to bind as {$username}: dn is {$this_dn}", 0);
        }
        ldap_close($auth_ldap_connection);
        return $this_dn;
    }

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix Unable to bind as {$username}: " . ldap_error($auth_ldap_connection), 0);
    }

    ldap_close($auth_ldap_connection);
    return false;
}


###################################

function ldap_setup_auth($ldap_connection, $password)
{

 #For the initial setup we need to make sure that whoever's running it has the default admin user
 #credentials as passed in ADMIN_BIND_*
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix Initial setup: opening another LDAP connection to test authentication as {$LDAP['admin_bind_dn']}.", 0);
    }
    $auth_ldap_connection = open_ldap_connection();
    $can_bind = @ldap_bind($auth_ldap_connection, $LDAP['admin_bind_dn'], $password);
    ldap_close($auth_ldap_connection);
    if ($can_bind) {
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix Initial setup: able to authenticate as {$LDAP['admin_bind_dn']}.", 0);
        }
        return true;
    } else {
        $this_error = "Initial setup: Unable to authenticate as {$LDAP['admin_bind_dn']}";
        if ($LDAP_DEBUG === true) {
            $this_error .= " with password $password";
        }
        $this_error .= ". The password used to authenticate for /setup should be the same as set by LDAP_ADMIN_BIND_PWD. ";
        $this_error .= ldap_error($ldap_connection);
        error_log("$log_prefix $this_error", 0);
        return false;
    }
}


#################################

function generate_salt($length)
{

    $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ./';

    mt_srand(intval(microtime()) * 1000000);

    $salt = '';
    while (strlen($salt) < $length) {
        $salt .= substr($permitted_chars, (rand() % strlen($permitted_chars)), 1);
    }

    return $salt;
}


##################################

function ldap_hashed_password($password)
{

    global $PASSWORD_HASH, $log_prefix, $SECURITY_CONFIG;

    $secure_algos = $SECURITY_CONFIG['password']['allowed_algorithms'];
    $default_algo = $SECURITY_CONFIG['password']['default_algorithm'];

    $check_algos = array(
     "SHA512CRYPT" => "CRYPT_SHA512",
     "SHA256CRYPT" => "CRYPT_SHA256"
    );

    $available_algos = array();
    foreach ($check_algos as $algo_name => $algo_function) {
        if (defined($algo_function) and constant($algo_function) != 0) {
            array_push($available_algos, $algo_name);
        }
    }

 // Always allow ARGON2 and SSHA
    $available_algos = array_merge(['ARGON2', 'SSHA'], $available_algos);

 // Select the best available algorithm
    $hash_algo = null;
    if (isset($PASSWORD_HASH)) {
        $PASSWORD_HASH = strtoupper($PASSWORD_HASH);
        if (!in_array($PASSWORD_HASH, $secure_algos)) {
            error_log("$log_prefix LDAP password: unknown or weak hash method ($PASSWORD_HASH), falling back to secure default", 0);
        } elseif ($PASSWORD_HASH === 'CLEAR') {
            error_log("$log_prefix password hashing - FATAL - CLEAR selected, refusing to store password in cleartext.", 0);
            die("FATAL: Refusing to store password in cleartext. Set PASSWORD_HASH to a secure value (ARGON2 or SSHA recommended).");
        } elseif (in_array($PASSWORD_HASH, $available_algos)) {
            $hash_algo = $PASSWORD_HASH;
        }
    }

    if (!$hash_algo) {
        // Use default from config if available
        if (in_array($default_algo, $available_algos)) {
            $hash_algo = $default_algo;
        } else {
            // Fallback to strongest available
            foreach ($secure_algos as $algo) {
                if ($algo === 'ARGON2' && defined('PASSWORD_ARGON2ID')) {
                    $hash_algo = 'ARGON2';
                    break;
                }
                if (in_array($algo, $available_algos)) {
                    $hash_algo = $algo;
                    break;
                }
            }
        }
    }

    if (!$hash_algo) {
        die("FATAL: No secure password hash available. Check your PHP and system configuration.");
    }

    error_log("$log_prefix LDAP password: using '{$hash_algo}' as the hashing method", 0);

    switch ($hash_algo) {
        case 'ARGON2':
            $hashed_pwd = '{ARGON2}' . password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 4, 'threads' => 3]);
            break;

        case 'SSHA':
            $salt = generate_salt(8);
            $hashed_pwd = '{SSHA}' . base64_encode(sha1($password . $salt, true) . $salt);
            break;

        case 'SHA512CRYPT':
            $hashed_pwd = '{CRYPT}' . crypt($password, '$6$' . generate_salt(8));
            break;

        case 'SHA256CRYPT':
            $hashed_pwd = '{CRYPT}' . crypt($password, '$5$' . generate_salt(8));
            break;

        default:
          // Fallback to ARGON2 if available, otherwise SSHA
            if (defined('PASSWORD_ARGON2ID')) {
                $hashed_pwd = '{ARGON2}' . password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 4, 'threads' => 3]);
            } else {
                $salt = generate_salt(8);
                $hashed_pwd = '{SSHA}' . base64_encode(sha1($password . $salt, true) . $salt);
            }
            break;
    }

    return $hashed_pwd;
}

##################################

function ldap_get_system_users($ldap_connection, $start = 0, $entries = null, $sort = "asc", $sort_key = null, $filters = null, $fields = null)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!$ldap_connection) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix ldap_get_system_users: no LDAP connection");
        }
        return [];
    }
    if (!is_array($LDAP) || empty($LDAP['people_dn']) || empty($LDAP['account_attribute'])) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix ldap_get_system_users: LDAP config missing people_dn or account_attribute");
        }
        return [];
    }

    $account_attr = $LDAP['account_attribute'];
    $people_dn = $LDAP['people_dn'];
    $use_uuid = !empty($LDAP['use_uuid_identification']);

    if (!isset($fields)) {
        $fields = array_unique(array($account_attr, "givenname", "sn", "cn", "mail", "description", "dn"));
        if ($use_uuid) {
            $fields[] = 'entryUUID';
        }
    }

    if (!isset($sort_key)) {
        $sort_key = $account_attr;
    }

    # Ensure the sort key attribute is always included in the requested fields
    if (!in_array($sort_key, $fields)) {
        $fields[] = $sort_key;
    }

    $filters = $filters ?? '';
    $this_filter = "(&(objectclass=inetOrgPerson)({$account_attr}=*){$filters})";

    # Search only in system users (not organization users)
    $users = array();

    # Search in system users only
    $ldap_search = @ ldap_search($ldap_connection, $people_dn, $this_filter, $fields);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);
        if ($LDAP_DEBUG === true) {
            if ($result && is_array($result)) {
                error_log("$log_prefix LDAP returned {$result['count']} system users when using this filter: $this_filter");
            } else {
                error_log("$log_prefix LDAP search failed or returned invalid result: " . print_r($result, true));
            }
        }

        # If we need entryUUID and it's not in the result, try with operational attributes
        if ($use_uuid && $result && is_array($result) && $result['count'] > 0) {
            $has_uuid = false;
            foreach ($result as $record) {
                if (isset($record['entryUUID']) || isset($record['entryuuid'])) {
                    $has_uuid = true;
                    break;
                }
            }
            if (!$has_uuid) {
                if ($LDAP_DEBUG === true) {
                    error_log("$log_prefix entryUUID not found, trying with operational attributes");
                }
                # Try again with operational attributes
                $ldap_search = @ ldap_search($ldap_connection, $people_dn, $this_filter, array_merge($fields, ['+']));
                if ($ldap_search) {
                    $result = @ ldap_get_entries($ldap_connection, $ldap_search);
                    if ($LDAP_DEBUG === true) {
                        if ($result && is_array($result)) {
                            error_log("$log_prefix LDAP returned {$result['count']} system users with operational attributes");
                        } else {
                            error_log("$log_prefix LDAP search with operational attributes failed or returned invalid result: " . print_r($result, true));
                        }
                    }
                }
            }
        }

        # Only process results if we have a valid array
        if ($result && is_array($result) && $result['count'] > 0) {
            foreach ($result as $key => $record) {
                # Skip non-numeric keys (like 'count', 'dn', etc.) - only process actual user records
                if (!is_numeric($key)) {
                    continue;
                }

                if ($LDAP_DEBUG === true) {
                    error_log("$log_prefix Processing record: " . print_r($record, true));
                    error_log("$log_prefix Record keys: " . print_r(array_keys($record), true));
                    error_log("$log_prefix Sort key: $sort_key, Sort key value: " . (isset($record[$sort_key][0]) ? $record[$sort_key][0] : 'NOT SET'));
                }

                if (isset($record[$sort_key][0])) {
                    $add_these = array();
                    if ($LDAP_DEBUG === true) {
                        error_log("$log_prefix Processing user record: " . $record[$sort_key][0]);
                        error_log("$log_prefix Available attributes in record: " . print_r(array_keys($record), true));
                        error_log("$log_prefix Requested fields: " . print_r($fields, true));
                    }
                    foreach ($fields as $this_attr) {
                        // Skip the sort key attribute itself, but include all other requested fields
                        if ($this_attr !== $sort_key) {
                            // Check for case-insensitive attribute match
                            $found_attr = false;
                            $attr_value = null;

                            if ($LDAP_DEBUG === true) {
                                error_log("$log_prefix Processing field: $this_attr");
                            }

                            // First try exact match
                            if (isset($record[$this_attr])) {
                                $found_attr = true;
                                $attr_value = $record[$this_attr];
                                if ($LDAP_DEBUG === true) {
                                    error_log("$log_prefix Found exact match for $this_attr");
                                }
                            } else {
                                // Try case-insensitive match
                                foreach (array_keys($record) as $key) {
                                    if (is_string($key) && strcasecmp($key, $this_attr) === 0) {
                                        $found_attr = true;
                                        $attr_value = $record[$key];
                                        if ($LDAP_DEBUG === true) {
                                            error_log("$log_prefix Found attribute $this_attr with different casing: $key");
                                            // Special debug for entryUUID
                                            if (strcasecmp($this_attr, 'entryUUID') === 0) {
                                                error_log("$log_prefix entryUUID case-insensitive match - key: $key, value: " . print_r($attr_value, true));
                                                error_log("$log_prefix Will store as: $this_attr (requested name)");
                                            }
                                        }
                                        break;
                                    }
                                }
                            }

                            if ($found_attr) {
                                if ($this_attr === 'dn') {
                                    // DN is a special case - it's not an array
                                    $add_these[$this_attr] = $attr_value;
                                } else {
                                    $add_these[$this_attr] = $attr_value[0];
                                }
                                if ($LDAP_DEBUG === true) {
                                    error_log("$log_prefix Added attribute $this_attr: " . print_r($add_these[$this_attr], true));
                                    // Special debug for entryUUID
                                    if (strcasecmp($this_attr, 'entryUUID') === 0) {
                                        error_log("$log_prefix entryUUID raw value: " . print_r($attr_value, true));
                                        error_log("$log_prefix entryUUID extracted value: " . print_r($add_these[$this_attr], true));
                                        error_log("$log_prefix entryUUID stored with key: $this_attr");
                                    }
                                }
                            } else {
                                if ($LDAP_DEBUG === true) {
                                    error_log("$log_prefix Attribute $this_attr not found in record (case-insensitive check)");
                                }
                            }
                        } else {
                            if ($LDAP_DEBUG === true) {
                                error_log("$log_prefix Skipping sort key attribute: $this_attr");
                            }
                        }
                    }
                    $users[$record[$sort_key][0]] = $add_these;
                    if ($LDAP_DEBUG === true) {
                        error_log("$log_prefix Added user to array: " . $record[$sort_key][0]);
                    }
                } else {
                    if ($LDAP_DEBUG === true) {
                        error_log("$log_prefix Record missing sort key: $sort_key");
                    }
                }
            }
        }
    }

    if ($sort == "asc") {
        ksort($users);
    } else {
        krsort($users);
    }

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix Final users array: " . print_r($users, true));
        error_log("$log_prefix Returning " . count($users) . " users");
    }

    return(array_slice($users, $start, $entries));
}

function ldap_get_user_list($ldap_connection, $start = 0, $entries = null, $sort = "asc", $sort_key = null, $filters = null, $fields = null)
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!isset($fields)) {
        $fields = array_unique(array("{$LDAP['account_attribute']}", "givenname", "sn", "cn", "mail", "description", "organization"));
        // Add UUID field if UUID identification is enabled
        if ($LDAP['use_uuid_identification']) {
            $fields[] = 'entryUUID';
        }
    }

    if (!isset($sort_key)) {
        $sort_key = $LDAP['account_attribute'];
    }

    $this_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=*)$filters)";

 # Search across all organizations and system users for users
    $users = array();

 # Search in organizations
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $this_filter, $fields);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix LDAP returned {$result['count']} users in organizations when using this filter: $this_filter", 0);
        }

        foreach ($result as $record) {
            if (isset($record[$sort_key][0])) {
                  $add_these = array();
                foreach ($fields as $this_attr) {
                    if ($this_attr !== $sort_key and isset($record[$this_attr])) {
                        $add_these[$this_attr] = $record[$this_attr][0];
                    }
                }
                $users[$record[$sort_key][0]] = $add_these;
            }
        }
    }

 # Search in system users
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $this_filter, $fields);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix LDAP returned {$result['count']} system users when using this filter: $this_filter", 0);
        }

        foreach ($result as $record) {
            if (isset($record[$sort_key][0])) {
                  $add_these = array();
                foreach ($fields as $this_attr) {
                    if ($this_attr !== $sort_key and isset($record[$this_attr])) {
                        $add_these[$this_attr] = $record[$this_attr][0];
                    }
                }
                $users[$record[$sort_key][0]] = $add_these;
            }
        }
    }

    if ($sort == "asc") {
        ksort($users);
    } else {
        krsort($users);
    }

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix Final users array: " . print_r($users, true));
        error_log("$log_prefix Returning " . count($users) . " users");
    }

    return(array_slice($users, $start, $entries));
}

##################################


function fetch_id_stored_in_ldap($ldap_connection, $type = "uid")
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

    $filter = "(&(objectclass=device)(cn=last{$type}))";
    $ldap_search = @ ldap_search($ldap_connection, "{$LDAP['base_dn']}", $filter, array('serialNumber'));
    $result = ldap_get_entries($ldap_connection, $ldap_search);

    if (isset($result[0]['serialnumber'][0]) and is_numeric($result[0]['serialnumber'][0])) {
        return $result[0]['serialnumber'][0];
    } else {
        return false;
    }
}


##################################


function ldap_get_highest_id($ldap_connection, $type = "uid")
{

    global $log_prefix, $LDAP, $LDAP_DEBUG, $min_uid;

 // Only UID functionality is supported now (groups are obsolete)
    if ($type != "uid") {
        $type = "uid";
    }

    $this_id = $min_uid;
    $record_base_dn = $LDAP['user_dn'];
    $record_filter = "({$LDAP['account_attribute']}=*)";
    $record_attribute = "uidnumber";

    $fetched_id = fetch_id_stored_in_ldap($ldap_connection, $type);

    if ($fetched_id !== false) {
        return($fetched_id);
    } else {
        error_log("$log_prefix cn=lastUID doesn't exist so the highest $type is determined by searching through all the LDAP records.", 0);

        $ldap_search = @ ldap_search($ldap_connection, $record_base_dn, $record_filter, array($record_attribute));
        $result = ldap_get_entries($ldap_connection, $ldap_search);

        foreach ($result as $record) {
            if (isset($record[$record_attribute][0])) {
                if ($record[$record_attribute][0] > $this_id) {
                    $this_id = $record[$record_attribute][0];
                }
            }
        }
    }

    return($this_id);
}


##################################

##################################

function ldap_get_role_members($ldap_connection, $role_name, $start = 0, $entries = null, $sort = "asc")
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

 // Search for the role in the global roles OU
    $ldap_search_query = "(cn=" . ldap_escape($role_name, "", LDAP_ESCAPE_FILTER) . ")";
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['roles_dn'], $ldap_search_query, array('member'));

    $result = @ ldap_get_entries($ldap_connection, $ldap_search);
    if ($result) {
        $result_count = $result['count'];
    } else {
        $result_count = 0;
    }

    $records = array();

    if ($result_count > 0 && isset($result[0]['member'])) {
        foreach ($result[0]['member'] as $key => $value) {
            if ($key !== 'count' and !empty($value)) {
                // Extract the DN from the member attribute
                $records[] = $value;
                if ($LDAP_DEBUG === true) {
                    error_log("$log_prefix {$value} is a member of role {$role_name}", 0);
                }
            }
        }

        $actual_result_count = count($records);
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix LDAP returned $actual_result_count members of role {$role_name}", 0);
        }

        if ($actual_result_count > 0) {
            if ($sort == "asc") {
                sort($records);
            } else {
                rsort($records);
            }
            return(array_slice($records, $start, $entries));
        } else {
            return array();
        }
    } else {
        return array();
    }
}

##################################

function ldap_is_group_member($ldap_connection, $base_dn, $group_name, $user_dn)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (empty($base_dn) || empty($group_name) || empty($user_dn)) {
        return false;
    }

    $ldap_search_query = "(&(objectclass=groupOfNames)(cn=$group_name)(member=$user_dn))";
    $ldap_search = @ldap_search($ldap_connection, $base_dn, $ldap_search_query);
    if (!$ldap_search) {
        return false;
    }

    $result = ldap_get_entries($ldap_connection, $ldap_search);
    if ($result['count'] > 0) {
        return ($result['count'] > 0);
    }

    return false;
}

##################################

/**
 * Log LDAP error and diagnostic message for a failed operation.
 *
 * @param resource|\LDAP\Connection $ldap_connection Active LDAP connection
 * @param string                    $context         Short description of the operation
 * @return void
 */
function ldap_log_ldap_failure($ldap_connection, string $context): void
{
    $err = @ldap_error($ldap_connection);
    $diag = '';
    if (@ldap_get_option($ldap_connection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag)) {
        $diag = is_string($diag) ? $diag : '';
    } else {
        $diag = '';
    }
    error_log("LDAP operation failed: {$context}; ldap_error=" . ($err ?: 'unknown') . ($diag !== '' ? "; diagnostic={$diag}" : ''));
}

/**
 * Ensure ou=roles and a groupOfNames status group exist under base DN.
 *
 * Status groups are used to store membership flags (e.g. memberOrganizations, disabledOrganizations).
 * groupOfNames entries must have at least one member, so we seed with the admin bind DN.
 *
 * @param resource|\LDAP\Connection $ldap_connection Active LDAP connection
 * @param string                    $groupCn          CN of the status group
 * @param string                    $baseDn           LDAP base DN
 * @param string                    $description      Optional group description
 * @return bool True if ready for use
 */
function ldap_ensure_status_group($ldap_connection, string $groupCn, string $baseDn, string $description = ''): bool
{
    global $LDAP;

    if ($baseDn === '') {
        return false;
    }

    $rolesOuDn = 'ou=roles,' . $baseDn;
    $rolesExists = @ldap_read($ldap_connection, $rolesOuDn, '(objectClass=*)', ['dn']);
    if ($rolesExists === false) {
        $ouEntry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou' => 'roles',
            'description' => 'Role groups (auto-created)',
        ];
        if (!@ldap_add($ldap_connection, $rolesOuDn, $ouEntry)) {
            ldap_log_ldap_failure($ldap_connection, "create roles OU {$rolesOuDn}");
            return false;
        }
    }

    $groupDn = 'cn=' . ldap_escape($groupCn, '', LDAP_ESCAPE_DN) . ',ou=roles,' . $baseDn;
    $groupExists = @ldap_read($ldap_connection, $groupDn, '(objectClass=*)', ['dn']);
    if ($groupExists !== false) {
        return true;
    }

    $seedMember = (string) ($LDAP['admin_bind_dn'] ?? '');
    if ($seedMember === '') {
        error_log("LDAP operation failed: cannot create status group {$groupDn}; admin_bind_dn not configured");
        return false;
    }

    $entry = [
        'objectClass' => ['top', 'groupOfNames'],
        'cn' => $groupCn,
        'description' => $description !== '' ? $description : ('Status group ' . $groupCn),
        // groupOfNames requires at least one member
        'member' => [$seedMember],
    ];

    if (!@ldap_add($ldap_connection, $groupDn, $entry)) {
        ldap_log_ldap_failure($ldap_connection, "create status group {$groupDn}");
        return false;
    }

    return true;
}

/**
 * Check if an entry (user or organization) is a member of a given status group.
 *
 * @param resource|\LDAP\Connection $ldap_connection Active LDAP connection
 * @param string                    $entryDn          Full DN of the entry to check
 * @param string                    $groupCn          CN of the status group (e.g. 'memberOrganizations')
 * @param string                    $baseDn           LDAP base DN
 * @return bool
 */
function isInStatusGroup($ldap_connection, string $entryDn, string $groupCn, string $baseDn): bool
{
    $groupDn = 'cn=' . ldap_escape($groupCn, '', LDAP_ESCAPE_DN)
        . ',ou=roles,' . $baseDn;
    $filter = '(member=' . ldap_escape($entryDn, '', LDAP_ESCAPE_FILTER) . ')';
    $result = @ldap_search($ldap_connection, $groupDn, $filter, ['cn'], 0, 1);

    return $result !== false && ldap_count_entries($ldap_connection, $result) > 0;
}

/**
 * Add an entry to a status group.
 *
 * @param resource|\LDAP\Connection $ldap_connection Active LDAP connection
 * @param string                    $entryDn          Full DN of the entry to add
 * @param string                    $groupCn          CN of the status group
 * @param string                    $baseDn            LDAP base DN
 * @return bool
 */
function addToStatusGroup($ldap_connection, string $entryDn, string $groupCn, string $baseDn): bool
{
    $groupDn = 'cn=' . ldap_escape($groupCn, '', LDAP_ESCAPE_DN)
        . ',ou=roles,' . $baseDn;

    if ($baseDn === '' || $entryDn === '') {
        return false;
    }

    if (!ldap_ensure_status_group($ldap_connection, $groupCn, $baseDn, 'Status group (auto-created)')) {
        return false;
    }

    // Idempotency: if already member, treat as success.
    if (isInStatusGroup($ldap_connection, $entryDn, $groupCn, $baseDn)) {
        return true;
    }

    $ok = @ldap_mod_add($ldap_connection, $groupDn, ['member' => [$entryDn]]);
    if (!$ok) {
        ldap_log_ldap_failure($ldap_connection, "add member {$entryDn} to {$groupDn}");
    }
    return (bool) $ok;
}

/**
 * Remove an entry from a status group.
 *
 * @param resource|\LDAP\Connection $ldap_connection Active LDAP connection
 * @param string                    $entryDn          Full DN of the entry to remove
 * @param string                    $groupCn          CN of the status group
 * @param string                    $baseDn            LDAP base DN
 * @return bool
 */
function removeFromStatusGroup($ldap_connection, string $entryDn, string $groupCn, string $baseDn): bool
{
    $groupDn = 'cn=' . ldap_escape($groupCn, '', LDAP_ESCAPE_DN)
        . ',ou=roles,' . $baseDn;

    if ($baseDn === '' || $entryDn === '') {
        return false;
    }

    if (!ldap_ensure_status_group($ldap_connection, $groupCn, $baseDn, 'Status group (auto-created)')) {
        return false;
    }

    // Idempotency: if not member, treat as success.
    if (!isInStatusGroup($ldap_connection, $entryDn, $groupCn, $baseDn)) {
        return true;
    }

    $ok = @ldap_mod_del($ldap_connection, $groupDn, ['member' => [$entryDn]]);
    if (!$ok) {
        ldap_log_ldap_failure($ldap_connection, "remove member {$entryDn} from {$groupDn}");
    }
    return (bool) $ok;
}

##################################

function ldap_user_get_organization($ldap_connection, $user_dn)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    return get_organization_from_user_dn($user_dn);
}

function get_organization_from_user_dn($user_dn)
{
    global $LDAP;

  // Pattern: uid=username,ou=people,o=OrgName,ou=organizations,dc=example,dc=com
    if (preg_match('/o=([^,]+),ou=organizations,/', $user_dn, $matches)) {
        return $matches[1];
    }

  // Alternative pattern for some structures
    if (preg_match('/o=([^,]+),/', $user_dn, $matches)) {
        return $matches[1];
    }

    return false;
}

##################################

/**
 * Build the full org DN for a user DN, or return empty string for non-org users.
 *
 * @param string $user_dn Full DN of the user entry
 * @return string Full org DN (e.g. "o=OrgName,ou=organizations,dc=example,dc=com") or ''
 */
function ldap_get_org_dn_for_user(string $user_dn): string
{
    global $LDAP;

    $org_name = get_organization_from_user_dn($user_dn);
    if ($org_name === false || $org_name === '') {
        return '';
    }

    return 'o=' . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . ',' . $LDAP['org_dn'];
}

/**
 * Check if a user is individually disabled (member of per-org disabledAccounts group).
 *
 * @param resource|\LDAP\Connection|false $ldap_connection Active LDAP connection
 * @param string                          $user_dn         Full DN of the user entry
 * @return bool True if user is in the per-org disabledAccounts group
 */
function ldap_user_is_individually_disabled($ldap_connection, $user_dn): bool
{
    if (!$ldap_connection || !$user_dn) {
        return false;
    }

    $org_dn = ldap_get_org_dn_for_user($user_dn);
    if ($org_dn === '') {
        return false;
    }

    $disabled_accounts_cn = getenv('LDAP_GROUP_DISABLED_ACCOUNTS') ?: 'disabledAccounts';
    return isInStatusGroup($ldap_connection, $user_dn, $disabled_accounts_cn, $org_dn);
}

##################################

function ldap_user_group_membership($ldap_connection, $user_dn)
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

  # Search for roles that contain this user
    $roles = array();

    if (empty($user_dn)) {
        return $roles;
    }

  # Check global roles (administrator, maintainer)
  # IMPORTANT: Check global roles independently, regardless of role value conflicts
    $global_admin_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['admin_role']})(member=" . ldap_escape($user_dn, "", LDAP_ESCAPE_FILTER) . "))";
    $global_maintainer_filter = "(&(objectclass=groupOfNames)(cn={$LDAP['maintainer_role']})(member=" . ldap_escape($user_dn, "", LDAP_ESCAPE_FILTER) . "))";

    $global_admin_search = @ldap_search($ldap_connection, $LDAP['roles_dn'], $global_admin_filter, ['cn']);
    $global_maintainer_search = @ldap_search($ldap_connection, $LDAP['roles_dn'], $global_maintainer_filter, ['cn']);

    if ($global_admin_search && ldap_count_entries($ldap_connection, $global_admin_search) > 0) {
        $roles[] = $LDAP['admin_role'];
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix User is global administrator");
        }
    } elseif ($global_maintainer_search && ldap_count_entries($ldap_connection, $global_maintainer_search) > 0) {
        $roles[] = $LDAP['maintainer_role'];
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix User is global maintainer");
        }
    }

  # Check organization-specific roles
    $org_roles_filter = "(&(objectclass=groupOfNames)(member=" . ldap_escape($user_dn, "", LDAP_ESCAPE_FILTER) . "))";
    $ldap_search = @ldap_search($ldap_connection, $LDAP['org_dn'], $org_roles_filter, array('cn'));
    if ($ldap_search) {
        $result = @ldap_get_entries($ldap_connection, $ldap_search);
        foreach ($result as $record) {
            if (isset($record['cn'][0])) {
                $roles[] = $record['cn'][0];
            }
        }
    }

    sort($roles);
    return $roles;
}




##################################

function ldap_organization_get_uuid($ldap_connection, $organization_name)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix ldap_organization_get_uuid: Searching for organization '$organization_name'");
    }

  // Escape the organization name for LDAP search
    $escaped_org_name = ldap_escape($organization_name, "", LDAP_ESCAPE_FILTER);

    $ldap_search = @ldap_search($ldap_connection, $LDAP['org_dn'], "(&(objectclass=organization)(o=$escaped_org_name))", array($LDAP['uuid_attribute']));

    if (!$ldap_search) {
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix ldap_organization_get_uuid: LDAP search failed: " . ldap_error($ldap_connection));
        }
        return false;
    }

    $result = ldap_get_entries($ldap_connection, $ldap_search);

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix ldap_organization_get_uuid: Found " . $result['count'] . " organizations");
    }

    if ($result['count'] > 0) {
        $uuid = $result[0][strtolower($LDAP['uuid_attribute'])][0];
        if ($LDAP_DEBUG === true) {
            error_log("$log_prefix ldap_organization_get_uuid: Returning UUID: $uuid");
        }
        return $uuid;
    }

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix ldap_organization_get_uuid: No organization found with name '$organization_name'");
    }
    return false;
}

function ldap_user_get_uuid($ldap_connection, $user_dn)
{
    global $LDAP;

    $read = @ldap_read($ldap_connection, $user_dn, '(objectClass=*)', [$LDAP['uuid_attribute']]);
    if (!$read) {
        return false;
    }
    $entries = ldap_get_entries($ldap_connection, $read);
    if (!is_array($entries) || ($entries['count'] ?? 0) < 1) {
        return false;
    }
    $attr = strtolower($LDAP['uuid_attribute']);
    if (!isset($entries[0][$attr][0])) {
        return false;
    }
    return $entries[0][$attr][0];
}

##################################

function ldap_complete_attribute_array($default_attributes, $additional_attributes)
{

    if (isset($additional_attributes)) {
        $user_attribute_r = explode(",", $additional_attributes);
        $to_merge = array();

        foreach ($user_attribute_r as $this_attr) {
            $this_r = array();
            $kv = explode(":", $this_attr);
            $attr_name = strtolower(filter_var($kv[0], FILTER_SANITIZE_FULL_SPECIAL_CHARS));
            $this_r['inputtype'] = "singleinput";

            if (substr($attr_name, -1) == '+') {
                $this_r['inputtype'] = "multipleinput";
                $attr_name = rtrim($attr_name, '+');
            }

            if (substr($attr_name, -1) == '^') {
                $this_r['inputtype'] = "binary";
                $attr_name = rtrim($attr_name, '^');
            }

            if (preg_match('/^[a-zA-Z0-9\-]+$/', $attr_name) == 1) {
                if (isset($kv[1]) and $kv[1] != "") {
                    $this_r['label'] = filter_var($kv[1], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                } else {
                    $this_r['label'] = $attr_name;
                }

                if (isset($kv[2]) and $kv[2] != "") {
                    $this_r['default'] = filter_var($kv[2], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                }

                $to_merge[$attr_name] = $this_r;
            }
        }

        $attribute_r = array_merge($default_attributes, $to_merge);

        return($attribute_r);
    } else {
        return($default_attributes);
    }
}


##################################

function ldap_new_account($ldap_connection, $account_r)
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (
        isset($account_r['givenname'][0])
        and isset($account_r['sn'][0])
        and isset($account_r['cn'][0])
        and isset($account_r[$LDAP['account_attribute']])
        and isset($account_r['password'][0])
        and isset($account_r['organization'][0])
    ) {
        $account_identifier = $account_r[$LDAP['account_attribute']][0];
        $organization = $account_r['organization'][0];

     # Check if organization exists
        $org_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], "o=" . ldap_escape($organization, "", LDAP_ESCAPE_FILTER));
        if (!$org_search || ldap_count_entries($ldap_connection, $org_search) == 0) {
            error_log("$log_prefix Create account; Organization '$organization' does not exist", 0);
            return false;
        }

     # Check if user already exists in this organization
        $user_dn = "ou=people,o=" . ldap_escape($organization, "", LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];

     # Ensure the users directory exists before trying to add a user
        $usersDirExists = @ldap_read($ldap_connection, $user_dn, '(objectClass=*)', ['dn']);
        if (!$usersDirExists) {
         // Create the ou=people directory under the organization
            $usersDirEntry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou' => 'people',
            'description' => 'Users for organization ' . $organization
            ];

            $createUsersDir = @ldap_add($ldap_connection, $user_dn, $usersDirEntry);
            if (!$createUsersDir) {
                error_log("$log_prefix Create account; Failed to create users directory at DN: $user_dn -- LDAP error: " . ldap_error($ldap_connection));
                return false;
            }
        }

        $ldap_search_query = "({$LDAP['account_attribute']}=" . ldap_escape(($account_identifier === null ? '' : $account_identifier), "", LDAP_ESCAPE_FILTER) . ")";
        $ldap_search = @ ldap_search($ldap_connection, $user_dn, $ldap_search_query);
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);

        if ($result['count'] == 0) {
            $hashed_pass = ldap_hashed_password($account_r['password'][0]);
            unset($account_r['password']);

            $objectclasses = $LDAP['account_objectclasses'];

            $account_attributes = array('objectclass' => $objectclasses,
                                'userpassword' => $hashed_pass,
                      );

            $account_attributes = array_merge($account_r, $account_attributes);

        # Ensure all attributes are properly formatted as arrays with numeric keys
            foreach ($account_attributes as $attr => $value) {
                if (!is_array($value)) {
                    $account_attributes[$attr] = array(0 => $value);
                } elseif (!isset($value[0])) {
                    // If it's an array but doesn't have numeric keys, fix it
                    $account_attributes[$attr] = array_values($value);
                }
            }

        # Set default description (role) if not specified
            if (!isset($account_attributes['description'][0])) {
                   $account_attributes['description'][0] = $LDAP['user_role'];
            }

        # Ensure uid is set to email for email-based login
            if ($LDAP['account_attribute'] === 'mail') {
                $account_attributes['uid'] = $account_identifier;
            }

        # Debug: Log the attributes being sent to ldap_add
            if ($LDAP_DEBUG === true) {
                error_log("$log_prefix ldap_new_account: Attributes for ldap_add: " . print_r($account_attributes, true));
            }

            $add_account = @ ldap_add(
                $ldap_connection,
                "{$LDAP['account_attribute']}=$account_identifier,{$user_dn}",
                $account_attributes
            );

            if ($add_account) {
                error_log("$log_prefix Created new account: $account_identifier in organization: $organization", 0);

                # Add user to organization admin role ONLY if they are organization users (not system users)
                # System administrators/maintainers already have full access to all organizations
                # Check: 1) User has org_admin role, 2) Organization is specified, 3) User DN is under org_dn (not people_dn)
                # IMPORTANT: Check organization admin role independently, regardless of role value conflicts
                if (
                    isset($account_attributes['description'][0]) &&
                    $account_attributes['description'][0] === $LDAP['org_admin_role'] &&
                    isset($account_attributes['o'][0]) &&
                    strpos($user_dn, $LDAP['org_dn']) !== false
                ) {
                    addUserToOrgAdmin($organization, "{$LDAP['account_attribute']}=$account_identifier,{$user_dn}");
                }

                return true;
            } else {
                ldap_get_option($ldap_connection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $detailed_err);
                error_log("$log_prefix Create account; couldn't create the account for {$account_identifier}: " . ldap_error($ldap_connection) . " -- " . $detailed_err, 0);
            }
        } else {
            error_log("$log_prefix Create account; Account for {$account_identifier} already exists in organization {$organization}", 0);
        }
    } else {
        error_log("$log_prefix Create account; missing parameters (organization is now required)", 0);
    }

    return false;
}


##################################

function ldap_delete_account($ldap_connection, $username)
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (isset($username)) {
     # Search for the user across all organizations and system users to find their DN
        $ldap_search_query = "{$LDAP['account_attribute']}=" . ldap_escape(($username === null ? '' : $username), "", LDAP_ESCAPE_FILTER);
        $user_dn = null;

     # Search in organizations first
        $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $ldap_search_query);
        if ($ldap_search) {
            $result = @ ldap_get_entries($ldap_connection, $ldap_search);
            if ($result['count'] > 0) {
                $user_dn = $result[0]['dn'];
            }
        }

     # If not found in organizations, search in system users
        if (!$user_dn) {
            $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $ldap_search_query);
            if ($ldap_search) {
                $result = @ ldap_get_entries($ldap_connection, $ldap_search);
                if ($result['count'] > 0) {
                       $user_dn = $result[0]['dn'];
                }
            }
        }

        if (!$user_dn) {
            error_log("$log_prefix Delete account; User {$username} not found", 0);
            return false;
        }

     // Remove user from all groups before deletion
        $group_cleanup_success = ldap_remove_user_from_all_groups($ldap_connection, $user_dn);
        if (!$group_cleanup_success) {
            error_log("$log_prefix Warning: Failed to remove user {$username} from some groups", 0);
         // Continue with deletion even if group cleanup failed
        }

        $delete = @ ldap_delete($ldap_connection, $user_dn);

        if ($delete) {
            error_log("$log_prefix Deleted account for $username at DN: $user_dn", 0);
            return true;
        } else {
            error_log("$log_prefix Couldn't delete account for {$username} at DN {$user_dn}: " . ldap_error($ldap_connection), 0);
            return false;
        }
    }

    return false;
}


##################################




##################################

function ldap_change_password($ldap_connection, $username, $new_password)
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

 # Find DN of user across all organizations and system users
    $ldap_search_query = "{$LDAP['account_attribute']}=" . ldap_escape(($username === null ? '' : $username), "", LDAP_ESCAPE_FILTER);
    $user_dn = null;

 # Search in organizations first
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $ldap_search_query);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);
        if ($result["count"] == 1) {
            $user_dn = $result[0]['dn'];
        }
    }

 # If not found in organizations, search in system users
    if (!$user_dn) {
        $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $ldap_search_query);
        if ($ldap_search) {
            $result = @ ldap_get_entries($ldap_connection, $ldap_search);
            if ($result["count"] == 1) {
                  $user_dn = $result[0]['dn'];
            }
        }
    }

    if (!$user_dn) {
        error_log("$log_prefix Couldn't find the DN for user $username");
        return false;
    }

    $entries["userPassword"] = ldap_hashed_password($new_password);
    $update = @ ldap_mod_replace($ldap_connection, $user_dn, $entries);

    if ($update) {
        error_log("$log_prefix Updated the password for $username", 0);
        return true;
    } else {
        error_log("$log_prefix Couldn't update the password for {$username}: " . ldap_error($ldap_connection), 0);
        return false;
    }
}


##################################

function ldap_get_user_info($ldap_connection, $username, $fields = null)
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!isset($fields)) {
        $fields = array_unique(array("{$LDAP['account_attribute']}", "givenname", "sn", "cn", "mail", "description", "organization", "userPassword"));
    }

    $ldap_search_query = "{$LDAP['account_attribute']}=" . ldap_escape(($username === null ? '' : $username), "", LDAP_ESCAPE_FILTER);
    $user_info = null;

 # Search in organizations first
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $ldap_search_query, $fields);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);
        if ($result['count'] > 0) {
            $user_info = array();
            foreach ($fields as $field) {
                if (isset($result[0][strtolower($field)][0])) {
                    $user_info[$field] = $result[0][strtolower($field)][0];
                }
            }
            $user_info['dn'] = $result[0]['dn'];
            return $user_info;
        }
    }

 # If not found in organizations, search in system users
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $ldap_search_query, $fields);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);
        if ($result['count'] > 0) {
            $user_info = array();
            foreach ($fields as $field) {
                if (isset($result[0][strtolower($field)][0])) {
                    $user_info[$field] = $result[0][strtolower($field)][0];
                }
            }
            $user_info['dn'] = $result[0]['dn'];
            return $user_info;
        }
    }

    if ($LDAP_DEBUG === true) {
        error_log("$log_prefix User {$username} not found", 0);
    }
    return false;
}

/**
 * Find a user entry by configured account attribute across org users and system users.
 *
 * @param mixed $ldap_connection LDAP connection
 * @param string $accountIdentifier Typically email (depends on LDAP_ACCOUNT_ATTRIBUTE)
 * @param array<int, string> $attributes Attributes to request (defaults to ['*', '+'])
 * @return array<string, mixed>|null LDAP entry array including 'dn' or null if not found
 */
function ldap_find_user_entry_by_account_identifier($ldap_connection, string $accountIdentifier, array $attributes = ['*', '+']): ?array
{
    global $LDAP;

    $accountIdentifier = trim($accountIdentifier);
    if ($accountIdentifier === '') {
        return null;
    }

    $filter = '(' . $LDAP['account_attribute'] . '=' . ldap_escape($accountIdentifier, '', LDAP_ESCAPE_FILTER) . ')';

    // Search org users first
    $search = @ldap_search($ldap_connection, $LDAP['org_dn'], $filter, $attributes);
    if ($search) {
        $entries = @ldap_get_entries($ldap_connection, $search);
        if (is_array($entries) && ($entries['count'] ?? 0) > 0 && isset($entries[0]) && is_array($entries[0])) {
            return $entries[0];
        }
    }

    // Search system users
    $search = @ldap_search($ldap_connection, $LDAP['people_dn'], $filter, $attributes);
    if ($search) {
        $entries = @ldap_get_entries($ldap_connection, $search);
        if (is_array($entries) && ($entries['count'] ?? 0) > 0 && isset($entries[0]) && is_array($entries[0])) {
            return $entries[0];
        }
    }

    return null;
}


##################################

function ldap_update_user_attributes($ldap_connection, $username, $attributes)
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (empty($attributes) || !is_array($attributes)) {
        error_log("$log_prefix Update user attributes; no attributes provided or invalid format", 0);
        return false;
    }

 # Find the user across all organizations and system users
    $ldap_search_query = "{$LDAP['account_attribute']}=" . ldap_escape(($username === null ? '' : $username), "", LDAP_ESCAPE_FILTER);
    $user_dn = null;

 # Search in organizations first
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $ldap_search_query);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);
        if ($result['count'] > 0) {
            $user_dn = $result[0]['dn'];
        }
    }

 # If not found in organizations, search in system users
    if (!$user_dn) {
        $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $ldap_search_query);
        if ($ldap_search) {
            $result = @ ldap_get_entries($ldap_connection, $ldap_search);
            if ($result['count'] > 0) {
                  $user_dn = $result[0]['dn'];
            }
        }
    }

    if (!$user_dn) {
        error_log("$log_prefix Update user attributes; User {$username} not found", 0);
        return false;
    }

 # Handle password hashing if password is being updated
    if (isset($attributes['userPassword'])) {
        $attributes['userPassword'] = ldap_hashed_password($attributes['userPassword']);
    }

    $update = @ ldap_mod_replace($ldap_connection, $user_dn, $attributes);

    if ($update) {
        error_log("$log_prefix Updated attributes for user {$username}", 0);
        return true;
    } else {
        error_log("$log_prefix Couldn't update attributes for user {$username}: " . ldap_error($ldap_connection), 0);
        return false;
    }
}


##################################

# UUID-based identification helper functions

/**
 * Get entry by UUID
 * @param resource $ldap_connection LDAP connection
 * @param string $uuid Entry UUID
 * @param string $base_dn Base DN to search in
 * @param array $attributes Attributes to retrieve
 * @return array|false Entry data or false if not found
 */
function ldap_get_entry_by_uuid($ldap_connection, $uuid, $base_dn, $attributes = ['*', '+'])
{
    global $LDAP;

    if (!$LDAP['use_uuid_identification']) {
        return false;
    }

    $uuid_attr = $LDAP['uuid_attribute'];
    $filter = "($uuid_attr=$uuid)";

    $search = @ldap_search($ldap_connection, $base_dn, $filter, $attributes);
    if (!$search) {
        return false;
    }

    $entries = @ldap_get_entries($ldap_connection, $search);
    if (!$entries || $entries['count'] == 0) {
        return false;
    }

    return $entries[0];
}

/**
 * Get organization by UUID
 * @param resource $ldap_connection LDAP connection
 * @param string $uuid Organization UUID
 * @return array|false Organization data or false if not found
 */
function ldap_get_organization_by_uuid($ldap_connection, $uuid)
{
    global $LDAP;
    return ldap_get_entry_by_uuid($ldap_connection, $uuid, $LDAP['org_dn']);
}

/**
 * Get user by UUID
 * @param resource $ldap_connection LDAP connection
 * @param string $uuid User UUID
 * @param string $org_dn Organization DN (optional, for org users)
 * @return array|false User data or false if not found
 */
function ldap_get_user_by_uuid($ldap_connection, $uuid, $org_dn = null)
{
    global $LDAP;

    if ($org_dn) {
        // Search in specific organization
        return ldap_get_entry_by_uuid($ldap_connection, $uuid, $org_dn);
    } else {
        // Search in system users first (most common case)
        $user = ldap_get_entry_by_uuid($ldap_connection, $uuid, $LDAP['people_dn']);
        if ($user) {
            return $user;
        }

        // Search in organizations by scanning the org_dn structure
        $org_search = @ldap_search($ldap_connection, $LDAP['org_dn'], "(objectClass=organization)", ['o']);
        if ($org_search) {
            $orgs = ldap_get_entries($ldap_connection, $org_search);
            for ($i = 0; $i < $orgs['count']; $i++) {
                $org_name = $orgs[$i]['o'][0];
                $org_people_dn = "ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
                $user = ldap_get_entry_by_uuid($ldap_connection, $uuid, $org_people_dn);
                if ($user) {
                    return $user;
                }
            }
        }
    }

    return false;
}

/**
 * Validate UUID format
 * @param string $uuid UUID to validate
 * @return bool True if valid UUID format
 */
function is_valid_uuid($uuid)
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
}

/**
 * Complete attribute map by merging additional attributes
 * @param array $base_map Base attribute map
 * @param array $additional_attrs Additional attributes to add
 * @return array Complete attribute map
 */
function ldap_complete_attribute_map($base_map, $additional_attrs)
{
    if (!is_array($additional_attrs)) {
        return $base_map;
    }

    foreach ($additional_attrs as $attr_name => $attr_config) {
        if (is_array($attr_config) && !isset($base_map[$attr_name])) {
            $base_map[$attr_name] = $attr_config;
        }
    }

    return $base_map;
}

/**
 * Generate secure URL parameter from UUID
 * @param string $uuid UUID to encode
 * @return string URL-safe UUID
 */
function uuid_to_url_param($uuid)
{
    return urlencode($uuid);
}

/**
 * Decode UUID from URL parameter
 * @param string $url_param URL parameter
 * @return string|false Decoded UUID or false if invalid
 */
function url_param_to_uuid($url_param)
{
    $uuid = urldecode($url_param);
    return is_valid_uuid($uuid) ? $uuid : false;
}

##################################

function get_user_dn_from_identifier($ldap_connection, $identifier)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

  // Check if identifier is a UUID
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
      // Search by UUID
        $ldap_search = @ldap_search(
            $ldap_connection,
            $LDAP['base_dn'],
            "({$LDAP['uuid_attribute']}=$identifier)",
            ['dn']
        );
        if ($ldap_search) {
            $result = ldap_get_entries($ldap_connection, $ldap_search);
            if ($result['count'] > 0) {
                return $result[0]['dn'];
            }
        }
    }

  // If not a UUID or UUID search failed, treat as username/email
    $ldap_search = @ldap_search(
        $ldap_connection,
        $LDAP['org_dn'],
        "({$LDAP['account_attribute']}=$identifier)",
        ['dn']
    );
    if ($ldap_search) {
        $result = ldap_get_entries($ldap_connection, $ldap_search);
        if ($result['count'] > 0) {
            return $result[0]['dn'];
        }
    }

  // Try system users
    $ldap_search = @ldap_search(
        $ldap_connection,
        $LDAP['people_dn'],
        "({$LDAP['account_attribute']}=$identifier)",
        ['dn']
    );
    if ($ldap_search) {
        $result = ldap_get_entries($ldap_connection, $ldap_search);
        if ($result['count'] > 0) {
            return $result[0]['dn'];
        }
    }

    return false;
}

##################################

function ldap_detect_rfc2307bis($ldap_connection)
{

    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (isset($LDAP['rfc2307bis_available'])) {
        return $LDAP['rfc2307bis_available'];
    } else {
        $LDAP['rfc2307bis_available'] = false;

        if ($LDAP['forced_rfc2307bis'] === true) {
            if ($LDAP_DEBUG === true) {
                error_log("$log_prefix LDAP RFC2307BIS detection - skipping autodetection because FORCE_RFC2307BIS is TRUE", 0);
            }
            $LDAP['rfc2307bis_available'] = true;
        } else {
            $schema_base_query = @ ldap_read($ldap_connection, "", "subschemaSubentry=*", array('subschemaSubentry'));

            if (!$schema_base_query) {
                error_log("$log_prefix LDAP RFC2307BIS detection - unable to query LDAP for objectClasses under {$schema_base_dn}:" . ldap_error($ldap_connection), 0);
                error_log("$log_prefix LDAP RFC2307BIS detection - we'll assume that the RFC2307BIS schema isn't available.  Set FORCE_RFC2307BIS to TRUE if you DO use RFC2307BIS.", 0);
            } else {
                $schema_base_results = @ ldap_get_entries($ldap_connection, $schema_base_query);

                if ($schema_base_results) {
                    $schema_base_dn = $schema_base_results[0]['subschemasubentry'][0];
                    if ($LDAP_DEBUG === true) {
                        error_log("$log_prefix LDAP RFC2307BIS detection - found that the 'subschemaSubentry' base DN is '$schema_base_dn'", 0);
                    }

                    $objclass_query = @ ldap_read($ldap_connection, $schema_base_dn, "(objectClasses=*)", array('objectClasses'));
                    if (!$objclass_query) {
                        error_log("$log_prefix LDAP RFC2307BIS detection - unable to query LDAP for objectClasses under {$schema_base_dn}:" . ldap_error($ldap_connection), 0);
                    } else {
                        $objclass_results = @ ldap_get_entries($ldap_connection, $objclass_query);
                        $this_count = $objclass_results[0]['objectclasses']['count'];
                        if ($this_count > 0) {
                            if ($LDAP_DEBUG === true) {
                                error_log("$log_prefix LDAP RFC2307BIS detection - found $this_count objectClasses under $schema_base_dn", 0);
                            }
                            $posixgroup_search = preg_grep("/NAME 'posixGroup'.*AUXILIARY/", $objclass_results[0]['objectclasses']);
                            if (count($posixgroup_search) > 0) {
                                if ($LDAP_DEBUG === true) {
                                    error_log("$log_prefix LDAP RFC2307BIS detection - found AUXILIARY in posixGroup definition which suggests we're using the RFC2307BIS schema", 0);
                                }
                                $LDAP['rfc2307bis_available'] = true;
                            } else {
                                if ($LDAP_DEBUG === true) {
                                    error_log("$log_prefix LDAP RFC2307BIS detection - couldn't find AUXILIARY in the posixGroup definition which suggests we're not using the RFC2307BIS schema.  Set FORCE_RFC2307BIS to TRUE if you DO use RFC2307BIS. ", 0);
                                }
                            }
                        } else {
                            if ($LDAP_DEBUG === true) {
                                error_log("$log_prefix LDAP RFC2307BIS detection - no objectClasses were returned when searching under $schema_base_dn", 0);
                            }
                        }
                    }
                } else {
                    if ($LDAP_DEBUG === true) {
                        error_log("$log_prefix LDAP RFC2307BIS detection - unable to detect the subschemaSubentry base DN", 0);
                    }
                }
            }
        }

        if ($LDAP['rfc2307bis_available'] === true) {
            if (!isset($LDAP['group_membership_attribute'])) {
                $LDAP['group_membership_attribute'] = 'uniquemember';
            }
            if (!isset($LDAP['group_membership_uses_uid'])) {
                $LDAP['group_membership_uses_uid'] = false;
            }
            if (!in_array('groupOfUniqueNames', $LDAP['group_objectclasses'])) {
                array_push($LDAP['group_objectclasses'], 'groupOfUniqueNames');
            }
            return true;
        } else {
            if (!isset($LDAP['group_membership_attribute'])) {
                $LDAP['group_membership_attribute'] = 'memberuid';
            }
            if (!isset($LDAP['group_membership_uses_uid'])) {
                $LDAP['group_membership_uses_uid'] = true;
            }
            return false;
        }
    }
}

##################################

/**
 * Get the DN of a user by username
 * @param resource $ldap_connection LDAP connection
 * @param string $username Username to look up
 * @return string|false User DN or false if not found
 */
function ldap_get_user_dn($ldap_connection, $username)
{
    global $LDAP;

  // First try to find the user in system users (ou=people)
    $search = @ldap_search(
        $ldap_connection,
        $LDAP['people_dn'],
        "(uid=$username)",
        ['dn']
    );
    if ($search) {
        $result = ldap_get_entries($ldap_connection, $search);
        if ($result['count'] > 0) {
            return $result[0]['dn'];
        }
    }

  // If not found in system users, try organization users
    $search = @ldap_search(
        $ldap_connection,
        $LDAP['org_dn'],
        "(uid=$username)",
        ['dn']
    );
    if ($search) {
        $result = ldap_get_entries($ldap_connection, $search);
        if ($result['count'] > 0) {
            return $result[0]['dn'];
        }
    }

    return false;
}

/**
 * Add a user to a role group
 * @param resource $ldap_connection LDAP connection
 * @param string $role_name Role name (e.g., 'administrators', 'maintainers')
 * @param string $username Username to add
 * @return bool Success status
 */
function ldap_add_member_to_group($ldap_connection, $role_name, $username)
{
    global $LDAP;

  // Get the user's DN
    $user_dn = ldap_get_user_dn($ldap_connection, $username);
    if (!$user_dn) {
        error_log("Failed to get DN for user: $username");
        return false;
    }

  // Determine if this is a global role or organization role
    $global_roles = array($LDAP['admin_role'], $LDAP['maintainer_role']);

    if (in_array($role_name, $global_roles)) {
      // Global role - add to ou=roles,dc=example,dc=com
        $group_dn = "cn=$role_name,{$LDAP['roles_dn']}";
    } else {
      // Organization role - need to determine which organization
      // For now, assume it's a global role if not found
        $group_dn = "cn=$role_name,{$LDAP['roles_dn']}";
    }

  // Check if the group exists
    $group_exists = @ldap_read($ldap_connection, $group_dn, '(objectClass=*)', ['dn']);
    if (!$group_exists) {
      // For global roles, try to create the missing group
        if (in_array($role_name, $global_roles)) {
            error_log("Group does not exist: $group_dn - attempting to create it");

          // Create the group with the user as the first member
            $group_entry = array(
            'objectClass' => array('top', 'groupOfNames'),
            'cn' => $role_name,
            'description' => 'System role group for ' . $role_name,
            'member' => $user_dn
            );

            $group_created = @ldap_add($ldap_connection, $group_dn, $group_entry);
            if ($group_created) {
                error_log("Successfully created group: $group_dn with user $username as first member");
                return true; // Group created and user added successfully
            } else {
                error_log("Failed to create group: $group_dn - " . ldap_error($ldap_connection));
                return false;
            }
        } else {
            error_log("Group does not exist: $group_dn");
            return false;
        }
    }

  // Add the user to the group
    $modify = @ldap_mod_add($ldap_connection, $group_dn, array('member' => $user_dn));
    if (!$modify) {
        error_log("Failed to add user $username to group $role_name: " . ldap_error($ldap_connection));
        return false;
    }

    return true;
}

/**
 * Remove a user from a role group
 * @param resource $ldap_connection LDAP connection
 * @param string $role_name Role name (e.g., 'administrators', 'maintainers')
 * @param string $username Username to remove
 * @return bool Success status
 */
function ldap_delete_member_from_group($ldap_connection, $role_name, $username)
{
    global $LDAP;

  // Get the user's DN
    $user_dn = ldap_get_user_dn($ldap_connection, $username);
    if (!$user_dn) {
        error_log("Failed to get DN for user: $username");
        return false;
    }

  // Determine if this is a global role or organization role
    $global_roles = array($LDAP['admin_role'], $LDAP['maintainer_role']);

    if (in_array($role_name, $global_roles)) {
      // Global role - remove from ou=roles,dc=example,dc=com
        $group_dn = "cn=$role_name,{$LDAP['roles_dn']}";
    } else {
      // Organization role - need to determine which organization
      // For now, assume it's a global role if not found
        $group_dn = "cn=$role_name,{$LDAP['roles_dn']}";
    }

  // Check if the group exists
    $group_exists = @ldap_read($ldap_connection, $group_dn, '(objectClass=*)', ['dn', 'member']);
    if (!$group_exists) {
        error_log("Group does not exist: $group_dn - cannot remove user");
        return false;
    }

  // Get current group information to check member count
    $group_info = ldap_get_entries($ldap_connection, $group_exists);
    if (!$group_info || $group_info['count'] == 0) {
        error_log("Failed to get group information for: $group_dn");
        return false;
    }

    $current_group = $group_info[0];
    $current_members = isset($current_group['member']) ? $current_group['member'] : array();
    $member_count = isset($current_members['count']) ? $current_members['count'] : 0;

  // Check if this user is actually a member
    $user_is_member = false;
    if ($member_count > 0) {
        for ($i = 0; $i < $member_count; $i++) {
            if ($current_members[$i] === $user_dn) {
                $user_is_member = true;
                break;
            }
        }
    }

    if (!$user_is_member) {
        error_log("User $username is not a member of group $role_name");
        return true; // Consider this a success since the user is already not in the group
    }

  // If this is the last member, delete the entire group
    if ($member_count == 1) {
      // Safety check: Don't allow removing the last administrator
        if ($role_name === $LDAP['admin_role']) {
            error_log("Cannot remove last administrator from administrators group - this would lock out the system");
            return false;
        }

        error_log("Removing last member from group $role_name - deleting entire group");
        $delete_group = @ldap_delete($ldap_connection, $group_dn);
        if ($delete_group) {
            error_log("Successfully deleted group $role_name (was last member)");
            return true;
        } else {
            error_log("Failed to delete group $role_name: " . ldap_error($ldap_connection));
            return false;
        }
    }

  // Remove the user from the group (there are other members)
    $modify = @ldap_mod_del($ldap_connection, $group_dn, array('member' => $user_dn));
    if (!$modify) {
        error_log("Failed to remove user $username from group $role_name: " . ldap_error($ldap_connection));
        return false;
    }

    error_log("Successfully removed user $username from group $role_name");
    return true;
}

/**
 * Find all groups that a user is a member of
 * @param resource $ldap_connection LDAP connection
 * @param string $user_dn User DN to search for
 * @return array Array of group DNs that the user is a member of
 */
function ldap_get_user_groups($ldap_connection, $user_dn)
{
    global $LDAP;
    $user_groups = array();

  // Search for groups in global roles OU
    $global_roles_search = @ldap_search(
        $ldap_connection,
        $LDAP['roles_dn'],
        "(&(objectClass=groupOfNames)(member=" . ldap_escape($user_dn, '', LDAP_ESCAPE_FILTER) . "))",
        ['dn', 'cn']
    );

    if ($global_roles_search) {
        $global_roles = ldap_get_entries($ldap_connection, $global_roles_search);
        for ($i = 0; $i < $global_roles['count']; $i++) {
            $user_groups[] = $global_roles[$i]['dn'];
        }
    }

  // Search for groups in organization roles OUs
    $org_search = @ldap_search(
        $ldap_connection,
        $LDAP['org_dn'],
        "(&(objectClass=organizationalUnit)(ou=roles))",
        ['dn']
    );

    if ($org_search) {
        $org_roles_ous = ldap_get_entries($ldap_connection, $org_search);
        for ($i = 0; $i < $org_roles_ous['count']; $i++) {
            $org_roles_dn = $org_roles_ous[$i]['dn'];

          // Search for groups in this organization's roles OU
            $org_groups_search = @ldap_search(
                $ldap_connection,
                $org_roles_dn,
                "(&(objectClass=groupOfNames)(member=" . ldap_escape($user_dn, '', LDAP_ESCAPE_FILTER) . "))",
                ['dn', 'cn']
            );

            if ($org_groups_search) {
                  $org_groups = ldap_get_entries($ldap_connection, $org_groups_search);
                for ($j = 0; $j < $org_groups['count']; $j++) {
                    $user_groups[] = $org_groups[$j]['dn'];
                }
            }
        }
    }

    return $user_groups;
}

/**
 * Remove a user from all groups they belong to
 * @param resource $ldap_connection LDAP connection
 * @param string $user_dn User DN to remove from groups
 * @return bool Success status
 */
function ldap_remove_user_from_all_groups($ldap_connection, $user_dn)
{
    global $LDAP;

  // Get all groups the user is a member of
    $user_groups = ldap_get_user_groups($ldap_connection, $user_dn);

    if (empty($user_groups)) {
      // User is not a member of any groups
        return true;
    }

    $success = true;

    foreach ($user_groups as $group_dn) {
      // Get current group information to check member count
        $group_info = @ldap_read($ldap_connection, $group_dn, '(objectClass=*)', ['dn', 'member']);
        if (!$group_info) {
            error_log("Failed to read group information for: $group_dn");
            $success = false;
            continue;
        }

        $group_data = ldap_get_entries($ldap_connection, $group_info);
        if (!$group_data || $group_data['count'] == 0) {
            error_log("Failed to get group data for: $group_dn");
            $success = false;
            continue;
        }

        $current_group = $group_data[0];
        $current_members = isset($current_group['member']) ? $current_group['member'] : array();
        $member_count = isset($current_members['count']) ? $current_members['count'] : 0;

      // If this is the last member, delete the entire group
        if ($member_count == 1) {
          // Safety check: Don't allow deleting the administrators group if it's the last admin
            if (strpos($group_dn, 'cn=' . $LDAP['admin_role']) !== false) {
                error_log("Cannot delete administrators group - would lock out the system");
                $success = false;
                continue;
            }

            error_log("Removing last member from group $group_dn - deleting entire group");
            $delete_group = @ldap_delete($ldap_connection, $group_dn);
            if ($delete_group) {
                error_log("Successfully deleted group $group_dn (was last member)");
            } else {
                error_log("Failed to delete group $group_dn: " . ldap_error($ldap_connection));
                $success = false;
            }
        } else {
          // Remove the user from the group (there are other members)
            $modify = @ldap_mod_del($ldap_connection, $group_dn, array('member' => $user_dn));
            if (!$modify) {
                error_log("Failed to remove user from group $group_dn: " . ldap_error($ldap_connection));
                $success = false;
            } else {
                error_log("Successfully removed user from group $group_dn");
            }
        }
    }

    return $success;
}

##################################

/**
 * Check whether a user account is administratively disabled (pwdAccountLockedTime = 000001010000Z).
 *
 * @param array<string, mixed> $ldapEntry LDAP entry array for the user (with pwdaccountlockedtime)
 * @return bool
 */
function isUserAccountDisabled(array $ldapEntry): bool
{
    $lockTime = $ldapEntry['pwdaccountlockedtime'][0] ?? '';

    return $lockTime === '000001010000Z';
}

/**
 * Administratively disable a user account via ppolicy (pwdAccountLockedTime = 000001010000Z).
 *
 * @param resource|\LDAP\Connection $ldap_connection Active LDAP connection
 * @param string                    $dn               Full DN of the user entry
 * @return bool
 */
function disableUserAccount($ldap_connection, string $dn): bool
{
    $result = @ldap_mod_add($ldap_connection, $dn, ['pwdAccountLockedTime' => ['000001010000Z']]);
    if ($result && function_exists('auditLog')) {
        auditLog('INFO', 'User account disabled', ['dn' => $dn, 'by' => $_SESSION['uid'] ?? '']);
    }

    return (bool) $result;
}

/**
 * Re-enable a previously disabled user account (remove pwdAccountLockedTime).
 *
 * @param resource|\LDAP\Connection $ldap_connection Active LDAP connection
 * @param string                    $dn               Full DN of the user entry
 * @return bool
 */
function enableUserAccount($ldap_connection, string $dn): bool
{
    $result = @ldap_mod_del($ldap_connection, $dn, ['pwdAccountLockedTime' => []]);
    if ($result && function_exists('auditLog')) {
        auditLog('INFO', 'User account re-enabled', ['dn' => $dn, 'by' => $_SESSION['uid'] ?? '']);
    }

    return (bool) $result;
}

/**
 * @param array<string, mixed> $ldap_entry Single entry from ldap_get_entries (lowercase keys)
 */
function ldap_user_entry_array_has_direct_disable(array $ldap_entry): bool
{
    return isset($ldap_entry['pwdaccountlockedtime']);
}

/**
 * User-level disable only: pwdAccountLockedTime (not organization disable).
 */
function ldap_user_has_direct_disable($ldap_connection, $user_dn): bool
{
    if (!$user_dn) {
        return false;
    }

    $user_attrs = @ldap_read($ldap_connection, $user_dn, '(objectClass=*)', ['pwdAccountLockedTime']);
    if (!$user_attrs) {
        return false;
    }
    $user_entry = ldap_get_entries($ldap_connection, $user_attrs);
    if ($user_entry['count'] < 1) {
        return false;
    }

    $entry = $user_entry[0];
    return is_array($entry) && ldap_user_entry_array_has_direct_disable($entry);
}

/**
 * True if the user is disabled: has pwdAccountLockedTime set or belongs to a disabled organization (status group).
 */
function ldap_user_is_disabled($ldap_connection, $user_dn)
{
    global $log_prefix, $LDAP_DEBUG;

    if (!$user_dn) {
        return false;
    }

    if (ldap_user_has_direct_disable($ldap_connection, $user_dn)) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix User $user_dn has direct account disable (pwdAccountLockedTime)");
        }
        return true;
    }

    // Check if user's organization is disabled
    $org_name = ldap_user_get_organization($ldap_connection, $user_dn);
    if ($org_name && ldap_organization_is_disabled($ldap_connection, $org_name)) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix User $user_dn is disabled due to disabled organization: $org_name");
        }
        return true;
    }

    return false;
}

##################################

/**
 * Check if an organization is disabled
 *
 * @param resource $ldap_connection LDAP connection resource
 * @param string $org_name Organization name to check
 * @return bool True if organization is disabled, false otherwise
 */
function ldap_organization_is_disabled($ldap_connection, $org_name)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!$org_name) {
        return false;
    }

    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $base_dn = $LDAP['base_dn'] ?? '';
    $disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';

    // Disabled when the organization DN is a member of the disabledOrganizations status group
    if ($base_dn !== '' && function_exists('isInStatusGroup') && isInStatusGroup($ldap_connection, $org_dn, $disabled_group_cn, $base_dn)) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix Organization $org_name is disabled via status group: $disabled_group_cn");
        }
        return true;
    }

    return false;
}

##################################

/**
 * Disable a user account using standard pwdAccountLockedTime
 *
 * @param resource $ldap_connection LDAP connection resource
 * @param string $user_dn User DN to disable
 * @return bool True if successful, false otherwise
 */
function ldap_disable_user_account($ldap_connection, $user_dn)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!$user_dn) {
        error_log("$log_prefix Cannot disable user: No DN provided");
        return false;
    }

    if (ldap_user_has_direct_disable($ldap_connection, $user_dn)) {
        // Already disabled at LDAP level; ensure the per-org group is consistent
        $org_dn = ldap_get_org_dn_for_user($user_dn);
        if ($org_dn !== '') {
            $disabled_accounts_cn = getenv('LDAP_GROUP_DISABLED_ACCOUNTS') ?: 'disabledAccounts';
            addToStatusGroup($ldap_connection, $user_dn, $disabled_accounts_cn, $org_dn);
        }
        return true;
    }

    $disable_value = '000001010000Z';
    $disable_attrs = ['pwdAccountLockedTime' => $disable_value];

    $result = @ldap_modify($ldap_connection, $user_dn, $disable_attrs);
    if (!$result) {
        $ldap_error = ldap_error($ldap_connection);
        error_log("$log_prefix Failed to disable user account $user_dn using pwdAccountLockedTime: $ldap_error");
        return false;
    }

    if ($LDAP_DEBUG) {
        error_log("$log_prefix Successfully disabled user account using pwdAccountLockedTime: $user_dn");
    }

    $org_dn = ldap_get_org_dn_for_user($user_dn);
    if ($org_dn !== '') {
        $disabled_accounts_cn = getenv('LDAP_GROUP_DISABLED_ACCOUNTS') ?: 'disabledAccounts';
        addToStatusGroup($ldap_connection, $user_dn, $disabled_accounts_cn, $org_dn);
    }

    return true;
}

##################################

/**
 * Enable a user account by removing pwdAccountLockedTime
 *
 * @param resource $ldap_connection LDAP connection resource
 * @param string $user_dn User DN to enable
 * @return bool True if successful, false otherwise
 */
function ldap_enable_user_account($ldap_connection, $user_dn)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!$user_dn) {
        error_log("$log_prefix Cannot enable user: No DN provided");
        return false;
    }

    // Remove from per-org disabledAccounts group
    $org_dn = ldap_get_org_dn_for_user($user_dn);
    if ($org_dn !== '') {
        $disabled_accounts_cn = getenv('LDAP_GROUP_DISABLED_ACCOUNTS') ?: 'disabledAccounts';
        removeFromStatusGroup($ldap_connection, $user_dn, $disabled_accounts_cn, $org_dn);
    }

    // If the organization is still disabled, keep pwdAccountLockedTime set
    $org_name = get_organization_from_user_dn($user_dn);
    if ($org_name !== false && $org_name !== '' && ldap_organization_is_disabled($ldap_connection, $org_name)) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix User $user_dn removed from disabledAccounts but pwdAccountLockedTime kept (organization is disabled)");
        }
        return true;
    }

    @ldap_modify($ldap_connection, $user_dn, ['pwdAccountLockedTime' => []]);

    $still = ldap_user_has_direct_disable($ldap_connection, $user_dn);
    if (!$still) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix Successfully enabled user account (pwdAccountLockedTime cleared): $user_dn");
        }
        return true;
    }

    if ($LDAP_DEBUG) {
        error_log("$log_prefix Enable incomplete for $user_dn: " . ldap_error($ldap_connection));
    }

    return false;
}

##################################

/**
 * Disable an organization and all its users
 *
 * @param resource $ldap_connection LDAP connection resource
 * @param string $org_name Organization name to disable
 * @return bool True if successful, false otherwise
 */
function ldap_disable_organization($ldap_connection, $org_name)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!$org_name) {
        error_log("$log_prefix Cannot disable organization: No name provided");
        return false;
    }

    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $base_dn = $LDAP['base_dn'] ?? '';
    $disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';

    if ($base_dn === '' || !function_exists('addToStatusGroup')) {
        error_log("$log_prefix Cannot disable organization $org_name: status group helpers not available (base_dn missing or addToStatusGroup unavailable)");
        return false;
    }

    $ok = addToStatusGroup($ldap_connection, $org_dn, $disabled_group_cn, $base_dn);
    if (!$ok) {
        return false;
    }

    if ($LDAP_DEBUG) {
        error_log("$log_prefix Disabled organization $org_name via status group $disabled_group_cn");
    }

    // Materialize pwdAccountLockedTime on all org users
    $users_dn = "ou=people," . $org_dn;
    $user_search = @ldap_search($ldap_connection, $users_dn, "(objectClass=inetOrgPerson)", ['dn']);

    $disabled_count = 0;
    if ($user_search) {
        $users = ldap_get_entries($ldap_connection, $user_search);
        $disable_value = '000001010000Z';
        for ($i = 0; $i < $users['count']; $i++) {
            $u_dn = $users[$i]['dn'];
            @ldap_modify($ldap_connection, $u_dn, ['pwdAccountLockedTime' => $disable_value]);
            $disabled_count++;
        }
    }

    if ($LDAP_DEBUG) {
        error_log("$log_prefix Set pwdAccountLockedTime on $disabled_count users in organization $org_name");
    }

    return true;
}

##################################

/**
 * Enable an organization and all its users
 *
 * @param resource $ldap_connection LDAP connection resource
 * @param string $org_name Organization name to enable
 * @return bool True if successful, false otherwise
 */
/**
 * Enable an organization: remove from disabledOrganizations, then selectively clear
 * pwdAccountLockedTime on users who are not individually disabled.
 *
 * @param resource|\LDAP\Connection $ldap_connection LDAP connection resource
 * @param string                    $org_name        Organization name to enable
 * @return array{ok: bool, enabled: int, still_disabled: int}|false Result counts, or false on error
 */
function ldap_enable_organization($ldap_connection, $org_name)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!$org_name) {
        error_log("$log_prefix Cannot enable organization: No name provided");
        return false;
    }

    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $base_dn = $LDAP['base_dn'] ?? '';
    $disabled_group_cn = getenv('LDAP_GROUP_DISABLED_ORGS') ?: 'disabledOrganizations';

    if ($base_dn === '' || !function_exists('removeFromStatusGroup')) {
        error_log("$log_prefix Cannot enable organization $org_name: status group helpers not available (base_dn missing or removeFromStatusGroup unavailable)");
        return false;
    }

    $ok = removeFromStatusGroup($ldap_connection, $org_dn, $disabled_group_cn, $base_dn);
    if (!$ok) {
        return false;
    }

    if ($LDAP_DEBUG) {
        error_log("$log_prefix Enabled organization $org_name via status group $disabled_group_cn");
    }

    // Selectively clear pwdAccountLockedTime: skip users in per-org disabledAccounts
    $disabled_accounts_cn = getenv('LDAP_GROUP_DISABLED_ACCOUNTS') ?: 'disabledAccounts';
    $users_dn = "ou=people," . $org_dn;
    $user_search = @ldap_search($ldap_connection, $users_dn, "(objectClass=inetOrgPerson)", ['dn']);

    $enabled = 0;
    $still_disabled = 0;

    if ($user_search) {
        $users = ldap_get_entries($ldap_connection, $user_search);
        for ($i = 0; $i < $users['count']; $i++) {
            $u_dn = $users[$i]['dn'];
            if (isInStatusGroup($ldap_connection, $u_dn, $disabled_accounts_cn, $org_dn)) {
                $still_disabled++;
            } else {
                @ldap_modify($ldap_connection, $u_dn, ['pwdAccountLockedTime' => []]);
                $enabled++;
            }
        }
    }

    if ($LDAP_DEBUG) {
        error_log("$log_prefix Organization $org_name enable: $enabled users enabled, $still_disabled individually disabled");
    }

    return ['ok' => true, 'enabled' => $enabled, 'still_disabled' => $still_disabled];
}

##################################

/**
 * Get disable status information for a user
 *
 * @param resource $ldap_connection LDAP connection resource
 * @param string $user_dn User DN to check
 * @return array|false Disable status information or false if error
 */
function ldap_get_user_disable_status($ldap_connection, $user_dn)
{
    global $log_prefix, $LDAP_DEBUG;

    if (!$user_dn) {
        return false;
    }

    $user_attrs = @ldap_read($ldap_connection, $user_dn, "(objectClass=*)", ['pwdAccountLockedTime', 'uid', 'cn']);
    if (!$user_attrs) {
        return false;
    }

    $user_entry = ldap_get_entries($ldap_connection, $user_attrs);
    if ($user_entry['count'] === 0) {
        return false;
    }

    $user_info = $user_entry[0];
    if (!is_array($user_info)) {
        return false;
    }
    $has_pwd_lock = ldap_user_entry_array_has_direct_disable($user_info);
    $individually_disabled = ldap_user_is_individually_disabled($ldap_connection, $user_dn);

    $org_name = ldap_user_get_organization($ldap_connection, $user_dn);
    $org_disabled = ($org_name && ldap_organization_is_disabled($ldap_connection, $org_name));

    $is_disabled = $has_pwd_lock || $individually_disabled || $org_disabled;

    if ($individually_disabled && $org_disabled) {
        $disable_reason = 'Account disabled + Organization disabled';
    } elseif ($individually_disabled) {
        $disable_reason = 'Account disabled by administrator';
    } elseif ($org_disabled) {
        $disable_reason = 'Organization disabled by administrator';
    } else {
        $disable_reason = null;
    }

    $status = [
        'dn' => $user_dn,
        'uid' => $user_info['uid'][0] ?? 'Unknown',
        'cn' => $user_info['cn'][0] ?? 'Unknown',
        'is_disabled' => $is_disabled,
        'individually_disabled' => $individually_disabled,
        'disable_time' => isset($user_info['pwdaccountlockedtime']) ? $user_info['pwdaccountlockedtime'][0] : null,
        'disable_reason' => $disable_reason,
    ];

    if ($org_disabled) {
        $status['org_disabled'] = $org_name;
    }

    return $status;
}

##################################

/**
 * Get disable status information for an organization
 *
 * @param resource $ldap_connection LDAP connection resource
 * @param string $org_name Organization name to check
 * @return array|false Disable status information or false if error
 */
function ldap_get_organization_disable_status($ldap_connection, $org_name)
{
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!$org_name) {
        return false;
    }

    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $org_attrs = @ldap_read($ldap_connection, $org_dn, "(objectClass=*)", ['o', 'description']);

    if (!$org_attrs) {
        return false;
    }

    $org_entry = ldap_get_entries($ldap_connection, $org_attrs);
    if ($org_entry['count'] === 0) {
        return false;
    }

    $org_info = $org_entry[0];
    $is_disabled = ldap_organization_is_disabled($ldap_connection, $org_name);

    $users_dn = "ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $user_search = @ldap_search($ldap_connection, $users_dn, "(objectClass=inetOrgPerson)", ['dn']);

    $total_users = 0;
    $disabled_users = 0;
    $individually_disabled_users = 0;

    if ($user_search) {
        $users = ldap_get_entries($ldap_connection, $user_search);
        $total_users = $users['count'];

        for ($i = 0; $i < $users['count']; $i++) {
            $u_dn = $users[$i]['dn'];
            if (ldap_user_is_disabled($ldap_connection, $u_dn)) {
                $disabled_users++;
            }
            if (ldap_user_is_individually_disabled($ldap_connection, $u_dn)) {
                $individually_disabled_users++;
            }
        }
    }

    $status = [
        'dn' => $org_dn,
        'name' => $org_name,
        'description' => $org_info['description'][0] ?? '',
        'is_disabled' => $is_disabled,
        'disable_time' => null,
        'disable_reason' => $is_disabled ? 'Organization disabled by administrator' : null,
        'total_users' => $total_users,
        'disabled_users' => $disabled_users,
        'individually_disabled_users' => $individually_disabled_users,
    ];

    return $status;
}

##################################
