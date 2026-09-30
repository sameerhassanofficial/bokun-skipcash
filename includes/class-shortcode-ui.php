<?php
if (!defined('ABSPATH')) {
    exit;
}

class Bokun_SkipCash_Shortcode_UI {
    private $bokun;
    public function __construct(Bokun_API $bokun) {
        $this->bokun = $bokun;
        add_shortcode('bokun_booking', array($this, 'render_booking_shortcode'));
        add_shortcode('bokun_skipcash_booking', array($this, 'render_booking_shortcode'));
    }
    public function render_booking_shortcode($atts) {
        $atts = shortcode_atts(array(
            'activity_id' => get_option('bokun_skipcash_default_activity_id', '1317760'),
            'title'       => 'The Pearl Kayaking Experience in Doha',
            'currency'    => get_option('bokun_skipcash_currency', 'QAR')
        ), $atts, 'bokun_booking');
        
        $activity_id = !empty($atts['activity_id']) ? $atts['activity_id'] : '1317760';
        $currency    = !empty($atts['currency']) ? $atts['currency'] : 'QAR';
        
        // Dynamically fetch ALL activity details and pricing categories from Bókun
        delete_transient('bokun_act_' . $activity_id);
        delete_transient('bokun_categories_' . $activity_id);
        $pricing_categories = $this->bokun->get_pricing_categories($activity_id, true);
        $act_details = $this->bokun->get_activity($activity_id, true);
        $bookable_extras = !empty($act_details['bookableExtras']) ? $act_details['bookableExtras'] : array();

        $rates = !empty($act_details['rates']) && is_array($act_details['rates']) ? $act_details['rates'] : array();
        $min_pax = intval($act_details['minParticipants'] ?? ($act_details['minPerBooking'] ?? 0));
        $max_pax = intval($act_details['maxParticipants'] ?? ($act_details['maxPerBooking'] ?? 0));

        foreach ($rates as $r) {
            if (!empty($r['minPerBooking'])) {
                $r_min = intval($r['minPerBooking']);
                if ($r_min > 0 && ($min_pax === 0 || $r_min < $min_pax)) {
                    $min_pax = $r_min;
                }
            }
            if (!empty($r['maxPerBooking'])) {
                $r_max = intval($r['maxPerBooking']);
                if ($r_max > 0 && ($max_pax === 0 || $r_max > $max_pax)) {
                    $max_pax = $r_max;
                }
            }
        }
        if ($min_pax <= 0) { $min_pax = 1; }
        $booking_cutoff_mins = intval($act_details['bookingCutoffMinutes'] ?? 0) +
            intval($act_details['bookingCutoffHours'] ?? 0) * 60 +
            intval($act_details['bookingCutoffDays'] ?? 0) * 1440 +
            intval($act_details['bookingCutoffWeeks'] ?? 0) * 10080 +
            intval($act_details['bookingCutoff'] ?? 0);

        wp_enqueue_style('bokun-booking-styles');
        // Enqueue the SHARED registered handle (see enqueue_frontend_assets) instead of
        // re-registering it with a new src + time() version here. Re-registering on every
        // shortcode render replaced the script definition AFTER wp_localize_script had run,
        // which stripped BokunSkipCashConfig from the output. The JS then posted to
        // /reserve without the X-Bokun-Nonce header and the REST permission callback
        // rejected it with "Security check failed. Please refresh the page and try again."
        if (!wp_script_is('bokun-booking-scripts', 'registered')) {
            wp_register_script('bokun-booking-scripts', BOKUN_SKIPCASH_URL . 'assets/js/bokun-booking.js', array(), BOKUN_SKIPCASH_VERSION, true);
        }
        wp_enqueue_script('bokun-booking-scripts');

        // Always route through the plugin's centralized config printer. It uses an
        // idempotent inline "before" bootstrap so window.BokunSkipCashConfig exists
        // even when the shortcode renders after the footer scripts have printed
        // (previously localize-at-render-time produced no output -> missing nonce ->
        // REST 403 "Security check failed").
        $plugin = Bokun_SkipCash_Plugin::get_instance();
        if (method_exists($plugin, 'localize_booking_config')) {
            $plugin->localize_booking_config();
        } else {
            wp_localize_script('bokun-booking-scripts', 'BokunSkipCashConfig', array(
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'restUrl'    => esc_url_raw(rest_url('bokun-skipcash/v1/')),
                'nonce'      => wp_create_nonce('bokun_skipcash_booking_nonce'),
                'wpRestNonce' => wp_create_nonce('wp_rest'),
                'currency'   => get_option('bokun_skipcash_currency', 'QAR'),
                'timeoutMin' => defined('BOKUN_SKIPCASH_TIMEOUT_MINUTES') ? BOKUN_SKIPCASH_TIMEOUT_MINUTES : 30
            ));
        }
        
        ob_start();
        ?>
        <style id="bokun-widget-theme-proof-css">
        @keyframes bk-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        body .bokun-booking-widget, body .entry-content .bokun-booking-widget, body .site-main .bokun-booking-widget, body #primary .bokun-booking-widget, body .elementor-element .bokun-booking-widget {
            position: relative !important; z-index: 1 !important; overflow: visible !important; box-sizing: border-box !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif !important; max-width: 650px !important; margin: 20px auto !important; padding: 0 !important; background: transparent !important; color: #0f172a !important; text-align: left !important; direction: ltr !important;
        }
        body .bokun-booking-widget *, body .bokun-booking-widget *::before, body .bokun-booking-widget *::after {
            box-sizing: border-box !important; font-family: inherit !important; text-transform: none !important; letter-spacing: normal !important; text-shadow: none !important; word-break: normal !important; float: none !important; clear: none !important; visibility: visible !important;
        }
        body .bokun-booking-widget button, body .entry-content .bokun-booking-widget button, body .site-main .bokun-booking-widget button, body #primary .bokun-booking-widget button, body .elementor-widget-container .bokun-booking-widget button {
            appearance: none !important; -webkit-appearance: none !important; background: transparent !important; border: none !important; box-shadow: none !important; outline: none !important; margin: 0 !important; float: none !important; clear: none !important; height: auto !important; min-height: 0 !important; width: auto !important; line-height: 1 !important; text-transform: none !important; letter-spacing: normal !important; font-family: inherit !important; cursor: pointer !important;
        }
        body .bokun-booking-widget .bk-layout { display: flex !important; flex-direction: column !important; gap: 20px !important; width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-sizing: border-box !important; }
        body .bokun-booking-widget .bk-card { background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; padding: 18px 16px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04) !important; display: block !important; margin: 0 !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; overflow: hidden !important; }
        @media (min-width: 640px) { body .bokun-booking-widget .bk-card { padding: 22px !important; } }
        body .bokun-booking-widget .bk-calendar { width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; overflow: hidden !important; }
        body .bokun-booking-widget .bk-title { font-size: 18px !important; font-weight: 700 !important; color: #0f172a !important; margin: 0 0 16px 0 !important; padding: 0 0 8px 0 !important; border: none !important; border-bottom: 2px solid #800020 !important; display: flex !important; align-items: center !important; gap: 10px !important; line-height: 1.3 !important; }
        body .bokun-booking-widget .bk-hidden { display: none !important; }
        body .bokun-booking-widget .bk-counter-pill { display: inline-flex !important; flex-direction: row !important; align-items: center !important; justify-content: space-between !important; width: 120px !important; height: 42px !important; border: 1.8px solid #800020 !important; border-radius: 24px !important; padding: 0 12px !important; background: #ffffff !important; margin: 0 !important; }
        body .bokun-booking-widget .bk-btn-minus, body .bokun-booking-widget .bk-btn-plus { width: 30px !important; height: 30px !important; background: transparent !important; color: #800020 !important; font-size: 22px !important; font-weight: 800 !important; cursor: pointer !important; line-height: 1 !important; padding: 0 !important; margin: 0 !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 50% !important; border: none !important; }
        body .bokun-booking-widget .bk-btn-minus:hover, body .bokun-booking-widget .bk-btn-plus:hover { background: #fff0f3 !important; }
        body .bokun-booking-widget .bk-val { font-size: 17px !important; font-weight: 800 !important; color: #0f172a !important; min-width: 24px !important; text-align: center !important; display: inline-block !important; margin: 0 !important; padding: 0 !important; }
        body .bokun-booking-widget .bk-cal-header { display: flex !important; justify-content: space-between !important; align-items: center !important; margin-bottom: 14px !important; width: 100% !important; }
        body .bokun-booking-widget .bk-cal-month { font-size: 16px !important; font-weight: 800 !important; color: #0f172a !important; margin: 0 !important; }
        body .bokun-booking-widget .bk-cal-header button { background: transparent !important; border: none !important; color: #800020 !important; font-size: 20px !important; font-weight: 800 !important; cursor: pointer !important; padding: 4px 10px !important; margin: 0 !important; }
        body .bokun-booking-widget .bk-cal-weekdays { display: grid !important; grid-template-columns: repeat(7, minmax(0, 1fr)) !important; gap: 4px !important; text-align: center !important; font-size: 11px !important; font-weight: 700 !important; color: #64748b !important; margin-bottom: 8px !important; width: 100% !important; box-sizing: border-box !important; }
        @media (min-width: 480px) { body .bokun-booking-widget .bk-cal-weekdays { font-size: 12px !important; gap: 6px !important; } }
        body .bokun-booking-widget .bk-cal-weekdays span { overflow: hidden !important; text-overflow: ellipsis !important; white-space: nowrap !important; }
        body .bokun-booking-widget .bk-cal-grid { display: grid !important; grid-template-columns: repeat(7, minmax(0, 1fr)) !important; gap: 4px !important; width: 100% !important; box-sizing: border-box !important; margin: 0 !important; padding: 0 !important; }
        @media (min-width: 480px) { body .bokun-booking-widget .bk-cal-grid { gap: 6px !important; } }
        body .bokun-booking-widget .bk-cal-cell { aspect-ratio: 1 / 1 !important; width: 100% !important; min-width: 0 !important; height: auto !important; min-height: 38px !important; display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: center !important; border-radius: 8px !important; position: relative !important; font-size: 13px !important; font-weight: 700 !important; cursor: pointer !important; border: 1.5px solid #cbd5e1 !important; background: #ffffff !important; color: #0f172a !important; margin: 0 !important; padding: 2px !important; box-sizing: border-box !important; overflow: hidden !important; transition: all 0.15s ease !important; }
        @media (min-width: 480px) { body .bokun-booking-widget .bk-cal-cell { font-size: 14px !important; min-height: 44px !important; border-radius: 10px !important; } }
        body .bokun-booking-widget .bk-cal-cell.disabled { color: #cbd5e1 !important; cursor: not-allowed !important; background: transparent !important; border-color: transparent !important; }
        body .bokun-booking-widget .bk-cal-cell.available { color: #0f172a !important; background: #ffffff !important; border: 1.5px solid #cbd5e1 !important; }
        body .bokun-booking-widget .bk-cal-cell.available:hover { background: #fff5f7 !important; border-color: #800020 !important; }
        body .bokun-booking-widget .bk-cal-cell.selected { background: #800020 !important; color: #ffffff !important; font-weight: 800 !important; border-color: #800020 !important; box-shadow: 0 4px 12px rgba(128, 0, 32, 0.3) !important; }
        body .bokun-booking-widget .bk-cal-cell .bk-price { font-size: 9px !important; font-weight: 700 !important; color: #800020 !important; margin-top: 1px !important; line-height: 1 !important; white-space: nowrap !important; max-width: 100% !important; overflow: hidden !important; text-overflow: ellipsis !important; }
        @media (min-width: 480px) { body .bokun-booking-widget .bk-cal-cell .bk-price { font-size: 10.5px !important; } }
        body .bokun-booking-widget .bk-cal-cell.selected .bk-price { color: #ffffff !important; }
        body .bokun-booking-widget .bk-cal-cell.available::after { content: '' !important; position: absolute !important; top: 2px !important; right: 2px !important; width: 0 !important; height: 0 !important; border-style: solid !important; border-width: 0 6px 6px 0 !important; border-color: transparent #10b981 transparent transparent !important; }
        body .bokun-booking-widget .bk-cal-cell.selected::after { border-color: transparent #ffffff transparent transparent !important; }
        body .bokun-booking-widget .bk-time-grid { display: flex !important; flex-wrap: wrap !important; gap: 12px !important; width: 100% !important; margin-top: 8px !important; }
        body .bokun-booking-widget .bk-time-btn { display: inline-flex !important; flex-direction: column !important; align-items: flex-start !important; justify-content: center !important; gap: 6px !important; padding: 10px 16px !important; border: 1.5px solid #cbd5e1 !important; border-radius: 12px !important; background: #ffffff !important; color: #0f172a !important; cursor: pointer !important; min-width: 110px !important; max-width: 140px !important; flex: 0 0 auto !important; box-sizing: border-box !important; margin: 0 !important; transition: all 0.15s ease !important; text-align: left !important; user-select: none !important; }
        body .bokun-booking-widget .bk-time-btn:hover { border-color: #800020 !important; background: #fffafa !important; }
        body .bokun-booking-widget .bk-time-btn.selected { background: #800020 !important; color: #ffffff !important; border-color: #800020 !important; box-shadow: 0 4px 14px rgba(128, 0, 32, 0.25) !important; }
        body .bokun-booking-widget .bk-time-top { display: flex !important; align-items: center !important; gap: 8px !important; font-weight: 800 !important; font-size: 15px !important; letter-spacing: -0.2px !important; line-height: 1 !important; color: inherit !important; white-space: nowrap !important; }
        body .bokun-booking-widget .bk-time-value { white-space: nowrap !important; font-weight: 800 !important; font-size: 15px !important; line-height: 1 !important; }
        body .bokun-booking-widget .bk-time-clock { color: #800020 !important; flex-shrink: 0 !important; }
        body .bokun-booking-widget .bk-time-btn.selected .bk-time-clock { color: #ffffff !important; stroke: #ffffff !important; }
        body .bokun-booking-widget .bk-time-tag { font-size: 11px !important; font-weight: 600 !important; padding: 2px 7px !important; border-radius: 4px !important; background: #f1f5f9 !important; color: #475569 !important; border: 1px solid #e2e8f0 !important; display: inline-block !important; line-height: 1.2 !important; white-space: nowrap !important; transition: all 0.15s ease !important; }
        body .bokun-booking-widget .bk-time-btn.selected .bk-time-tag { background: transparent !important; color: #ffffff !important; border-color: rgba(255, 255, 255, 0.8) !important; }
        body .bokun-booking-widget .bk-options-list { display: flex !important; flex-direction: column !important; gap: 12px !important; width: 100% !important; }
        body .bokun-booking-widget .bk-option-card, body .bokun-booking-widget .bk-opt-card { border: 1.5px solid #cbd5e1 !important; border-radius: 12px !important; padding: 14px 18px !important; background: #ffffff !important; cursor: pointer !important; display: flex !important; justify-content: space-between !important; align-items: center !important; margin: 0 !important; width: 100% !important; box-sizing: border-box !important; user-select: none !important; -webkit-user-select: none !important; transition: all 0.2s ease !important; min-height: 56px !important; white-space: nowrap !important; }
        body .bokun-booking-widget .bk-option-card:hover, body .bokun-booking-widget .bk-opt-card:hover { border-color: #800020 !important; background: #fffafa !important; }
        body .bokun-booking-widget .bk-option-card.selected, body .bokun-booking-widget .bk-opt-card.selected { border: 1.5px solid #800020 !important; background: #fff5f7 !important; }
        body .bokun-booking-widget .bk-opt-radio { width: 22px !important; height: 22px !important; border-radius: 50% !important; border: 2px solid #cbd5e1 !important; background: #ffffff !important; display: flex !important; align-items: center !important; justify-content: center !important; flex-shrink: 0 !important; transition: all 0.2s ease !important; }
        body .bokun-booking-widget .bk-option-card.selected .bk-opt-radio, body .bokun-booking-widget .bk-opt-card.selected .bk-opt-radio { border-color: #800020 !important; background: #800020 !important; }
        body .bokun-booking-widget .bk-option-card.selected .bk-opt-check, body .bokun-booking-widget .bk-opt-card.selected .bk-opt-check { display: block !important; }
        body .bokun-booking-widget .bk-form-grid { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 14px !important; width: 100% !important; }
        @media (max-width: 600px) { body .bokun-booking-widget .bk-form-grid { grid-template-columns: 1fr !important; } }
        body .bokun-booking-widget .bk-phone-group { display: flex !important; gap: 8px !important; width: 100% !important; align-items: stretch !important; position: relative !important; z-index: 50 !important; }
        body .bokun-booking-widget .bk-country-dropdown-wrapper { position: relative !important; flex-shrink: 0 !important; width: auto !important; min-width: 130px !important; z-index: 100 !important; }
        body .bokun-booking-widget .bk-country-btn { height: 44px !important; width: 100% !important; min-width: 130px !important; padding: 0 10px !important; background: #ffffff !important; border: 1.5px solid #cbd5e1 !important; border-radius: 10px !important; font-size: 13px !important; font-weight: 600 !important; color: #0f172a !important; display: flex !important; align-items: center !important; justify-content: space-between !important; gap: 6px !important; cursor: pointer !important; white-space: nowrap !important; box-shadow: 0 1px 2px rgba(0,0,0,0.04) !important; }
        body .bokun-booking-widget #bk-selected-country-label { overflow: hidden !important; text-overflow: ellipsis !important; white-space: nowrap !important; max-width: 110px !important; display: inline-block !important; }
        body .bokun-booking-widget input[type="text"], body .bokun-booking-widget input[type="email"], body .bokun-booking-widget input[type="tel"] { display: block !important; width: 100% !important; box-sizing: border-box !important; padding: 12px 14px !important; border: 1.5px solid #cbd5e1 !important; border-radius: 10px !important; font-size: 14px !important; color: #0f172a !important; background: #ffffff !important; margin: 0 !important; box-shadow: none !important; height: auto !important; min-height: 42px !important; line-height: normal !important; }
        body .bokun-booking-widget input[type="text"]:focus, body .bokun-booking-widget input[type="email"]:focus, body .bokun-booking-widget input[type="tel"]:focus { border-color: #800020 !important; outline: none !important; box-shadow: 0 0 0 3px rgba(128, 0, 32, 0.15) !important; }
        body .bokun-booking-widget .bk-card { background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04) !important; overflow: visible !important; position: relative !important; }
        body .bokun-booking-widget #bk-step-contact { position: relative !important; z-index: 40 !important; overflow: visible !important; }
        body .bokun-booking-widget .bk-sidebar { position: relative !important; z-index: 10 !important; }
        body .bokun-booking-widget .bk-ticket-card { background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05) !important; overflow: hidden !important; margin: 0 !important; width: 100% !important; }
        body .bokun-booking-widget .bk-checkout-btn { width: 100% !important; margin-top: 16px !important; padding: 16px !important; background: #800020 !important; color: #ffffff !important; border: none !important; border-radius: 12px !important; font-size: 17px !important; font-weight: 800 !important; cursor: pointer !important; display: flex !important; justify-content: center !important; align-items: center !important; gap: 10px !important; box-shadow: 0 4px 14px rgba(128, 0, 32, 0.25) !important; margin-bottom: 0 !important; }
        body .bokun-booking-widget .bk-checkout-btn:disabled { background: #e2e8f0 !important; color: #94a3b8 !important; cursor: not-allowed !important; box-shadow: none !important; }
        </style>
        <div class="bokun-booking-widget" id="bokun-widget-<?php echo esc_attr($activity_id); ?>" data-activity-id="<?php echo esc_attr($activity_id); ?>" data-currency="<?php echo esc_attr($currency); ?>" data-title="<?php echo esc_attr($atts['title']); ?>" data-min-pax="<?php echo esc_attr($min_pax); ?>" data-max-pax="<?php echo esc_attr($max_pax); ?>" data-booking-cutoff="<?php echo esc_attr($booking_cutoff_mins); ?>" data-initial-rates="<?php echo esc_attr(wp_json_encode($rates)); ?>" data-initial-categories="<?php echo esc_attr(wp_json_encode($pricing_categories)); ?>">
            <div class="bk-layout" style="display: flex !important; flex-direction: column !important; max-width: 650px !important; margin: 0 auto !important; gap: 20px !important; position: relative !important; overflow: visible !important;">
                
                <!-- Step 1: Participants -->
                <div class="bk-card" id="bk-step-participants" style="background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; padding: 22px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04) !important;">
                    <h3 class="bk-title" style="font-size: 18px !important; font-weight: 700 !important; color: #0f172a !important; margin: 0 0 16px 0 !important; padding-bottom: 8px !important; border-bottom: 2px solid #800020 !important; display: flex !important; align-items: center !important; gap: 10px !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        <span>Participants (<span id="bk-participants-total-count"><?php echo $min_pax > 0 ? $min_pax : 1; ?></span> Selected)</span>
                    </h3>

                    <?php if ($min_pax > 1): ?>
                        <div class="bk-pax-policy-badge" style="margin-bottom: 14px !important;">
                            <span style="display: inline-flex !important; align-items: center !important; gap: 6px !important; padding: 5px 10px !important; background: #fff1f2 !important; border: 1.2px solid #fecdd3 !important; border-radius: 8px !important; font-size: 12px !important; font-weight: 700 !important; color: #800020 !important;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0!important;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                <span>
                                    <?php 
                                    if ($min_pax > 1 && $max_pax > 0) {
                                        echo 'Min ' . $min_pax . ' – Max ' . $max_pax . ' Guests';
                                    } else {
                                        echo 'Min ' . $min_pax . ' Guests Required';
                                    }
                                    ?>
                                </span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div id="bk-categories-list" class="bk-categories-list" style="display: flex !important; flex-direction: column !important; gap: 12px !important;">
                        <?php if (!empty($pricing_categories)): ?>
                            <?php 
                            $has_explicit_default = false;
                            foreach ($pricing_categories as $chk_cat) {
                                if (!empty($chk_cat['defaultCategory'])) {
                                    $has_explicit_default = true;
                                    break;
                                }
                            }
                            ?>
                            <?php foreach ($pricing_categories as $idx => $pcat): 
                                $cat_title = esc_html($pcat['title'] ?? 'Participant');
                                $min_a = intval($pcat['minAge'] ?? 0);
                                $max_a = intval($pcat['maxAge'] ?? 0);
                                $is_age_qual = !empty($pcat['ageQualified']);
                                $age_lbl = '';
                                if ($is_age_qual || $min_a > 0 || $max_a > 0) {
                                    if ($min_a > 0 && $max_a > 0) { $age_lbl = 'Age ' . $min_a . ' - ' . $max_a; }
                                    elseif ($min_a > 0) { $age_lbl = 'Age ' . $min_a . '+'; }
                                    elseif ($max_a > 0) { $age_lbl = 'Age up to ' . $max_a; }
                                }
                                $is_def = !empty($pcat['defaultCategory']) || (!$has_explicit_default && $idx === 0);
                                $init_cnt = $is_def ? ($min_pax > 0 ? $min_pax : 1) : 0;
                            ?>
                            <div class="bk-category-row" data-cat-id="<?php echo esc_attr($pcat['id']); ?>" data-unit-price="<?php echo esc_attr($pcat['unitPrice'] ?? 199.00); ?>" style="display: flex !important; justify-content: space-between !important; align-items: center !important; padding-bottom: 10px !important; border-bottom: 1px solid #f1f5f9 !important;">
                                <div>
                                    <div style="display: flex !important; align-items: baseline !important; gap: 4px !important;">
                                        <span class="bk-label" style="font-size: 16px !important; font-weight: 700 !important; color: #1e293b !important;"><?php echo $cat_title; ?></span>
                                        <?php if (!empty($pcat['unitPrice']) && $pcat['unitPrice'] > 0): ?>
                                            <span style="font-size: 13px !important; color: #800020 !important; font-weight: 700 !important; margin-left: 6px !important;"><?php echo esc_html($currency); ?> <?php echo number_format($pcat['unitPrice'], 0); ?></span>
                                            <span class="bk-cat-subtotal" style="font-size: 12px !important; color: #64748b !important; font-weight: 600 !important; margin-left: 4px !important; <?php echo $init_cnt > 1 ? '' : 'display:none!important;'; ?>">(<?php echo esc_html($currency); ?> <?php echo number_format($pcat['unitPrice'] * $init_cnt, 0); ?>)</span>
                                        <?php elseif (isset($pcat['unitPrice']) && floatval($pcat['unitPrice']) == 0): ?>
                                            <span style="font-size: 12px !important; color: #16a34a !important; font-weight: 700 !important; margin-left: 6px !important;">Free</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($age_lbl): ?>
                                    <span class="bk-cat-age" style="font-size: 12px !important; color: #64748b !important; display: block !important; margin-top: 2px !important;"><?php echo esc_html($age_lbl); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="bk-counter-pill" style="display: inline-flex !important; flex-direction: row !important; align-items: center !important; justify-content: space-between !important; width: 120px !important; height: 42px !important; border: 1.8px solid #800020 !important; border-radius: 24px !important; padding: 0 10px !important; background: #ffffff !important; box-sizing: border-box !important;">
                                    <button type="button" class="bk-btn-minus" data-action="minus" onclick="if(window.bkChangeCategory)window.bkChangeCategory(event, this, -1);" aria-label="Decrease <?php echo $cat_title; ?>" style="width: 28px !important; height: 28px !important; background: transparent !important; border: none !important; color: #800020 !important; cursor: pointer !important; padding: 0 !important; margin: 0 !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 50% !important;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none!important;"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    </button>
                                    <span class="bk-val" id="bk-val-<?php echo esc_attr($pcat['id']); ?>" data-cat-id="<?php echo esc_attr($pcat['id']); ?>" style="font-size: 17px !important; font-weight: 800 !important; color: #0f172a !important; min-width: 24px !important; text-align: center !important; display: inline-block !important; user-select:none!important;"><?php echo $init_cnt; ?></span>
                                    <button type="button" class="bk-btn-plus" data-action="plus" onclick="if(window.bkChangeCategory)window.bkChangeCategory(event, this, 1);" aria-label="Increase <?php echo $cat_title; ?>" style="width: 28px !important; height: 28px !important; background: transparent !important; border: none !important; color: #800020 !important; cursor: pointer !important; padding: 0 !important; margin: 0 !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 50% !important;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none!important;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 12px 0; color: #64748b; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                                <span style="display: inline-block; width: 14px; height: 14px; border: 2px solid #800020; border-top-color: transparent; border-radius: 50%; animation: bk-spin 0.8s linear infinite;"></span>
                                Loading Bókun categories...
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Step 2: Date Selection -->
                <div class="bk-card" id="bk-step-date" style="background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; padding: 22px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04) !important;">


                    <h3 class="bk-title" style="font-size: 18px !important; font-weight: 700 !important; color: #0f172a !important; margin: 0 0 16px 0 !important; padding-bottom: 8px !important; border-bottom: 2px solid #800020 !important; display: flex !important; align-items: center !important; gap: 10px !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span>Choose a date</span>
                    </h3>
                    <div class="bk-calendar">
                        <div class="bk-cal-header" style="display: flex !important; justify-content: space-between !important; align-items: center !important; margin-bottom: 16px !important;">
                            <button type="button" class="bk-cal-prev" aria-label="Previous month" style="background: transparent !important; border: none !important; color: #800020 !important; cursor: pointer !important; padding: 6px 10px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 6px !important;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"></path></svg>
                            </button>
                            <span class="bk-cal-month" id="bk-cal-month-label" style="font-size: 17px !important; font-weight: 800 !important; color: #0f172a !important;">September 2026</span>
                            <button type="button" class="bk-cal-next" aria-label="Next month" style="background: transparent !important; border: none !important; color: #800020 !important; cursor: pointer !important; padding: 6px 10px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; border-radius: 6px !important;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"></path></svg>
                            </button>
                        </div>
                        <div class="bk-cal-weekdays" style="display: grid !important; grid-template-columns: repeat(7, minmax(0, 1fr)) !important; gap: 4px !important; text-align: center !important; font-size: 11px !important; font-weight: 700 !important; color: #64748b !important; margin-bottom: 8px !important; width: 100% !important; box-sizing: border-box !important;">
                            <span style="overflow:hidden !important; text-overflow:ellipsis !important;">Mon</span>
                            <span style="overflow:hidden !important; text-overflow:ellipsis !important;">Tue</span>
                            <span style="overflow:hidden !important; text-overflow:ellipsis !important;">Wed</span>
                            <span style="overflow:hidden !important; text-overflow:ellipsis !important;">Thu</span>
                            <span style="overflow:hidden !important; text-overflow:ellipsis !important;">Fri</span>
                            <span style="overflow:hidden !important; text-overflow:ellipsis !important;">Sat</span>
                            <span style="overflow:hidden !important; text-overflow:ellipsis !important;">Sun</span>
                        </div>
                        <div class="bk-cal-grid" id="bk-cal-grid" style="display: grid !important; grid-template-columns: repeat(7, minmax(0, 1fr)) !important; gap: 4px !important; width: 100% !important; box-sizing: border-box !important; margin: 0 !important; padding: 0 !important;"></div>
                        <div class="bk-cal-footer" style="font-size: 12px !important; color: #64748b !important; margin-top: 14px !important; text-align: left !important; font-weight: 500 !important;">Showing prices in <?php echo esc_html($atts['currency']); ?></div>
                    </div>
                </div>

                <!-- Step 3: Time Selection -->
                <div class="bk-card bk-hidden" id="bk-step-time" style="background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; padding: 22px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04) !important;">
                    <button type="button" class="bk-back-btn" id="bk-back-to-date" style="background: #fff1f2 !important; border: 1px solid #fecdd3 !important; color: #800020 !important; font-size: 13px !important; font-weight: 700 !important; cursor: pointer !important; margin-bottom: 14px !important; padding: 6px 12px !important; border-radius: 8px !important; display: inline-flex !important; align-items: center !important; gap: 6px !important;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"></path></svg>
                        <span>Back to Calendar (<span id="bk-selected-date-lbl">September 16, 2026</span>)</span>
                    </button>
                    <h3 class="bk-title" style="font-size: 18px !important; font-weight: 700 !important; color: #0f172a !important; margin: 0 0 16px 0 !important; padding-bottom: 8px !important; border-bottom: 2px solid #800020 !important; display: flex !important; align-items: center !important; gap: 10px !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Choose a time</span>
                    </h3>
                    
                    <div class="bk-time-grid" id="bk-time-grid" style="display: flex !important; flex-wrap: wrap !important; gap: 12px !important;"></div>
                </div>

                <!-- Step 4: Options Selection -->
                <div class="bk-card bk-hidden" id="bk-step-options" style="background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; padding: 22px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04) !important;">
                    <h3 class="bk-title" style="font-size: 18px !important; font-weight: 700 !important; color: #0f172a !important; margin: 0 0 16px 0 !important; padding-bottom: 8px !important; border-bottom: 2px solid #800020 !important; display: flex !important; align-items: center !important; gap: 10px !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                        <span>Rate Option</span>
                    </h3>
                    <div class="bk-options-list" id="bk-options-list" style="display: flex !important; flex-direction: column !important; gap: 12px !important;"></div>
                </div>
                
                <!-- Step 5: Contact Form -->
                <div class="bk-card bk-hidden" id="bk-step-contact" style="background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; padding: 22px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.04) !important;">
                    <h3 class="bk-title" style="font-size: 18px !important; font-weight: 700 !important; color: #0f172a !important; margin: 0 0 16px 0 !important; padding-bottom: 8px !important; border-bottom: 2px solid #800020 !important; display: flex !important; align-items: center !important; gap: 10px !important;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <span>Lead Guest Details</span>
                    </h3>
                    <div class="bk-form-grid" style="display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 14px !important;">
                        <div class="bk-field bk-field-half" style="grid-column: span 1 !important;">
                            <label for="bk-first-name" style="display: block !important; font-size: 13px !important; font-weight: 600 !important; color: #475569 !important; margin-bottom: 6px !important;">First Name *</label>
                            <div style="position: relative !important; display: flex !important; align-items: center !important;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute !important; left: 12px !important; pointer-events: none !important;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                <input type="text" id="bk-first-name" placeholder="First Name *" required style="width: 100% !important; padding: 12px 14px 12px 38px !important; border: 1.5px solid #cbd5e1 !important; border-radius: 10px !important; font-size: 14px !important; color: #0f172a !important; background: #ffffff !important; box-sizing: border-box !important;" />
                            </div>
                        </div>
                        <div class="bk-field bk-field-half" style="grid-column: span 1 !important;">
                            <label for="bk-last-name" style="display: block !important; font-size: 13px !important; font-weight: 600 !important; color: #475569 !important; margin-bottom: 6px !important;">Last Name *</label>
                            <div style="position: relative !important; display: flex !important; align-items: center !important;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute !important; left: 12px !important; pointer-events: none !important;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                <input type="text" id="bk-last-name" placeholder="Last Name *" required style="width: 100% !important; padding: 12px 14px 12px 38px !important; border: 1.5px solid #cbd5e1 !important; border-radius: 10px !important; font-size: 14px !important; color: #0f172a !important; background: #ffffff !important; box-sizing: border-box !important;" />
                            </div>
                        </div>
                        <div class="bk-field bk-field-full" style="grid-column: span 2 !important;">
                            <label for="bk-email" style="display: block !important; font-size: 13px !important; font-weight: 600 !important; color: #475569 !important; margin-bottom: 6px !important;">Email Address *</label>
                            <div style="position: relative !important; display: flex !important; align-items: center !important;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute !important; left: 12px !important; pointer-events: none !important;"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>
                                <input type="email" id="bk-email" placeholder="Email Address *" required style="width: 100% !important; padding: 12px 14px 12px 38px !important; border: 1.5px solid #cbd5e1 !important; border-radius: 10px !important; font-size: 14px !important; color: #0f172a !important; background: #ffffff !important; box-sizing: border-box !important;" />
                            </div>
                        </div>
                        <div class="bk-field bk-field-full" style="grid-column: span 2 !important;">
                            <label for="bk-phone" style="display: block !important; font-size: 13px !important; font-weight: 600 !important; color: #475569 !important; margin-bottom: 6px !important;">Phone Number *</label>
                            <div class="bk-phone-group" style="display: flex !important; gap: 8px !important; width: 100% !important; position: relative !important;">
                                <!-- Searchable Country Dropdown Component -->
                                <div class="bk-country-dropdown-wrapper" style="position: relative !important; flex-shrink: 0 !important; z-index: 100 !important; width: auto !important; min-width: 130px !important;">
                                    <input type="hidden" id="bk-country-code" value="+974" />
                                    <button type="button" id="bk-country-btn" class="bk-country-btn" style="height: 44px !important; min-width: 130px !important; padding: 0 10px !important; background: #ffffff !important; border: 1.5px solid #cbd5e1 !important; border-radius: 10px !important; font-size: 13px !important; font-weight: 600 !important; color: #0f172a !important; display: flex !important; align-items: center !important; justify-content: space-between !important; gap: 6px !important; cursor: pointer !important; white-space: nowrap !important; box-shadow: 0 1px 2px rgba(0,0,0,0.04) !important;">
                                        <span style="display: flex !important; align-items: center !important; gap: 6px !important; overflow: hidden !important;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0 !important;"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                                            <span id="bk-selected-country-label" style="overflow: hidden !important; text-overflow: ellipsis !important; white-space: nowrap !important; max-width: 100px !important; display: inline-block !important;">Qatar (+974)</span>
                                        </span>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0 !important;"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                    </button>
                                    
                                    <!-- Searchable Dropdown Popup -->
                                    <div id="bk-country-menu" class="bk-country-menu bk-hidden" style="position: absolute !important; left: 0 !important; top: calc(100% + 6px) !important; width: 280px !important; max-height: 290px !important; background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; box-shadow: 0 16px 36px rgba(0, 0, 0, 0.18), 0 4px 12px rgba(0,0,0,0.08) !important; z-index: 999999 !important; overflow: hidden !important;">
                                        <div class="bk-country-search-wrap" style="padding: 10px !important; background: #ffffff !important; border-bottom: 1px solid #f1f5f9 !important; flex-shrink: 0 !important;">
                                            <div style="position: relative !important; width: 100% !important; display: flex !important; align-items: center !important;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute !important; left: 10px !important; pointer-events: none !important;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                                <input type="text" id="bk-country-search" class="bk-country-search-input" placeholder="Search country or code..." style="width: 100% !important; padding: 8px 10px 8px 32px !important; font-size: 13px !important; color: #0f172a !important; border: 1.5px solid #cbd5e1 !important; border-radius: 8px !important; background: #ffffff !important; outline: none !important; box-sizing: border-box !important;" />
                                            </div>
                                        </div>
                                        <div id="bk-country-list" class="bk-country-list" style="overflow-y: auto !important; max-height: 220px !important; flex: 1 1 auto !important; min-height: 80px !important; width: 100% !important;">
                                            <!-- Dynamically populated via frontend.js -->
                                        </div>
                                    </div>
                                </div>
                                <div style="position: relative !important; flex: 1 !important; min-width: 0 !important; display: flex !important; align-items: center !important;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position: absolute !important; left: 12px !important; pointer-events: none !important;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                    <input type="tel" id="bk-phone" placeholder="3300 1234" required style="width: 100% !important; padding: 12px 14px 12px 38px !important; border: 1.5px solid #cbd5e1 !important; border-radius: 10px !important; font-size: 14px !important; color: #0f172a !important; background: #ffffff !important; box-sizing: border-box !important;" />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bk-error-msg" id="bk-error-msg" style="color: #e11d48 !important; font-size: 13px !important; font-weight: 600 !important; margin-top: 10px !important;"></div>
                </div>

                <!-- Single Column Booking Summary Ticket -->
                <div class="bk-sidebar" style="width: 100% !important;">
                    <div class="bk-ticket-card" style="background: #ffffff !important; border: 1px solid #e2e8f0 !important; border-radius: 14px !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05) !important; overflow: hidden !important;">
                        <div class="bk-ticket-header" style="padding: 20px 22px 0 22px !important;">
                            <h3 class="bk-title" style="font-size: 18px !important; font-weight: 700 !important; color: #0f172a !important; margin: 0 0 12px 0 !important; padding-bottom: 8px !important; border-bottom: 2px solid #800020 !important; display: flex !important; align-items: center !important; gap: 10px !important;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#800020" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v2z"></path></svg>
                                <span>Booking Summary</span>
                            </h3>
                        </div>
                        <div class="bk-ticket-body" style="padding: 12px 22px 22px 22px !important;">
                            <div class="bk-ticket-top">
                                <h4 class="bk-ticket-event" id="bk-summary-event-title" style="margin: 0 0 8px 0 !important; color: #800020 !important; font-size: 20px !important; font-weight: 800 !important; line-height: 1.25 !important;"><?php echo esc_html($atts['title']); ?></h4>
                                <div class="bk-ticket-guests" style="font-size: 15px !important; color: #1e293b !important; font-weight: 600 !important;">Participants: <span id="bk-summary-adults">1 Adult</span></div>
                            </div>
                            
                            <div style="margin: 16px 0 !important; border-top: 1px solid #f1f5f9 !important;"></div>
                            
                            <div class="bk-ticket-bottom">
                                <div class="bk-ticket-time-row" style="display: flex !important; align-items: center !important; gap: 10px !important; margin-bottom: 4px !important;">
                                    <span class="bk-ticket-time" id="bk-summary-time" style="font-size: 22px !important; font-weight: 800 !important; color: #800020 !important;">--:--</span>
                                </div>
                                <div class="bk-ticket-date" id="bk-summary-date" style="font-size: 14px !important; font-weight: 600 !important; color: #800020 !important; margin-bottom: 16px !important;">Select a date</div>
                                <div class="bk-ticket-price-box" style="display: flex !important; justify-content: space-between !important; align-items: baseline !important; border-top: 1px solid #f1f5f9 !important; padding-top: 12px !important;">
                                    <span class="bk-ticket-total-lbl" style="font-size: 14px !important; font-weight: 600 !important; color: #64748b !important;">Total:</span>
                                    <span class="bk-ticket-total-val" id="bk-summary-total" style="font-size: 24px !important; font-weight: 800 !important; color: #0f172a !important;">--</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="bk-checkout-btn" id="bk-checkout-btn" disabled style="width: 100% !important; margin-top: 16px !important; padding: 16px !important; background: #800020 !important; color: #ffffff !important; border: none !important; border-radius: 12px !important; font-size: 17px !important; font-weight: 800 !important; cursor: pointer !important; display: flex !important; justify-content: center !important; align-items: center !important; gap: 10px !important; box-shadow: 0 4px 14px rgba(128, 0, 32, 0.25) !important;">
                        <span>Proceed to Payment</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </button>
                </div>

            

            </div>
        </div>

        <?php
        return ob_get_clean();
    }
}