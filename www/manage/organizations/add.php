<?php

declare(strict_types=1);

set_include_path(".:" . __DIR__ . "/../../includes/");
require_once "bootstrap_manage.inc.php";
bootstrapManage(['ldap', 'organization']);

// Use the enhanced access control function
setPageAccess(["admin", "maintainer"]);

// Ensure CSRF token is generated early
getCsrfToken();

$message = '';
$message_type = '';

// Handle organization creation
if (isset($_POST['action']) && $_POST['action'] == 'create_organization') {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        $message = t('manage.common.msg.security_validation_failed');
        $message_type = 'danger';
    } elseif (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = t('manage.common.msg.security_validation_failed');
        $message_type = 'danger';
    } elseif (!currentUserCanCreateOrganization()) {
        $message = t('manage.orgs.add.msg.permission_create_org');
        $message_type = 'danger';
    } else {
        // Build organization data using field mappings
        $org_data = [];

        // Debug: Check LDAP configuration
        if (!isset($LDAP['org_field_mappings'])) {
            $message = t('manage.orgs.add.msg.field_mappings_missing');
            $message_type = 'danger';
        } else {
            // Map form fields to LDAP attributes using the configuration
            foreach ($LDAP['org_field_mappings'] as $form_field => $ldap_attr) {
                if (isset($_POST[$form_field]) && !empty(trim($_POST[$form_field]))) {
                    $org_data[$ldap_attr] = trim($_POST[$form_field]);
                }
            }

            if (isset($org_data['labeledURI'])) {
                $websiteError = applyWebsiteUrlNormalization($org_data);
                if ($websiteError !== null) {
                    $message = $websiteError;
                    $message_type = 'danger';
                }
            }

            // Ensure required field 'o' (organization name) is present
            if ($message_type !== 'danger' && (!isset($org_data['o']) || empty($org_data['o']))) {
                $message = t('manage.orgs.add.msg.required_org_name_missing');
                $message_type = 'danger';
            }

            // Special handling for postalAddress from individual address fields
            if (isset($_POST['org_address']) || isset($_POST['org_zip']) || isset($_POST['org_city']) || isset($_POST['org_state']) || isset($_POST['org_country'])) {
                $postal_parts = [
                    trim($_POST['org_address'] ?? ''),
                    trim($_POST['org_zip'] ?? ''),
                    trim($_POST['org_city'] ?? ''),
                    trim($_POST['org_state'] ?? ''),
                    trim($_POST['org_country'] ?? '')
                ];
                $postal_address = implode('$', $postal_parts);
                if (!empty(trim($postal_address, '$'))) {
                    $org_data['postalAddress'] = $postal_address;
                }
            }

            // Create organization using the createOrganization function
            if ($message_type !== 'danger') {
                $result = createOrganization($org_data);
                if ($result[0]) {
                    setFlash(t('manage.orgs.add.msg.created_ok'), 'success');
                    $org_name = (string) ($org_data['o'] ?? '');
                    $entryUuid = isset($result[2]) ? (string) $result[2] : '';
                    if ($entryUuid !== '') {
                        header('Location: ' . getBaseUrl() . 'manage/organizations/' . urlencode($entryUuid) . '/');
                    } elseif ($org_name !== '') {
                        header('Location: ' . getBaseUrl() . 'manage/organizations/show/index.php?org=' . urlencode($org_name));
                    } else {
                        header('Location: ' . getBaseUrl() . 'manage/organizations/');
                    }
                    exit;
                } else {
                    error_log("createOrganization failed: " . (string) $result[1]);
                    $message = t('manage.orgs.add.msg.create_fail');
                    $message_type = 'danger';
                }
            }
        }
    }
}

renderHeader((string) $ORGANISATION_NAME . ' - ' . t('manage.orgs.add_new'));
render_submenu();

?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2><?php echo htmlspecialchars(t('manage.orgs.add_new'), ENT_QUOTES, 'UTF-8'); ?></h2>
            
            <?php if ($message) : ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible" role="alert">
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php echo htmlspecialchars(t('modal.close_aria'), ENT_QUOTES, 'UTF-8'); ?>"></button>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title"><?php echo htmlspecialchars(t('manage.orgs.add.create_new_heading'), ENT_QUOTES, 'UTF-8'); ?></h3>
                </div>
                <div class="card-body">
                    <form method="post" action="" id="createOrgForm">
                        <?php echo csrfTokenField(); ?>
                        <input type="hidden" name="action" value="create_organization">
                        
                        <?php
                        // Generate required fields first
                        foreach ($LDAP['org_required_fields'] as $ldap_attr) {
                            // Find the form field name for this LDAP attribute
                            $form_field = null;
                            foreach ($LDAP['org_field_mappings'] as $form_name => $ldap_name) {
                                if ($ldap_name === $ldap_attr) {
                                    $form_field = $form_name;
                                    break;
                                }
                            }

                            if ($form_field !== null && isset($LDAP['org_field_labels'][$form_field])) {
                                $label = $LDAP['org_field_labels'][$form_field];
                                $field_type = $LDAP['org_field_types'][$form_field] ?? 'text';
                                $required = 'required';

                                echo "<div class='form-group'>";

                                $widget = $LDAP['org_field_widgets'][$form_field] ?? null;
                                if ($widget === 'website') {
                                    renderWebsiteUrlField($form_field, $label, '', true);
                                } else {
                                    echo "<label for='{$form_field}'>{$label} *</label>";

                                    if ($field_type === 'textarea') {
                                        echo "<textarea class='form-control' id='{$form_field}' name='{$form_field}' {$required} rows='3'></textarea>";
                                    } else {
                                        echo "<input type='{$field_type}' class='form-control' id='{$form_field}' name='{$form_field}' {$required}>";
                                    }
                                }
                                echo "</div>";
                            }
                        }

                        // Generate optional fields
                        foreach ($LDAP['org_optional_fields'] as $ldap_attr) {
                            // Find the form field name for this LDAP attribute
                            $form_field = null;
                            foreach ($LDAP['org_field_mappings'] as $form_name => $ldap_name) {
                                if ($ldap_name === $ldap_attr) {
                                    $form_field = $form_name;
                                    break;
                                }
                            }

                            if ($form_field !== null && isset($LDAP['org_field_labels'][$form_field])) {
                                $label = $LDAP['org_field_labels'][$form_field];
                                $field_type = $LDAP['org_field_types'][$form_field] ?? 'text';

                                echo "<div class='form-group'>";

                                $widget = $LDAP['org_field_widgets'][$form_field] ?? null;
                                if ($widget === 'website') {
                                    renderWebsiteUrlField($form_field, $label);
                                } else {
                                    echo "<label for='{$form_field}'>{$label}</label>";

                                    if ($field_type === 'textarea') {
                                        echo "<textarea class='form-control' id='{$form_field}' name='{$form_field}' rows='3'></textarea>";
                                    } else {
                                        echo "<input type='{$field_type}' class='form-control' id='{$form_field}' name='{$form_field}'>";
                                    }
                                }
                                echo "</div>";
                            }
                        }
                        ?>
                        
                        <!-- Address Fields (dynamically generated from configuration) -->
                        <?php
                        // Generate address fields dynamically using configuration
                        foreach ($LDAP['org_address_fields'] as $field_name => $field_config) {
                            echo "<div class='form-group'>";

                            // Generate label with required indicator if needed
                            $label = $field_config['label'];
                            if ($field_config['required']) {
                                $label .= ' <sup>*</sup>';
                            }

                            echo "<label for='{$field_name}'>{$label}</label>";

                            // Generate input field (country picker as select)
                            $required_attr = $field_config['required'] ? ' required' : '';
                            if ($field_name === 'org_country') {
                                $country_options = getLocalizedCountryOptions();
                                echo "<select class='form-select' id='{$field_name}' name='{$field_name}'{$required_attr}>";
                                echo "<option value=''></option>";
                                foreach ($country_options as $country_code => $country_name) {
                                    echo "<option value='" . htmlspecialchars($country_code, ENT_QUOTES, 'UTF-8') . "'>" .
                                        htmlspecialchars($country_name . " ({$country_code})", ENT_QUOTES, 'UTF-8') . "</option>";
                                }
                                echo "</select>";
                            } else {
                                echo "<input type='{$field_config['type']}' class='form-control' id='{$field_name}' name='{$field_name}'{$required_attr}>";
                            }

                            echo "</div>";
                        }
                        ?>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-success"><?php echo htmlspecialchars(t('manage.orgs.add.create_submit'), ENT_QUOTES, 'UTF-8'); ?></button>
                            <a href="<?php echo htmlspecialchars(getBaseUrl() . 'manage/organizations/', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('manage.common.cancel'), ENT_QUOTES, 'UTF-8'); ?></a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(getAssetBase(), ENT_QUOTES, 'UTF-8'); ?>js/website-field.min.js"></script>
<?php
renderFooter();
?>
