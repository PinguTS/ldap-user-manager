<?php

// Include security configuration
include_once __DIR__ . '/security_config.inc.php';

// Composer autoload (vendor may be next to www/ or one level up for local dev)
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
}
if (is_file($autoload)) {
    require_once $autoload;
}

 $log_prefix = "";

 # User account defaults

 $DEFAULT_USER_GROUP = (getenv('DEFAULT_USER_GROUP') ? getenv('DEFAULT_USER_GROUP') : 'everybody');
 $DEFAULT_USER_SHELL = (getenv('DEFAULT_USER_SHELL') ? getenv('DEFAULT_USER_SHELL') : '/bin/bash');
 $ENFORCE_SAFE_SYSTEM_NAMES = ((strcasecmp(getenv('ENFORCE_SAFE_SYSTEM_NAMES') ?: 'TRUE', 'FALSE') == 0) ? false : true);
 $USERNAME_FORMAT = (getenv('USERNAME_FORMAT') ? getenv('USERNAME_FORMAT') : '{first_name}-{last_name}');
 $USERNAME_REGEX  = (getenv('USERNAME_REGEX')  ? getenv('USERNAME_REGEX') : '^[a-z][a-zA-Z0-9\._-]{3,32}$');   #We use the username regex for groups too.

if (getenv('PASSWORD_HASH')) {
    $PASSWORD_HASH = strtoupper(getenv('PASSWORD_HASH'));
}
 $ACCEPT_WEAK_PASSWORDS = ((strcasecmp(getenv('ACCEPT_WEAK_PASSWORDS') ?: 'FALSE', 'TRUE') == 0) ? true : false);

 # Password strength configuration
 $PASSWORD_STRENGTH_MIN_SCORE = (int)(getenv('PASSWORD_STRENGTH_MIN_SCORE') ?: 2);
 $PASSWORD_STRENGTH_MIN_LENGTH = (int)(getenv('PASSWORD_STRENGTH_MIN_LENGTH') ?: 8);
 $PASSWORD_STRENGTH_REQUIRE_UPPERCASE = ((strcasecmp(getenv('PASSWORD_STRENGTH_REQUIRE_UPPERCASE') ?: 'TRUE', 'FALSE') == 0) ? false : true);
 $PASSWORD_STRENGTH_REQUIRE_LOWERCASE = ((strcasecmp(getenv('PASSWORD_STRENGTH_REQUIRE_LOWERCASE') ?: 'TRUE', 'FALSE') == 0) ? false : true);
 $PASSWORD_STRENGTH_REQUIRE_NUMBERS = ((strcasecmp(getenv('PASSWORD_STRENGTH_REQUIRE_NUMBERS') ?: 'TRUE', 'FALSE') == 0) ? false : true);
 $PASSWORD_STRENGTH_REQUIRE_SYMBOLS = ((strcasecmp(getenv('PASSWORD_STRENGTH_REQUIRE_SYMBOLS') ?: 'FALSE', 'TRUE') == 0) ? true : false);

 $min_uid = 2000;
 $min_gid = 2000;


 # User field configuration
 # Required fields for user creation (must be present)
 # Note: The 'uid' field is always required as it's used as the RDN
 $LDAP['user_required_fields'] = getenv('LDAP_USER_REQUIRED_FIELDS') ?
     explode(',', getenv('LDAP_USER_REQUIRED_FIELDS')) :
     ['uid', 'givenname', 'sn', 'mail'];

 # Ensure 'uid' field is always included in required fields
if (!in_array('uid', $LDAP['user_required_fields'])) {
    $LDAP['user_required_fields'][] = 'uid';
}

 # Optional fields for user creation (can be present but not required)
 # For system users, we only need basic fields - address fields are not needed
 $LDAP['user_optional_fields'] = getenv('LDAP_USER_OPTIONAL_FIELDS') ?
     explode(',', getenv('LDAP_USER_OPTIONAL_FIELDS')) :
     ['cn', 'organization', 'description', 'telephoneNumber', 'labeledURI'];

 # Field mappings from form names to LDAP attributes
 # Simplified for system users - removed address-related fields
 $LDAP['user_field_mappings'] = [
     'first_name' => 'givenname',
     'last_name' => 'sn',
     'email' => 'mail',
     'common_name' => 'cn',
     'uid' => 'uid',
     'organization' => 'organization',
     'user_role' => 'description',
     'phone' => 'telephoneNumber',
     'website' => 'labeledURI'
 ];

 # Field labels for the UI (human-readable names)
 $LDAP['user_field_labels'] = [
     'first_name' => 'First Name',
     'last_name' => 'Last Name',
     'email' => 'Email',
     'common_name' => 'Common Name',
     'uid' => 'Account ID',
     'organization' => 'Organization',
     'user_role' => 'User Role',
     'phone' => 'Phone Number',
     'website' => 'Website'
 ];

 # Field types for form rendering
 $LDAP['user_field_types'] = [
     'first_name' => 'text',
     'last_name' => 'text',
     'email' => 'email',
     'common_name' => 'text',
     'uid' => 'text',
     'organization' => 'text',
     'user_role' => 'text',
     'phone' => 'tel',
     'website' => 'url'
 ];

 #Default attributes and objectclasses

 $LDAP['account_attribute'] = (getenv('LDAP_ACCOUNT_ATTRIBUTE') ? getenv('LDAP_ACCOUNT_ATTRIBUTE') : 'mail');
 $LDAP['account_objectclasses'] = array( 'person', 'inetOrgPerson' );
 $LDAP['default_attribute_map'] = array(
    "givenname" => array(
        "label" => "First name",
        "onkeyup" => "updateUsername(); updateEmail(); updateCn(); updateHomedir(); check_email_validity(document.getElementById('mail').value);",
        "required" => true,
    ),
    "sn" => array(
        "label" => "Last name",
        "onkeyup" => "updateUsername(); updateEmail(); updateCn(); updateHomedir(); check_email_validity(document.getElementById('mail').value);",
        "required" => true,
    ),
    "mail" => array(
        "label" => "Email",
        "onkeyup" => "auto_email_update = false; check_email_validity(document.getElementById('mail').value);",
        "required" => true,
    ),
    "cn" => array(
        "label" => "Common name",
        "onkeyup" => "auto_cn_update = false;",
    ),
    "organization" => array(
        "label" => "Organization",
        "required" => true,
    ),
    "description" => array(
        "label" => "User Role",
        "default" => "user",
    ),
     "userPassword" => array(
     "label" => "Password",
    )
 );

 $LDAP['group_attribute'] = (getenv('LDAP_GROUP_ATTRIBUTE') ? getenv('LDAP_GROUP_ATTRIBUTE') : 'cn');
 $LDAP['group_objectclasses'] = array( 'top', 'posixGroup' ); #groupOfUniqueNames is added automatically if rfc2307bis is available.

 $LDAP['default_group_attribute_map'] = array( "description" => array("label" => "Description"));

 $SHOW_POSIX_ATTRIBUTES = ((strcasecmp(getenv('SHOW_POSIX_ATTRIBUTES') ?: 'FALSE', 'TRUE') == 0) ? true : false);

 if ($SHOW_POSIX_ATTRIBUTES != true) {
   # Remove POSIX-specific attributes when not needed
     unset($LDAP['default_attribute_map']['uidnumber']);
     unset($LDAP['default_attribute_map']['gidnumber']);
     unset($LDAP['default_attribute_map']['homedirectory']);
     unset($LDAP['default_attribute_map']['loginshell']);
 } else {
     $LDAP['default_attribute_map']["uidnumber"]  = array("label" => "UID");
     $LDAP['default_attribute_map']["gidnumber"]  = array("label" => "GID");
     $LDAP['default_attribute_map']["homedirectory"]  = array("label" => "Home directory", "onkeyup" => "auto_homedir_update = false;");
     $LDAP['default_attribute_map']["loginshell"]  = array("label" => "Shell", "default" => $DEFAULT_USER_SHELL);
     $LDAP['default_group_attribute_map']["gidnumber"] = array("label" => "Group ID number");
 }


 ## LDAP server

 $LDAP['uri'] = getenv('LDAP_URI');
 $LDAP['base_dn'] = getenv('LDAP_BASE_DN');
 $LDAP['admin_bind_dn'] = getenv('LDAP_ADMIN_BIND_DN');
 $LDAP['admin_bind_pwd'] = getenv('LDAP_ADMIN_BIND_PWD');
 $LDAP['connection_type'] = "plain";
 $LDAP['require_starttls'] = ((strcasecmp(getenv('LDAP_REQUIRE_STARTTLS') ?: 'FALSE', 'TRUE') == 0) ? true : false);
 $LDAP['ignore_cert_errors'] = ((strcasecmp(getenv('LDAP_IGNORE_CERT_ERRORS') ?: 'FALSE', 'TRUE') == 0) ? true : false);
 $LDAP['rfc2307bis_check_run'] = false;


 # Various advanced LDAP settings

 # Role names used throughout the system (groups, user descriptions, etc.)
 # Note: Role values now default to group names to eliminate duplication
 $LDAP['admin_role'] = getenv('LDAP_ADMIN_ROLE') ?: 'administrators';
 $LDAP['maintainer_role'] = getenv('LDAP_MAINTAINER_ROLE') ?: 'maintainers';
 $LDAP['org_admin_role'] = getenv('LDAP_ORG_ADMIN_ROLE') ?: 'org_admin';
 $LDAP['user_role'] = getenv('LDAP_USER_ROLE') ?: 'user';

 # Display labels for UI (human-readable role names)
 $LDAP['role_display_labels'] = [
     'admin_role' => getenv('LDAP_ADMIN_DISPLAY_LABEL') ?: 'System Administrator',
     'maintainer_role' => getenv('LDAP_MAINTAINER_DISPLAY_LABEL') ?: 'System Maintainer',
     'org_admin_role' => getenv('LDAP_ORG_ADMIN_DISPLAY_LABEL') ?: 'Organization Administrator',
     'user_role' => getenv('LDAP_USER_DISPLAY_LABEL') ?: 'User'
 ];

 # Error message templates (configurable for localization)
 $LDAP['error_messages'] = [
     'maintainer_cannot_delete_admin' => getenv('LDAP_ERROR_MAINTAINER_CANNOT_DELETE_ADMIN') ?: 'Maintainers cannot delete administrators',
     'maintainer_cannot_create_admin' => getenv('LDAP_ERROR_MAINTAINER_CANNOT_CREATE_ADMIN') ?: 'Maintainers cannot create users with administrator roles',
     'cannot_delete_self' => getenv('LDAP_ERROR_CANNOT_DELETE_SELF') ?: 'You cannot delete your own account'
 ];

 # Role hierarchy and conflict prevention
 $LDAP['role_hierarchy'] = [
     'global_admin' => 100,      # Highest level - can do everything
     'maintainer' => 80,         # High level - can manage users and orgs
     'org_admin' => 60,          # Medium level - can manage their org
     'user' => 10                # Lowest level - basic user
 ];

 # Role conflict prevention - ensure roles are unique
 $LDAP['prevent_role_conflicts'] = getenv('LDAP_PREVENT_ROLE_CONFLICTS') ?: 'TRUE';

 # Organization field configuration
# Required fields for organization creation (must be present)
# Note: The 'o' (organization name) field is always required as it's used as the RDN
 $LDAP['org_required_fields'] = getenv('LDAP_ORG_REQUIRED_FIELDS') ?
    explode(',', getenv('LDAP_ORG_REQUIRED_FIELDS')) :
    ['o'];

# Ensure 'o' field is always included in required fields
 if (!in_array('o', $LDAP['org_required_fields'])) {
     $LDAP['org_required_fields'][] = 'o';
 }

 # Optional fields for organization creation (can be present but not required)
 # Fields listed here are considered optional and won't have required validation
 # Required fields are those NOT listed here (e.g., 'o' for organization name is always required)
 $LDAP['org_optional_fields'] = getenv('LDAP_ORG_OPTIONAL_FIELDS') ?
    explode(',', getenv('LDAP_ORG_OPTIONAL_FIELDS')) :
   ['telephoneNumber', 'facsimileTelephoneNumber', 'labeledURI', 'mail', 'description', 'businessCategory', 'postalAddress', 'memberNumber', 'memberSince', 'memberUntil'];

 # Field mappings from form names to LDAP attributes
 # Note: Individual address fields (street, city, state, postalCode, country) are combined into postalAddress
 # To make a field required, remove it from the org_optional_fields array above
 $LDAP['org_field_mappings'] = [
    'org_name' => 'o',
    'org_phone' => 'telephoneNumber',
    'org_fax' => 'facsimileTelephoneNumber',
    'org_website' => 'labeledURI',
    'org_email' => 'mail',
    'org_description' => 'description',
    'org_category' => 'businessCategory',
    'org_member_number' => 'memberNumber',
   'org_member_since' => 'memberSince',
   'org_member_until' => 'memberUntil'
 ];

 # Address field configuration (these are composite fields that combine into postalAddress)
 # These fields are not stored directly in LDAP but are combined into the postalAddress attribute
 # Set required => true for any address fields that should be mandatory
 # The form will automatically add required validation and visual indicators
 $LDAP['org_address_fields'] = [
    'org_address' => ['label' => 'Street Address', 'type' => 'text', 'required' => false],
    'org_zip' => ['label' => 'Postal Code', 'type' => 'text', 'required' => false],
    'org_city' => ['label' => 'City', 'type' => 'text', 'required' => false],
    'org_state' => ['label' => 'State/Province', 'type' => 'text', 'required' => false],
    'org_country' => ['label' => 'Country', 'type' => 'text', 'required' => false]
 ];

 # Field labels for the UI (human-readable names)
 $LDAP['org_field_labels'] = [
    'org_name' => 'Organization Name',
    'org_phone' => 'Phone Number',
    'org_fax' => 'Fax number',
    'org_website' => 'Website',
    'org_email' => 'Email',
    'org_description' => 'Description',
    'org_category' => 'Business Category',
    'org_member_number' => 'Member number',
   'org_member_since' => 'Member since (YYYY-MM-DD)',
   'org_member_until' => 'Member until (YYYY-MM-DD)'
 ];

 # Field types for form rendering
 $LDAP['org_field_types'] = [
    'org_name' => 'text',
    'org_phone' => 'tel',
    'org_fax' => 'tel',
    'org_website' => 'url',
    'org_email' => 'email',
    'org_description' => 'textarea',
    'org_category' => 'text',
    'org_member_number' => 'text',
   'org_member_since' => 'date',
   'org_member_until' => 'date'
 ];

 $LDAP['group_ou'] = (getenv('LDAP_GROUP_OU') ? getenv('LDAP_GROUP_OU') : 'groups');
 $LDAP['user_ou'] = (getenv('LDAP_USER_OU') ? getenv('LDAP_USER_OU') : 'people');
 $LDAP['org_ou'] = (getenv('LDAP_ORG_OU') ? getenv('LDAP_ORG_OU') : 'organizations');
 $LDAP['forced_rfc2307bis'] = ((strcasecmp(getenv('FORCE_RFC2307BIS') ?: '', 'TRUE') == 0) ? true : false);

 if (getenv('LDAP_ACCOUNT_ADDITIONAL_OBJECTCLASSES')) {
     $account_additional_objectclasses = strtolower(getenv('LDAP_ACCOUNT_ADDITIONAL_OBJECTCLASSES'));
 }
 if (getenv('LDAP_ACCOUNT_ADDITIONAL_ATTRIBUTES')) {
     $LDAP['account_additional_attributes'] = getenv('LDAP_ACCOUNT_ADDITIONAL_ATTRIBUTES');
 }

 if (getenv('LDAP_GROUP_ADDITIONAL_OBJECTCLASSES')) {
     $group_additional_objectclasses = getenv('LDAP_GROUP_ADDITIONAL_OBJECTCLASSES');
 }
 if (getenv('LDAP_GROUP_ADDITIONAL_ATTRIBUTES')) {
     $LDAP['group_additional_attributes'] = getenv('LDAP_GROUP_ADDITIONAL_ATTRIBUTES');
 }

 if (getenv('LDAP_GROUP_MEMBERSHIP_ATTRIBUTE')) {
     $LDAP['group_membership_attribute'] = getenv('LDAP_GROUP_MEMBERSHIP_ATTRIBUTE');
 }
 if (getenv('LDAP_GROUP_MEMBERSHIP_USES_UID')) {
     if (strtoupper(getenv('LDAP_GROUP_MEMBERSHIP_USES_UID')) == 'TRUE') {
         $LDAP['group_membership_uses_uid']  = true;
     }
     if (strtoupper(getenv('LDAP_GROUP_MEMBERSHIP_USES_UID')) == 'FALSE') {
         $LDAP['group_membership_uses_uid']  = false;
     }
 }

 # Updated LDAP structure for new organization-based approach
 $LDAP['org_dn'] = "ou={$LDAP['org_ou']},{$LDAP['base_dn']}";

 $LDAP['people_dn'] = "ou=people,{$LDAP['base_dn']}";
 $LDAP['org_people_dn'] = "ou=people,{$LDAP['org_dn']}";
 $LDAP['roles_dn'] = "ou=roles,{$LDAP['base_dn']}";

# UUID-based identification configuration
 $LDAP['use_uuid_identification'] = getenv('LDAP_USE_UUID_IDENTIFICATION') ?
    (strtolower(getenv('LDAP_USE_UUID_IDENTIFICATION')) === 'true') :
    true; // Default to true for security

# UUID attribute name (OpenLDAP operational attribute)
 $LDAP['uuid_attribute'] = 'entryUUID';


 # Interface customisation

 $ORGANISATION_NAME = (getenv('ORGANISATION_NAME') ? getenv('ORGANISATION_NAME') : 'LDAP');
 $SITE_NAME = (getenv('SITE_NAME') ? getenv('SITE_NAME') : "$ORGANISATION_NAME user manager");

 $SITE_LOGIN_LDAP_ATTRIBUTE = (getenv('SITE_LOGIN_LDAP_ATTRIBUTE') ? getenv('SITE_LOGIN_LDAP_ATTRIBUTE') : 'mail' );
 $SITE_LOGIN_FIELD_LABEL = (getenv('SITE_LOGIN_FIELD_LABEL') ? getenv('SITE_LOGIN_FIELD_LABEL') : "Email" );

 $SERVER_HOSTNAME = (getenv('SERVER_HOSTNAME') ? getenv('SERVER_HOSTNAME') : "ldapusermanager.org");
 $SERVER_PATH = (getenv('SERVER_PATH') !== false ? (string) getenv('SERVER_PATH') : '/');
 if ($SERVER_PATH === '') {
     $SERVER_PATH = '/';
 }

 $SESSION_TIMEOUT = (getenv('SESSION_TIMEOUT') ? getenv('SESSION_TIMEOUT') : 60); // 60 minutes (1 hour)
// Directory for app session files (must be writable and shared across all app instances, e.g. a volume in Docker)
 $SESSION_SAVE_PATH = rtrim(getenv('SESSION_SAVE_PATH') ?: '/tmp', '/');

 $NO_HTTPS = ((strcasecmp(getenv('NO_HTTPS') ?: '', 'TRUE') == 0) ? true : false);

 $REMOTE_HTTP_HEADERS_LOGIN = ((strcasecmp(getenv('REMOTE_HTTP_HEADERS_LOGIN') ?: '', 'TRUE') == 0) ? true : false);

 # Sending email

 $SMTP['host'] = getenv('SMTP_HOSTNAME');
 $SMTP['user'] = (getenv('SMTP_USERNAME') ? getenv('SMTP_USERNAME') : null);
 $SMTP['pass'] = (getenv('SMTP_PASSWORD') ? getenv('SMTP_PASSWORD') : null);
 $SMTP['port'] = (getenv('SMTP_HOST_PORT') ? getenv('SMTP_HOST_PORT') : 25);
 $SMTP['helo'] = (getenv('SMTP_HELO_HOST') ? getenv('SMTP_HELO_HOST') : null);
 $SMTP['ssl']  = ((strcasecmp(getenv('SMTP_USE_SSL') ?: '', 'TRUE') == 0) ? true : false);
 $SMTP['tls']  = ((strcasecmp(getenv('SMTP_USE_TLS') ?: '', 'TRUE') == 0) ? true : false);
 if ($SMTP['tls'] == true) {
     $SMTP['ssl'] = false;
 }

 $EMAIL_DOMAIN = (getenv('EMAIL_DOMAIN') ? getenv('EMAIL_DOMAIN') : null);

 $default_email_from_domain = ($EMAIL_DOMAIN ? $EMAIL_DOMAIN : 'ldapusermanger.org');

 $EMAIL['from_address'] = (getenv('EMAIL_FROM_ADDRESS') ? getenv('EMAIL_FROM_ADDRESS') : "admin@" . $default_email_from_domain );
 $EMAIL['from_name'] = (getenv('EMAIL_FROM_NAME') ? getenv('EMAIL_FROM_NAME') : $SITE_NAME );

 if ($SMTP['host'] != "") {
     include_once __DIR__ . '/email_status.inc.php';
     if (email_status_needs_refresh()) {
         include_once __DIR__ . '/email_verify.inc.php';
         $email_result = run_email_verification();
         set_email_verified($email_result['passed']);
     }
     $EMAIL_SENDING_ENABLED = is_email_verified();
 } else {
     $EMAIL_SENDING_ENABLED = false;
 }

 # Account requests

 $ACCOUNT_REQUESTS_ENABLED = ((strcasecmp(getenv('ACCOUNT_REQUESTS_ENABLED') ?: '', 'TRUE') == 0) ? true : false);
 if (($EMAIL_SENDING_ENABLED == false) && ($ACCOUNT_REQUESTS_ENABLED == true)) {
     $ACCOUNT_REQUESTS_ENABLED = false;
     error_log("$log_prefix Config: ACCOUNT_REQUESTS_ENABLED was set to TRUE but SMTP_HOSTNAME wasn't set, so account requesting has been disabled as we can't send out the request email", 0);
 }

 $ACCOUNT_REQUESTS_EMAIL = (getenv('ACCOUNT_REQUESTS_EMAIL') ? getenv('ACCOUNT_REQUESTS_EMAIL') : $EMAIL['from_address']);

 # PHPMailer path configuration (used only when not using Composer autoload; default to vendor path)
 $vendorPhpMailer = dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src';
 if (!is_dir($vendorPhpMailer)) {
     $vendorPhpMailer = dirname(__DIR__, 2) . '/vendor/phpmailer/phpmailer/src';
 }
 $PHPMailer_PATH = (getenv('PHPMailer_PATH') ? getenv('PHPMailer_PATH') : $vendorPhpMailer);

 # Debugging

 $LDAP_DEBUG = ((strcasecmp(getenv('LDAP_DEBUG') ?: '', 'TRUE') == 0) ? true : false);
 $LDAP_VERBOSE_CONNECTION_LOGS = ((strcasecmp(getenv('LDAP_VERBOSE_CONNECTION_LOGS') ?: '', 'TRUE') == 0) ? true : false);
 $SESSION_DEBUG = ((strcasecmp(getenv('SESSION_DEBUG') ?: '', 'TRUE') == 0) ? true : false);
 $SETUP_DEBUG = ((strcasecmp(getenv('SETUP_DEBUG') ?: '', 'TRUE') == 0) ? true : false);
 $SMTP['debug_level'] = getenv('SMTP_LOG_LEVEL');
 if (!is_numeric($SMTP['debug_level']) or $SMTP['debug_level'] > 4 or $SMTP['debug_level'] < 0) {
     $SMTP['debug_level'] = 0;
 }

 # Sanity checking

 $CUSTOM_LOGO = (getenv('CUSTOM_LOGO') ? getenv('CUSTOM_LOGO') : false);
 $CUSTOM_STYLES = (getenv('CUSTOM_STYLES') ? getenv('CUSTOM_STYLES') : false);

 $errors = "";

 if (empty($LDAP['uri'])) {
     $errors .= "<div class='alert alert-warning'><p class='text-center'>LDAP_URI isn't set</p></div>\n";
 }
 if (empty($LDAP['base_dn'])) {
     $errors .= "<div class='alert alert-warning'><p class='text-center'>LDAP_BASE_DN isn't set</p></div>\n";
 }
 if (empty($LDAP['admin_bind_dn'])) {
     $errors .= "<div class='alert alert-warning'><p class='text-center'>LDAP_ADMIN_BIND_DN isn't set</p></div>\n";
 }
 if (empty($LDAP['admin_bind_pwd'])) {
     $errors .= "<div class='alert alert-warning'><p class='text-center'>LDAP_ADMIN_BIND_PWD isn't set</p></div>\n";
 }
 if (empty($LDAP['admin_role'])) {
     $errors .= "<div class='alert alert-warning'><p class='text-center'>LDAP_ADMIN_ROLE isn't set</p></div>\n";
 }

 if ($errors != "") {
     renderHeader("Fatal errors", false);
     print $errors;
     renderFooter();
     exit(1);
 }

 // Validate role configuration to prevent conflicts
 // Note: Role values and group names are now synchronized by default to eliminate duplication
 // LDAP_ADMIN_ROLE defaults to LDAP_ADMIN_GROUP_NAME, LDAP_MAINTAINER_ROLE defaults to LDAP_MAINTAINER_GROUP_NAME
 if ($LDAP['prevent_role_conflicts'] === 'TRUE' || $LDAP['prevent_role_conflicts'] === true) {
     // CRITICAL: Check for admin/maintainer role conflicts that would break access control
     if ($LDAP['admin_role'] === $LDAP['maintainer_role']) {
         $errors .= "<div class='alert alert-danger'><p class='text-center'><strong>CRITICAL ERROR: Admin and Maintainer roles cannot be the same!</strong></p>";
         $errors .= "<p class='text-center'>Current values: admin_role = '{$LDAP['admin_role']}', maintainer_role = '{$LDAP['maintainer_role']}'</p>";
         $errors .= "<p class='text-center'>This configuration will completely break the access control system.</p>";
         $errors .= "<p class='text-center'>Please set different values for LDAP_ADMIN_ROLE and LDAP_MAINTAINER_ROLE.</p></div>\n";
     }

     // Check for admin/maintainer group name conflicts
     if ($LDAP['admin_role'] === $LDAP['maintainer_role']) {
         $errors .= "<div class='alert alert-danger'><p class='text-center'><strong>CRITICAL ERROR: Admin and Maintainer group names cannot be the same!</strong></p>";
         $errors .= "<p class='text-center'>Current values: admin_group_name = '{$LDAP['admin_role']}', maintainer_group_name = '{$LDAP['maintainer_role']}'</p>";
         $errors .= "<p class='text-center'>This configuration will completely break the access control system.</p>";
         $errors .= "<p class='text-center'>Please set different values for LDAP_ADMIN_GROUP_NAME and LDAP_MAINTAINER_GROUP_NAME.</p></div>\n";
     }

     // Check for role value conflicts with group names
     if ($LDAP['admin_role'] === $LDAP['maintainer_role']) {
         $errors .= "<div class='alert alert-danger'><p class='text-center'><strong>CRITICAL ERROR: Admin role conflicts with Maintainer group!</strong></p>";
         $errors .= "<p class='text-center'>Current values: admin_role = '{$LDAP['admin_role']}', maintainer_group_name = '{$LDAP['maintainer_role']}'</p>";
         $errors .= "<p class='text-center'>This will cause access control confusion.</p></div>\n";
     }

     if ($LDAP['maintainer_role'] === $LDAP['admin_role']) {
         $errors .= "<div class='alert alert-danger'><p class='text-center'><strong>CRITICAL ERROR: Maintainer role conflicts with Admin group!</strong></p>";
         $errors .= "<p class='text-center'>Current values: maintainer_role = '{$LDAP['maintainer_role']}', admin_group_name = '{$LDAP['admin_role']}'</p>";
         $errors .= "<p class='text-center'>This will cause access control confusion.</p></div>\n";
     }

     // General role value uniqueness check
     $role_values = [
         'admin_role' => $LDAP['admin_role'],
         'maintainer_role' => $LDAP['maintainer_role'],
         'org_admin_role' => $LDAP['org_admin_role'],
         'user_role' => $LDAP['user_role']
     ];

     // Check for group name conflicts
     $group_values = [
         'admin_group_name' => $LDAP['admin_role'],
         'maintainer_group_name' => $LDAP['maintainer_role']
     ];
 }

 /**
  * Runtime role conflict check - call this function throughout the web app
  * to detect configuration conflicts and put system into maintenance mode
  *
  * @return bool True if conflicts detected (system should be in maintenance mode)
  */
 function checkRuntimeRoleConflicts()
 {
     global $LDAP;

     // Check for critical admin/maintainer conflicts
     if ($LDAP['admin_role'] === $LDAP['maintainer_role']) {
         return true;
     }

     if ($LDAP['admin_role'] === $LDAP['maintainer_role']) {
         return true;
     }

     // Check for role/group cross-conflicts
     if ($LDAP['admin_role'] === $LDAP['maintainer_role']) {
         return true;
     }

     if ($LDAP['maintainer_role'] === $LDAP['admin_role']) {
         return true;
     }

     return false;
 }

 /**
  * Display maintenance mode page when role conflicts are detected
  * This prevents the system from operating with broken access control
  */
 function displayMaintenanceMode()
 {
     if (!function_exists('t')) {
         require_once __DIR__ . '/i18n.inc.php';
         lum_i18n_bootstrap();
     }

     global $LDAP;

     $conflicts = [];

     if ($LDAP['admin_role'] === $LDAP['maintainer_role']) {
         $conflicts[] = t('maintenance.conflict.same_roles', ['role' => (string) $LDAP['admin_role']]);
     }

     $lang = htmlspecialchars(lum_current_locale(), ENT_QUOTES, 'UTF-8');
     $title = htmlspecialchars(t('maintenance.page_title'), ENT_QUOTES, 'UTF-8');
     echo '<!DOCTYPE html>
     <html lang="' . $lang . '">
     <head>
         <meta charset="UTF-8">
         <meta name="viewport" content="width=device-width, initial-scale=1.0">
         <title>' . $title . '</title>
         <style>
             body { font-family: Arial, sans-serif; background-color: #f5f5f5; margin: 0; padding: 20px; }
             .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
             .error-header { color: #d32f2f; text-align: center; margin-bottom: 30px; }
             .error-icon { font-size: 48px; margin-bottom: 20px; }
             .conflict-list { background: #fff3e0; border-left: 4px solid #ff9800; padding: 20px; margin: 20px 0; }
             .conflict-list h3 { color: #e65100; margin-top: 0; }
             .conflict-list ul { margin: 10px 0; padding-left: 20px; }
             .conflict-list li { margin: 5px 0; color: #bf360c; }
             .solution { background: #e8f5e8; border-left: 4px solid #4caf50; padding: 20px; margin: 20px 0; }
             .solution h3 { color: #2e7d32; margin-top: 0; }
             .code-block { background: #f5f5f5; padding: 15px; border-radius: 4px; font-family: monospace; margin: 10px 0; white-space: pre-wrap; }
         </style>
     </head>
     <body>
         <div class="container">
             <div class="error-header">
                 <div class="error-icon" aria-hidden="true">🚨</div>
                 <h1>' . htmlspecialchars(t('maintenance.h1'), ENT_QUOTES, 'UTF-8') . '</h1>
                 <h2>' . htmlspecialchars(t('maintenance.h2'), ENT_QUOTES, 'UTF-8') . '</h2>
             </div>
             <div class="conflict-list">
                 <h3>' . htmlspecialchars(t('maintenance.conflicts_heading'), ENT_QUOTES, 'UTF-8') . '</h3>
                 <ul>';
     foreach ($conflicts as $conflict) {
         echo '<li>' . htmlspecialchars($conflict, ENT_QUOTES, 'UTF-8') . '</li>';
     }
     echo '</ul>
             </div>
             <div class="solution">
                 <h3>' . htmlspecialchars(t('maintenance.why_heading'), ENT_QUOTES, 'UTF-8') . '</h3>
                 <p>' . htmlspecialchars(t('maintenance.why_body'), ENT_QUOTES, 'UTF-8') . '</p>
                 <h3>' . htmlspecialchars(t('maintenance.fix_heading'), ENT_QUOTES, 'UTF-8') . '</h3>
                 <p>' . htmlspecialchars(t('maintenance.fix_body'), ENT_QUOTES, 'UTF-8') . '</p>
                 <div class="code-block">' . htmlspecialchars(t('maintenance.code_example'), ENT_QUOTES, 'UTF-8') . '</div>
                 <p><strong>' . htmlspecialchars(t('maintenance.important_label'), ENT_QUOTES, 'UTF-8') . '</strong> ' . htmlspecialchars(t('maintenance.restart_note'), ENT_QUOTES, 'UTF-8') . '</p>
             </div>
             <div class="solution">
                 <h3>' . htmlspecialchars(t('maintenance.current_config_heading'), ENT_QUOTES, 'UTF-8') . '</h3>
                 <div class="code-block">' . htmlspecialchars(t('maintenance.current_admin', ['role' => (string) $LDAP['admin_role']]), ENT_QUOTES, 'UTF-8') . '
' . htmlspecialchars(t('maintenance.current_maintainer', ['role' => (string) $LDAP['maintainer_role']]), ENT_QUOTES, 'UTF-8') . '</div>
             </div>
         </div>
     </body>
     </html>';

     exit(1);
 }

 // File upload configuration
 $FILE_UPLOAD_MAX_SIZE = getenv('FILE_UPLOAD_MAX_SIZE') ? intval(getenv('FILE_UPLOAD_MAX_SIZE')) : 2 * 1024 * 1024; // Default 2MB
 $FILE_UPLOAD_ALLOWED_MIME_TYPES = getenv('FILE_UPLOAD_ALLOWED_MIME_TYPES')
    ? array_map('trim', explode(',', getenv('FILE_UPLOAD_ALLOWED_MIME_TYPES')))
    : [
        'image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain'
      ];
