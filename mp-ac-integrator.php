<?php
/**
 * Plugin Name: MemberPress ActiveCampaign Integration
 * Plugin URI: https://christianwedel.de
 * Description: Automatische Weiterleitung von MemberPress Registrierungen zu ActiveCampaign mit URL-basierten Tags
 * Version: 1.0.0
 * Author: Christian Wedel
 * Author URI: https://christianwedel.de
 * License: GPL v2 or later
 * Text Domain: mepr-ac-integration
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberPress_ActiveCampaign_Integration {

    private static $instance = null;
    private $option_name = 'mepr_ac_settings';
    private $api_url = '';
    private $api_key = '';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_settings();
        $this->init_hooks();
    }

    private function load_settings() {
        $settings = get_option($this->option_name, array());
        $this->api_url = isset($settings['api_url']) ? sanitize_text_field($settings['api_url']) : '';
        $this->api_key = isset($settings['api_key']) ? sanitize_text_field($settings['api_key']) : '';
    }

    private function init_hooks() {
        // Admin Menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // AJAX Handlers
        add_action('wp_ajax_mepr_ac_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_mepr_ac_send_test', array($this, 'ajax_send_test'));

        // Session starten für Tag-Speicherung
        add_action('init', array($this, 'maybe_start_session'));

        // Tags beim Seitenaufruf speichern
        add_action('template_redirect', array($this, 'capture_tags'));

        // MemberPress Hook
        if ($this->is_configured()) {
            add_action('mepr-signup', array($this, 'handle_signup'), 10, 1);
        }

        // Admin Notices
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    public function maybe_start_session() {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
    }

    public function capture_tags() {
        // Nur auf Frontend-Seiten ausführen
        if (is_admin()) {
            return;
        }

        // Tags aus der aktuellen URL extrahieren
        $tags = $this->extract_tags_from_current_url();

        // Wenn Tags gefunden wurden, in Session speichern
        if (!empty($tags)) {
            $_SESSION['mepr_ac_tags'] = $tags;
            $_SESSION['mepr_ac_tags_timestamp'] = time();

            // Debug Logging
            $this->log_error('Tags captured and stored in session: ' . implode(', ', $tags));
        }
    }

    public function add_admin_menu() {
        add_options_page(
            __('MemberPress ActiveCampaign', 'mepr-ac-integration'),
            __('MemberPress AC', 'mepr-ac-integration'),
            'manage_options',
            'mepr-ac-integration',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting(
            'mepr_ac_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
    }

    public function sanitize_settings($input) {
        $sanitized = array();

        if (isset($input['api_url'])) {
            $sanitized['api_url'] = esc_url_raw(rtrim($input['api_url'], '/'));
        }

        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }

        if (isset($input['enable_page_slug'])) {
            $sanitized['enable_page_slug'] = (bool) $input['enable_page_slug'];
        }

        if (isset($input['enable_url_param'])) {
            $sanitized['enable_url_param'] = (bool) $input['enable_url_param'];
        }

        if (isset($input['url_param_name'])) {
            $sanitized['url_param_name'] = sanitize_key($input['url_param_name']);
        }

        if (isset($input['page_slug_prefix'])) {
            $sanitized['page_slug_prefix'] = sanitize_key($input['page_slug_prefix']);
        }

        if (isset($input['url_param_prefix'])) {
            $sanitized['url_param_prefix'] = sanitize_key($input['url_param_prefix']);
        }

        if (isset($input['require_url_param'])) {
            $sanitized['require_url_param'] = (bool) $input['require_url_param'];
        }

        return $sanitized;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option($this->option_name, array());
        $api_url = isset($settings['api_url']) ? $settings['api_url'] : '';
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $enable_page_slug = isset($settings['enable_page_slug']) ? $settings['enable_page_slug'] : true;
        $enable_url_param = isset($settings['enable_url_param']) ? $settings['enable_url_param'] : true;
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';
        $page_slug_prefix = isset($settings['page_slug_prefix']) ? $settings['page_slug_prefix'] : '';
        $url_param_prefix = isset($settings['url_param_prefix']) ? $settings['url_param_prefix'] : '';
        $require_url_param = isset($settings['require_url_param']) ? $settings['require_url_param'] : false;

        ?>
        <div class="wrap mepr-ac-settings">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (!$this->check_memberpress_active()): ?>
                <div class="mepr-ac-notice mepr-ac-notice-error">
                    <p><strong><?php _e('MemberPress ist nicht aktiv!', 'mepr-ac-integration'); ?></strong></p>
                    <p><?php _e('Dieses Plugin benötigt MemberPress um zu funktionieren.', 'mepr-ac-integration'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" id="mepr-ac-settings-form" class="mepr-ac-form">
                <?php
                settings_fields('mepr_ac_settings_group');
                ?>

                <div class="mepr-ac-card">
                    <h2 class="mepr-ac-card-title"><?php _e('ActiveCampaign API Einstellungen', 'mepr-ac-integration'); ?></h2>
                <table class="form-table mepr-ac-table">

                    <tr>
                        <th scope="row">
                            <label for="api_url"><?php _e('API URL', 'mepr-ac-integration'); ?></label>
                        </th>
                        <td>
                            <input type="url"
                                   id="api_url"
                                   name="<?php echo $this->option_name; ?>[api_url]"
                                   value="<?php echo esc_attr($api_url); ?>"
                                   class="regular-text"
                                   placeholder="https://DEIN-ACCOUNT.api-us1.com">
                            <p class="description">
                                <?php _e('Deine ActiveCampaign API URL. Zu finden unter: Settings → Developer → API Access', 'mepr-ac-integration'); ?><br>
                                <?php _e('Beispiel: https://deinaccount.api-us1.com', 'mepr-ac-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="api_key"><?php _e('API Key', 'mepr-ac-integration'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="api_key"
                                   name="<?php echo $this->option_name; ?>[api_key]"
                                   value="<?php echo esc_attr($api_key); ?>"
                                   class="regular-text"
                                   placeholder="<?php _e('Dein API Key', 'mepr-ac-integration'); ?>">
                            <p class="description">
                                <?php _e('Dein ActiveCampaign API Key. Zu finden unter: Settings → Developer → API Access', 'mepr-ac-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" id="test-connection" class="button button-secondary">
                                <?php _e('API Verbindung testen', 'mepr-ac-integration'); ?>
                            </button>
                            <span id="connection-result" style="margin-left: 10px;"></span>
                        </td>
                    </tr>
                </table>
                </div>

                <div class="mepr-ac-card">
                    <h2 class="mepr-ac-card-title"><?php _e('Tag Einstellungen', 'mepr-ac-integration'); ?></h2>
                <table class="form-table mepr-ac-table">

                    <tr>
                        <th scope="row">
                            <label for="enable_page_slug">
                                <?php _e('Page Slug als Tag', 'mepr-ac-integration'); ?>
                            </label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="enable_page_slug"
                                       name="<?php echo $this->option_name; ?>[enable_page_slug]"
                                       value="1"
                                    <?php checked($enable_page_slug, true); ?>>
                                <?php _e('Aktiviert', 'mepr-ac-integration'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Der letzte Teil der URL wird als Tag verwendet.', 'mepr-ac-integration'); ?><br>
                                <strong><?php _e('Beispiel:', 'mepr-ac-integration'); ?></strong>
                                <code>example.com/membership/premium/</code> → Tag: <code>premium</code>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="page_slug_prefix">
                                <?php _e('Page Slug Prefix', 'mepr-ac-integration'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="page_slug_prefix"
                                   name="<?php echo $this->option_name; ?>[page_slug_prefix]"
                                   value="<?php echo esc_attr($page_slug_prefix); ?>"
                                   class="regular-text"
                                   placeholder="<?php _e('z.B. page', 'mepr-ac-integration'); ?>">
                            <p class="description">
                                <?php _e('Optional: Prefix für Page Slug Tags (z.B. "page" → "page-premium").', 'mepr-ac-integration'); ?><br>
                                <?php _e('Leer lassen für kein Prefix.', 'mepr-ac-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="enable_url_param">
                                <?php _e('URL Parameter als Tag', 'mepr-ac-integration'); ?>
                            </label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="enable_url_param"
                                       name="<?php echo $this->option_name; ?>[enable_url_param]"
                                       value="1"
                                    <?php checked($enable_url_param, true); ?>>
                                <?php _e('Aktiviert', 'mepr-ac-integration'); ?>
                            </label>
                            <p class="description">
                                <?php _e('URL Parameter wird als zusätzliches Tag verwendet.', 'mepr-ac-integration'); ?><br>
                                <strong><?php _e('Beispiel:', 'mepr-ac-integration'); ?></strong>
                                <code>example.com/premium/?source=facebook</code> → Tag: <code>facebook</code>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="url_param_name">
                                <?php _e('Parameter Name', 'mepr-ac-integration'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="url_param_name"
                                   name="<?php echo $this->option_name; ?>[url_param_name]"
                                   value="<?php echo esc_attr($url_param_name); ?>"
                                   class="regular-text"
                                   placeholder="source">
                            <p class="description">
                                <?php _e('Name des URL Parameters (z.B. "source", "utm_source", "campaign").', 'mepr-ac-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="url_param_prefix">
                                <?php _e('URL Parameter Prefix', 'mepr-ac-integration'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="url_param_prefix"
                                   name="<?php echo $this->option_name; ?>[url_param_prefix]"
                                   value="<?php echo esc_attr($url_param_prefix); ?>"
                                   class="regular-text"
                                   placeholder="<?php _e('z.B. source', 'mepr-ac-integration'); ?>">
                            <p class="description">
                                <?php _e('Optional: Prefix für URL Parameter Tags (z.B. "source" → "source-facebook").', 'mepr-ac-integration'); ?><br>
                                <?php _e('Leer lassen für kein Prefix.', 'mepr-ac-integration'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr id="require_param_row" style="display: none;">
                        <th scope="row">
                            <label for="require_url_param">
                                <?php _e('Parameter erforderlich', 'mepr-ac-integration'); ?>
                            </label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="require_url_param"
                                       name="<?php echo $this->option_name; ?>[require_url_param]"
                                       value="1"
                                    <?php checked($require_url_param, true); ?>>
                                <?php _e('Aktiviert', 'mepr-ac-integration'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Kein Tracking durchführen, wenn kein URL Parameter vorhanden ist.', 'mepr-ac-integration'); ?><br>
                                <?php _e('Diese Option ist nur aktiv, wenn Page Slug Tracking deaktiviert und URL Parameter Tracking aktiviert ist.', 'mepr-ac-integration'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                </div>

                <?php submit_button(__('Einstellungen speichern', 'mepr-ac-integration'), 'primary mepr-ac-button-primary'); ?>
            </form>

            <div class="mepr-ac-divider"></div>

            <div class="mepr-ac-card">
                <h2 class="mepr-ac-card-title"><?php _e('Test Funktion', 'mepr-ac-integration'); ?></h2>
                <p class="mepr-ac-description"><?php _e('Sende eine Test-E-Mail mit einem Tag zu ActiveCampaign um die Integration zu testen.', 'mepr-ac-integration'); ?></p>

            <table class="form-table mepr-ac-table">
                <tr>
                    <th scope="row">
                        <label for="test_email"><?php _e('Test E-Mail', 'mepr-ac-integration'); ?></label>
                    </th>
                    <td>
                        <input type="email"
                               id="test_email"
                               class="regular-text"
                               placeholder="test@example.com">
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="test_tag"><?php _e('Test Tag', 'mepr-ac-integration'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="test_tag"
                               class="regular-text"
                               placeholder="test-tag">
                    </td>
                </tr>

                <tr>
                    <th scope="row"></th>
                    <td>
                        <button type="button" id="send-test" class="button button-secondary">
                            <?php _e('Test senden', 'mepr-ac-integration'); ?>
                        </button>
                        <span id="test-result" style="margin-left: 10px;"></span>
                    </td>
                </tr>
            </table>
            </div>

            <div class="mepr-ac-divider"></div>

            <div class="mepr-ac-card">
            <h2 class="mepr-ac-card-title"><?php _e('Wie es funktioniert', 'mepr-ac-integration'); ?></h2>
            <ol>
                <li><?php _e('Wenn sich jemand über ein MemberPress Registrierungsformular anmeldet, wird die E-Mail automatisch zu ActiveCampaign gesendet.', 'mepr-ac-integration'); ?></li>
                <li><?php _e('Der Contact wird in ActiveCampaign erstellt oder aktualisiert.', 'mepr-ac-integration'); ?></li>
                <li><?php _e('Je nach Einstellung werden automatisch Tags vergeben:', 'mepr-ac-integration'); ?>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><strong><?php _e('Page Slug:', 'mepr-ac-integration'); ?></strong> <?php _e('Der letzte Teil der URL-Pfad', 'mepr-ac-integration'); ?></li>
                        <li><strong><?php _e('URL Parameter:', 'mepr-ac-integration'); ?></strong> <?php _e('Wert aus dem URL Parameter (z.B. ?source=facebook)', 'mepr-ac-integration'); ?></li>
                    </ul>
                </li>
                <li><?php _e('Tags werden automatisch in ActiveCampaign erstellt, falls sie noch nicht existieren.', 'mepr-ac-integration'); ?></li>
            </ol>

            <h3><?php _e('Beispiele', 'mepr-ac-integration'); ?></h3>
            <table class="wp-list-table widefat striped">
                <thead>
                <tr>
                    <th><?php _e('URL', 'mepr-ac-integration'); ?></th>
                    <th><?php _e('Tags (ohne Prefix)', 'mepr-ac-integration'); ?></th>
                    <th><?php _e('Tags (mit Prefix)', 'mepr-ac-integration'); ?></th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td><code>example.com/premium/</code></td>
                    <td><code>premium</code></td>
                    <td><code>page-premium</code></td>
                </tr>
                <tr>
                    <td><code>example.com/basic/?source=facebook</code></td>
                    <td><code>basic, facebook</code></td>
                    <td><code>page-basic, source-facebook</code></td>
                </tr>
                <tr>
                    <td><code>example.com/membership/?source=instagram</code></td>
                    <td><code>membership, instagram</code></td>
                    <td><code>page-membership, source-instagram</code></td>
                </tr>
                </tbody>
            </table>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Bedingte Anzeige der "Parameter erforderlich" Checkbox
                function toggleRequireParamRow() {
                    var pageSlugEnabled = $('#enable_page_slug').is(':checked');
                    var urlParamEnabled = $('#enable_url_param').is(':checked');

                    if (!pageSlugEnabled && urlParamEnabled) {
                        $('#require_param_row').show();
                    } else {
                        $('#require_param_row').hide();
                    }
                }

                // Initial anzeigen/verstecken
                toggleRequireParamRow();

                // Bei Änderungen aktualisieren
                $('#enable_page_slug, #enable_url_param').on('change', function() {
                    toggleRequireParamRow();
                });

                // Test Connection
                $('#test-connection').on('click', function() {
                    var button = $(this);
                    var result = $('#connection-result');

                    button.prop('disabled', true).text('<?php _e('Teste...', 'mepr-ac-integration'); ?>');
                    result.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mepr_ac_test_connection',
                            api_url: $('#api_url').val(),
                            api_key: $('#api_key').val(),
                            nonce: '<?php echo wp_create_nonce('mepr_ac_test_connection'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                            } else {
                                result.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                            }
                        },
                        error: function() {
                            result.html('<span style="color: red;">✗ <?php _e('Fehler beim Testen', 'mepr-ac-integration'); ?></span>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('<?php _e('API Verbindung testen', 'mepr-ac-integration'); ?>');
                        }
                    });
                });

                // Send Test
                $('#send-test').on('click', function() {
                    var button = $(this);
                    var result = $('#test-result');
                    var email = $('#test_email').val();
                    var tag = $('#test_tag').val();

                    if (!email || !tag) {
                        result.html('<span style="color: red;">✗ <?php _e('Bitte E-Mail und Tag eingeben', 'mepr-ac-integration'); ?></span>');
                        return;
                    }

                    button.prop('disabled', true).text('<?php _e('Sende...', 'mepr-ac-integration'); ?>');
                    result.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mepr_ac_send_test',
                            email: email,
                            tag: tag,
                            nonce: '<?php echo wp_create_nonce('mepr_ac_send_test'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                            } else {
                                result.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                            }
                        },
                        error: function() {
                            result.html('<span style="color: red;">✗ <?php _e('Fehler beim Senden', 'mepr-ac-integration'); ?></span>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('<?php _e('Test senden', 'mepr-ac-integration'); ?>');
                        }
                    });
                });
            });
        </script>

        <style>
            /* Material Design M3 Styles - Scoped to .mepr-ac-settings */

            /* ========== Color Palette ========== */
            .mepr-ac-settings {
                --mepr-primary: #F3A400;
                --mepr-primary-dark: #D99000;
                --mepr-primary-light: #FFB825;
                --mepr-on-primary: #FFFFFF;
                --mepr-surface: #FFFFFF;
                --mepr-surface-variant: #F5F5F5;
                --mepr-outline: #E0E0E0;
                --mepr-outline-variant: #C9C9C9;
                --mepr-on-surface: #1C1B1F;
                --mepr-on-surface-variant: #49454F;
                --mepr-error: #BA1A1A;
                --mepr-success: #0F9D58;
                --mepr-shadow: rgba(0, 0, 0, 0.12);
                --mepr-elevation-1: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
                --mepr-elevation-2: 0 2px 4px 0 rgba(0, 0, 0, 0.08);
                --mepr-elevation-3: 0 4px 8px 0 rgba(0, 0, 0, 0.12);
            }

            /* ========== Typography ========== */
            .mepr-ac-settings {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;
                color: var(--mepr-on-surface);
                line-height: 1.6;
            }

            .mepr-ac-settings h1 {
                font-size: 32px;
                font-weight: 400;
                letter-spacing: 0;
                margin: 0 0 24px 0;
                color: var(--mepr-on-surface);
            }

            /* ========== Cards ========== */
            .mepr-ac-settings .mepr-ac-card {
                background: var(--mepr-surface);
                border-radius: 12px;
                box-shadow: var(--mepr-elevation-1);
                padding: 24px;
                margin-bottom: 16px;
                transition: box-shadow 0.28s cubic-bezier(0.4, 0, 0.2, 1);
                border: 1px solid var(--mepr-outline);
            }

            .mepr-ac-settings .mepr-ac-card:hover {
                box-shadow: var(--mepr-elevation-2);
            }

            .mepr-ac-settings .mepr-ac-card-title {
                font-size: 22px;
                font-weight: 500;
                letter-spacing: 0;
                margin: 0 0 16px 0;
                color: var(--mepr-on-surface);
                padding: 0;
                border: none;
            }

            .mepr-ac-settings .mepr-ac-description {
                font-size: 14px;
                color: var(--mepr-on-surface-variant);
                margin: 0 0 16px 0;
            }

            /* ========== Form Tables ========== */
            .mepr-ac-settings .mepr-ac-table {
                margin: 0;
            }

            .mepr-ac-settings .mepr-ac-table tr {
                border-bottom: 1px solid var(--mepr-outline);
            }

            .mepr-ac-settings .mepr-ac-table tr:last-child {
                border-bottom: none;
            }

            .mepr-ac-settings .mepr-ac-table th {
                padding: 16px 16px 16px 0;
                font-weight: 500;
                font-size: 14px;
                color: var(--mepr-on-surface);
                width: 200px;
                vertical-align: top;
            }

            .mepr-ac-settings .mepr-ac-table td {
                padding: 16px 0;
            }

            .mepr-ac-settings .mepr-ac-table label {
                font-size: 14px;
                font-weight: 500;
                color: var(--mepr-on-surface);
            }

            .mepr-ac-settings .mepr-ac-table .description {
                font-size: 12px;
                color: var(--mepr-on-surface-variant);
                margin: 8px 0 0 0;
                line-height: 1.5;
            }

            /* ========== Input Fields ========== */
            .mepr-ac-settings input[type="text"],
            .mepr-ac-settings input[type="email"],
            .mepr-ac-settings input[type="url"] {
                border: 1px solid var(--mepr-outline);
                border-radius: 4px;
                padding: 12px 16px;
                font-size: 14px;
                line-height: 1.5;
                background: var(--mepr-surface);
                color: var(--mepr-on-surface);
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: none;
            }

            .mepr-ac-settings input[type="text"]:focus,
            .mepr-ac-settings input[type="email"]:focus,
            .mepr-ac-settings input[type="url"]:focus {
                outline: none;
                border-color: var(--mepr-primary);
                box-shadow: 0 0 0 2px rgba(243, 164, 0, 0.1);
            }

            .mepr-ac-settings input[type="text"]::placeholder,
            .mepr-ac-settings input[type="email"]::placeholder,
            .mepr-ac-settings input[type="url"]::placeholder {
                color: var(--mepr-on-surface-variant);
                opacity: 0.6;
            }

            /* ========== Checkboxes ========== */
            .mepr-ac-settings input[type="checkbox"] {
                width: 18px;
                height: 18px;
                border: 2px solid var(--mepr-outline-variant);
                border-radius: 2px;
                margin-right: 8px;
                cursor: pointer;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .mepr-ac-settings input[type="checkbox"]:checked {
                background-color: var(--mepr-primary);
                border-color: var(--mepr-primary);
            }

            .mepr-ac-settings input[type="checkbox"]:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(243, 164, 0, 0.2);
            }

            /* ========== Buttons ========== */
            .mepr-ac-settings .button,
            .mepr-ac-settings button {
                border-radius: 20px;
                padding: 10px 24px;
                font-size: 14px;
                font-weight: 500;
                letter-spacing: 0.1px;
                text-transform: none;
                transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: none;
                border: none;
                cursor: pointer;
                height: auto;
                line-height: 1.5;
            }

            /* Primary Button Override */
            .mepr-ac-settings .button-primary,
            .mepr-ac-settings .mepr-ac-button-primary,
            .mepr-ac-settings #submit {
                background: var(--mepr-primary) !important;
                color: var(--mepr-on-primary) !important;
                border: none !important;
                box-shadow: var(--mepr-elevation-1) !important;
                text-shadow: none !important;
            }

            .mepr-ac-settings .button-primary:hover,
            .mepr-ac-settings .mepr-ac-button-primary:hover,
            .mepr-ac-settings #submit:hover {
                background: var(--mepr-primary-dark) !important;
                box-shadow: var(--mepr-elevation-2) !important;
                transform: translateY(-1px);
            }

            .mepr-ac-settings .button-primary:active,
            .mepr-ac-settings .mepr-ac-button-primary:active,
            .mepr-ac-settings #submit:active {
                background: var(--mepr-primary-dark) !important;
                box-shadow: var(--mepr-elevation-1) !important;
                transform: translateY(0);
            }

            .mepr-ac-settings .button-primary:focus,
            .mepr-ac-settings .mepr-ac-button-primary:focus,
            .mepr-ac-settings #submit:focus {
                box-shadow: 0 0 0 3px rgba(243, 164, 0, 0.3) !important;
            }

            /* Secondary Button */
            .mepr-ac-settings .button-secondary {
                background: var(--mepr-surface) !important;
                color: var(--mepr-primary) !important;
                border: 1px solid var(--mepr-outline) !important;
                box-shadow: none !important;
            }

            .mepr-ac-settings .button-secondary:hover {
                background: var(--mepr-surface-variant) !important;
                border-color: var(--mepr-primary) !important;
                box-shadow: var(--mepr-elevation-1) !important;
            }

            .mepr-ac-settings .button-secondary:active {
                background: var(--mepr-surface-variant) !important;
            }

            .mepr-ac-settings .button-secondary:focus {
                box-shadow: 0 0 0 3px rgba(243, 164, 0, 0.2) !important;
            }

            .mepr-ac-settings .button:disabled,
            .mepr-ac-settings button:disabled {
                opacity: 0.38;
                cursor: not-allowed;
            }

            /* ========== Notices ========== */
            .mepr-ac-settings .mepr-ac-notice {
                border-radius: 8px;
                padding: 16px;
                margin: 16px 0;
                border-left: 4px solid;
            }

            .mepr-ac-settings .mepr-ac-notice-error {
                background: #FEF1F1;
                border-left-color: var(--mepr-error);
                color: #5F2120;
            }

            .mepr-ac-settings .mepr-ac-notice-error p {
                margin: 0;
            }

            /* ========== Divider ========== */
            .mepr-ac-settings .mepr-ac-divider {
                height: 1px;
                background: var(--mepr-outline);
                margin: 32px 0;
                border: none;
            }

            /* ========== Status Messages ========== */
            .mepr-ac-settings #connection-result,
            .mepr-ac-settings #test-result {
                font-weight: 500;
                font-size: 14px;
                display: inline-block;
                padding: 6px 12px;
                border-radius: 16px;
                transition: all 0.2s;
            }

            .mepr-ac-settings #connection-result span[style*="green"],
            .mepr-ac-settings #test-result span[style*="green"] {
                color: var(--mepr-success) !important;
                background: rgba(15, 157, 88, 0.1);
                padding: 6px 12px;
                border-radius: 16px;
            }

            .mepr-ac-settings #connection-result span[style*="red"],
            .mepr-ac-settings #test-result span[style*="red"] {
                color: var(--mepr-error) !important;
                background: rgba(186, 26, 26, 0.1);
                padding: 6px 12px;
                border-radius: 16px;
            }

            /* ========== Lists ========== */
            .mepr-ac-settings ol,
            .mepr-ac-settings ul {
                margin: 16px 0;
                padding-left: 24px;
            }

            .mepr-ac-settings ol li,
            .mepr-ac-settings ul li {
                margin: 8px 0;
                color: var(--mepr-on-surface);
                line-height: 1.6;
            }

            .mepr-ac-settings ul ul {
                margin-top: 8px;
            }

            /* ========== Tables (Example Table) ========== */
            .mepr-ac-settings .wp-list-table {
                border-radius: 8px;
                overflow: hidden;
                border: 1px solid var(--mepr-outline);
                margin-top: 16px;
            }

            .mepr-ac-settings .wp-list-table thead th {
                background: var(--mepr-surface-variant);
                font-weight: 500;
                font-size: 14px;
                padding: 12px 16px;
                border-bottom: 1px solid var(--mepr-outline);
            }

            .mepr-ac-settings .wp-list-table tbody td {
                padding: 12px 16px;
                font-size: 14px;
                border-bottom: 1px solid var(--mepr-outline);
            }

            .mepr-ac-settings .wp-list-table tbody tr:last-child td {
                border-bottom: none;
            }

            .mepr-ac-settings .wp-list-table.striped > tbody > tr:nth-child(odd) {
                background: var(--mepr-surface-variant);
            }

            .mepr-ac-settings .wp-list-table code {
                background: rgba(243, 164, 0, 0.1);
                color: var(--mepr-primary-dark);
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 13px;
            }

            /* ========== Code Elements ========== */
            .mepr-ac-settings code {
                background: rgba(243, 164, 0, 0.1);
                color: var(--mepr-primary-dark);
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 13px;
                font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
            }

            /* ========== Transitions & Animations ========== */
            .mepr-ac-settings * {
                transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* ========== Responsive ========== */
            @media (max-width: 782px) {
                .mepr-ac-settings .mepr-ac-card {
                    padding: 16px;
                }

                .mepr-ac-settings .mepr-ac-table th,
                .mepr-ac-settings .mepr-ac-table td {
                    display: block;
                    width: 100%;
                    padding: 8px 0;
                }

                .mepr-ac-settings .mepr-ac-table th {
                    padding-bottom: 4px;
                }

                .mepr-ac-settings .button,
                .mepr-ac-settings button {
                    width: 100%;
                    margin-bottom: 8px;
                }
            }
        </style>
        <?php
    }

    public function ajax_test_connection() {
        check_ajax_referer('mepr_ac_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Keine Berechtigung', 'mepr-ac-integration')));
        }

        $api_url = isset($_POST['api_url']) ? esc_url_raw($_POST['api_url']) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error(array('message' => __('API URL und Key müssen ausgefüllt sein', 'mepr-ac-integration')));
        }

        $response = wp_remote_get($api_url . '/api/3/users/me', array(
            'headers' => array(
                'Api-Token' => $api_key
            ),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => sprintf(__('Verbindungsfehler: %s', 'mepr-ac-integration'), $response->get_error_message())
            ));
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $username = isset($body['user']['username']) ? $body['user']['username'] : __('Unbekannt', 'mepr-ac-integration');
            wp_send_json_success(array(
                'message' => sprintf(__('Verbindung erfolgreich! Angemeldet als: %s', 'mepr-ac-integration'), $username)
            ));
        } elseif ($code === 403) {
            wp_send_json_error(array('message' => __('API Key ungültig', 'mepr-ac-integration')));
        } else {
            wp_send_json_error(array(
                'message' => sprintf(__('Fehler: HTTP %d', 'mepr-ac-integration'), $code)
            ));
        }
    }

    public function ajax_send_test() {
        check_ajax_referer('mepr_ac_send_test', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Keine Berechtigung', 'mepr-ac-integration')));
        }

        if (!$this->is_configured()) {
            wp_send_json_error(array('message' => __('API nicht konfiguriert. Bitte speichere zuerst die Einstellungen.', 'mepr-ac-integration')));
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $tag = isset($_POST['tag']) ? sanitize_text_field($_POST['tag']) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => __('Ungültige E-Mail Adresse', 'mepr-ac-integration')));
        }

        if (empty($tag)) {
            wp_send_json_error(array('message' => __('Tag darf nicht leer sein', 'mepr-ac-integration')));
        }

        $result = $this->send_to_activecampaign($email, '', '', array($tag));

        if ($result['success']) {
            wp_send_json_success(array('message' => __('Test erfolgreich gesendet! Contact wurde erstellt/aktualisiert und Tag zugewiesen.', 'mepr-ac-integration')));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    public function handle_signup($event) {
        try {
            $user = $event->user();

            if (!$user || !isset($user->user_email)) {
                $this->log_error('Invalid user object in signup event');
                return;
            }

            $email = sanitize_email($user->user_email);
            $first_name = isset($user->first_name) ? sanitize_text_field($user->first_name) : '';
            $last_name = isset($user->last_name) ? sanitize_text_field($user->last_name) : '';

            // Priorität 1: Tags aus dem POST mepr_current_url field extrahieren
            $tags = array();
            if (isset($_POST['mepr_current_url']) && !empty($_POST['mepr_current_url'])) {
                $current_url = esc_url_raw($_POST['mepr_current_url']);
                $tags = $this->extract_tags_from_url($current_url);
                if (!empty($tags)) {
                    $this->log_error('Using tags from POST mepr_current_url: ' . implode(', ', $tags) . ' (URL: ' . $current_url . ')');
                }
            }

            // Priorität 2: Versuche Tags aus der Session zu laden
            if (empty($tags)) {
                $tags = $this->get_stored_tags();
                if (!empty($tags)) {
                    $this->log_error('Using tags from session: ' . implode(', ', $tags));
                }
            }

            // Priorität 3: Fallback - Versuche Tags aus dem Referer zu extrahieren (alte Methode)
            if (empty($tags)) {
                $this->log_error('No tags in POST or session, falling back to referer extraction');
                $tags = $this->extract_tags();
            }

            // Prüfe, ob Tracking übersprungen werden soll
            if ($this->should_skip_tracking($tags)) {
                $this->log_error('Tracking skipped: require_url_param is enabled but no URL parameter found');
                $this->clear_stored_tags();
                return;
            }

            $this->send_to_activecampaign($email, $first_name, $last_name, $tags);

            // Session Tags löschen nach erfolgreicher Verwendung
            $this->clear_stored_tags();

        } catch (Exception $e) {
            $this->log_error('Exception in handle_signup: ' . $e->getMessage());
        }
    }

    private function extract_tags() {
        $tags = array();
        $settings = get_option($this->option_name, array());

        $enable_page_slug = isset($settings['enable_page_slug']) ? $settings['enable_page_slug'] : true;
        $enable_url_param = isset($settings['enable_url_param']) ? $settings['enable_url_param'] : true;
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';
        $page_slug_prefix = isset($settings['page_slug_prefix']) ? $settings['page_slug_prefix'] : '';
        $url_param_prefix = isset($settings['url_param_prefix']) ? $settings['url_param_prefix'] : '';

        $referer = wp_get_referer();

        if (empty($referer)) {
            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        }

        if (empty($referer)) {
            return $tags;
        }

        // Page Slug extrahieren
        if ($enable_page_slug) {
            $path = parse_url($referer, PHP_URL_PATH);
            if ($path) {
                $url_parts = explode('/', rtrim($path, '/'));
                $page_slug = end($url_parts);

                if (!empty($page_slug) && $page_slug !== '') {
                    $tag = $page_slug;
                    if (!empty($page_slug_prefix)) {
                        $tag = $page_slug_prefix . '-' . $tag;
                    }
                    $tags[] = sanitize_text_field($tag);
                }
            }
        }

        // URL Parameter extrahieren
        if ($enable_url_param && !empty($url_param_name)) {
            $query = parse_url($referer, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $params);
                if (isset($params[$url_param_name]) && !empty($params[$url_param_name])) {
                    $param_value = $params[$url_param_name];
                    $tag = $param_value;
                    if (!empty($url_param_prefix)) {
                        $tag = $url_param_prefix . '-' . $tag;
                    }
                    $tags[] = sanitize_text_field($tag);
                }
            }
        }

        return array_unique(array_filter($tags));
    }

    private function extract_tags_from_current_url() {
        $tags = array();
        $settings = get_option($this->option_name, array());

        $enable_page_slug = isset($settings['enable_page_slug']) ? $settings['enable_page_slug'] : true;
        $enable_url_param = isset($settings['enable_url_param']) ? $settings['enable_url_param'] : true;
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';
        $page_slug_prefix = isset($settings['page_slug_prefix']) ? $settings['page_slug_prefix'] : '';
        $url_param_prefix = isset($settings['url_param_prefix']) ? $settings['url_param_prefix'] : '';

        // Aktuelle URL verwenden statt Referer
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        // Page Slug extrahieren
        if ($enable_page_slug) {
            $path = parse_url($current_url, PHP_URL_PATH);
            if ($path) {
                $url_parts = explode('/', rtrim($path, '/'));
                $page_slug = end($url_parts);

                if (!empty($page_slug) && $page_slug !== '') {
                    $tag = $page_slug;
                    if (!empty($page_slug_prefix)) {
                        $tag = $page_slug_prefix . '-' . $tag;
                    }
                    $tags[] = sanitize_text_field($tag);
                }
            }
        }

        // URL Parameter extrahieren - direkt aus $_GET
        if ($enable_url_param && !empty($url_param_name)) {
            if (isset($_GET[$url_param_name]) && !empty($_GET[$url_param_name])) {
                $param_value = $_GET[$url_param_name];
                $tag = $param_value;
                if (!empty($url_param_prefix)) {
                    $tag = $url_param_prefix . '-' . $tag;
                }
                $tags[] = sanitize_text_field($tag);
            }
        }

        return array_unique(array_filter($tags));
    }

    private function extract_tags_from_url($url) {
        $tags = array();
        $settings = get_option($this->option_name, array());

        $enable_page_slug = isset($settings['enable_page_slug']) ? $settings['enable_page_slug'] : true;
        $enable_url_param = isset($settings['enable_url_param']) ? $settings['enable_url_param'] : true;
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';
        $page_slug_prefix = isset($settings['page_slug_prefix']) ? $settings['page_slug_prefix'] : '';
        $url_param_prefix = isset($settings['url_param_prefix']) ? $settings['url_param_prefix'] : '';

        if (empty($url)) {
            return $tags;
        }

        // Page Slug extrahieren
        if ($enable_page_slug) {
            $path = parse_url($url, PHP_URL_PATH);
            if ($path) {
                $url_parts = explode('/', rtrim($path, '/'));
                $page_slug = end($url_parts);

                if (!empty($page_slug) && $page_slug !== '') {
                    $tag = $page_slug;
                    if (!empty($page_slug_prefix)) {
                        $tag = $page_slug_prefix . '-' . $tag;
                    }
                    $tags[] = sanitize_text_field($tag);
                }
            }
        }

        // URL Parameter extrahieren
        if ($enable_url_param && !empty($url_param_name)) {
            $query = parse_url($url, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $params);
                if (isset($params[$url_param_name]) && !empty($params[$url_param_name])) {
                    $param_value = $params[$url_param_name];
                    $tag = $param_value;
                    if (!empty($url_param_prefix)) {
                        $tag = $url_param_prefix . '-' . $tag;
                    }
                    $tags[] = sanitize_text_field($tag);
                }
            }
        }

        return array_unique(array_filter($tags));
    }

    private function get_stored_tags() {
        if (!isset($_SESSION['mepr_ac_tags'])) {
            return array();
        }

        // Tags nur verwenden, wenn sie nicht älter als 1 Stunde sind
        $timestamp = isset($_SESSION['mepr_ac_tags_timestamp']) ? $_SESSION['mepr_ac_tags_timestamp'] : 0;
        if (time() - $timestamp > 3600) {
            $this->log_error('Stored tags expired (older than 1 hour)');
            $this->clear_stored_tags();
            return array();
        }

        return $_SESSION['mepr_ac_tags'];
    }

    private function clear_stored_tags() {
        if (isset($_SESSION['mepr_ac_tags'])) {
            unset($_SESSION['mepr_ac_tags']);
        }
        if (isset($_SESSION['mepr_ac_tags_timestamp'])) {
            unset($_SESSION['mepr_ac_tags_timestamp']);
        }
    }

    private function send_to_activecampaign($email, $first_name = '', $last_name = '', $tags = array()) {
        if (empty($email) || !is_email($email)) {
            return array('success' => false, 'message' => __('Ungültige E-Mail', 'mepr-ac-integration'));
        }

        if (empty($this->api_url) || empty($this->api_key)) {
            return array('success' => false, 'message' => __('API nicht konfiguriert', 'mepr-ac-integration'));
        }

        $contact_data = array(
            'contact' => array(
                'email' => $email
            )
        );

        if (!empty($first_name)) {
            $contact_data['contact']['firstName'] = $first_name;
        }

        if (!empty($last_name)) {
            $contact_data['contact']['lastName'] = $last_name;
        }

        // Contact erstellen/aktualisieren
        $response = wp_remote_post($this->api_url . '/api/3/contact/sync', array(
            'headers' => array(
                'Api-Token' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($contact_data),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            $this->log_error('API Error: ' . $response->get_error_message());
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200 && $code !== 201) {
            $body = wp_remote_retrieve_body($response);
            $this->log_error('API returned code ' . $code . ': ' . $body);
            return array('success' => false, 'message' => sprintf(__('API Fehler: HTTP %d', 'mepr-ac-integration'), $code));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['contact']['id'])) {
            $this->log_error('No contact ID in response: ' . print_r($body, true));
            return array('success' => false, 'message' => __('Keine Contact ID erhalten', 'mepr-ac-integration'));
        }

        $contact_id = $body['contact']['id'];

        // Tags zuweisen
        if (!empty($tags)) {
            foreach ($tags as $tag_name) {
                $this->assign_tag($contact_id, $tag_name);
            }
        }

        return array('success' => true, 'contact_id' => $contact_id);
    }

    private function assign_tag($contact_id, $tag_name) {
        if (empty($tag_name)) {
            return false;
        }

        // Tag erstellen oder finden
        $tag_data = array(
            'tag' => array(
                'tag' => $tag_name,
                'tagType' => 'contact'
            )
        );

        $tag_response = wp_remote_post($this->api_url . '/api/3/tags', array(
            'headers' => array(
                'Api-Token' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($tag_data),
            'timeout' => 15
        ));

        if (is_wp_error($tag_response)) {
            $this->log_error('Tag creation error: ' . $tag_response->get_error_message());
            return false;
        }

        $tag_body = json_decode(wp_remote_retrieve_body($tag_response), true);

        // Tag ID aus Response oder Fehler extrahieren
        $tag_id = null;

        if (isset($tag_body['tag']['id'])) {
            $tag_id = $tag_body['tag']['id'];
        } elseif (isset($tag_body['errors'])) {
            // Wenn Tag bereits existiert, ID aus Fehler extrahieren
            foreach ($tag_body['errors'] as $error) {
                if (isset($error['code']) && $error['code'] === 'duplicate' && isset($error['tag_id'])) {
                    $tag_id = $error['tag_id'];
                    break;
                }
            }
        }

        if (empty($tag_id)) {
            $this->log_error('No tag ID found for: ' . $tag_name);
            return false;
        }

        // Tag dem Contact zuweisen
        $contact_tag_data = array(
            'contactTag' => array(
                'contact' => $contact_id,
                'tag' => $tag_id
            )
        );

        $assign_response = wp_remote_post($this->api_url . '/api/3/contactTags', array(
            'headers' => array(
                'Api-Token' => $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($contact_tag_data),
            'timeout' => 15
        ));

        if (is_wp_error($assign_response)) {
            $this->log_error('Tag assignment error: ' . $assign_response->get_error_message());
            return false;
        }

        $assign_code = wp_remote_retrieve_response_code($assign_response);

        if ($assign_code === 200 || $assign_code === 201) {
            return true;
        }

        // Tag Assignment kann auch 422 zurückgeben wenn bereits zugewiesen
        $assign_body = json_decode(wp_remote_retrieve_body($assign_response), true);
        if (isset($assign_body['errors'])) {
            foreach ($assign_body['errors'] as $error) {
                if (isset($error['code']) && $error['code'] === 'duplicate') {
                    return true; // Tag ist bereits zugewiesen, das ist ok
                }
            }
        }

        $this->log_error('Tag assignment failed with code ' . $assign_code . ': ' . wp_remote_retrieve_body($assign_response));
        return false;
    }

    private function should_skip_tracking($tags) {
        $settings = get_option($this->option_name, array());

        $require_url_param = isset($settings['require_url_param']) ? $settings['require_url_param'] : false;
        $enable_page_slug = isset($settings['enable_page_slug']) ? $settings['enable_page_slug'] : true;
        $enable_url_param = isset($settings['enable_url_param']) ? $settings['enable_url_param'] : true;

        // Nur prüfen, wenn require_url_param aktiv, page_slug deaktiviert und url_param aktiviert ist
        if (!$require_url_param || $enable_page_slug || !$enable_url_param) {
            return false;
        }

        // Prüfe, ob ein URL Parameter Tag vorhanden ist
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';
        $url_param_prefix = isset($settings['url_param_prefix']) ? $settings['url_param_prefix'] : '';

        // Wenn keine Tags vorhanden sind, überspringen
        if (empty($tags)) {
            return true;
        }

        // Prüfe, ob mindestens ein Tag vom URL Parameter stammt
        // Dies ist der Fall, wenn ein Tag mit dem url_param_prefix beginnt oder
        // wenn wir direkt prüfen können, ob der Parameter in der URL war
        $has_url_param_tag = false;

        foreach ($tags as $tag) {
            // Wenn ein Prefix gesetzt ist, prüfe ob das Tag damit beginnt
            if (!empty($url_param_prefix)) {
                if (strpos($tag, $url_param_prefix . '-') === 0) {
                    $has_url_param_tag = true;
                    break;
                }
            } else {
                // Ohne Prefix können wir nicht sicher unterscheiden, also akzeptieren wir jedes Tag
                $has_url_param_tag = true;
                break;
            }
        }

        return !$has_url_param_tag;
    }

    private function is_configured() {
        return !empty($this->api_url) && !empty($this->api_key);
    }

    private function check_memberpress_active() {
        return class_exists('MeprAppCtrl');
    }

    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log('MemberPress ActiveCampaign Integration: ' . $message);
        }
    }

    public function admin_notices() {
        if (!$this->check_memberpress_active()) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php _e('MemberPress ActiveCampaign Integration:', 'mepr-ac-integration'); ?></strong>
                    <?php _e('MemberPress ist nicht installiert oder aktiviert. Das Plugin benötigt MemberPress um zu funktionieren.', 'mepr-ac-integration'); ?>
                </p>
            </div>
            <?php
        }

        if ($this->check_memberpress_active() && !$this->is_configured()) {
            $settings_url = admin_url('options-general.php?page=mepr-ac-integration');
            ?>
            <div class="notice notice-info">
                <p>
                    <strong><?php _e('MemberPress ActiveCampaign Integration:', 'mepr-ac-integration'); ?></strong>
                    <?php printf(
                        __('Bitte konfiguriere die <a href="%s">API Einstellungen</a> um die Integration zu aktivieren.', 'mepr-ac-integration'),
                        esc_url($settings_url)
                    ); ?>
                </p>
            </div>
            <?php
        }
    }
}

// Plugin initialisieren
function mepr_ac_integration_init() {
    return MemberPress_ActiveCampaign_Integration::get_instance();
}

add_action('plugins_loaded', 'mepr_ac_integration_init');