<?php

###################################

function open_ldap_connection($ldap_bind=TRUE) {

 global $log_prefix, $LDAP, $SENT_HEADERS, $LDAP_DEBUG, $LDAP_VERBOSE_CONNECTION_LOGS;

 // Enforce TLS in production environments
 if (getenv('ENVIRONMENT') !== 'development' && getenv('ENVIRONMENT') !== 'test') {
     if ($LDAP['ignore_cert_errors'] == TRUE) { 
         error_log("$log_prefix WARNING: Certificate errors are being ignored in production environment", 0);
     }
 }

 if ($LDAP['ignore_cert_errors'] == TRUE) { putenv('LDAPTLS_REQCERT=never'); }
 $ldap_connection = @ ldap_connect($LDAP['uri']);

 if (!$ldap_connection) {
  print "Problem: Can't connect to the LDAP server at {$LDAP['uri']}";
  die("Can't connect to the LDAP server at {$LDAP['uri']}");
  exit(1);
 }

 ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
 if ($LDAP_VERBOSE_CONNECTION_LOGS == TRUE) { ldap_set_option(NULL, LDAP_OPT_DEBUG_LEVEL, 7); }

 // Enforce TLS for non-localhost connections in production
 $is_localhost = preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri']) || 
                 preg_match('/^ldap:\/\/localhost(:[0-9]+)?$/', $LDAP['uri']);
 
 if (!preg_match("/^ldaps:/", $LDAP['uri'])) {

  $tls_result = @ ldap_start_tls($ldap_connection);

  if ($tls_result != TRUE) {

   if (!preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri'])) { 
     error_log("$log_prefix Failed to start STARTTLS connection to {$LDAP['uri']}: " . ldap_error($ldap_connection),0); 
   }

   if ($LDAP["require_starttls"] == TRUE || (!$is_localhost && getenv('ENVIRONMENT') !== 'development')) {
    print "<div style='position: fixed;bottom: 0;width: 100%;' class='alert alert-danger'>Fatal:  Couldn't create a secure connection to {$LDAP['uri']} and LDAP_REQUIRE_STARTTLS is TRUE.</div>";
    exit(0);
   }
   else {
    if ($SENT_HEADERS == TRUE and !preg_match('/^ldap:\/\/localhost(:[0-9]+)?$/', $LDAP['uri']) and !preg_match('/^ldap:\/\/127\.0\.0\.([0-9]+)(:[0-9]+)$/', $LDAP['uri'])) {
      print "<div style='position: fixed;bottom: 0px;width: 100%;height: 20px;border-bottom:solid 20px yellow;'>WARNING: Insecure LDAP connection to {$LDAP['uri']}</div>";
    }
    ldap_close($ldap_connection);
    $ldap_connection = @ ldap_connect($LDAP['uri']);
    ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, 3);
   }
  }
  else {
   if ($LDAP_DEBUG == TRUE) {
     error_log("$log_prefix Start STARTTLS connection to {$LDAP['uri']}",0);
   }
   $LDAP['connection_type'] = "StartTLS";
  }

 }
 else {
  if ($LDAP_DEBUG == TRUE) {
    error_log("$log_prefix Using an LDAPS encrypted connection to {$LDAP['uri']}",0);
   }
   $LDAP['connection_type'] = 'LDAPS';
 }

 if ($ldap_bind == TRUE) {

   if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix Attempting to bind to {$LDAP['uri']} as {$LDAP['admin_bind_dn']}",0); }
   $bind_result = @ ldap_bind( $ldap_connection, $LDAP['admin_bind_dn'], $LDAP['admin_bind_pwd']);

   if ($bind_result != TRUE) {

     $this_error = "Failed to bind to {$LDAP['uri']} as {$LDAP['admin_bind_dn']}";
     if ($LDAP_DEBUG == TRUE) { $this_error .= " with password {$LDAP['admin_bind_pwd']}"; }
     $this_error .= ": " . ldap_error($ldap_connection);
     print "Problem: Failed to bind as {$LDAP['admin_bind_dn']}";
     error_log("$log_prefix $this_error",0);

     exit(1);

   }
   elseif ($LDAP_DEBUG == TRUE) {
     error_log("$log_prefix Bound successfully as {$LDAP['admin_bind_dn']}",0);
   }

 }

 return $ldap_connection;

}


###################################

function ldap_auth_username($ldap_connection, $username, $password) {

 # Search for the DN for the given username across all organizations.  If found, try binding with the DN and user's password.
 # If the binding succeeds, return the DN.

 global $log_prefix, $LDAP, $SITE_LOGIN_LDAP_ATTRIBUTE, $LDAP_DEBUG;

 $ldap_search_query="{$SITE_LOGIN_LDAP_ATTRIBUTE}=" . ldap_escape(($username === null ? '' : $username), "", LDAP_ESCAPE_FILTER);
 if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix Running LDAP search for: $ldap_search_query"); }

 # Search across all organizations for the user
 $ldap_search = @ ldap_search( $ldap_connection, $LDAP['org_dn'], $ldap_search_query );

 if (!$ldap_search) {
  error_log("$log_prefix Couldn't search for $ldap_search_query: " . ldap_error($ldap_connection),0);
  return FALSE;
 }

 $result = @ ldap_get_entries($ldap_connection, $ldap_search);
 if (!$result) {
  error_log("$log_prefix Couldn't get LDAP entries for {$username}: " . ldap_error($ldap_connection),0);
  return FALSE;
 }
 if ($LDAP_DEBUG == TRUE) {
   error_log("$log_prefix LDAP search returned " . $result["count"] . " records for $ldap_search_query",0);
   for ($i=1; $i==$result["count"]; $i++) {
     error_log("$log_prefix ". "Entry {$i}: " . $result[$i-1]['dn'], 0);
   }
 }

 if ($result["count"] == 1) {

  $this_dn = $result[0]['dn'];
  if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix Attempting authenticate as $username by binding with {$this_dn} ",0); }
  $auth_ldap_connection = open_ldap_connection(FALSE);
  $can_bind =  @ ldap_bind($auth_ldap_connection, $result[0]['dn'], $password);

  if ($can_bind) {
   preg_match("/{$LDAP['account_attribute']}=(.*?),/",$result[0]['dn'],$dn_match);
   $account_id=$dn_match[1];
   if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix Able to bind as {$username}: dn is {$result[0]['dn']} and account ID is {$account_id}",0); }
   ldap_close($auth_ldap_connection);
   return $account_id;
  }
  else {
   if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix Unable to bind as {$username}: " . ldap_error($auth_ldap_connection),0); }
   ldap_close($auth_ldap_connection);
   return FALSE;
  }

 }
 elseif ($result["count"] > 1) {
   if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix There was more than one entry for {$ldap_search_query} so it wasn't possible to determine which user to log in as."); }
 }

}


###################################

function ldap_setup_auth($ldap_connection, $password) {

 #For the initial setup we need to make sure that whoever's running it has the default admin user
 #credentials as passed in ADMIN_BIND_*
 global $log_prefix, $LDAP, $LDAP_DEBUG;

  if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix Initial setup: opening another LDAP connection to test authentication as {$LDAP['admin_bind_dn']}.",0); }
  $auth_ldap_connection = open_ldap_connection();
  $can_bind = @ldap_bind($auth_ldap_connection, $LDAP['admin_bind_dn'], $password);
  ldap_close($auth_ldap_connection);
  if ($can_bind) {
    if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix Initial setup: able to authenticate as {$LDAP['admin_bind_dn']}.",0); }
    return TRUE;
  }
  else {
    $this_error="Initial setup: Unable to authenticate as {$LDAP['admin_bind_dn']}";
    if ($LDAP_DEBUG == TRUE) { $this_error .= " with password $password"; }
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

function ldap_get_user_list($ldap_connection,$start=0,$entries=NULL,$sort="asc",$sort_key=NULL,$filters=NULL,$fields=NULL) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (!isset($fields)) { $fields = array_unique( array("{$LDAP['account_attribute']}", "givenname", "sn", "cn", "mail", "description", "organization")); }

 if (!isset($sort_key)) { $sort_key = $LDAP['account_attribute']; }

 $this_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=*)$filters)";

 # Search across all organizations and system users for users
 $users = array();
 
 # Search in organizations
 $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $this_filter, $fields);
 if ($ldap_search) {
   $result = @ ldap_get_entries($ldap_connection, $ldap_search);
   if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP returned {$result['count']} users in organizations when using this filter: $this_filter",0); }
   
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
   if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP returned {$result['count']} system users when using this filter: $this_filter",0); }
   
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

 global $log_prefix, $LDAP, $LDAP_DEBUG, $min_uid, $min_gid;

 if ($type == "uid") {
  $this_id = $min_uid;
  $record_base_dn = $LDAP['user_dn'];
  $record_filter = "({$LDAP['account_attribute']}=*)";
  $record_attribute = "uidnumber";
 }
 else {
  $type = "gid";
  $this_id = $min_gid;
  $record_base_dn = $LDAP['group_dn'];
  $record_filter = "(objectClass=posixGroup)";
  $record_attribute = "gidnumber";
 }

 $fetched_id = fetch_id_stored_in_ldap($ldap_connection,$type);

 if ($fetched_id != FALSE) {

  return($fetched_id);

 }
 else {

  error_log("$log_prefix cn=lastGID doesn't exist so the highest $type is determined by searching through all the LDAP records.",0);

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


function ldap_get_group_list($ldap_connection,$start=0,$entries=NULL,$sort="asc",$filters=NULL) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 $this_filter = "(&(objectclass=*)$filters)";
 $ldap_search = @ ldap_search($ldap_connection, "{$LDAP['group_dn']}", $this_filter);

 $result = @ ldap_get_entries($ldap_connection, $ldap_search);
 if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP returned {$result['count']} groups for {$LDAP['group_dn']} when using this filter: $this_filter",0); }

 $records = array();
 foreach ($result as $record) {

  if (isset($record[$LDAP['group_attribute']][0])) {

   array_push($records, $record[$LDAP['group_attribute']][0]);

  }
 }

 if ($sort == "asc") { sort($records); } else { rsort($records); }

 return(array_slice($records,$start,$entries));


}


##################################


function ldap_get_group_entry($ldap_connection,$group_name) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (isset($group_name)) {

  $ldap_search_query = "({$LDAP['group_attribute']}=" . ldap_escape(($group_name === null ? '' : $group_name), "", LDAP_ESCAPE_FILTER) . ")";
  $ldap_search = @ ldap_search($ldap_connection, "{$LDAP['group_dn']}", $ldap_search_query);
  $result = @ ldap_get_entries($ldap_connection, $ldap_search);

  if ($result['count'] > 0) {
    return $result;
  }
  else {
    return FALSE;
  }

 }

 return FALSE;

}

##################################

function ldap_get_group_members($ldap_connection,$group_name,$start=0,$entries=NULL,$sort="asc") {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 $rfc2307bis_available = ldap_detect_rfc2307bis($ldap_connection);

 $ldap_search_query = "({$LDAP['group_attribute']}=". ldap_escape(($group_name === null ? '' : $group_name), "", LDAP_ESCAPE_FILTER) . ")";
 $ldap_search = @ ldap_search($ldap_connection, "{$LDAP['group_dn']}", $ldap_search_query, array($LDAP['group_membership_attribute']));

 $result = @ ldap_get_entries($ldap_connection, $ldap_search);
 if ($result) { $result_count = $result['count']; } else { $result_count = 0; }

 $records = array();

 if ($result_count > 0) {

  foreach ($result[0][$LDAP['group_membership_attribute']] as $key => $value) {

   if ($key !== 'count' and !empty($value)) {
    $this_member = preg_replace("/^.*?=(.*?),.*/", "$1", $value ?? '');
    array_push($records, $this_member);
    if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix {$value} is a member",0); }
   }

  }

  $actual_result_count = count($records);
  if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP returned $actual_result_count members of {$group_name} when using this search: $ldap_search_query and this filter: {$LDAP['group_membership_attribute']}",0); }

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

function ldap_get_role_members($ldap_connection, $role_name, $start=0, $entries=NULL, $sort="asc") {
 global $log_prefix, $LDAP, $LDAP_DEBUG;

 // Search for the role in the global roles OU
 $ldap_search_query = "(cn=" . ldap_escape($role_name, "", LDAP_ESCAPE_FILTER) . ")";
 $ldap_search = @ ldap_search($ldap_connection, "ou=roles,{$LDAP['base_dn']}", $ldap_search_query, array('member'));

 $result = @ ldap_get_entries($ldap_connection, $ldap_search);
 if ($result) { $result_count = $result['count']; } else { $result_count = 0; }

 $records = array();

 if ($result_count > 0 && isset($result[0]['member'])) {
  foreach ($result[0]['member'] as $key => $value) {
   if ($key !== 'count' and !empty($value)) {
    // Extract the DN from the member attribute
    $records[] = $value;
    if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix {$value} is a member of role {$role_name}",0); }
   }
  }

  $actual_result_count = count($records);
  if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP returned $actual_result_count members of role {$role_name}",0); }

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

function ldap_is_group_member($ldap_connection, $group_name, $username) {
    global $log_prefix, $LDAP, $LDAP_DEBUG;

    if (empty($group_name) || empty($username)) {
        return FALSE;
    }

    $rfc2307bis_available = ldap_detect_rfc2307bis($ldap_connection);

    $ldap_search_query = "({$LDAP['group_attribute']}=" . ldap_escape(($group_name === null ? '' : $group_name), "", LDAP_ESCAPE_FILTER) . ")";
    $ldap_search = @ldap_search($ldap_connection, "{$LDAP['group_dn']}", $ldap_search_query);

    if ($ldap_search) {
        $result = ldap_get_entries($ldap_connection, $ldap_search);

        if ($LDAP['group_membership_uses_uid'] == FALSE) {
            $username = "{$LDAP['account_attribute']}=$username,{$LDAP['user_dn']}";
        }

        $members = isset($result[0][$LDAP['group_membership_attribute']]) && is_array($result[0][$LDAP['group_membership_attribute']])
            ? $result[0][$LDAP['group_membership_attribute']]
            : [];

        if (preg_grep("/^{$username}$/i", $members)) {
            return TRUE;
        } else {
            return FALSE;
        }
    } else {
        return FALSE;
    }
}


##################################

function ldap_user_group_membership($ldap_connection,$username) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 # Get user DN first
 $user_dn = null;
 $user_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=$username))";
 
 # Search in organizations
 $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $user_filter, array('dn'));
 if ($ldap_search) {
   $result = @ ldap_get_entries($ldap_connection, $ldap_search);
   if ($result['count'] > 0) {
     $user_dn = $result[0]['dn'];
   }
 }
 
 # If not found in organizations, search in system users
 if (!$user_dn) {
   $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $user_filter, array('dn'));
   if ($ldap_search) {
     $result = @ ldap_get_entries($ldap_connection, $ldap_search);
     if ($result['count'] > 0) {
       $user_dn = $result[0]['dn'];
     }
   }
 }
 
 if (!$user_dn) {
   if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix User $username not found for role membership check",0); }
   return array();
 }

 # Search for roles that contain this user
 $roles = array();
 
 # Check global roles (administrator, maintainer)
 $global_roles_filter = "(&(objectclass=groupOfNames)(member=$user_dn))";
 $ldap_search = @ ldap_search($ldap_connection, $LDAP['roles_dn'], $global_roles_filter, array('cn'));
 if ($ldap_search) {
   $result = @ ldap_get_entries($ldap_connection, $ldap_search);
   foreach ($result as $record) {
     if (isset($record['cn'][0])) {
       $roles[] = $record['cn'][0];
     }
   }
 }
 
 # Check organization-specific roles
 $org_roles_filter = "(&(objectclass=groupOfNames)(member=$user_dn))";
 $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $org_roles_filter, array('cn'));
 if ($ldap_search) {
   $result = @ ldap_get_entries($ldap_connection, $ldap_search);
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

function ldap_new_group($ldap_connection,$group_name,$initial_member="",$extra_attributes=array()) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 $rfc2307bis_available = ldap_detect_rfc2307bis($ldap_connection);

 if (isset($group_name)) {

   $new_group = ldap_escape(($group_name === null ? '' : $group_name), "", LDAP_ESCAPE_FILTER);
   $initial_member = ldap_escape(($initial_member === null ? '' : $initial_member), "", LDAP_ESCAPE_FILTER);
   $update_gid_store=FALSE;

   $ldap_search_query = "({$LDAP['group_attribute']}=$new_group,{$LDAP['group_dn']})";
   $ldap_search = @ ldap_search($ldap_connection, "{$LDAP['group_dn']}", $ldap_search_query);
   $result = @ ldap_get_entries($ldap_connection, $ldap_search);

   if ($result['count'] == 0) {

     if ($LDAP['group_membership_uses_uid'] == FALSE and $initial_member != "") { $initial_member = "{$LDAP['account_attribute']}=$initial_member,{$LDAP['user_dn']}"; }

     $new_group_array=array( 'objectClass' => $LDAP['group_objectclasses'],
                             'cn' => $new_group,
                             $LDAP['group_membership_attribute'] => $initial_member
                           );

     $new_group_array = array_merge($new_group_array,$extra_attributes);

     if (!isset($new_group_array["gidnumber"][0]) or !is_numeric($new_group_array["gidnumber"][0])) {
       $highest_gid = ldap_get_highest_id($ldap_connection,'gid');
       $new_gid = $highest_gid + 1;
       $new_group_array["gidnumber"] = $new_gid;
       $update_gid_store=TRUE;
     }

     $group_dn="cn=$new_group,{$LDAP['group_dn']}";

     $add_group = @ ldap_add($ldap_connection, $group_dn, $new_group_array);

     if (! $add_group ) {
       $this_error="$log_prefix LDAP: unable to add new group ({$group_dn}): " . ldap_error($ldap_connection);
       if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix DEBUG add_group array: ". strip_tags(print_r($new_group_array,true)),0); }
       error_log($this_error,0);
     }
     else {
       error_log("$log_prefix Added new group $group_name",0);

       if ($update_gid_store == TRUE) {
         $this_gid = fetch_id_stored_in_ldap($ldap_connection,"gid");
         if ($this_gid != FALSE) {
           $update_gid = @ ldap_mod_replace($ldap_connection, "cn=lastGID,{$LDAP['base_dn']}", array( 'serialNumber' => $new_gid ));
           if ($update_gid) {
             error_log("$log_prefix Updated cn=lastGID with $new_gid",0);
           }
           else {
             error_log("$log_prefix Unable to update cn=lastGID to $new_gid - this could cause groups to share the same GID.",0);
           }
         }
       }
       return TRUE;
     }

   }
   else {
     error_log("$log_prefix Create group; group $group_name already exists.",0);
   }
 }
 else {
   error_log("$log_prefix Create group; group name wasn't set.",0);
 }

 return FALSE;

}


##################################

function ldap_update_group_attributes($ldap_connection,$group_name,$extra_attributes) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (isset($group_name) and (count($extra_attributes) > 0)) {

  $group_name = ldap_escape(($group_name === null ? '' : $group_name), "", LDAP_ESCAPE_FILTER);
  $group_dn = "{$LDAP['group_attribute']}=$group_name,{$LDAP['group_dn']}";

  $update_group = @ ldap_mod_replace($ldap_connection, $group_dn, $extra_attributes);

  if (!$update_group ) {
    $this_error="$log_prefix LDAP: unable to update group attributes for group ({$group_dn}): " . ldap_error($ldap_connection);
    if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix DEBUG update group attributes array: ". print_r($extra_attributes,true),0); }
    error_log($this_error,0);
    return FALSE;
  }
  else {
    error_log("$log_prefix Updated group attributes for $group_name",0);
    return TRUE;
  }
 }
 else {
  error_log("$log_prefix Update group attributes; group name wasn't set.",0);
  return FALSE;
 }

}

##################################

function ldap_delete_group($ldap_connection,$group_name) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (isset($group_name)) {

  $delete_query = "{$LDAP['group_attribute']}=" . ldap_escape(($group_name === null ? '' : $group_name), "", LDAP_ESCAPE_FILTER) . ",{$LDAP['group_dn']}";
  $delete = @ ldap_delete($ldap_connection, $delete_query);

  if ($delete) {
   error_log("$log_prefix Deleted group $group_name",0);
   return TRUE;
  }
  else {
   error_log("$log_prefix Couldn't delete group $group_name" . ldap_error($ldap_connection) ,0);
   return FALSE;
  }

 }

}


##################################

function ldap_get_gid_of_group($ldap_connection,$group_name) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (isset($group_name)) {

  $ldap_search_query = "({$LDAP['group_attribute']}=" . ldap_escape(($group_name === null ? '' : $group_name), "", LDAP_ESCAPE_FILTER) . ")";
  $ldap_search = @ ldap_search($ldap_connection, "{$LDAP['group_dn']}", $ldap_search_query , array("gidNumber"));
  $result = @ ldap_get_entries($ldap_connection, $ldap_search);

  if (isset($result[0]['gidnumber'][0]) and is_numeric($result[0]['gidnumber'][0])) {
    return $result[0]['gidnumber'][0];
  }

 }

 return FALSE;

}


##################################

function ldap_get_group_name_from_gid($ldap_connection,$gid) {

 global $log_prefix, $LDAP, $LDAP_DEBUG;

 if (isset($gid)) {

  $ldap_search_query = "(gidnumber=" . ldap_escape($gid, "", LDAP_ESCAPE_FILTER) . ")";
  $ldap_search = @ ldap_search($ldap_connection, "{$LDAP['group_dn']}", $ldap_search_query , array("cn"));
  $result = @ ldap_get_entries($ldap_connection, $ldap_search);

  if (isset($result[0]['cn'][0])) {
    return $result[0]['cn'][0];
  }

 }

 return FALSE;

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

     # Set default description (role) if not specified
     if (!isset($account_attributes['description'][0])) {
       $account_attributes['description'][0] = 'user';
     }

     # Ensure uid is set to email for email-based login
     if ($LDAP['account_attribute'] === 'mail') {
       $account_attributes['uid'] = $account_identifier;
     }

     $add_account = @ ldap_add($ldap_connection,
                               "{$LDAP['account_attribute']}=$account_identifier,{$user_dn}",
                               $account_attributes
                              );

     if ($add_account) {
       error_log("$log_prefix Created new account: $account_identifier in organization: $organization",0);
       
       # Add user to organization if they have org_admin role
       if (isset($account_attributes['description'][0]) && $account_attributes['description'][0] === 'org_admin') {
         addUserToOrgManagers($organization, "{$LDAP['account_attribute']}=$account_identifier,{$user_dn}");
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

function ldap_add_member_to_group($ldap_connection,$group_name,$username) {

  global $log_prefix, $LDAP, $LDAP_DEBUG;

  # Get user DN first
  $user_dn = null;
  $user_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=$username))";
  
  # Search in organizations
  $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $user_filter, array('dn'));
  if ($ldap_search) {
    $result = @ ldap_get_entries($ldap_connection, $ldap_search);
    if ($result['count'] > 0) {
      $user_dn = $result[0]['dn'];
    }
  }
  
  # If not found in organizations, search in system users
  if (!$user_dn) {
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $user_filter, array('dn'));
    if ($ldap_search) {
      $result = @ ldap_get_entries($ldap_connection, $ldap_search);
      if ($result['count'] > 0) {
        $user_dn = $result[0]['dn'];
      }
    }
  }
  
  if (!$user_dn) {
    error_log("$log_prefix Add member to group; User $username not found",0);
    return FALSE;
  }

  # Determine if this is a global role or organization-specific role
  $role_dn = null;
  
  # Check if it's a global role (administrator, maintainer)
  if (in_array($group_name, array('administrator', 'maintainer'))) {
    $role_dn = "cn=$group_name,{$LDAP['roles_dn']}";
  } else {
    # Check if it's an organization-specific role
    $org_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], "(&(objectclass=groupOfNames)(cn=$group_name))", array('dn'));
    if ($org_search) {
      $result = @ ldap_get_entries($ldap_connection, $org_search);
      if ($result['count'] > 0) {
        $role_dn = $result[0]['dn'];
      }
    }
  }
  
  if (!$role_dn) {
    error_log("$log_prefix Add member to group; Role/group $group_name not found",0);
    return FALSE;
  }

  # Add user to the role
  $add_member = @ ldap_mod_add($ldap_connection, $role_dn, array('member' => $user_dn));

  if ($add_member) {
   error_log("$log_prefix Added user $username to role/group $group_name",0);
   return TRUE;
  }
  else {
   error_log("$log_prefix Couldn't add user $username to role/group $group_name: " . ldap_error($ldap_connection),0);
   return FALSE;
  }

}


##################################

function ldap_delete_member_from_group($ldap_connection,$group_name,$username) {

  global $log_prefix, $LDAP, $LDAP_DEBUG;

  # Get user DN first
  $user_dn = null;
  $user_filter = "(&(objectclass=inetOrgPerson)({$LDAP['account_attribute']}=$username))";
  
  # Search in organizations
  $ldap_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], $user_filter, array('dn'));
  if ($ldap_search) {
    $result = @ ldap_get_entries($ldap_connection, $ldap_search);
    if ($result['count'] > 0) {
      $user_dn = $result[0]['dn'];
    }
  }
  
  # If not found in organizations, search in system users
  if (!$user_dn) {
    $ldap_search = @ ldap_search($ldap_connection, $LDAP['people_dn'], $user_filter, array('dn'));
    if ($ldap_search) {
      $result = @ ldap_get_entries($ldap_connection, $ldap_search);
      if ($result['count'] > 0) {
        $user_dn = $result[0]['dn'];
      }
    }
  }
  
  if (!$user_dn) {
    error_log("$log_prefix Remove member from group; User $username not found",0);
    return FALSE;
  }

  # Determine if this is a global role or organization-specific role
  $role_dn = null;
  
  # Check if it's a global role (administrator, maintainer)
  if (in_array($group_name, array('administrator', 'maintainer'))) {
    $role_dn = "cn=$group_name,{$LDAP['roles_dn']}";
  } else {
    # Check if it's an organization-specific role
    $org_search = @ ldap_search($ldap_connection, $LDAP['org_dn'], "(&(objectclass=groupOfNames)(cn=$group_name))", array('dn'));
    if ($org_search) {
      $result = @ ldap_get_entries($ldap_connection, $org_search);
      if ($result['count'] > 0) {
        $role_dn = $result[0]['dn'];
      }
    }
  }
  
  if (!$role_dn) {
    error_log("$log_prefix Remove member from group; Role/group $group_name not found",0);
    return FALSE;
  }

  # Remove user from the role
  $remove_member = @ ldap_mod_delete($ldap_connection, $role_dn, array('member' => $user_dn));

  if ($remove_member) {
   error_log("$log_prefix Removed user $username from role/group $group_name",0);
   return TRUE;
  }
  else {
   error_log("$log_prefix Couldn't remove user $username from role/group $group_name: " . ldap_error($ldap_connection),0);
   return FALSE;
  }

}


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
 
 if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix User {$username} not found",0); }
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

function ldap_detect_rfc2307bis($ldap_connection) {

  global $log_prefix, $LDAP, $LDAP_DEBUG;

  if (isset($LDAP['rfc2307bis_available'])) {
    return $LDAP['rfc2307bis_available'];
  }
  else {

    $LDAP['rfc2307bis_available'] = FALSE;

    if ($LDAP['forced_rfc2307bis'] == TRUE) {
      if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - skipping autodetection because FORCE_RFC2307BIS is TRUE",0); }
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
          if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - found that the 'subschemaSubentry' base DN is '$schema_base_dn'",0); }

          $objclass_query = @ ldap_read($ldap_connection,$schema_base_dn,"(objectClasses=*)",array('objectClasses'));
          if (!$objclass_query) {
            error_log("$log_prefix LDAP RFC2307BIS detection - unable to query LDAP for objectClasses under {$schema_base_dn}:" . ldap_error($ldap_connection),0);
          }
          else {
            $objclass_results = @ ldap_get_entries($ldap_connection, $objclass_query);
            $this_count = $objclass_results[0]['objectclasses']['count'];
            if ($this_count > 0) {
              if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - found $this_count objectClasses under $schema_base_dn" ,0); }
              $posixgroup_search = preg_grep("/NAME 'posixGroup'.*AUXILIARY/",$objclass_results[0]['objectclasses']);
              if (count($posixgroup_search) > 0) {
                if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - found AUXILIARY in posixGroup definition which suggests we're using the RFC2307BIS schema" ,0); }
                $LDAP['rfc2307bis_available'] = TRUE;
              }
              else {
                if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - couldn't find AUXILIARY in the posixGroup definition which suggests we're not using the RFC2307BIS schema.  Set FORCE_RFC2307BIS to TRUE if you DO use RFC2307BIS. " ,0); }
              }
            }
            else {
              if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - no objectClasses were returned when searching under $schema_base_dn" ,0); }
            }
          }
        }
        else {
         if ($LDAP_DEBUG == TRUE) { error_log("$log_prefix LDAP RFC2307BIS detection - unable to detect the subschemaSubentry base DN" ,0); }
        }
      }
    }

    if ($LDAP['rfc2307bis_available'] == TRUE) {
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

