<?php
declare(strict_types=1);

 #Modules and how they can be accessed.

 #access:
 #auth = need to be logged-in to see it
 #hidden_on_login = only visible when not logged in
 #admin = need to be logged in as an admin to see it
 #admin_maintainer_org_admin = need to be logged in as admin, maintainer, or org_admin to see it

 $MODULES = array(
                    'log_in'          => 'hidden_on_login',
                    'change_password' => 'auth',
                    'manage'          => 'admin_maintainer_org_admin',
                  );

if ($ACCOUNT_REQUESTS_ENABLED == TRUE) {
  $MODULES['request_account'] = 'hidden_on_login';
}
if (!$REMOTE_HTTP_HEADERS_LOGIN) {
  $MODULES['log_out'] = 'auth';
}
