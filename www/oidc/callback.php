<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../includes/");

include_once "web_functions.inc.php";
include_once "ldap_functions.inc.php";
include_once "access_functions.inc.php";
include_once "oidc_functions.inc.php";

// Initialize OIDC configuration
init_oidc_config();

// Handle OIDC callback
if (handle_oidc_callback()) {
    // Redirect to main application
    header("Location: /");
    exit;
} else {
    // Handle error
    http_response_code(400);
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo htmlspecialchars(lum_current_locale(), ENT_QUOTES, 'UTF-8'); ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars(t('oidc.callback.title'), ENT_QUOTES, 'UTF-8'); ?></title>
        <link rel="stylesheet" href="<?php print htmlspecialchars(get_asset_base(), ENT_QUOTES, 'UTF-8'); ?>bootstrap/css/bootstrap.min.css">
    </head>
    <body>
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="text-danger"><?php echo htmlspecialchars(t('oidc.callback.title'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        </div>
                        <div class="card-body">
                            <p><?php echo htmlspecialchars(t('oidc.callback.body'), ENT_QUOTES, 'UTF-8'); ?></p>
                            <a href="/" class="btn btn-primary"><?php echo htmlspecialchars(t('oidc.callback.home'), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
