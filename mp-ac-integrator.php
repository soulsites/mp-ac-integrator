<?php
/**
 * Plugin Name: MemberPress ActiveCampaign Integration
 * Plugin URI: https://christianwedel.de
 * Description: Automatische Weiterleitung von MemberPress Registrierungen zu ActiveCampaign mit URL-Parameter-basierten Tags
 * Version: 2.1.0
 * Author: Christian Wedel
 * Author URI: https://christianwedel.de
 * License: GPL v2 or later
 * Text Domain: mepr-ac-integration
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * Changelog:
 * 2.1.0 - Robustheit und Debug-Verbesserungen:
 *       - Debug-Modus für Frontend-Troubleshooting hinzugefügt
 *       - JavaScript robuster gegen Timing-Probleme gemacht
 *       - MutationObserver für dynamisch geladene Formulare
 *       - Form Submit Event Listener als zusätzliche Absicherung
 *       - Verbesserte Tag-Erfassungs-Priorität (HTTP_REFERER höher priorisiert)
 *       - Ausführliches Server-seitiges Logging
 *       - Bessere Kompatibilität mit anderen AC-Integrationen
 * 2.0.0 - Vereinfachung und Fokus auf URL-Parameter:
 *       - Page Slug Tagging entfernt
 *       - Nur noch URL-Parameter-basiertes Tagging (z.B. ?source=messekoeln)
 *       - Tagging erfolgt nur wenn der konfigurierte Parameter in der URL vorhanden ist
 *       - Vereinfachte Settings-Oberfläche
 *       - Aktualisierte Dokumentation
 * 1.1.0 - Robustheitsverbesserungen:
 *       - JavaScript-basierte URL-Erfassung hinzugefügt
 *       - Cookie-Fallback zusätzlich zu Sessions
 *       - Automatisches Einfügen von Hidden Fields in Formulare
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

        // JavaScript für Frontend-Tracking einbinden
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracking_script'));

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

        // Wenn Tags gefunden wurden, sowohl in Session als auch in Cookie speichern
        if (!empty($tags)) {
            // Session speichern
            $_SESSION['mepr_ac_tags'] = $tags;
            $_SESSION['mepr_ac_tags_timestamp'] = time();

            // Cookie speichern (7 Tage gültig)
            $cookie_data = json_encode(array(
                'tags' => $tags,
                'timestamp' => time()
            ));
            setcookie('mepr_ac_tags', $cookie_data, time() + (7 * 24 * 60 * 60), '/');

            // Debug Logging mit URL-Info
            $current_url = $this->get_current_url();
            $this->log_error('Tags captured from URL: ' . $current_url);
            $this->log_error('Extracted tags: ' . implode(', ', $tags));
            $this->log_error('Tags stored in session and cookie');
        }
    }

    public function enqueue_tracking_script() {
        // Nur auf Frontend-Seiten
        if (is_admin()) {
            return;
        }

        // JavaScript inline hinzufügen
        add_action('wp_footer', array($this, 'output_tracking_script'), 5);
    }

    public function output_tracking_script() {
        $settings = get_option($this->option_name, array());
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';
        $debug_mode = isset($settings['debug_mode']) && $settings['debug_mode'] === '1';

        ?>
        <script type="text/javascript">
        (function() {
            var DEBUG = <?php echo $debug_mode ? 'true' : 'false'; ?>;
            var PARAM_NAME = '<?php echo esc_js($url_param_name); ?>';

            function debugLog(message, data) {
                if (DEBUG) {
                    console.log('[MP-AC Debug] ' + message, data || '');
                }
            }

            // Tag-Extraktion clientseitig - nur URL Parameter
            function extractTags() {
                var tags = [];
                var currentUrl = window.location.href;

                // URL Parameter extrahieren
                var urlParams = new URLSearchParams(window.location.search);
                var paramValue = urlParams.get(PARAM_NAME);

                if (paramValue) {
                    var paramTag = paramValue.toLowerCase();
                    tags.push(paramTag);
                    debugLog('Extracted tag from URL parameter "' + PARAM_NAME + '"', paramTag);
                }

                return tags;
            }

            // Tags extrahieren und in Cookie speichern
            var tags = extractTags();
            if (tags.length > 0) {
                var cookieData = JSON.stringify({
                    tags: tags,
                    timestamp: Math.floor(Date.now() / 1000),
                    url: window.location.href
                });

                // Cookie setzen (7 Tage)
                var expires = new Date();
                expires.setTime(expires.getTime() + (7 * 24 * 60 * 60 * 1000));
                document.cookie = 'mepr_ac_tags_js=' + encodeURIComponent(cookieData) +
                                '; expires=' + expires.toUTCString() + '; path=/';

                debugLog('Tags saved to cookie', tags);
            }

            // Hidden Field in MemberPress-Formulare einfügen
            function injectTagsIntoForms() {
                var forms = document.querySelectorAll('form.mepr-signup-form, form.mepr_form, form[action*="mepr"]');

                debugLog('Found ' + forms.length + ' MemberPress forms');

                forms.forEach(function(form) {
                    // Prüfen, ob bereits ein Hidden Field existiert
                    var existingField = form.querySelector('input[name="mepr_ac_tags"]');
                    if (!existingField && tags.length > 0) {
                        var hiddenField = document.createElement('input');
                        hiddenField.type = 'hidden';
                        hiddenField.name = 'mepr_ac_tags';
                        hiddenField.value = tags.join(',');
                        form.appendChild(hiddenField);

                        debugLog('Tags injected into form', tags);
                    } else if (existingField && tags.length > 0) {
                        // Update existing field
                        existingField.value = tags.join(',');
                        debugLog('Existing tag field updated', tags);
                    }

                    // Auch die URL speichern
                    var existingUrlField = form.querySelector('input[name="mepr_ac_url"]');
                    if (!existingUrlField) {
                        var urlField = document.createElement('input');
                        urlField.type = 'hidden';
                        urlField.name = 'mepr_ac_url';
                        urlField.value = window.location.href;
                        form.appendChild(urlField);
                        debugLog('URL field injected', window.location.href);
                    } else {
                        existingUrlField.value = window.location.href;
                    }

                    // Event Listener für Form Submit hinzufügen (als zusätzliche Absicherung)
                    if (!form.dataset.meprAcListenerAdded) {
                        form.addEventListener('submit', function() {
                            var tagField = form.querySelector('input[name="mepr_ac_tags"]');
                            var urlField = form.querySelector('input[name="mepr_ac_url"]');

                            debugLog('Form submitting with tags', tagField ? tagField.value : 'none');
                            debugLog('Form submitting with URL', urlField ? urlField.value : 'none');

                            // Letzte Chance: Falls Fields noch nicht existieren, jetzt einfügen
                            if (!tagField && tags.length > 0) {
                                var hiddenField = document.createElement('input');
                                hiddenField.type = 'hidden';
                                hiddenField.name = 'mepr_ac_tags';
                                hiddenField.value = tags.join(',');
                                form.appendChild(hiddenField);
                                debugLog('Emergency tag injection on submit', tags);
                            }

                            if (!urlField) {
                                var urlFieldNew = document.createElement('input');
                                urlFieldNew.type = 'hidden';
                                urlFieldNew.name = 'mepr_ac_url';
                                urlFieldNew.value = window.location.href;
                                form.appendChild(urlFieldNew);
                            }
                        });
                        form.dataset.meprAcListenerAdded = 'true';
                    }
                });

                return forms.length;
            }

            // Sofort ausführen
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    injectTagsIntoForms();
                });
            } else {
                injectTagsIntoForms();
            }

            // Mutation Observer für dynamisch geladene Formulare
            var observer = new MutationObserver(function(mutations) {
                var formsAdded = false;
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) { // Element node
                            if (node.matches && (node.matches('form.mepr-signup-form') ||
                                node.matches('form.mepr_form') ||
                                node.matches('form[action*="mepr"]'))) {
                                formsAdded = true;
                            } else if (node.querySelector) {
                                var forms = node.querySelectorAll('form.mepr-signup-form, form.mepr_form, form[action*="mepr"]');
                                if (forms.length > 0) {
                                    formsAdded = true;
                                }
                            }
                        }
                    });
                });

                if (formsAdded) {
                    debugLog('New forms detected, injecting tags');
                    injectTagsIntoForms();
                }
            });

            // Observer starten
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            // Auch nach AJAX-Laden nochmal prüfen (für dynamische Formulare)
            setTimeout(function() {
                var count = injectTagsIntoForms();
                debugLog('Delayed injection check (1s), found forms: ' + count);
            }, 1000);

            setTimeout(function() {
                var count = injectTagsIntoForms();
                debugLog('Delayed injection check (3s), found forms: ' + count);
            }, 3000);

            debugLog('Script initialized with parameter "' + PARAM_NAME + '"', {
                currentUrl: window.location.href,
                tags: tags,
                debug: DEBUG
            });
        })();
        </script>
        <?php
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

        // API Einstellungen
        $sanitized['api_url'] = isset($input['api_url']) ? esc_url_raw(rtrim($input['api_url'], '/')) : '';
        $sanitized['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';

        // URL Parameter Name
        $sanitized['url_param_name'] = isset($input['url_param_name']) ? sanitize_key($input['url_param_name']) : 'source';

        // Debug Mode
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? '1' : '0';

        return $sanitized;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option($this->option_name, array());
        $api_url = isset($settings['api_url']) ? $settings['api_url'] : '';
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';
        $debug_mode = isset($settings['debug_mode']) && $settings['debug_mode'] === '1';

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
                    <p class="mepr-ac-description"><?php _e('Das Plugin taggt E-Mails basierend auf einem URL-Parameter. Tags werden nur gesetzt, wenn der Parameter in der URL vorhanden ist.', 'mepr-ac-integration'); ?></p>
                <table class="form-table mepr-ac-table">

                    <tr>
                        <th scope="row">
                            <label for="url_param_name">
                                <?php _e('URL Parameter Name', 'mepr-ac-integration'); ?>
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
                                <?php _e('Name des URL Parameters (z.B. "source", "utm_source", "campaign").', 'mepr-ac-integration'); ?><br>
                                <strong><?php _e('Beispiel:', 'mepr-ac-integration'); ?></strong>
                                <code>example.com/premium/?source=messekoeln</code> → Tag: <code>messekoeln</code>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="debug_mode">
                                <?php _e('Debug Modus', 'mepr-ac-integration'); ?>
                            </label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="debug_mode"
                                       name="<?php echo $this->option_name; ?>[debug_mode]"
                                       value="1"
                                       <?php checked($debug_mode, true); ?>>
                                <?php _e('Debug-Meldungen in Browser Console anzeigen', 'mepr-ac-integration'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Aktiviere dies, um detaillierte Informationen über das Tag-Tracking in der Browser-Console zu sehen. Nützlich für Troubleshooting.', 'mepr-ac-integration'); ?>
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
                <li><?php _e('Wenn ein Besucher eine URL mit dem konfigurierten Parameter aufruft (z.B. ?source=messekoeln), wird der Parameterwert gespeichert.', 'mepr-ac-integration'); ?></li>
                <li><?php _e('Wenn sich der Besucher über ein MemberPress Registrierungsformular anmeldet, wird die E-Mail automatisch zu ActiveCampaign gesendet.', 'mepr-ac-integration'); ?></li>
                <li><?php _e('Der Contact wird in ActiveCampaign erstellt oder aktualisiert.', 'mepr-ac-integration'); ?></li>
                <li><?php _e('Der gespeicherte Parameterwert wird als Tag in ActiveCampaign zugewiesen (z.B. "messekoeln").', 'mepr-ac-integration'); ?></li>
                <li><?php _e('Tags werden automatisch in ActiveCampaign erstellt, falls sie noch nicht existieren.', 'mepr-ac-integration'); ?></li>
                <li><?php _e('Wichtig: Wenn kein URL-Parameter vorhanden war, wird auch kein Tag gesetzt.', 'mepr-ac-integration'); ?></li>
            </ol>

            <h3><?php _e('Beispiele', 'mepr-ac-integration'); ?></h3>
            <table class="wp-list-table widefat striped">
                <thead>
                <tr>
                    <th><?php _e('URL', 'mepr-ac-integration'); ?></th>
                    <th><?php _e('Tag in ActiveCampaign', 'mepr-ac-integration'); ?></th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td><code>example.com/premium/?source=messekoeln</code></td>
                    <td><code>messekoeln</code></td>
                </tr>
                <tr>
                    <td><code>example.com/basic/?source=facebook</code></td>
                    <td><code>facebook</code></td>
                </tr>
                <tr>
                    <td><code>example.com/membership/?source=instagram</code></td>
                    <td><code>instagram</code></td>
                </tr>
                <tr>
                    <td><code>example.com/membership/?utm_source=newsletter</code></td>
                    <td><code>newsletter</code> <em>(wenn "utm_source" als Parameter konfiguriert)</em></td>
                </tr>
                <tr>
                    <td><code>example.com/premium/</code> <em>(ohne Parameter)</em></td>
                    <td><em><?php _e('Kein Tag', 'mepr-ac-integration'); ?></em></td>
                </tr>
                </tbody>
            </table>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
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

            $this->log_error('=== SIGNUP STARTED for ' . $email . ' ===');

            // Tags aus verschiedenen Quellen sammeln
            $tags = $this->collect_tags_from_all_sources();

            // Wenn keine Tags gefunden wurden, kein Tracking durchführen
            if (empty($tags)) {
                $this->log_error('No tags found - skipping ActiveCampaign tagging (no URL parameter present)');
                $this->clear_stored_tags();
                return;
            }

            $this->log_error('Final tags to be sent to ActiveCampaign: ' . implode(', ', $tags));

            $this->send_to_activecampaign($email, $first_name, $last_name, $tags);

            // Cookies und Session Tags löschen nach erfolgreicher Verwendung
            $this->clear_stored_tags();

            $this->log_error('=== SIGNUP COMPLETED ===');

        } catch (Exception $e) {
            $this->log_error('Exception in handle_signup: ' . $e->getMessage());
        }
    }

    private function collect_tags_from_all_sources() {
        $tags = array();
        $source_found = false;

        $this->log_error('=== TAG COLLECTION STARTED ===');
        $this->log_error('POST data: ' . print_r(array_keys($_POST), true));
        $this->log_error('Cookie data: ' . print_r(array_keys($_COOKIE), true));
        $this->log_error('HTTP_REFERER: ' . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'not set'));

        // Priorität 1: Tags direkt aus aktueller URL extrahieren (falls noch in $_GET vorhanden)
        $current_url_tags = $this->extract_tags_from_current_url();
        if (!empty($current_url_tags)) {
            $tags = $this->normalize_tags($current_url_tags);
            $this->log_error('Source 1 (HIGHEST PRIORITY): Found tags in current URL ($_GET): ' . implode(', ', $tags));
            $source_found = true;
        }

        // Priorität 2: Tags aus POST mepr_ac_url extrahieren (vom JavaScript injiziert)
        if (!$source_found && isset($_POST['mepr_ac_url']) && !empty($_POST['mepr_ac_url'])) {
            $posted_url = esc_url_raw($_POST['mepr_ac_url']);
            $tags = $this->extract_tags_from_url($posted_url);
            if (!empty($tags)) {
                $tags = $this->normalize_tags($tags);
                $this->log_error('Source 2: Extracted tags from POST mepr_ac_url: ' . implode(', ', $tags) . ' (URL: ' . $posted_url . ')');
                $source_found = true;
            } else {
                $this->log_error('Source 2: POST mepr_ac_url present but no tags found: ' . $posted_url);
            }
        }

        // Priorität 3: Tags aus dem POST mepr_ac_tags field (vom JavaScript injiziert)
        if (!$source_found && isset($_POST['mepr_ac_tags']) && !empty($_POST['mepr_ac_tags'])) {
            $posted_tags = sanitize_text_field($_POST['mepr_ac_tags']);
            $tags = array_map('trim', explode(',', $posted_tags));
            $tags = $this->normalize_tags($tags);
            $this->log_error('Source 3: Found tags in POST mepr_ac_tags: ' . implode(', ', $tags));
            $source_found = true;
        }

        // Priorität 4: Tags aus HTTP_REFERER extrahieren (wichtig!)
        if (!$source_found) {
            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
            if (!empty($referer)) {
                $referer_tags = $this->extract_tags_from_url($referer);
                if (!empty($referer_tags)) {
                    $tags = $this->normalize_tags($referer_tags);
                    $this->log_error('Source 4: Extracted tags from HTTP_REFERER: ' . implode(', ', $tags) . ' (Referer: ' . $referer . ')');
                    $source_found = true;
                } else {
                    $this->log_error('Source 4: HTTP_REFERER present but no tags found: ' . $referer);
                }
            } else {
                $this->log_error('Source 4: HTTP_REFERER not set');
            }
        }

        // Priorität 5: Tags aus JavaScript-Cookie laden
        if (!$source_found && isset($_COOKIE['mepr_ac_tags_js']) && !empty($_COOKIE['mepr_ac_tags_js'])) {
            $cookie_data = json_decode(stripslashes($_COOKIE['mepr_ac_tags_js']), true);
            if ($cookie_data && isset($cookie_data['tags']) && is_array($cookie_data['tags'])) {
                // Cookie-Zeitstempel prüfen (7 Tage)
                $timestamp = isset($cookie_data['timestamp']) ? intval($cookie_data['timestamp']) : 0;
                if (time() - $timestamp <= (7 * 24 * 60 * 60)) {
                    $tags = $this->normalize_tags($cookie_data['tags']);
                    $cookie_url = isset($cookie_data['url']) ? $cookie_data['url'] : 'unknown';
                    $this->log_error('Source 5: Found tags in JavaScript cookie: ' . implode(', ', $tags) . ' (from URL: ' . $cookie_url . ')');
                    $source_found = true;
                } else {
                    $this->log_error('Source 5: JavaScript cookie expired (older than 7 days)');
                }
            }
        }

        // Priorität 6: Tags aus PHP-Cookie laden
        if (!$source_found && isset($_COOKIE['mepr_ac_tags']) && !empty($_COOKIE['mepr_ac_tags'])) {
            $cookie_data = json_decode(stripslashes($_COOKIE['mepr_ac_tags']), true);
            if ($cookie_data && isset($cookie_data['tags']) && is_array($cookie_data['tags'])) {
                // Cookie-Zeitstempel prüfen (7 Tage)
                $timestamp = isset($cookie_data['timestamp']) ? intval($cookie_data['timestamp']) : 0;
                if (time() - $timestamp <= (7 * 24 * 60 * 60)) {
                    $tags = $this->normalize_tags($cookie_data['tags']);
                    $this->log_error('Source 6: Found tags in PHP cookie: ' . implode(', ', $tags));
                    $source_found = true;
                } else {
                    $this->log_error('Source 6: PHP cookie expired (older than 7 days)');
                }
            }
        }

        // Priorität 7: Tags aus der Session laden
        if (!$source_found) {
            $session_tags = $this->get_stored_tags();
            if (!empty($session_tags)) {
                $tags = $this->normalize_tags($session_tags);
                $this->log_error('Source 7: Found tags in session: ' . implode(', ', $tags));
                $source_found = true;
            }
        }

        // Priorität 8: Fallback - wp_get_referer()
        if (!$source_found) {
            $this->log_error('Source 8: Falling back to wp_get_referer()');
            $tags = $this->extract_tags();
            if (!empty($tags)) {
                $tags = $this->normalize_tags($tags);
                $this->log_error('Source 8: Extracted tags from wp_get_referer: ' . implode(', ', $tags));
                $source_found = true;
            } else {
                $this->log_error('Source 8: No tags found in wp_get_referer');
            }
        }

        $final_tags = array_unique(array_filter($tags));
        $this->log_error('=== TAG COLLECTION COMPLETED ===');
        $this->log_error('Final tags: ' . (!empty($final_tags) ? implode(', ', $final_tags) : 'NONE'));

        return $final_tags;
    }

    private function normalize_tags($tags) {
        if (!is_array($tags)) {
            return array();
        }

        return array_map(function($tag) {
            // Zu String konvertieren, trimmen und lowercase
            $tag = trim(strval($tag));
            $tag = strtolower($tag);
            // Sanitizen
            $tag = sanitize_text_field($tag);
            return $tag;
        }, $tags);
    }

    private function extract_tags() {
        $tags = array();
        $settings = get_option($this->option_name, array());
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';

        $referer = wp_get_referer();

        if (empty($referer)) {
            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        }

        if (empty($referer)) {
            return $tags;
        }

        // URL Parameter extrahieren
        if (!empty($url_param_name)) {
            $query = parse_url($referer, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $params);
                if (isset($params[$url_param_name]) && !empty($params[$url_param_name])) {
                    $param_value = $params[$url_param_name];
                    $tags[] = sanitize_text_field($param_value);
                }
            }
        }

        return array_unique(array_filter($tags));
    }

    private function extract_tags_from_current_url() {
        $tags = array();
        $settings = get_option($this->option_name, array());
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';

        // URL Parameter extrahieren - direkt aus $_GET
        if (!empty($url_param_name)) {
            if (isset($_GET[$url_param_name]) && !empty($_GET[$url_param_name])) {
                $param_value = $_GET[$url_param_name];
                $tags[] = sanitize_text_field($param_value);
            }
        }

        return array_unique(array_filter($tags));
    }

    private function extract_tags_from_url($url) {
        $tags = array();
        $settings = get_option($this->option_name, array());
        $url_param_name = isset($settings['url_param_name']) ? $settings['url_param_name'] : 'source';

        if (empty($url)) {
            return $tags;
        }

        // URL Parameter extrahieren
        if (!empty($url_param_name)) {
            $query = parse_url($url, PHP_URL_QUERY);
            if ($query) {
                parse_str($query, $params);
                if (isset($params[$url_param_name]) && !empty($params[$url_param_name])) {
                    $param_value = $params[$url_param_name];
                    $tags[] = sanitize_text_field($param_value);
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
        // Session löschen
        if (isset($_SESSION['mepr_ac_tags'])) {
            unset($_SESSION['mepr_ac_tags']);
        }
        if (isset($_SESSION['mepr_ac_tags_timestamp'])) {
            unset($_SESSION['mepr_ac_tags_timestamp']);
        }

        // Cookies löschen
        if (isset($_COOKIE['mepr_ac_tags'])) {
            setcookie('mepr_ac_tags', '', time() - 3600, '/');
        }
        if (isset($_COOKIE['mepr_ac_tags_js'])) {
            setcookie('mepr_ac_tags_js', '', time() - 3600, '/');
        }

        $this->log_error('Cleared all stored tags (session and cookies)');
    }

    private function get_current_url() {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        return $protocol . "://" . $host . $uri;
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

        $this->log_error('=== TAG ASSIGNMENT STARTED for tag: ' . $tag_name . ' (contact: ' . $contact_id . ') ===');

        // Tag erstellen oder finden
        $tag_data = array(
            'tag' => array(
                'tag' => $tag_name,
                'tagType' => 'contact'
            )
        );

        $this->log_error('Sending tag creation request to: ' . $this->api_url . '/api/3/tags');
        $this->log_error('Tag data: ' . json_encode($tag_data));

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

        $tag_response_code = wp_remote_retrieve_response_code($tag_response);
        $tag_response_body = wp_remote_retrieve_body($tag_response);
        $this->log_error('Tag creation response code: ' . $tag_response_code);
        $this->log_error('Tag creation response body: ' . $tag_response_body);

        $tag_body = json_decode($tag_response_body, true);

        // Tag ID aus Response oder Fehler extrahieren
        $tag_id = null;

        if (isset($tag_body['tag']['id'])) {
            $tag_id = $tag_body['tag']['id'];
            $this->log_error('Tag created successfully with ID: ' . $tag_id);
        } elseif ($tag_response_code === 422) {
            // Bei 422 könnte das Tag bereits existieren - versuchen wir es zu finden
            $this->log_error('Tag creation returned 422 (likely duplicate), searching for existing tag...');

            // Tag per GET suchen
            $search_response = wp_remote_get($this->api_url . '/api/3/tags?search=' . urlencode($tag_name), array(
                'headers' => array(
                    'Api-Token' => $this->api_key
                ),
                'timeout' => 15
            ));

            if (!is_wp_error($search_response) && wp_remote_retrieve_response_code($search_response) === 200) {
                $search_body = json_decode(wp_remote_retrieve_body($search_response), true);
                $this->log_error('Tag search response: ' . wp_remote_retrieve_body($search_response));

                // Durch alle gefundenen Tags iterieren und nach exakter Übereinstimmung suchen
                if (isset($search_body['tags']) && is_array($search_body['tags'])) {
                    foreach ($search_body['tags'] as $tag) {
                        if (isset($tag['tag']) && strtolower($tag['tag']) === strtolower($tag_name)) {
                            $tag_id = $tag['id'];
                            $this->log_error('Found existing tag with ID: ' . $tag_id);
                            break;
                        }
                    }
                }
            }
        } elseif (isset($tag_body['errors'])) {
            $this->log_error('Tag creation returned errors: ' . json_encode($tag_body['errors']));
            // Wenn Tag bereits existiert, ID aus Fehler extrahieren (alte API-Version)
            foreach ($tag_body['errors'] as $error) {
                if (isset($error['code']) && $error['code'] === 'duplicate' && isset($error['tag_id'])) {
                    $tag_id = $error['tag_id'];
                    $this->log_error('Tag already exists, extracted ID from error: ' . $tag_id);
                    break;
                }
            }
        }

        if (empty($tag_id)) {
            $this->log_error('ERROR: No tag ID found for: ' . $tag_name);
            $this->log_error('Full response analysis: ' . print_r($tag_body, true));
            return false;
        }

        // Tag dem Contact zuweisen
        $contact_tag_data = array(
            'contactTag' => array(
                'contact' => $contact_id,
                'tag' => $tag_id
            )
        );

        $this->log_error('Assigning tag ID ' . $tag_id . ' to contact ID ' . $contact_id);
        $this->log_error('Contact tag data: ' . json_encode($contact_tag_data));

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
        $assign_body_raw = wp_remote_retrieve_body($assign_response);
        $this->log_error('Tag assignment response code: ' . $assign_code);
        $this->log_error('Tag assignment response body: ' . $assign_body_raw);

        if ($assign_code === 200 || $assign_code === 201) {
            $this->log_error('Tag successfully assigned to contact!');
            $this->log_error('=== TAG ASSIGNMENT COMPLETED SUCCESSFULLY ===');
            return true;
        }

        // Tag Assignment kann auch 422 zurückgeben wenn bereits zugewiesen
        $assign_body = json_decode($assign_body_raw, true);
        if (isset($assign_body['errors'])) {
            foreach ($assign_body['errors'] as $error) {
                if (isset($error['code']) && $error['code'] === 'duplicate') {
                    $this->log_error('Tag already assigned to contact (duplicate), returning success');
                    $this->log_error('=== TAG ASSIGNMENT COMPLETED (ALREADY ASSIGNED) ===');
                    return true; // Tag ist bereits zugewiesen, das ist ok
                }
            }
        }

        $this->log_error('ERROR: Tag assignment failed with code ' . $assign_code);
        $this->log_error('=== TAG ASSIGNMENT FAILED ===');
        return false;
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