<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Bokun_SkipCash_Admin_Settings
 * Adds Settings -> Bókun + SkipCash menu in WP Admin.
 */
class Bokun_SkipCash_Admin_Settings {
    private $bokun;

    public function __construct(Bokun_API $bokun = null) {
        $this->bokun = $bokun ?: new Bokun_API();
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_bokun_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_bokun_test_reservation', array($this, 'ajax_test_reservation'));
        add_action('wp_ajax_skipcash_test_connection', array($this, 'ajax_test_skipcash_connection'));
    }

    public function add_menu_page() {
        add_options_page(
            'Bókun & SkipCash Gateway Settings',
            'Bókun + SkipCash',
            'manage_options',
            'bokun-skipcash-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        // Bókun Settings
        register_setting('bokun_skipcash_options', 'bokun_skipcash_bokun_base_url');
        register_setting('bokun_skipcash_options', 'bokun_skipcash_bokun_access_key');
        register_setting('bokun_skipcash_options', 'bokun_skipcash_bokun_secret_key');
        register_setting('bokun_skipcash_options', 'bokun_skipcash_default_activity_id');

        // SkipCash Settings
        register_setting('bokun_skipcash_options', 'bokun_skipcash_skipcash_base_url');
        register_setting('bokun_skipcash_options', 'bokun_skipcash_skipcash_key_id');
        register_setting('bokun_skipcash_options', 'bokun_skipcash_skipcash_secret_key');
        register_setting('bokun_skipcash_options', 'bokun_skipcash_skipcash_client_id');
        register_setting('bokun_skipcash_options', 'bokun_skipcash_skipcash_webhook_secret');

        // General
        register_setting('bokun_skipcash_options', 'bokun_skipcash_currency');
    }

    public function ajax_test_connection() {
        check_ajax_referer('bokun_admin_test_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $activity_id = intval($_POST['activity_id'] ?? get_option('bokun_skipcash_default_activity_id', 1317760));
        $result = $this->bokun->test_connection($activity_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function ajax_test_reservation() {
        check_ajax_referer('bokun_admin_test_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $activity_id = intval($_POST['activity_id'] ?? get_option('bokun_skipcash_default_activity_id', 1317760));
        $result = $this->bokun->test_reservation($activity_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function ajax_test_skipcash_connection() {
        check_ajax_referer('bokun_admin_test_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $skipcash = new SkipCash_API();
        
        $test_params = array(
            'amount' => '1.00',
            'first_name' => 'Test',
            'last_name' => 'Connection',
            'phone' => '+97400000000',
            'email' => 'test@example.com',
            'transaction_id' => 'TEST-' . time(),
            'confirmation_code' => 'TEST_CODE',
            'activity_name' => 'SkipCash Connection Test',
            'return_url' => home_url('/'),
            'webhook_url' => rest_url('bokun-skipcash/v1/webhook')
        );
        
        $res = $skipcash->create_payment_link($test_params);
        if (!empty($res['success'])) {
            wp_send_json_success(array(
                'message' => 'SkipCash connection successful! Payment session created for 1.00 QAR.', 
                'payUrl'  => $res['payUrl']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $res['message'] ?? 'SkipCash connection failed.',
                'raw'     => $res['raw'] ?? ''
            ));
        }
    }

    public function render_settings_page() {
        $webhook_url = esc_url_raw(rest_url('bokun-skipcash/v1/webhook'));
        $test_nonce  = wp_create_nonce('bokun_admin_test_nonce');
        ?>
        <div class="wrap">
            <h1>Bókun Booking API & SkipCash Qatar Payment Gateway</h1>
            <p>This plugin implements the <strong>RESERVE_FOR_EXTERNAL_PAYMENT</strong> flow from Bókun API, enabling seamless credit/debit card processing via SkipCash without relying on the incompatible Bókun booking widget.</p>

            <div class="notice notice-info" style="padding: 12px; margin-bottom: 20px;">
                <strong>Webhook Callback URL for SkipCash Portal:</strong><br>
                <code style="display:inline-block; margin-top:5px; padding:6px 10px; background:#fff; border:1px solid #ccd0d4; font-size:14px;">
                    <?php echo esc_html($webhook_url); ?>
                </code>
                <p style="margin-top:6px; font-size:12px; color:#555;">Copy this URL and paste it into your SkipCash Merchant Dashboard under <em>Webhooks / Notification URL</em>.</p>
            </div>

            <!-- Diagnostics & Test Box -->
            <div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #2271b1; padding:16px 20px; border-radius:4px; margin-bottom:24px;">
                <h3 style="margin-top:0;">API Connection & Activity Diagnostics</h3>
                <p style="color:#555;">Verify that your Bókun Access Key, Secret Key, and HMAC-SHA1 signature are valid, and test live reservation submission:</p>
                
                <div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-top:10px;">
                    <label for="test_activity_id"><strong>Activity ID to Test:</strong></label>
                    <input type="number" id="test_activity_id" value="<?php echo esc_attr(get_option('bokun_skipcash_default_activity_id', '1317760')); ?>" style="width:140px;" />
                    <button type="button" class="button button-secondary" id="btn_test_bokun_connection">1. Test Connection</button>
                    <button type="button" class="button button-primary" id="btn_test_bokun_reservation">2. Test Live Reservation</button>
                    <button type="button" class="button button-secondary" id="btn_test_skipcash_connection">3. Test SkipCash Auth</button>
                    <span id="test_spinner" class="spinner" style="float:none; margin:0;"></span>
                </div>

                <div id="test_result_box" style="margin-top:14px; display:none; padding:12px; border-radius:4px;"></div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('#btn_test_bokun_connection').on('click', function(e) {
                    e.preventDefault();
                    var $spinner = $('#test_spinner').addClass('is-active');
                    var $box = $('#test_result_box').hide();
                    var actId = $('#test_activity_id').val();

                    $.post(ajaxurl, {
                        action: 'bokun_test_connection',
                        nonce: '<?php echo esc_js($test_nonce); ?>',
                        activity_id: actId
                    }, function(res) {
                        $spinner.removeClass('is-active');
                        $box.show();
                        if (res.success) {
                            var act = res.data.activity;
                            var cats = '';
                            if (act.pricingCategories && act.pricingCategories.length) {
                                cats = act.pricingCategories.map(function(c){ return c.title + ' (ID: ' + c.id + ')'; }).join(', ');
                            }
                            var times = '';
                            if (act.startTimes && act.startTimes.length) {
                                times = act.startTimes.map(function(t){
                                    var h = String(t.hour || 0).padStart(2, '0');
                                    var m = String(t.minute || 0).padStart(2, '0');
                                    return h + ':' + m + ' (ID: ' + t.id + ')';
                                }).join(', ');
                            }
                            var rates = '';
                            if (act.rates && act.rates.length) {
                                rates = act.rates.map(function(r){ return (r.title || 'Standard') + ' (ID: ' + r.id + ')'; }).join(', ');
                            }
                            $box.css({background: '#edf7ed', border: '1px solid #c8e6c9', color: '#1e4620'}).html(
                                '<div style="font-size:14px; font-weight:bold; margin-bottom:6px;">✓ ' + res.data.message + '</div>' +
                                '<div style="display:grid; grid-template-columns:140px 1fr; gap:4px; font-size:13px;">' +
                                    '<strong>Activity Name:</strong> <span>' + act.title + '</span>' +
                                    '<strong>Booking Type:</strong> <span><code>' + (act.bookingType || 'DATE_AND_TIME') + '</code></span>' +
                                    '<strong>Pricing Categories:</strong> <span>' + (cats || '<em>None detected</em>') + '</span>' +
                                    '<strong>Start Time Slots:</strong> <span>' + (times || '<em>No start times configured (Date-only)</em>') + '</span>' +
                                    '<strong>Rates Found:</strong> <span>' + (rates || '<em>Default Rate</em>') + '</span>' +
                                '</div>'
                            );
                        } else {
                            $box.css({background: '#fde8e8', border: '1px solid #f8b4b4', color: '#9b1c1c'}).html(
                                '<strong>CONNECTION FAILED:</strong> ' + (res.data ? res.data.message : 'Unknown error.')
                            );
                        }
                    }).fail(function() {
                        $spinner.removeClass('is-active');
                        $box.show().css({background: '#fde8e8', border: '1px solid #f8b4b4', color: '#9b1c1c'}).html(
                            '<strong>HTTP Error:</strong> Could not connect to WordPress AJAX handler.'
                        );
                    });
                });

                $('#btn_test_bokun_reservation').on('click', function(e) {
                    e.preventDefault();
                    var $spinner = $('#test_spinner').addClass('is-active');
                    var $box = $('#test_result_box').hide();
                    var actId = $('#test_activity_id').val();

                    $.post(ajaxurl, {
                        action: 'bokun_test_reservation',
                        nonce: '<?php echo esc_js($test_nonce); ?>',
                        activity_id: actId
                    }, function(res) {
                        $spinner.removeClass('is-active');
                        $box.show();
                        if (res.success) {
                            $box.css({background: '#edf7ed', border: '1px solid #c8e6c9', color: '#1e4620'}).html(
                                '<div style="font-size:14px; font-weight:bold; margin-bottom:6px;">✓ ' + (res.data.message || 'Reservation test passed!') + '</div>' +
                                '<div style="font-size:12px; margin-top:8px;"><strong>Confirmation Code:</strong> <code>' + (res.data.confirmationCode || 'N/A') + '</code></div>' +
                                '<details style="margin-top:8px;"><summary style="cursor:pointer; font-weight:600;">View Sent Payload & Bókun Response</summary><pre style="background:#fff; padding:8px; border:1px solid #ccd0d4; border-radius:4px; max-height:200px; overflow:auto; font-size:11px;">' + JSON.stringify(res.data, null, 2) + '</pre></details>'
                            );
                        } else {
                            $box.css({background: '#fde8e8', border: '1px solid #f8b4b4', color: '#9b1c1c'}).html(
                                '<div style="font-weight:bold; margin-bottom:6px;">❌ RESERVATION FAILED: ' + (res.data ? res.data.message : 'Unknown error') + '</div>' +
                                (res.data && res.data.sent_payload ? '<details style="margin-top:8px;"><summary style="cursor:pointer; font-weight:600;">View Sent Payload</summary><pre style="background:#fff; padding:8px; border:1px solid #ccd0d4; border-radius:4px; max-height:200px; overflow:auto; font-size:11px;">' + JSON.stringify(res.data.sent_payload, null, 2) + '</pre></details>' : '') +
                                (res.data && res.data.raw ? '<details style="margin-top:8px;"><summary style="cursor:pointer; font-weight:600;">View Raw Bókun Response</summary><pre style="background:#fff; padding:8px; border:1px solid #ccd0d4; border-radius:4px; max-height:200px; overflow:auto; font-size:11px;">' + JSON.stringify(res.data.raw, null, 2) + '</pre></details>' : '')
                            );
                        }
                    }).fail(function() {
                        $spinner.removeClass('is-active');
                        $box.show().css({background: '#fde8e8', border: '1px solid #f8b4b4', color: '#9b1c1c'}).html(
                            '<strong>HTTP Error:</strong> Could not connect to WordPress AJAX handler.'
                        );
                    });
                });

                $('#btn_test_skipcash_connection').on('click', function(e) {
                    e.preventDefault();
                    var $spinner = $('#test_spinner').addClass('is-active');
                    var $box = $('#test_result_box').hide();

                    $.post(ajaxurl, {
                        action: 'skipcash_test_connection',
                        nonce: '<?php echo esc_js($test_nonce); ?>'
                    }, function(res) {
                        $spinner.removeClass('is-active');
                        $box.show();
                        
                        if (res.success) {
                            $box.css({background: '#edf7ed', border: '1px solid #c8e6c9', color: '#1e4620'}).html(
                                '<div style="font-size:14px; font-weight:bold; margin-bottom:6px;">✓ ' + (res.data.message || 'SkipCash connection passed!') + '</div>' +
                                '<div style="font-size:12px; margin-top:8px;"><strong>Payment URL Generated:</strong> <a href="' + res.data.payUrl + '" target="_blank">' + res.data.payUrl + '</a></div>'
                            );
                        } else {
                            $box.css({background: '#fde8e8', border: '1px solid #f8b4b4', color: '#9b1c1c'}).html(
                                '<div style="font-weight:bold; margin-bottom:6px;">❌ SKIPCASH FAILED: ' + (res.data ? res.data.message : 'Unknown error') + '</div>' +
                                (res.data && res.data.raw ? '<details style="margin-top:8px;"><summary style="cursor:pointer; font-weight:600;">View Raw SkipCash Response</summary><pre style="background:#fff; padding:8px; border:1px solid #ccd0d4; border-radius:4px; max-height:200px; overflow:auto; font-size:11px;">' + JSON.stringify(res.data.raw, null, 2) + '</pre></details>' : '')
                            );
                        }
                    }).fail(function() {
                        $spinner.removeClass('is-active');
                        $box.show().css({background: '#fde8e8', border: '1px solid #f8b4b4', color: '#9b1c1c'}).html(
                            '<strong>HTTP Error:</strong> Could not connect to WordPress AJAX handler.'
                        );
                    });
                });
            });
            </script>

            <form method="post" action="options.php">
                <?php settings_fields('bokun_skipcash_options'); ?>
                
                <h2>Bókun API Credentials</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Bókun API Base URL</th>
                        <td>
                            <input type="text" name="bokun_skipcash_bokun_base_url" value="<?php echo esc_attr(get_option('bokun_skipcash_bokun_base_url', 'https://api.bokun.io')); ?>" class="regular-text" />
                            <p class="description">Standard production URL is <code>https://api.bokun.io</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bókun Access Key</th>
                        <td>
                            <input type="text" name="bokun_skipcash_bokun_access_key" value="<?php echo esc_attr(get_option('bokun_skipcash_bokun_access_key', '')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bókun Secret Key</th>
                        <td>
                            <input type="password" name="bokun_skipcash_bokun_secret_key" value="<?php echo esc_attr(get_option('bokun_skipcash_bokun_secret_key', '')); ?>" class="regular-text" />
                            <p class="description">Used to sign HMAC-SHA1 requests (X-Bokun-Signature).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Default Activity ID</th>
                        <td>
                            <input type="number" name="bokun_skipcash_default_activity_id" value="<?php echo esc_attr(get_option('bokun_skipcash_default_activity_id', '1317760')); ?>" class="regular-text" />
                            <p class="description">e.g. <code>1317760</code> for Pearl Kayaking</p>
                        </td>
                    </tr>
                </table>

                <h2>SkipCash Gateway Credentials</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">SkipCash Base URL</th>
                        <td>
                            <input type="text" name="bokun_skipcash_skipcash_base_url" value="<?php echo esc_attr(get_option('bokun_skipcash_skipcash_base_url', 'https://api.skipcash.app')); ?>" class="regular-text" />
                            <p class="description">Production: <code>https://api.skipcash.app</code> | Sandbox: <code>https://skipcashtest.azurewebsites.net</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Key ID</th>
                        <td>
                            <input type="text" name="bokun_skipcash_skipcash_key_id" value="<?php echo esc_attr(get_option('bokun_skipcash_skipcash_key_id', '')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Secret Key</th>
                        <td>
                            <input type="password" name="bokun_skipcash_skipcash_secret_key" value="<?php echo esc_attr(get_option('bokun_skipcash_skipcash_secret_key', '')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Client ID</th>
                        <td>
                            <input type="text" name="bokun_skipcash_skipcash_client_id" value="<?php echo esc_attr(get_option('bokun_skipcash_skipcash_client_id', '')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Webhook Secret</th>
                        <td>
                            <input type="password" name="bokun_skipcash_skipcash_webhook_secret" value="<?php echo esc_attr(get_option('bokun_skipcash_skipcash_webhook_secret', '')); ?>" class="regular-text" />
                            <p class="description">Used to verify incoming webhook signatures from SkipCash.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Default Currency</th>
                        <td>
                            <input type="text" name="bokun_skipcash_currency" value="<?php echo esc_attr(get_option('bokun_skipcash_currency', 'QAR')); ?>" class="small-text" />
                            <p class="description">Defaults to QAR (Qatari Riyal).</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Shortcode Usage</h2>
            <p>Replace the Bókun widget on any page with this shortcode:</p>
            <code>[bokun_booking activity_id="1317760" title="Pearl Kayaking"]</code>
        </div>
        <?php
    }
}
