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
function open_ldap_connection($ldap_bind = TRUE) {

    global $log_prefix, $LDAP, $SENT_HEADERS, $LDAP_DEBUG, $LDAP_VERBOSE_CONNECTION_LOGS;

    // Enforce TLS in production environments
    if (getenv('ENVIRONMENT') !== 'development' && getenv('ENVIRONMENT') !== 'test') {
        if ($LDAP['ignore_cert_errors'] === TRUE) { 
            error_log("$log_prefix WARNING: Certificate errors are being ignored in production environment", 0);
        }
    }

    if ($LDAP['ignore_cert_errors'] === TRUE) {
        putenv('LDAPTLS_REQCERT=never');
    }
    $ldap_connection = @ldap_connect($LDAP['uri']);

    if (!$ldap_connection) {
        print "Problem: Can't connect to the LDAP server at {$LDAP['uri']}";
        die("Can't connect to the LDAP server at {$LDAP['uri']}");
        exit(1);
    }

    ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
    if ($LDAP_VERBOSE_CONNECTION_LOGS === TRUE) {
        ldap_set_option(NULL, LDAP_OPT_DEBUG_LEVEL, 7);
    }

    // Enforce TLS for non-localhost connections in production
    $is_localhost = preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri']) || 
                    preg_match('/^ldap:\/\/localhost(:[0-9]+)?$/', $LDAP['uri']);
    
    if (!preg_match("/^ldaps:/", $LDAP['uri'])) {

        $tls_result = @ldap_start_tls($ldap_connection);

        if ($tls_result !== TRUE) {

            if (!preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri'])) { 
                error_log("$log_prefix Failed to start STARTTLS connection to {$LDAP['uri']}: " . ldap_error($ldap_connection), 0); 
            }

            if ($LDAP["require_starttls"] === TRUE || (!$is_localhost && getenv('ENVIRONMENT') !== 'development')) {
                print "<div style='position: fixed;bottom: 0;width: 100%;' class='alert alert-danger'>Fatal:  Couldn't create a secure connection to {$LDAP['uri']} and LDAP_REQUIRE_STARTTLS is TRUE.</div>";
                exit(0);
            } else {
                if ($SENT_HEADERS === TRUE and !preg_match('/^ldap:\/\/localhost(:[0-9]+)?$/', $LDAP['uri']) and !preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri'])) {
                    print "<div style='position: fixed;bottom: 0px;width: 100%;height: 20px;border-bottom:solid 20px yellow;'>WARNING: Insecure LDAP connection to {$LDAP['uri']}</div>";
                }
                ldap_close($ldap_connection);
                $ldap_connection = @ldap_connect($LDAP['uri']);
                ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            }
        } else {
            if ($LDAP_DEBUG === TRUE) {
                error_log("$log_prefix Start STARTTLS connection to {$LDAP['uri']}", 0);
            }
            $LDAP['connection_type'] = "StartTLS";
        }

    } else {
        if ($LDAP_DEBUG === TRUE) {
            error_log("$log_prefix Using an LDAPS encrypted connection to {$LDAP['uri']}", 0);
        }
        $LDAP['connection_type'] = 'LDAPS';
    }

    if ($ldap_bind === TRUE) {

        if ($LDAP_DEBUG === TRUE) {
            error_log("$log_prefix Attempting to bind to {$LDAP['uri']} as {$LDAP['admin_bind_dn']}", 0);
        }
        $bind_result = @ldap_bind($ldap_connection, $LDAP['admin_bind_dn'], $LDAP['admin_bind_pwd']);

        if ($bind_result !== TRUE) {

            $this_error = "Failed to bind to {$LDAP['uri']} as {$LDAP['admin_bind_dn']}";
            if ($LDAP_DEBUG === TRUE) {
                $this_error .= " with password {$LDAP['admin_bind_pwd']}";
            }
            $this_error .= ": " . ldap_error($ldap_connection);
            print "Problem: Failed to bind as {$LDAP['admin_bind_dn']}";
            error_log("$log_prefix $this_error", 0);

            exit(1);

        } elseif ($LDAP_DEBUG === TRUE) {
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
 * @return bool True if authentication succeeds, false otherwise
 */
function ldap_auth_username($ldap_connection, $username, $password) {

  # Search for the DN for the given username across all organizations.  If found, try binding with the DN and user's password.
  # If the binding succeeds, return the DN.

  global $log_prefix, $LDAP, $SITE_LOGIN_LDAP_ATTRIBUTE, $LDAP_DEBUG;

  $ldap_search_query="{$SITE_LOGIN_LDAP_ATTRIBUTE}=" . ldap_escape(($username === null ? '' : $username), "", LDAP_ESCAPE_FILTER);
  if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix Running LDAP search for: $ldap_search_query"); }

  # Search across all organizations for the user
  $ldap_search = @ldap_search( $ldap_connection, $LDAP['org_dn'], $ldap_search_query );
  if (!$ldap_search) {
    error_log("$log_prefix Couldn't search for $ldap_search_query: " . ldap_error($ldap_connection),0);
    return FALSE;
  }

  $result = @ldap_get_entries($ldap_connection, $ldap_search);
  if (!$result) {
    error_log("$log_prefix Couldn't get LDAP entries for {$username}: " . ldap_error($ldap_connection),0);
    return FALSE;
  }

  if ($LDAP_DEBUG === TRUE) {
    error_log("$log_prefix LDAP search returned " . $result["count"] . " records for $ldap_search_query",0);
    for ($i=1; $i==$result["count"]; $i++) {
      error_log("$log_prefix ". "Entry {$i}: " . $result[$i-1]['dn'], 0);
    }
  }

  if ($result["count"] > 1) {
    if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix There was more than one entry for {$ldap_search_query} so it wasn't possible to determine which user to log in as."); }
    return FALSE;
  }

  if ($result["count"] == 1) {
    $this_dn = $result[0]['dn'];
  }

  # If not found in organizations, search in system users
  if ($result["count"] == 0) {
    if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix User not found in organizations, searching in system users"); }
    
    $ldap_search = @ldap_search( $ldap_connection, $LDAP['people_dn'], $ldap_search_query );
    if (!$ldap_search) {
      error_log("$log_prefix Couldn't search for $ldap_search_query in system users: " . ldap_error($ldap_connection),0);
      return FALSE;
    }

    $result = @ldap_get_entries($ldap_connection, $ldap_search);
    if (!$result) {
      error_log("$log_prefix Couldn't get LDAP entries for {$username} in system users: " . ldap_error($ldap_connection),0);
      return FALSE;
    }

    if ($LDAP_DEBUG === TRUE) {
      error_log("$log_prefix LDAP search in system users returned " . $result["count"] . " records for $ldap_search_query",0);
      for ($i=1; $i==$result["count"]; $i++) {
        error_log("$log_prefix ". "Entry {$i}: " . $result[$i-1]['dn'], 0);
      }
    }

    if ($result["count"] > 1) {
      if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix There was more than one entry for {$username} in system users so it wasn't possible to determine which user to log in as."); }
      return FALSE;
    }

    if ($result["count"] == 1) {
      $this_dn = $result[0]['dn'];
    }

    if ($result["count"] == 0) {
      if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix There was no entry for {$username} in system users so it wasn't possible to determine which user to log in as."); }
      return FALSE;
    }
  }

  if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix Attempting authenticate as $username by binding with {$this_dn} ",0); }

  $auth_ldap_connection = open_ldap_connection(FALSE);

  $can_bind =  @ldap_bind($auth_ldap_connection, $this_dn, $password);
  if ($can_bind) {
    if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix Able to bind as {$username}: dn is {$this_dn}",0); }
    ldap_close($auth_ldap_connection);
    return $this_dn;
  }

  if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix Unable to bind as {$username}: " . ldap_error($auth_ldap_connection),0); }

  ldap_close($auth_ldap_connection);
  return FALSE;
}


###################################

function ldap_setup_auth($ldap_connection, $password) {

 #For the initial setup we need to make sure that whoever's running it has the default admin user
 #credentials as passed in ADMIN_BIND_*
 global $log_prefix, $LDAP, $LDAP_DEBUG;

  if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix Initial setup: opening another LDAP connection to test authentication as {$LDAP['admin_bind_dn']}.",0); }
  $auth_ldap_connection = open_ldap_connection();
  $can_bind = @ldap_bind($auth_ldap_connection, $LDAP['admin_bind_dn'], $password);
  ldap_close($auth_ldap_connection);
  if ($can_bind) {
    if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix Initial setup: able to authenticate as {$LDAP['admin_bind_dn']}.",0); }
    return TRUE;
  }
  else {
    $this_error="Initial setup: Unable to authenticate as {$LDAP['admin_bind_dn']}";
    if ($LDAP_DEBUG === TRUE) { $this_error .= " with password $password"; }
    $this_error .= ". The password used to authenticate for /setup should be the same as set by LDAP_ADMIN_BIND_PWD. ";
    $this_error .= ldap_error($ldap_connection);
    error_log("$log_prefix $this_error",0);
    return FALSE;
  }


}


#################################

function generate_salt($length) {

 $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ./';

 mt_srand(intval(microtime()) * 1000000);

 $salt = '';
 while (strlen($salt) < $length) {
    $salt .= substr($permitted_chars, (rand() % strlen($permitted_chars)), 1);
  }

 return $salt;

}


##################################

function verify_ldap_passcode($passcode, $stored_hash) {
  global $log_prefix;
  
  // Handle different LDAP hash formats
  if (preg_match('/^\{ARGON2\}(.+)$/', $stored_hash, $matches)) {
    return password_verify($passcode, $matches[1]);
  } elseif (preg_match('/^\{SSHA\}(.+)$/', $stored_hash, $matches)) {
    $hash_data = base64_decode($matches[1]);
    $salt = substr($hash_data, -8);
    $hash = substr($hash_data, 0, -8);
    return hash_equals(sha1($passcode . $salt, TRUE), $hash);
  } elseif (preg_match('/^\{CRYPT\}(.+)$/', $stored_hash, $matches)) {
    return hash_equals(crypt($passcode, $matches[1]), $stored_hash);
  } elseif (preg_match('/^\{SMD5\}(.+)$/', $stored_hash, $matches)) {
    $hash_data = base64_decode($matches[1]);
    $salt = substr($hash_data, -8);
    $hash = substr($hash_data, 0, -8);
    return hash_equals(md5($passcode . $salt, TRUE), $hash);
  } elseif (preg_match('/^\{MD5\}(.+)$/', $stored_hash, $matches)) {
    return hash_equals(base64_encode(md5($passcode, TRUE)), $matches[1]);
  } elseif (preg_match('/^\{SHA\}(.+)$/', $stored_hash, $matches)) {
    return hash_equals(base64_encode(sha1($passcode, TRUE)), $matches[1]);
  } else {
    // Fallback to direct comparison for cleartext (not recommended)
    error_log("$log_prefix Warning: Using cleartext passcode comparison", 0);
    return hash_equals($passcode, $stored_hash);
  }
}

function ldap_hashed_password($password) {

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
 
 error_log("$log_prefix LDAP password: using '{$hash_algo}' as the hashing method",0);

 switch ($hash_algo) {

  case 'ARGON2':
    $hashed_pwd = '{ARGON2}' . password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 4, 'threads' => 3]);
    break;

  case 'SSHA':
    $salt = generate_salt(8);
    $hashed_pwd = '{SSHA}' . base64_encode(sha1($password . $salt, TRUE) . $salt);
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
        $hashed_pwd = '{SSHA}' . base64_encode(sha1($password . $salt, TRUE) . $salt);
    }
    break;
 }

 return $hashed_pwd;

}

function ldap_hashed_passcode($passcode) {

 global $PASSWORD_HASH, $log_prefix, $SECURITY_CONFIG;

 $secure_algos = $SECURITY_CONFIG['passcode']['allowed_algorithms'];
 $default_algo = $SECURITY_CONFIG['passcode']['default_algorithm'];
 
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
         error_log("$log_prefix LDAP passcode: unknown or weak hash method ($PASSWORD_HASH), falling back to secure default", 0);
     } elseif ($PASSWORD_HASH === 'CLEAR') {
         error_log("$log_prefix passcode hashing - FATAL - CLEAR selected, refusing to store passcode in cleartext.", 0);
         die("FATAL: Refusing to store passcode in cleartext. Set PASSWORD_HASH to a secure value (ARGON2 or SSHA recommended).");
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
     die("FATAL: No secure passcode hash available. Check your PHP and system configuration.");
 }
 
 error_log("$log_prefix LDAP passcode: using '{$hash_algo}' as the hashing method",0);

 switch ($hash_algo) {

  case 'ARGON2':
    $hashed_passcode = '{ARGON2}' . password_hash($passcode, PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 4, 'threads' => 3]);
    break;

  case 'SSHA':
    $salt = generate_salt(8);
    $hashed_passcode = '{SSHA}' . base64_encode(sha1($passcode . $salt, TRUE) . $salt);
    break;

  case 'SHA512CRYPT':
    $hashed_passcode = '{CRYPT}' . crypt($passcode, '$6$' . generate_salt(8));
    break;

  case 'SHA256CRYPT':
    $hashed_passcode = '{CRYPT}' . crypt($passcode, '$5$' . generate_salt(8));
    break;

  default:
    // Fallback to ARGON2 if available, otherwise SSHA
    if (defined('PASSWORD_ARGON2ID')) {
        $hashed_passcode = '{ARGON2}' . password_hash($passcode, PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 4, 'threads' => 3]);
    } else {
        $salt = generate_salt(8);
        $hashed_passcode = '{SSHA}' . base64_encode(sha1($passcode . $salt, TRUE) . $salt);
    }
    break;
 }

 return $hashed_passcode;

}


##################################

function ldap_get_system_users($ldap_connection, $start=0, $entries=NULL, $sort="asc", $sort_key=NULL, $filters=NULL, $fields=NULL) {
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (!isset($fields)) { 
        $fields = array_unique( array("{$LDAP['account_attribute']}", "givenname", "sn", "cn", "mail", "description", "dn"));
        // Add UUID field if UUID identification is enabled
        if ($LDAP['use_uuid_identification']) {
            $fields[] = 'entryUUID';
        }
    }

    if (!isset($sort_key)) { $sort_key = $LDAP['account_attribute']; }
    
    # Ensure the sort key attribute is always included in the requested fields
    if (!in_array($sort_key, $fields)) {
        $fields[] = $sort_key;
    }

    $this_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=*)$filters)";

    # Search only in system users (not organization users)
    $users = array();
    
    # Search in system users only
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $this_filter, $fields);
    if ($ldap_search) {
        $result = @ ldap_get_entries($ldap_connection, $ldap_search);
        if ($LDAP_DEBUG === TRUE) { 
            if ($result && is_array($result)) {
                error_log("$log_prefix LDAP returned {$result['count']} system users when using this filter: $this_filter");
            } else {
                error_log("$log_prefix LDAP search failed or returned invalid result: " . print_r($result, true));
            }
        }
        
        # If we need entryUUID and it's not in the result, try with operational attributes
        if ($LDAP['use_uuid_identification'] && $result && is_array($result) && $result['count'] > 0) {
            $has_uuid = false;
            foreach ($result as $record) {
                if (isset($record['entryUUID']) || isset($record['entryuuid'])) {
                    $has_uuid = true;
                    break;
                }
            }
            if (!$has_uuid) {
                if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix entryUUID not found, trying with operational attributes"); }
                # Try again with operational attributes
                $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $this_filter, array_merge($fields, ['+']));
                if ($ldap_search) {
                    $result = @ ldap_get_entries($ldap_connection, $ldap_search);
                    if ($LDAP_DEBUG === TRUE) { 
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
                
                if ($LDAP_DEBUG === TRUE) {
                    error_log("$log_prefix Processing record: " . print_r($record, true));
                    error_log("$log_prefix Record keys: " . print_r(array_keys($record), true));
                    error_log("$log_prefix Sort key: $sort_key, Sort key value: " . (isset($record[$sort_key][0]) ? $record[$sort_key][0] : 'NOT SET'));
                }
                
                if (isset($record[$sort_key][0])) {
                    $add_these = array();
                    if ($LDAP_DEBUG === TRUE) {
                        error_log("$log_prefix Processing user record: " . $record[$sort_key][0]);
                        error_log("$log_prefix Available attributes in record: " . print_r(array_keys($record), true));
                        error_log("$log_prefix Requested fields: " . print_r($fields, true));
                    }
                    foreach($fields as $this_attr) {
                        // Skip the sort key attribute itself, but include all other requested fields
                        if ($this_attr !== $sort_key) {
                            // Check for case-insensitive attribute match
                            $found_attr = false;
                            $attr_value = null;
                            
                            if ($LDAP_DEBUG === TRUE) {
                                error_log("$log_prefix Processing field: $this_attr");
                            }
                            
                            // First try exact match
                            if (isset($record[$this_attr])) {
                                $found_attr = true;
                                $attr_value = $record[$this_attr];
                                if ($LDAP_DEBUG === TRUE) {
                                    error_log("$log_prefix Found exact match for $this_attr");
                                }
                            } else {
                                // Try case-insensitive match
                                foreach (array_keys($record) as $key) {
                                    if (is_string($key) && strcasecmp($key, $this_attr) === 0) {
                                        $found_attr = true;
                                        $attr_value = $record[$key];
                                        if ($LDAP_DEBUG === TRUE) {
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
                                if ($LDAP_DEBUG === TRUE) {
                                    error_log("$log_prefix Added attribute $this_attr: " . print_r($add_these[$this_attr], true));
                                    // Special debug for entryUUID
                                    if (strcasecmp($this_attr, 'entryUUID') === 0) {
                                        error_log("$log_prefix entryUUID raw value: " . print_r($attr_value, true));
                                        error_log("$log_prefix entryUUID extracted value: " . print_r($add_these[$this_attr], true));
                                        error_log("$log_prefix entryUUID stored with key: $this_attr");
                                    }
                                }
                            } else {
                                if ($LDAP_DEBUG === TRUE) {
                                    error_log("$log_prefix Attribute $this_attr not found in record (case-insensitive check)");
                                }
                            }
                        } else {
                            if ($LDAP_DEBUG === TRUE) {
                                error_log("$log_prefix Skipping sort key attribute: $this_attr");
                            }
                        }
                    }
                    $users[$record[$sort_key][0]] = $add_these;
                    if ($LDAP_DEBUG === TRUE) {
                        error_log("$log_prefix Added user to array: " . $record[$sort_key][0]);
                    }
                } else {
                    if ($LDAP_DEBUG === TRUE) {
                        error_log("$log_prefix Record missing sort key: $sort_key");
                    }
                }
            }
        }
    }

    if ($sort == "asc") { ksort($users); } else { krsort($users); }

    if ($LDAP_DEBUG === TRUE) {
        error_log("$log_prefix Final users array: " . print_r($users, true));
        error_log("$log_prefix Returning " . count($users) . " users");
    }

    return(array_slice($users,$start,$entries));
}

function ldap_get_user_list($ldap_connection,$start=0,$entries=NULL,$sort="asc",$sort_key=NULL,$filters=NULL,$fields=NULL) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (!isset($fields)) { 
     $fields = array_unique( array("{$LDAP['account_attribute']}", "givenname", "sn", "cn", "mail", "description", "organization"));
     // Add UUID field if UUID identification is enabled
     if ($LDAP['use_uuid_identification']) {
         $fields[] = 'entryUUID';
     }
 }

 if (!isset($sort_key)) { $sort_key = $LDAP['account_attribute']; }

 $this_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=*)$filters)";

 # Search across all organizations and system users for users
 $users = array();
 
 # Search in organizations
 $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $this_filter, $fields);
 if ($ldap_search) {
   $result = @ ldap_get_entries($ldap_connection, $ldap_search);
   if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP returned {$result['count']} users in organizations when using this filter: $this_filter",0); }
   
   foreach ($result as $record) {
     if (isset($record[$sort_key][0])) {
       $add_these = array();
       foreach($fields as $this_attr) {
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
   if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP returned {$result['count']} system users when using this filter: $this_filter",0); }
   
   foreach ($result as $record) {
     if (isset($record[$sort_key][0])) {
       $add_these = array();
       foreach($fields as $this_attr) {
         if ($this_attr !== $sort_key and isset($record[$this_attr])) { 
           $add_these[$this_attr] = $record[$this_attr][0]; 
         }
       }
       $users[$record[$sort_key][0]] = $add_these;
     }
   }
 }

 if ($sort == "asc") { ksort($users); } else { krsort($users); }

 if ($LDAP_DEBUG === TRUE) {
     error_log("$log_prefix Final users array: " . print_r($users, true));
     error_log("$log_prefix Returning " . count($users) . " users");
 }

 return(array_slice($users,$start,$entries));

}

##################################


function fetch_id_stored_in_ldap($ldap_connection,$type="uid") {

  global $log_prefix, $LDAP, $LDAP_DEBUG;

  $filter = "(&(objectclass=device)(cn=last{$type}))";
  $ldap_search = @ ldap_search($ldap_connection, "{$LDAP['base_dn']}", $filter, array('serialNumber'));
  $result = ldap_get_entries($ldap_connection, $ldap_search);

  if (isset($result[0]['serialnumber'][0]) and is_numeric($result[0]['serialnumber'][0])){
    return $result[0]['serialnumber'][0];
  }
  else {
    return FALSE;
  }

}


##################################


function ldap_get_highest_id($ldap_connection,$type="uid") {

 global $log_prefix, $LDAP, $LDAP_DEBUG, $min_uid;

 // Only UID functionality is supported now (groups are obsolete)
 if ($type != "uid") {
  $type = "uid";
 }
 
 $this_id = $min_uid;
 $record_base_dn = $LDAP['user_dn'];
 $record_filter = "({$LDAP['account_attribute']}=*)";
 $record_attribute = "uidnumber";

 $fetched_id = fetch_id_stored_in_ldap($ldap_connection,$type);

 if ($fetched_id !== FALSE) {

  return($fetched_id);

 }
 else {

  error_log("$log_prefix cn=lastUID doesn't exist so the highest $type is determined by searching through all the LDAP records.",0);

  $ldap_search = @ ldap_search($ldap_connection, $record_base_dn, $record_filter, array($record_attribute));
  $result = ldap_get_entries($ldap_connection, $ldap_search);

  foreach ($result as $record) {
   if (isset($record[$record_attribute][0])) {
    if ($record[$record_attribute][0] > $this_id) { $this_id = $record[$record_attribute][0]; }
   }
  }

 }

 return($this_id);

}


##################################

##################################

function ldap_get_role_members($ldap_connection, $role_name, $start=0, $entries=NULL, $sort="asc") {
 global $log_prefix, $LDAP, $LDAP_DEBUG;

 // Search for the role in the global roles OU
 $ldap_search_query = "(cn=" . ldap_escape($role_name, "", LDAP_ESCAPE_FILTER) . ")";
 $ldap_search = @ ldap_search($ldap_connection, $LDAP['roles_dn'], $ldap_search_query, array('member'));

 $result = @ ldap_get_entries($ldap_connection, $ldap_search);
 if ($result) { $result_count = $result['count']; } else { $result_count = 0; }

 $records = array();

 if ($result_count > 0 && isset($result[0]['member'])) {
  foreach ($result[0]['member'] as $key => $value) {
   if ($key !== 'count' and !empty($value)) {
    // Extract the DN from the member attribute
    $records[] = $value;
    if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix {$value} is a member of role {$role_name}",0); }
   }
  }

  $actual_result_count = count($records);
  if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP returned $actual_result_count members of role {$role_name}",0); }

  if ($actual_result_count > 0) {
   if ($sort == "asc") { sort($records); } else { rsort($records); }
   return(array_slice($records,$start,$entries));
  }
  else {
   return array();
  }
 }
 else {
  return array();
 }
}

##################################

function ldap_is_group_member($ldap_connection, $base_dn, $group_name, $user_dn) {
  global $log_prefix, $LDAP, $LDAP_DEBUG;

  if (empty($base_dn) || empty($group_name) || empty($user_dn)) {
    return FALSE;
  }

  $ldap_search_query = "(&(objectclass=groupOfNames)(cn=$group_name)(member=$user_dn))";
  $ldap_search = @ldap_search($ldap_connection, $base_dn, $ldap_search_query);
  if (!$ldap_search) {
    return FALSE;
  }

  $result = ldap_get_entries($ldap_connection, $ldap_search);
  if ($result['count'] > 0) {
    return ($result['count'] > 0);
  }

  return FALSE;
}


##################################

function ldap_user_get_organization($ldap_connection, $user_dn) {
  global $log_prefix, $LDAP, $LDAP_DEBUG;

  return get_organization_from_user_dn($user_dn);
}

function get_organization_from_user_dn($user_dn) {
  global $LDAP;
  
  // Pattern: uid=username,ou=people,o=OrgName,ou=organizations,dc=example,dc=com
  if (preg_match('/o=([^,]+),ou=organizations,/', $user_dn, $matches)) {
      return $matches[1];
  }
  
  // Alternative pattern for some structures
  if (preg_match('/o=([^,]+),/', $user_dn, $matches)) {
      return $matches[1];
  }
  
  return FALSE;
}

##################################

function ldap_user_group_membership($ldap_connection,$user_dn) {

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
      if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix User is global administrator"); }
  } elseif ($global_maintainer_search && ldap_count_entries($ldap_connection, $global_maintainer_search) > 0) {
      $roles[] = $LDAP['maintainer_role'];
      if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix User is global maintainer"); }
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

function ldap_organization_get_uuid($ldap_connection, $organization_name) {
  global $log_prefix, $LDAP, $LDAP_DEBUG;

  if ($LDAP_DEBUG === TRUE) {
    error_log("$log_prefix ldap_organization_get_uuid: Searching for organization '$organization_name'");
  }

  // Escape the organization name for LDAP search
  $escaped_org_name = ldap_escape($organization_name, "", LDAP_ESCAPE_FILTER);
  
  $ldap_search = @ldap_search($ldap_connection, $LDAP['org_dn'], "(&(objectclass=organization)(o=$escaped_org_name))", array($LDAP['uuid_attribute']));
  
  if (!$ldap_search) {
    if ($LDAP_DEBUG === TRUE) {
      error_log("$log_prefix ldap_organization_get_uuid: LDAP search failed: " . ldap_error($ldap_connection));
    }
    return FALSE;
  }
  
  $result = ldap_get_entries($ldap_connection, $ldap_search);
  
  if ($LDAP_DEBUG === TRUE) {
    error_log("$log_prefix ldap_organization_get_uuid: Found " . $result['count'] . " organizations");
  }

  if ($result['count'] > 0) {
    $uuid = $result[0][strtolower($LDAP['uuid_attribute'])][0];
    if ($LDAP_DEBUG === TRUE) {
      error_log("$log_prefix ldap_organization_get_uuid: Returning UUID: $uuid");
    }
    return $uuid;
  }

  if ($LDAP_DEBUG === TRUE) {
    error_log("$log_prefix ldap_organization_get_uuid: No organization found with name '$organization_name'");
  }
  return FALSE;

}

function ldap_user_get_uuid($ldap_connection, $user_dn) {
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

##################################

function ldap_complete_attribute_array($default_attributes,$additional_attributes) {

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
        }
        else {
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

  }
  else {
    return($default_attributes);
  }

}


##################################

function ldap_new_account($ldap_connection,$account_r) {

  global $log_prefix, $LDAP, $LDAP_DEBUG;

  if (    isset($account_r['givenname'][0])
      and isset($account_r['sn'][0])
      and isset($account_r['cn'][0])
      and isset($account_r[$LDAP['account_attribute']])
      and isset($account_r['password'][0])
      and isset($account_r['organization'][0])) {

   $account_identifier = $account_r[$LDAP['account_attribute']][0];
   $organization = $account_r['organization'][0];
   
   # Check if organization exists
   $org_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], "o=" . ldap_escape($organization, "", LDAP_ESCAPE_FILTER));
   if (!$org_search || ldap_count_entries($ldap_connection, $org_search) == 0) {
     error_log("$log_prefix Create account; Organization '$organization' does not exist",0);
     return FALSE;
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
       return FALSE;
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

     # Handle passcode if provided
     if (isset($account_r['passcode'][0]) && !empty($account_r['passcode'][0])) {
       $hashed_passcode = ldap_hashed_passcode($account_r['passcode'][0]);
       # Add passcode to userPassword (multiple values supported)
       if (!isset($account_attributes['userpassword'])) {
         $account_attributes['userpassword'] = array();
       }
       $account_attributes['userpassword'][] = $hashed_passcode;
       unset($account_r['passcode']);
     }

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
     if ($LDAP_DEBUG === TRUE) {
         error_log("$log_prefix ldap_new_account: Attributes for ldap_add: " . print_r($account_attributes, true));
     }

     $add_account = @ ldap_add($ldap_connection,
                               "{$LDAP['account_attribute']}=$account_identifier,{$user_dn}",
                               $account_attributes
                              );

     if ($add_account) {
       error_log("$log_prefix Created new account: $account_identifier in organization: $organization",0);
       
       # Add user to organization admin role ONLY if they are organization users (not system users)
       # System administrators/maintainers already have full access to all organizations
       # Check: 1) User has org_admin role, 2) Organization is specified, 3) User DN is under org_dn (not people_dn)
       # IMPORTANT: Check organization admin role independently, regardless of role value conflicts
       if (isset($account_attributes['description'][0]) && 
           $account_attributes['description'][0] === $LDAP['org_admin_role'] &&
           isset($account_attributes['o'][0]) && 
           strpos($user_dn, $LDAP['org_dn']) !== false) {
         addUserToOrgAdmin($organization, "{$LDAP['account_attribute']}=$account_identifier,{$user_dn}");
       }
       
       return TRUE;
     }
     else {
       ldap_get_option($ldap_connection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $detailed_err);
       error_log("$log_prefix Create account; couldn't create the account for {$account_identifier}: " . ldap_error($ldap_connection) . " -- " . $detailed_err,0);
     }

   }

   else {
     error_log("$log_prefix Create account; Account for {$account_identifier} already exists in organization {$organization}",0);
   }

  }
  else {
    error_log("$log_prefix Create account; missing parameters (organization is now required)",0);
  }

  return FALSE;

}


##################################

function ldap_delete_account($ldap_connection,$username) {

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
   error_log("$log_prefix Delete account; User {$username} not found",0);
   return FALSE;
  }
  
  // Remove user from all groups before deletion
  $group_cleanup_success = ldap_remove_user_from_all_groups($ldap_connection, $user_dn);
  if (!$group_cleanup_success) {
   error_log("$log_prefix Warning: Failed to remove user {$username} from some groups",0);
   // Continue with deletion even if group cleanup failed
  }
  
  $delete = @ ldap_delete($ldap_connection, $user_dn);

  if ($delete) {
   error_log("$log_prefix Deleted account for $username at DN: $user_dn",0);
   return TRUE;
  }
  else {
   error_log("$log_prefix Couldn't delete account for {$username} at DN {$user_dn}: " . ldap_error($ldap_connection),0);
   return FALSE;
  }

 }

 return FALSE;

}


##################################




##################################

function ldap_change_password($ldap_connection,$username,$new_password) {

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
   return FALSE;
 }

 $entries["userPassword"] = ldap_hashed_password($new_password);
 $update = @ ldap_mod_replace($ldap_connection, $user_dn, $entries);

 if ($update) {
  error_log("$log_prefix Updated the password for $username",0);
  return TRUE;
 }
 else {
  error_log("$log_prefix Couldn't update the password for {$username}: " . ldap_error($ldap_connection),0);
  return FALSE;
 }

}


##################################

function ldap_change_passcode($ldap_connection,$username,$new_passcode) {

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
   return FALSE;
 }

 # Get current user attributes to preserve existing userPassword values
 $current_attrs = @ ldap_read($ldap_connection, $user_dn, "(objectClass=*)", ["userPassword"]);
 if ($current_attrs) {
   $current_entries = @ ldap_get_entries($ldap_connection, $current_attrs);
   if ($current_entries['count'] > 0 && isset($current_entries[0]['userpassword'])) {
     # Remove old passcode values (keep regular passwords)
     $new_userpassword = array();
     foreach ($current_entries[0]['userpassword'] as $index => $pwd) {
       if ($index === "count") continue;
       # Keep only non-passcode hashes (regular passwords)
       if (strpos($pwd, '{') === 0 && !preg_match('/^\{ARGON2\}|\{SSHA\}|\{CRYPT\}|\{SMD5\}|\{MD5\}|\{SHA\}/', $pwd)) {
         $new_userpassword[] = $pwd;
       }
     }
     # Add new passcode
     $new_userpassword[] = ldap_hashed_passcode($new_passcode);
     
     $entries["userPassword"] = $new_userpassword;
   } else {
     # No existing userPassword, just add new passcode
     $entries["userPassword"] = ldap_hashed_passcode($new_passcode);
   }
 } else {
   # No existing attributes, just add new passcode
   $entries["userPassword"] = ldap_hashed_passcode($new_passcode);
 }

 $update = @ ldap_mod_replace($ldap_connection, $user_dn, $entries);

 if ($update) {
  error_log("$log_prefix Updated the passcode for $username",0);
  return TRUE;
 }
 else {
  error_log("$log_prefix Couldn't update the passcode for {$username}: " . ldap_error($ldap_connection),0);
  return FALSE;
 }

}


##################################

function ldap_get_user_info($ldap_connection, $username, $fields = NULL) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (!isset($fields)) { 
   $fields = array_unique( array("{$LDAP['account_attribute']}", "givenname", "sn", "cn", "mail", "description", "organization", "userPassword")); 
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
 
 if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix User {$username} not found",0); }
 return FALSE;

}


##################################

function ldap_update_user_attributes($ldap_connection, $username, $attributes) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (empty($attributes) || !is_array($attributes)) {
  error_log("$log_prefix Update user attributes; no attributes provided or invalid format",0);
  return FALSE;
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
  error_log("$log_prefix Update user attributes; User {$username} not found",0);
  return FALSE;
 }
 
 # Handle password hashing if password is being updated
 if (isset($attributes['userPassword'])) {
  $attributes['userPassword'] = ldap_hashed_password($attributes['userPassword']);
 }
 
 # Handle passcode hashing if passcode is being updated
 if (isset($attributes['passcode'])) {
  # Add passcode to userPassword (multiple values supported)
  if (!isset($attributes['userPassword'])) {
    $attributes['userPassword'] = array();
  }
  $attributes['userPassword'][] = ldap_hashed_passcode($attributes['passcode']);
  unset($attributes['passcode']); // Remove the passcode key
 }

 $update = @ ldap_mod_replace($ldap_connection, $user_dn, $attributes);

 if ($update) {
  error_log("$log_prefix Updated attributes for user {$username}",0);
  return TRUE;
 }
 else {
  error_log("$log_prefix Couldn't update attributes for user {$username}: " . ldap_error($ldap_connection),0);
  return FALSE;
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
function ldap_get_entry_by_uuid($ldap_connection, $uuid, $base_dn, $attributes = ['*', '+']) {
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
function ldap_get_organization_by_uuid($ldap_connection, $uuid) {
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
function ldap_get_user_by_uuid($ldap_connection, $uuid, $org_dn = null) {
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
function is_valid_uuid($uuid) {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
}

/**
 * Complete attribute map by merging additional attributes
 * @param array $base_map Base attribute map
 * @param array $additional_attrs Additional attributes to add
 * @return array Complete attribute map
 */
function ldap_complete_attribute_map($base_map, $additional_attrs) {
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
function uuid_to_url_param($uuid) {
    return urlencode($uuid);
}

/**
 * Decode UUID from URL parameter
 * @param string $url_param URL parameter
 * @return string|false Decoded UUID or false if invalid
 */
function url_param_to_uuid($url_param) {
    $uuid = urldecode($url_param);
    return is_valid_uuid($uuid) ? $uuid : false;
}

##################################

function get_user_dn_from_identifier($ldap_connection, $identifier) {
  global $log_prefix, $LDAP, $LDAP_DEBUG;
  
  // Check if identifier is a UUID
  if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
    // Search by UUID
    $ldap_search = @ldap_search($ldap_connection, $LDAP['base_dn'], 
      "({$LDAP['uuid_attribute']}=$identifier)", ['dn']);
    if ($ldap_search) {
      $result = ldap_get_entries($ldap_connection, $ldap_search);
      if ($result['count'] > 0) {
        return $result[0]['dn'];
      }
    }
  }
  
  // If not a UUID or UUID search failed, treat as username/email
  $ldap_search = @ldap_search($ldap_connection, $LDAP['org_dn'], 
    "({$LDAP['account_attribute']}=$identifier)", ['dn']);
  if ($ldap_search) {
    $result = ldap_get_entries($ldap_connection, $ldap_search);
    if ($result['count'] > 0) {
      return $result[0]['dn'];
    }
  }
  
  // Try system users
  $ldap_search = @ldap_search($ldap_connection, $LDAP['people_dn'], 
    "({$LDAP['account_attribute']}=$identifier)", ['dn']);
  if ($ldap_search) {
    $result = ldap_get_entries($ldap_connection, $ldap_search);
    if ($result['count'] > 0) {
      return $result[0]['dn'];
    }
  }
  
  return FALSE;
}

##################################

function ldap_detect_rfc2307bis($ldap_connection) {

  global $log_prefix, $LDAP, $LDAP_DEBUG;

  if (isset($LDAP['rfc2307bis_available'])) {
    return $LDAP['rfc2307bis_available'];
  }
  else {

    $LDAP['rfc2307bis_available'] = FALSE;

    if ($LDAP['forced_rfc2307bis'] === TRUE) {
      if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - skipping autodetection because FORCE_RFC2307BIS is TRUE",0); }
      $LDAP['rfc2307bis_available'] = TRUE;
    }
    else {

      $schema_base_query = @ ldap_read($ldap_connection,"","subschemaSubentry=*",array('subschemaSubentry'));

      if (!$schema_base_query) {
        error_log("$log_prefix LDAP RFC2307BIS detection - unable to query LDAP for objectClasses under {$schema_base_dn}:" . ldap_error($ldap_connection),0);
        error_log("$log_prefix LDAP RFC2307BIS detection - we'll assume that the RFC2307BIS schema isn't available.  Set FORCE_RFC2307BIS to TRUE if you DO use RFC2307BIS.",0);
      }
      else {
        $schema_base_results = @ ldap_get_entries($ldap_connection, $schema_base_query);

        if ($schema_base_results) {

          $schema_base_dn = $schema_base_results[0]['subschemasubentry'][0];
          if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - found that the 'subschemaSubentry' base DN is '$schema_base_dn'",0); }

          $objclass_query = @ ldap_read($ldap_connection,$schema_base_dn,"(objectClasses=*)",array('objectClasses'));
          if (!$objclass_query) {
            error_log("$log_prefix LDAP RFC2307BIS detection - unable to query LDAP for objectClasses under {$schema_base_dn}:" . ldap_error($ldap_connection),0);
          }
          else {
            $objclass_results = @ ldap_get_entries($ldap_connection, $objclass_query);
            $this_count = $objclass_results[0]['objectclasses']['count'];
            if ($this_count > 0) {
              if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - found $this_count objectClasses under $schema_base_dn" ,0); }
              $posixgroup_search = preg_grep("/NAME 'posixGroup'.*AUXILIARY/",$objclass_results[0]['objectclasses']);
              if (count($posixgroup_search) > 0) {
                if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - found AUXILIARY in posixGroup definition which suggests we're using the RFC2307BIS schema" ,0); }
                $LDAP['rfc2307bis_available'] = TRUE;
              }
              else {
                if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - couldn't find AUXILIARY in the posixGroup definition which suggests we're not using the RFC2307BIS schema.  Set FORCE_RFC2307BIS to TRUE if you DO use RFC2307BIS. " ,0); }
              }
            }
            else {
              if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - no objectClasses were returned when searching under $schema_base_dn" ,0); }
            }
          }
        }
        else {
         if ($LDAP_DEBUG === TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - unable to detect the subschemaSubentry base DN" ,0); }
        }
      }
    }

    if ($LDAP['rfc2307bis_available'] === TRUE) {
      if (!isset($LDAP['group_membership_attribute'])) { $LDAP['group_membership_attribute'] = 'uniquemember'; }
      if (!isset($LDAP['group_membership_uses_uid'])) { $LDAP['group_membership_uses_uid'] = FALSE; }
      if (!in_array('groupOfUniqueNames',$LDAP['group_objectclasses'])) { array_push($LDAP['group_objectclasses'], 'groupOfUniqueNames'); }
      return TRUE;
    }
    else {
      if (!isset($LDAP['group_membership_attribute'])) { $LDAP['group_membership_attribute'] = 'memberuid'; }
      if (!isset($LDAP['group_membership_uses_uid'])) { $LDAP['group_membership_uses_uid'] = TRUE; }
      return FALSE;
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
function ldap_get_user_dn($ldap_connection, $username) {
  global $LDAP;
  
  // First try to find the user in system users (ou=people)
  $search = @ldap_search($ldap_connection, $LDAP['people_dn'], 
    "(uid=$username)", ['dn']);
  if ($search) {
    $result = ldap_get_entries($ldap_connection, $search);
    if ($result['count'] > 0) {
      return $result[0]['dn'];
    }
  }
  
  // If not found in system users, try organization users
  $search = @ldap_search($ldap_connection, $LDAP['org_dn'], 
    "(uid=$username)", ['dn']);
  if ($search) {
    $result = ldap_get_entries($ldap_connection, $search);
    if ($result['count'] > 0) {
      return $result[0]['dn'];
    }
  }
  
  return FALSE;
}

/**
 * Add a user to a role group
 * @param resource $ldap_connection LDAP connection
 * @param string $role_name Role name (e.g., 'administrators', 'maintainers')
 * @param string $username Username to add
 * @return bool Success status
 */
function ldap_add_member_to_group($ldap_connection, $role_name, $username) {
  global $LDAP;
  
  // Get the user's DN
  $user_dn = ldap_get_user_dn($ldap_connection, $username);
  if (!$user_dn) {
    error_log("Failed to get DN for user: $username");
    return FALSE;
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
        return TRUE; // Group created and user added successfully
      } else {
        error_log("Failed to create group: $group_dn - " . ldap_error($ldap_connection));
        return FALSE;
      }
    } else {
      error_log("Group does not exist: $group_dn");
      return FALSE;
    }
  }
  
  // Add the user to the group
  $modify = @ldap_mod_add($ldap_connection, $group_dn, array('member' => $user_dn));
  if (!$modify) {
    error_log("Failed to add user $username to group $role_name: " . ldap_error($ldap_connection));
    return FALSE;
  }
  
  return TRUE;
}

/**
 * Remove a user from a role group
 * @param resource $ldap_connection LDAP connection
 * @param string $role_name Role name (e.g., 'administrators', 'maintainers')
 * @param string $username Username to remove
 * @return bool Success status
 */
function ldap_delete_member_from_group($ldap_connection, $role_name, $username) {
  global $LDAP;
  
  // Get the user's DN
  $user_dn = ldap_get_user_dn($ldap_connection, $username);
  if (!$user_dn) {
    error_log("Failed to get DN for user: $username");
    return FALSE;
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
    return FALSE;
  }
  
  // Get current group information to check member count
  $group_info = ldap_get_entries($ldap_connection, $group_exists);
  if (!$group_info || $group_info['count'] == 0) {
    error_log("Failed to get group information for: $group_dn");
    return FALSE;
  }
  
  $current_group = $group_info[0];
  $current_members = isset($current_group['member']) ? $current_group['member'] : array();
  $member_count = isset($current_members['count']) ? $current_members['count'] : 0;
  
  // Check if this user is actually a member
  $user_is_member = FALSE;
  if ($member_count > 0) {
    for ($i = 0; $i < $member_count; $i++) {
      if ($current_members[$i] === $user_dn) {
        $user_is_member = TRUE;
        break;
      }
    }
  }
  
  if (!$user_is_member) {
    error_log("User $username is not a member of group $role_name");
    return TRUE; // Consider this a success since the user is already not in the group
  }
  
  // If this is the last member, delete the entire group
  if ($member_count == 1) {
    // Safety check: Don't allow removing the last administrator
    if ($role_name === $LDAP['admin_role']) {
      error_log("Cannot remove last administrator from administrators group - this would lock out the system");
      return FALSE;
    }
    
    error_log("Removing last member from group $role_name - deleting entire group");
    $delete_group = @ldap_delete($ldap_connection, $group_dn);
    if ($delete_group) {
      error_log("Successfully deleted group $role_name (was last member)");
      return TRUE;
    } else {
      error_log("Failed to delete group $role_name: " . ldap_error($ldap_connection));
      return FALSE;
    }
  }
  
  // Remove the user from the group (there are other members)
  $modify = @ldap_mod_del($ldap_connection, $group_dn, array('member' => $user_dn));
  if (!$modify) {
    error_log("Failed to remove user $username from group $role_name: " . ldap_error($ldap_connection));
    return FALSE;
  }
  
  error_log("Successfully removed user $username from group $role_name");
  return TRUE;
}

/**
 * Find all groups that a user is a member of
 * @param resource $ldap_connection LDAP connection
 * @param string $user_dn User DN to search for
 * @return array Array of group DNs that the user is a member of
 */
function ldap_get_user_groups($ldap_connection, $user_dn) {
  global $LDAP;
  $user_groups = array();
  
  // Search for groups in global roles OU
  $global_roles_search = @ldap_search($ldap_connection, $LDAP['roles_dn'], 
    "(&(objectClass=groupOfNames)(member=" . ldap_escape($user_dn, '', LDAP_ESCAPE_FILTER) . "))", 
    ['dn', 'cn']);
  
  if ($global_roles_search) {
    $global_roles = ldap_get_entries($ldap_connection, $global_roles_search);
    for ($i = 0; $i < $global_roles['count']; $i++) {
      $user_groups[] = $global_roles[$i]['dn'];
    }
  }
  
  // Search for groups in organization roles OUs
  $org_search = @ldap_search($ldap_connection, $LDAP['org_dn'], 
    "(&(objectClass=organizationalUnit)(ou=roles))", ['dn']);
  
  if ($org_search) {
    $org_roles_ous = ldap_get_entries($ldap_connection, $org_search);
    for ($i = 0; $i < $org_roles_ous['count']; $i++) {
      $org_roles_dn = $org_roles_ous[$i]['dn'];
      
      // Search for groups in this organization's roles OU
      $org_groups_search = @ldap_search($ldap_connection, $org_roles_dn, 
        "(&(objectClass=groupOfNames)(member=" . ldap_escape($user_dn, '', LDAP_ESCAPE_FILTER) . "))", 
        ['dn', 'cn']);
      
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
function ldap_remove_user_from_all_groups($ldap_connection, $user_dn) {
  global $LDAP;
  
  // Get all groups the user is a member of
  $user_groups = ldap_get_user_groups($ldap_connection, $user_dn);
  
  if (empty($user_groups)) {
    // User is not a member of any groups
    return TRUE;
  }
  
  $success = TRUE;
  
  foreach ($user_groups as $group_dn) {
    // Get current group information to check member count
    $group_info = @ldap_read($ldap_connection, $group_dn, '(objectClass=*)', ['dn', 'member']);
    if (!$group_info) {
      error_log("Failed to read group information for: $group_dn");
      $success = FALSE;
      continue;
    }
    
    $group_data = ldap_get_entries($ldap_connection, $group_info);
    if (!$group_data || $group_data['count'] == 0) {
      error_log("Failed to get group data for: $group_dn");
      $success = FALSE;
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
        $success = FALSE;
        continue;
      }
      
      error_log("Removing last member from group $group_dn - deleting entire group");
      $delete_group = @ldap_delete($ldap_connection, $group_dn);
      if ($delete_group) {
        error_log("Successfully deleted group $group_dn (was last member)");
      } else {
        error_log("Failed to delete group $group_dn: " . ldap_error($ldap_connection));
        $success = FALSE;
      }
    } else {
      // Remove the user from the group (there are other members)
      $modify = @ldap_mod_del($ldap_connection, $group_dn, array('member' => $user_dn));
      if (!$modify) {
        error_log("Failed to remove user from group $group_dn: " . ldap_error($ldap_connection));
        $success = FALSE;
      } else {
        error_log("Successfully removed user from group $group_dn");
      }
    }
  }
  
  return $success;
}

##################################

/**
 * Check if a user account is locked/disabled
 * 
 * @param resource $ldap_connection LDAP connection resource
 * @param string $user_dn User DN to check
 * @return bool True if user is locked/disabled, false otherwise
 */
function ldap_user_is_locked($ldap_connection, $user_dn) {
    global $log_prefix, $LDAP_DEBUG;
    
    if (!$user_dn) {
        return false;
    }
    
    // Check user's pwdAccountLockedTime attribute
    $user_attrs = @ldap_read($ldap_connection, $user_dn, "(objectClass=*)", ['pwdAccountLockedTime', 'description']);
    if ($user_attrs) {
        $user_entry = ldap_get_entries($ldap_connection, $user_attrs);
        if ($user_entry['count'] > 0) {
            // Check for pwdAccountLockedTime lock
            if (isset($user_entry[0]['pwdaccountlockedtime'])) {
                if ($LDAP_DEBUG) {
                    error_log("$log_prefix User $user_dn has pwdAccountLockedTime: " . $user_entry[0]['pwdaccountlockedtime'][0]);
                }
                return true;
            }
            
            // Check for description-based lock
            if (isset($user_entry[0]['description'])) {
                foreach ($user_entry[0]['description'] as $desc) {
                    if ($desc === 'ACCOUNT_LOCKED') {
                        if ($LDAP_DEBUG) {
                            error_log("$log_prefix User $user_dn has description lock: $desc");
                        }
                        return true;
                    }
                }
            }
        }
    }
    
    // Check if user's organization is locked
    $org_name = ldap_user_get_organization($ldap_connection, $user_dn);
    if ($org_name && ldap_organization_is_locked($ldap_connection, $org_name)) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix User $user_dn is locked due to locked organization: $org_name");
        }
        return true;
    }
    
    return false;
}

##################################

/**
 * Check if an organization is locked/disabled
 * 
 * @param resource $ldap_connection LDAP connection resource
 * @param string $org_name Organization name to check
 * @return bool True if organization is locked/disabled, false otherwise
 */
function ldap_organization_is_locked($ldap_connection, $org_name) {
    global $log_prefix, $LDAP, $LDAP_DEBUG;
    
    if (!$org_name) {
        return false;
    }
    
    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $org_attrs = @ldap_read($ldap_connection, $org_dn, "(objectClass=*)", ['pwdAccountLockedTime']);
    
    if ($org_attrs) {
        $org_entry = ldap_get_entries($ldap_connection, $org_attrs);
        if ($org_entry['count'] > 0 && isset($org_entry[0]['pwdaccountlockedtime'])) {
            if ($LDAP_DEBUG) {
                error_log("$log_prefix Organization $org_name has pwdAccountLockedTime: " . $org_entry[0]['pwdaccountlockedtime'][0]);
            }
            return true;
        }
    }
    
    return false;
}

##################################

/**
 * Lock a user account using standard pwdAccountLockedTime
 * 
 * @param resource $ldap_connection LDAP connection resource
 * @param string $user_dn User DN to lock
 * @return bool True if successful, false otherwise
 */
function ldap_lock_user_account($ldap_connection, $user_dn) {
    global $log_prefix, $LDAP_DEBUG;
    
    if (!$user_dn) {
        error_log("$log_prefix Cannot lock user: No DN provided");
        return false;
    }
    
    // Try standard pwdAccountLockedTime first
    $lock_value = '000001010000Z';
    $lock_attrs = ['pwdAccountLockedTime' => $lock_value];
    
    $result = @ldap_modify($ldap_connection, $user_dn, $lock_attrs);
    if ($result) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix Successfully locked user account using pwdAccountLockedTime: $user_dn");
        }
        return true;
    }
    
    // If pwdAccountLockedTime fails, try alternative method using description
    $ldap_error = ldap_error($ldap_connection);
    if ($LDAP_DEBUG) {
        error_log("$log_prefix pwdAccountLockedTime failed for $user_dn: $ldap_error");
        error_log("$log_prefix Trying alternative locking method using description attribute");
    }
    
    // Alternative: Use description attribute to mark account as locked
    $alt_lock_attrs = ['description' => 'ACCOUNT_LOCKED'];
    $alt_result = @ldap_modify($ldap_connection, $user_dn, $alt_lock_attrs);
    
    if ($alt_result) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix Successfully locked user account using description: $user_dn");
        }
        return true;
    } else {
        $alt_ldap_error = ldap_error($ldap_connection);
        error_log("$log_prefix Failed to lock user account $user_dn using both methods. pwdAccountLockedTime error: $ldap_error, description error: $alt_ldap_error");
        return false;
    }
}

##################################

/**
 * Unlock a user account by removing pwdAccountLockedTime
 * 
 * @param resource $ldap_connection LDAP connection resource
 * @param string $user_dn User DN to unlock
 * @return bool True if successful, false otherwise
 */
function ldap_unlock_user_account($ldap_connection, $user_dn) {
    global $log_prefix, $LDAP_DEBUG;
    
    if (!$user_dn) {
        error_log("$log_prefix Cannot unlock user: No DN provided");
        return false;
    }
    
    $success = false;
    
    // Try to remove pwdAccountLockedTime first
    $unlock_attrs = ['pwdAccountLockedTime' => []];
    $result = @ldap_modify($ldap_connection, $user_dn, $unlock_attrs);
    
    if ($result) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix Successfully unlocked user account by removing pwdAccountLockedTime: $user_dn");
        }
        $success = true;
    } else {
        if ($LDAP_DEBUG) {
            $ldap_error = ldap_error($ldap_connection);
            error_log("$log_prefix pwdAccountLockedTime removal failed for $user_dn: $ldap_error");
        }
    }
    
    // Also try to remove description-based lock
    $desc_unlock_attrs = ['description' => []];
    $desc_result = @ldap_modify($ldap_connection, $user_dn, $desc_unlock_attrs);
    
    if ($desc_result) {
        if ($LDAP_DEBUG) {
            error_log("$log_prefix Successfully unlocked user account by removing description lock: $user_dn");
        }
        $success = true;
    } else {
        if ($LDAP_DEBUG) {
            $desc_ldap_error = ldap_error($ldap_connection);
            error_log("$log_prefix Description lock removal failed for $user_dn: $desc_ldap_error");
        }
    }
    
    return $success;
}

##################################

/**
 * Lock an organization and all its users
 * 
 * @param resource $ldap_connection LDAP connection resource
 * @param string $org_name Organization name to lock
 * @return bool True if successful, false otherwise
 */
function ldap_lock_organization($ldap_connection, $org_name) {
    global $log_prefix, $LDAP, $LDAP_DEBUG;
    
    if (!$org_name) {
        error_log("$log_prefix Cannot lock organization: No name provided");
        return false;
    }
    
    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    
    // Standard RFC-compliant lock value: January 1, 1970 00:00:00 UTC
    $lock_value = '000001010000Z';
    
    // Lock the organization itself
    $org_lock_attrs = ['pwdAccountLockedTime' => $lock_value];
    $org_result = @ldap_modify($ldap_connection, $org_dn, $org_lock_attrs);
    
    if (!$org_result) {
        error_log("$log_prefix Failed to lock organization $org_name: " . ldap_error($ldap_connection));
        return false;
    }
    
    // Lock all users in the organization
    $users_dn = "ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $user_search = @ldap_search($ldap_connection, $users_dn, "(objectClass=inetOrgPerson)", ['dn']);
    
    if ($user_search) {
        $users = ldap_get_entries($ldap_connection, $user_search);
        $locked_count = 0;
        
        for ($i = 0; $i < $users['count']; $i++) {
            $user_dn = $users[$i]['dn'];
            if (ldap_lock_user_account($ldap_connection, $user_dn)) {
                $locked_count++;
            }
        }
        
        if ($LDAP_DEBUG) {
            error_log("$log_prefix Locked organization $org_name and $locked_count users");
        }
    }
    
    return true;
}

##################################

/**
 * Unlock an organization and all its users
 * 
 * @param resource $ldap_connection LDAP connection resource
 * @param string $org_name Organization name to unlock
 * @return bool True if successful, false otherwise
 */
function ldap_unlock_organization($ldap_connection, $org_name) {
    global $log_prefix, $LDAP, $LDAP_DEBUG;
    
    if (!$org_name) {
        error_log("$log_prefix Cannot unlock organization: No name provided");
        return false;
    }
    
    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    
    // Unlock the organization itself
    $org_unlock_attrs = ['pwdAccountLockedTime' => []];
    $org_result = @ldap_modify($ldap_connection, $org_dn, $org_unlock_attrs);
    
    if (!$org_result) {
        error_log("$log_prefix Failed to unlock organization $org_name: " . ldap_error($ldap_connection));
        return false;
    }
    
    // Unlock all users in the organization
    $users_dn = "ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $user_search = @ldap_search($ldap_connection, $users_dn, "(objectClass=inetOrgPerson)", ['dn']);
    
    if ($user_search) {
        $users = ldap_get_entries($ldap_connection, $user_search);
        $unlocked_count = 0;
        
        for ($i = 0; $i < $users['count']; $i++) {
            $user_dn = $users[$i]['dn'];
            if (ldap_unlock_user_account($ldap_connection, $user_dn)) {
                $unlocked_count++;
            }
        }
        
        if ($LDAP_DEBUG) {
            error_log("$log_prefix Unlocked organization $org_name and $unlocked_count users");
        }
    }
    
    return true;
}

##################################

/**
 * Get lock status information for a user
 * 
 * @param resource $ldap_connection LDAP connection resource
 * @param string $user_dn User DN to check
 * @return array|false Lock status information or false if error
 */
function ldap_get_user_lock_status($ldap_connection, $user_dn) {
    global $log_prefix, $LDAP_DEBUG;
    
    if (!$user_dn) {
        return false;
    }
    
    $user_attrs = @ldap_read($ldap_connection, $user_dn, "(objectClass=*)", ['pwdAccountLockedTime', 'uid', 'cn']);
    if (!$user_attrs) {
        return false;
    }
    
    $user_entry = ldap_get_entries($ldap_connection, $user_attrs);
    if ($user_entry['count'] == 0) {
        return false;
    }
    
    $user_info = $user_entry[0];
    $is_locked = isset($user_info['pwdaccountlockedtime']);
    
    $status = [
        'dn' => $user_dn,
        'uid' => $user_info['uid'][0] ?? 'Unknown',
        'cn' => $user_info['cn'][0] ?? 'Unknown',
        'is_locked' => $is_locked,
        'lock_time' => $is_locked ? $user_info['pwdaccountlockedtime'][0] : null,
        'lock_reason' => $is_locked ? 'Account locked by administrator' : null
    ];
    
    // Check if user is locked due to organization lock
    $org_name = ldap_user_get_organization($ldap_connection, $user_dn);
    if ($org_name && ldap_organization_is_locked($ldap_connection, $org_name)) {
        $status['is_locked'] = true;
        $status['lock_reason'] = 'Organization locked by administrator';
        $status['org_locked'] = $org_name;
    }
    
    return $status;
}

##################################

/**
 * Get lock status information for an organization
 * 
 * @param resource $ldap_connection LDAP connection resource
 * @param string $org_name Organization name to check
 * @return array|false Lock status information or false if error
 */
function ldap_get_organization_lock_status($ldap_connection, $org_name) {
    global $log_prefix, $LDAP, $LDAP_DEBUG;
    
    if (!$org_name) {
        return false;
    }
    
    $org_dn = "o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $org_attrs = @ldap_read($ldap_connection, $org_dn, "(objectClass=*)", ['pwdAccountLockedTime', 'o', 'description']);
    
    if (!$org_attrs) {
        return false;
    }
    
    $org_entry = ldap_get_entries($ldap_connection, $org_attrs);
    if ($org_entry['count'] == 0) {
        return false;
    }
    
    $org_info = $org_entry[0];
    $is_locked = isset($org_info['pwdaccountlockedtime']);
    
    // Count locked users in the organization
    $users_dn = "ou=people,o=" . ldap_escape($org_name, '', LDAP_ESCAPE_DN) . "," . $LDAP['org_dn'];
    $user_search = @ldap_search($ldap_connection, $users_dn, "(objectClass=inetOrgPerson)", ['dn']);
    
    $total_users = 0;
    $locked_users = 0;
    
    if ($user_search) {
        $users = ldap_get_entries($ldap_connection, $user_search);
        $total_users = $users['count'];
        
        for ($i = 0; $i < $users['count']; $i++) {
            if (ldap_user_is_locked($ldap_connection, $users[$i]['dn'])) {
                $locked_users++;
            }
        }
    }
    
    $status = [
        'dn' => $org_dn,
        'name' => $org_name,
        'description' => $org_info['description'][0] ?? '',
        'is_locked' => $is_locked,
        'lock_time' => $is_locked ? $org_info['pwdaccountlockedtime'][0] : null,
        'lock_reason' => $is_locked ? 'Organization locked by administrator' : null,
        'total_users' => $total_users,
        'locked_users' => $locked_users
    ];
    
    return $status;
}

##################################



