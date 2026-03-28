<?php

declare(strict_types=1);

set_include_path('.:' . __DIR__ . '/../../includes/');

include_once 'web_functions.inc.php';
include_once 'ldap_functions.inc.php';
include_once 'mail_functions.inc.php';
include_once 'password_reset_functions.inc.php';

setPageAccess('hidden_on_login');

$submitted = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_reset']));
$message = null;
$messageType = 'info';

if ($submitted) {
    $email = trim((string) ($_POST['email'] ?? ''));

    // Always respond generically to avoid user enumeration.
    $message = t('password.reset.message');
    $messageType = 'info';

    if ($email !== '' && isValidEmail($email)) {
        global $EMAIL_SENDING_ENABLED, $reset_password_mail_subject, $reset_password_mail_body;

        if ($EMAIL_SENDING_ENABLED === true && is_password_reset_link_enabled()) {
            $ldap = open_ldap_connection();
            $user = ldap_find_user_entry_by_account_identifier($ldap, $email, ['mail', 'givenName', 'sn', $GLOBALS['LDAP']['account_attribute'] ?? 'mail']);
            ldap_close($ldap);

            if (is_array($user)) {
                $accountAttr = (string) ($GLOBALS['LDAP']['account_attribute'] ?? 'mail');
                $login = (string) (($user[strtolower($accountAttr)][0] ?? '') ?: ($user['mail'][0] ?? $email));
                $first = (string) ($user['givenname'][0] ?? $user['givenName'][0] ?? '');
                $last = (string) ($user['sn'][0] ?? '');

                $payload = build_password_action_payload($login, 'reset');
                $token = create_password_action_token($payload);
                $resetUrl = build_password_action_url($token);

                $ttlMinutes = (int) ceil(get_password_reset_token_ttl_seconds() / 60);
                $vars = [
                    'login' => $login,
                    'first_name' => $first,
                    'last_name' => $last,
                    'password_reset_url' => $resetUrl,
                    'token_expires_minutes' => (string) $ttlMinutes,
                ];

                $subject = parse_mail_template((string) $reset_password_mail_subject, $vars);
                $body = parse_mail_template((string) $reset_password_mail_body, $vars);
                send_email($email, trim($first . ' ' . $last), $subject, $body);
            }
        }
    }
}

renderHeader('Request password reset');
?>

<div class="container">
    <div class="col-sm-6 offset-sm-3">
        <div class="card">
            <div class="card-header text-center"><?php echo htmlspecialchars(t('password.reset.card_title'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="card-body">
                <?php if ($message !== null) : ?>
                    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <p class="text-muted">
                    <?php echo htmlspecialchars(t('password.reset.lead'), ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <form method="post" action="">
                    <input type="hidden" name="request_reset" value="1">
                    <div class="form-group">
                        <label for="email" class="form-label"><?php echo htmlspecialchars(t('password.reset.email_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input type="email" class="form-control" name="email" id="email" required>
                    </div>
                    <div class="form-group mt-2">
                        <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('password.reset.submit'), ENT_QUOTES, 'UTF-8'); ?></button>
                        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(getBaseUrl() . 'login/'); ?>"><?php echo htmlspecialchars(t('password.reset.back_login'), ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
renderFooter();

