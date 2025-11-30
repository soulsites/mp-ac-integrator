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

        // MemberPress Hook
        if ($this->is_configured()) {
            add_action('mepr-signup', array($this, 'handle_signup'), 10, 1);
        }

        // Admin Notices
        add_action('admin_notices', array($this, 'admin_notices'));
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

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (!$this->check_memberpress_active()): ?>
                <div class="notice notice-error">
                    <p><strong><?php _e('MemberPress ist nicht aktiv!', 'mepr-ac-integration'); ?></strong></p>
                    <p><?php _e('Dieses Plugin benötigt MemberPress um zu funktionieren.', 'mepr-ac-integration'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" id="mepr-ac-settings-form">
                <?php
                settings_fields('mepr_ac_settings_group');
                ?>

                <table class="form-table">
                    <tr>
                        <th colspan="2">
                            <h2><?php _e('ActiveCampaign API Einstellungen', 'mepr-ac-integration'); ?></h2>
                        </th>
                    </tr>

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

                    <tr>
                        <th colspan="2">
                            <h2><?php _e('Tag Einstellungen', 'mepr-ac-integration'); ?></h2>
                        </th>
                    </tr>

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
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php _e('Test Funktion', 'mepr-ac-integration'); ?></h2>
            <p><?php _e('Sende eine Test-E-Mail mit einem Tag zu ActiveCampaign um die Integration zu testen.', 'mepr-ac-integration'); ?></p>

            <table class="form-table">
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

            <hr>

            <h2><?php _e('Wie es funktioniert', 'mepr-ac-integration'); ?></h2>
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
            #mepr-ac-settings-form h2 {
                padding: 10px 0;
                border-bottom: 1px solid #ccc;
                margin-bottom: 20px;
            }
            #connection-result,
            #test-result {
                font-weight: bold;
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

            $tags = $this->extract_tags();

            $this->send_to_activecampaign($email, $first_name, $last_name, $tags);

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