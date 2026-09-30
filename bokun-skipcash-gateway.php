<?php
/**
 * Plugin Name:       Bókun & SkipCash Booking Gateway
 * Plugin URI:        https://github.com/your-org/bokun-skipcash-gateway
 * Description:       Integrates Bókun Tour Booking API with SkipCash Qatar payment gateway via the RESERVE_FOR_EXTERNAL_PAYMENT flow, replacing the incompatible Bókun widget.
 * Version:           2.8.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tour Operations Team
 * License:           GPL v2 or later
 * Text Domain:       bokun-skipcash
 *
 * Changelog:
 * v2.8.0 (2026-09-30): Security & correctness hardening pass from code review.
 *  - RESTORED reservation persistence: bookings are now stored in a dedicated {prefix}bokun_reservations table (Bokun_Reservation_Store) so the confirmation flow no longer silently loses reservations ("No transient cache" regression).
 *  - PAYMENT VERIFICATION: bookings can no longer be confirmed without server-side proof of payment. The return redirect and /confirm-status endpoint now require a signed per-reservation bearer token AND a verified SkipCash API status check (statusId=2/paidDate) before calling Bókun confirm-reserved. The "returned without failure flags => assume paid" heuristic was removed.
 *  - SERVER-AUTHORITATIVE PRICING: the charged amount is computed on the server from live Bókun availability prices (never max(reservation, client amount)); client-supplied amounts are ignored except as a display hint. Removed the 199 QAR hard-coded price fallback.
 *  - AUTHENTICATION ON PUBLIC ENDPOINTS: /reserve requires a valid wp nonce (X-Bokun-Nonce header) and rate limiting; /confirm-status and /status require the reservation bearer token; webhooks now REQUIRE valid signatures (SkipCash webhook secret / Bókun HMAC) instead of treating them as optional.
 *  - REMOVED hard-coded activity/category/rate/startTime IDs (1317760, 1248692, 1248695, 5890195, 2625633, 5772342, 2577246); missing IDs now produce a clear validation error pointing to plugin settings.
 *  - CACHING: restored proper 5-minute transients for activity details, pricing categories and availabilities (previously deleted on every request, causing uncached upstream HTTP calls during page rendering). Shortcode rendering no longer performs blocking live API calls on every page view.
 *  - DATA LEAKAGE: public error responses no longer include raw upstream payloads, sent payloads containing customer PII, or internal URLs; details are logged server-side only.
 *  - ADMIN SETTINGS: register_setting now sanitizes all inputs; secrets are no longer echoed back into password field values.
 *  - SCRIPT HANDLES: fixed duplicate registration/enqueue with time() version busting; assets now use a stable version and defer loading.
 *  - Phone/email deep-search heuristics replaced with explicit validated fields; bogus default phone (+97430191237) and email (booking@example.com) fallbacks removed.
 * v1.3.9 (2026-09-29):
 *  - Removed Sunrise and Sunset labels from timesList and departure buttons, replacing them with clean AM/PM designations and converting all 24-hour formats to standard 12-hour AM/PM formats.
 * v1.3.8 (2026-09-29):
 *  - Fixed standard rate option pricing bug where selecting multiple different categories (e.g. 1 Adult @ 199 QAR + 1 Child @ 99 QAR = 298 QAR) incorrectly multiplied adult unit price by total pax (resulting in 398 QAR). Rate options now accurately aggregate each category unit price.
 *  - Made First Name, Last Name, Email, and Phone Number strictly mandatory across frontend widgets and backend reservation controller, preventing reservations with missing guest identity.
 * v1.3.7 (2026-09-28):
 *  - Dynamically fetch and synchronize ALL available pricing categories configured in Bókun (Adults, Children, Infants, Seniors, etc.).
 *  - Added dedicated /wp-json/bokun-skipcash/v1/categories REST endpoint with live Bókun category discovery and rate slot unit price mapping.
 *  - Eliminated stale 1-hour transient caching blocking newly added categories by switching to 5-minute transient with force-refresh support (?refresh=1).
 *  - Upgraded frontend category synchronization (bokun-booking.js and shortcode UI) with non-destructive multi-category merging so newly added categories immediately display with accurate QAR prices.
 *  - Updated default activity ID to live Bókun activity 1317760 (Pearl Kayaking).
 * v1.3.6 (2026-09-28):
 *  - Fixed SkipCash checkout showing only single adult price (e.g. 199 QAR) when customer selects multiple pax (e.g. 3 pax = 597 QAR).
 *  - Expanded activityBookings.passengers into individual passenger DTOs in class-bokun-api.php to strictly match Bókun OpenAPI DirectBooking schema instead of collapsing into single-item groupSize.
 *  - Attached individual passenger question answers across all selected tickets to guarantee Bókun reserves all seats.
 *  - Enhanced class-booking-controller.php to extract pricingCategories, categoryQuantities, and pricingCategoryBookings, and enforced max(reservation_total, expected_amount) so SkipCash never undercharges multi-pax bookings.
 *  - Unified checkout payload in assets/js/bokun-booking.js and class-shortcode-ui.php with explicit pricingCategoryBookings and totalOrderAmount.
 * v1.3.5 (2026-09-28):
 *  - Redesigned participant counting architecture to eliminate counter freeze and sync mismatches ("showing 2 in summary but stuck on 1 at top").
 *  - Unified assets/js/bokun-booking.js and class-shortcode-ui.php to share identical state management and dynamic category logic.
 *  - Fixed missing DOM selector #bk-adults-val by targeting .bk-val, #bk-participants-total-count, and per-category counters.
 *  - Synchronous reactive updates on plus/minus clicks for row pill, header badge, itemized subtotal, calendar date prices, and ticket total.
 * v1.3.4 (2026-09-28):
 *  - Fixed participant counter synchronization: clicking plus/minus immediately updates row counter, top participant header count badge, summary ticket breakdown, and dynamic subtotal without sticking on 1.
 *  - Preserved active user participant selections across background Bókun API hydration and month navigation.
 *  - Added dynamic participant header badge in Step 1 and shortcode summary ticket.
 * v1.3.3 (2026-09-28):
 *  - Fixed category counter reset bug by adding single-execution init guard and removing background state overwriting.
 *  - Fixed option card rate calculation in renderOptions() to dynamically reflect category unit price (QAR 199.00 per adult, QAR 597.00 for 3 adults) instead of raw slot payload anomalies (QAR 701.38).
 *  - Added live unit price and itemized group subtotal labels directly on category rows.
 *  - Synchronized total price calculation across shortcode frontend, REST reservation controller, and SkipCash payment gateway link creation.
 * v1.3.2 (2026-09-23):
 *  - Enhanced dynamic calendar with quick date shortcuts (Today, Tomorrow, Weekend, Next Week), month caching, dynamic day pricing, and live capacity badges ("X spots left", "Sold Out").
 *  - Dynamic pricing categories synchronization from Bókun REST API with zero hardcoded age ranges and accurate multi-category subtotal calculation.
 *  - Improved departure time cards with remaining seat counters, tour category labels, and guaranteed departure flags.
 *  - Bilingual English / Arabic calendar weekday and month header support.
 * v1.3.1 (2026-09-23):
 *  - Fixed SkipCash 400 validation error ("The length of 'Phone' must be 15 characters or fewer. You entered 16 characters.") caused by duplicate dial code concatenation (+974+974...).
 *  - Added Bokun_API::normalize_phone_components to universally normalize phone numbers into clean E.164 and local body without regex backslash escaping hazards.
 *  - Added length clamp and digit sanitizer in SkipCash_API::create_payment_link to guarantee Phone never exceeds 15 characters.
 *  - Fixed frontend JavaScript phone normalizer across both shortcode and standalone widget scripts to deduplicate dial prefix when typing or pasting.
 * v1.3.0 (2026-09-23):
 *  - Implemented direct Bókun customer profile update via POST /booking.json/update-customer/{bookingConfirmationCode} to guarantee phone number appears on Bókun admin dashboard.
 *  - Added dual phone synchronization (ISO 'QA' country code and dial code '+974') across reservation, webhook, and redirect flows.
 *  - Fixed response status code check in confirm_reserved_booking.
 *  - Cleaned question answer sync to avoid rejected speculative IDs on /question.json/booking/{id}.
 * v1.2.9 (2026-09-23):
 *  - Fixed Bókun backend phone number visibility by formatting numbers to strict E.164 (+974XXXXXXXX) and national digits without spaces.
 *  - Added dynamic activity-level booking questions extraction from GET /activity.json/{id} to populate custom merchant phone question IDs.
 *  - Injected answers into activityBookings[0].answers and passengerDetails[0].answers to satisfy all Bókun question contexts (BOOKING, PASSENGER, ACTIVITY).
 *  - Enhanced answer_booking_questions to accept the full reservation payload, query /question.json/activity-booking/{id}, and write detailed debug logs.
 * v1.2.8 (2026-09-23):
 *  - Resolved Bókun Main Contact phone number not saving by injecting CustomerFieldEnum uppercase identifiers (PHONE_NUMBER, FIRST_NAME, LAST_NAME, EMAIL).
 *  - Added automated multi-level question synchronization via GET & POST /question.json/booking/{bookingId} and /question.json/activity-booking/{id} during reservation.
 *  - Fixed Bókun Jackson JSON deserialization error ("Invalid JSON in body") by strictly removing extraneous customer and question properties from CheckoutRequest.
 * v1.2.7 (2026-09-22):
 *  - Made selected option card border match hover border style (1.5px solid #800020) for consistent clean aesthetics.
 * v1.2.6 (2026-09-22):
 *  - Fixed time selection so options and lead guest details box appear immediately on first click without requiring a second click.
 * v1.2.5 (2026-09-22):
 *  - Fixed country code button label truncation by allowing sufficient min-width.
 *  - Fixed country select dropdown clipping by ensuring proper stacking context (z-index) and overflow visible on contact card.
 * v1.2.4 (2026-09-22):
 *  - Reverted font family stack to native clean system sans-serif for theme compatibility.
 *  - Reduced country code dropdown trigger width to compact size for optimal phone field layout.
 *  - Removed external Google font enqueueing to eliminate font rendering conflicts.
 * v1.2.3 (2026-09-22):
 *  - Standardized phone country code dropdown labels to clean "Country (+code)" format.
 *  - Built custom interactive searchable country dropdown component.
 * v1.2.1 (2026-09-12):
 *  - Refactored Bókun mainContactDetails to send AnswerDto array and replaced quantity with groupSize.
 *  - Fixed SkipCash 403 Forbidden by dynamically signing only transmitted fields via HMAC-SHA256.
 *  - Added SkipCash Client ID header and fallback mandatory address data to resolve session creation failures.
 * v1.2.0 (2026-05-25):
 *  - Fixed HTTP 400 "Invalid JSON in body" by removing unsupported "time" string from activityBookings payload.
 *  - Added dynamic resolution of "startTimeId" from /activity.json/{id}/availabilities and startTimes.
 *  - Removed non-schema sendNotificationToMainContact parameter from CheckoutRequest payload.
 *  - Added strict integer casting and zero-indexed array_values() for pricingCategoryBookings.
 *  - Enhanced diagnostic connection tool to inspect start times, rates, and booking type.
 * v1.1.0 (2026-05-24):
 *  - Updated endpoint to POST /checkout.json/submit?currency={CURRENCY}.
 *  - Implemented pricingCategoryBookings schema mapping.
 *  - Added WP Admin connection & signature test tool.
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

define('BOKUN_SKIPCASH_VERSION', '2.8.0');
define('BOKUN_SKIPCASH_FILE', __FILE__);
define('BOKUN_SKIPCASH_PATH', plugin_dir_path(__FILE__));
define('BOKUN_SKIPCASH_URL', plugin_dir_url(__FILE__));
define('BOKUN_SKIPCASH_TIMEOUT_MINUTES', 30);

// Require core classes.
// Each include is guarded so that a single missing/unreadable file produces a
// clear admin notice instead of a fatal "Failed opening required ..." error that
// white-screens the whole site on activation.
$GLOBALS['bokun_skipcash_missing_includes'] = array();
$bokun_skipcash_includes = array(
    'includes/class-bokun-api.php',
    'includes/class-skipcash-api.php',
    'includes/class-reservation-store.php',
    'includes/class-rate-limiter.php',
    'includes/class-booking-controller.php',
    'includes/class-admin-settings.php',
    'includes/class-shortcode-ui.php',
);
foreach ($bokun_skipcash_includes as $bokun_skipcash_include) {
    $bokun_skipcash_file = BOKUN_SKIPCASH_PATH . $bokun_skipcash_include;
    if (is_readable($bokun_skipcash_file)) {
        require_once $bokun_skipcash_file;
    } else {
        $GLOBALS['bokun_skipcash_missing_includes'][] = $bokun_skipcash_include;
    }
}

if (!empty($GLOBALS['bokun_skipcash_missing_includes'])) {
    add_action('admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p><strong>Bókun &amp; SkipCash Booking Gateway:</strong> could not load %s. Reactivate or reinstall the plugin.</p></div>',
            esc_html(implode(', ', $GLOBALS['bokun_skipcash_missing_includes']))
        );
    });
    return; // Abort bootstrap: nothing can run safely without its classes.
}

/**
 * Main Singleton Class
 */
class Bokun_SkipCash_Plugin {
    private static $instance = null;

    public $bokun_api;
    public $skipcash_api;
    public $store;
    public $controller;
    public $admin;
    public $shortcode;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_components();
        add_action('init', array($this, 'init_plugin'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        // Safety net: ensure table exists even if activation hook did not fire (e.g. dropped in).
        add_action('plugins_loaded', array('Bokun_Reservation_Store', 'maybe_install'), 20);
        // Housekeeping for expired holds.
        add_action('bokun_skipcash_daily_cleanup', array($this, 'run_daily_cleanup'));
    }

    private function init_components() {
        $this->bokun_api    = new Bokun_API();
        $this->skipcash_api = new SkipCash_API();
        $this->store        = new Bokun_Reservation_Store();
        $this->controller   = new Bokun_SkipCash_Booking_Controller($this->bokun_api, $this->skipcash_api, $this->store);
        $this->admin        = new Bokun_SkipCash_Admin_Settings($this->bokun_api);
        $this->shortcode    = new Bokun_SkipCash_Shortcode_UI($this->bokun_api);
    }

    public function run_daily_cleanup() {
        $this->store->purge_expired();
    }

    public function init_plugin() {
        load_plugin_textdomain('bokun-skipcash', false, dirname(plugin_basename(__FILE__)) . '/languages');
        if (!wp_next_scheduled('bokun_skipcash_daily_cleanup')) {
            wp_schedule_event(time() + 60, 'daily', 'bokun_skipcash_daily_cleanup');
        }
    }

    public function enqueue_frontend_assets() {
        wp_register_style(
            'bokun-booking-styles',
            BOKUN_SKIPCASH_URL . 'assets/css/bokun-booking.css',
            array(),
            BOKUN_SKIPCASH_VERSION
        );
        wp_enqueue_style('bokun-booking-styles');

        // Register ONCE with a stable version (was previously registered and enqueued
        // twice with time() cache-busting, defeating browser caching on every request).
        wp_register_script('bokun-booking-scripts', BOKUN_SKIPCASH_URL . 'assets/js/bokun-booking.js', array(), BOKUN_SKIPCASH_VERSION, true);
        wp_enqueue_script('bokun-booking-scripts');

        $this->localize_booking_config();
    }

    /**
     * Print BokunSkipCashConfig for the booking script.
     *
     * IMPORTANT: wp_localize_script only emits output if it runs BEFORE the
     * script is actually printed in the page. On cached / Full-Site-Editing
     * pages the shortcode can render after print_footer_scripts, so relying on
     * the enqueue-time localize alone intermittently left window.BokunSkipCashConfig
     * undefined -> JS posted to /reserve without X-Bokun-Nonce -> REST returned
     * {"code":"bokun_invalid_nonce","message":"Security check failed..."} (403).
     *
     * We therefore (a) keep wp_localize_script for the normal path and (b) add an
     * idempotent inline-boot fallback on wp_head that defines the config object
     * before any script runs. Both calls produce identical data; whichever fires
     * first wins and the later one is a no-op guard (`window.X = window.X || {...}`).
     */
    public function localize_booking_config() {
        static $printed = false;

        $config = array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'restUrl'    => esc_url_raw(rest_url('bokun-skipcash/v1/')),
            // Fresh nonce per request, valid ~24h for logged-out visitors and
            // rotated for logged-in users. Sent by JS as X-Bokun-Nonce on /reserve.
            'nonce'      => wp_create_nonce('bokun_skipcash_booking_nonce'),
            'wpRestNonce' => wp_create_nonce('wp_rest'),
            'currency'   => get_option('bokun_skipcash_currency', 'QAR'),
            'timeoutMin' => defined('BOKUN_SKIPCASH_TIMEOUT_MINUTES') ? BOKUN_SKIPCASH_TIMEOUT_MINUTES : 30,
        );

        if (!$printed) {
            $printed = true;
            // Normal path: emitted right before the script tag when the action
            // order is standard (enqueue during wp_enqueue_scripts).
            wp_localize_script('bokun-booking-scripts', 'BokunSkipCashConfig', $config);
        }

        // Fallback path: inline boot snippet in <head>, guaranteed to be printed
        // even if the script handle was registered late or the page was cached.
        wp_add_inline_script('bokun-booking-scripts', $this->build_config_bootstrap_js($config), 'before');
    }

    /**
     * Idempotent JS that defines window.BokunSkipCashConfig if nothing else did.
     */
    private function build_config_bootstrap_js(array $config) {
        return 'window.BokunSkipCashConfig = window.BokunSkipCashConfig || ' . wp_json_encode($config) . ';';
    }
}

// Initialize on plugins_loaded — but only when every required class is present.
// Without this guard a partial deploy triggers
// "Fatal error: Uncaught Error: Class 'Bokun_API' not found" during activation.
add_action('plugins_loaded', function () {
    $required = array('Bokun_API', 'SkipCash_API', 'Bokun_Reservation_Store', 'Bokun_Rate_Limiter', 'Bokun_SkipCash_Plugin');
    foreach ($required as $class) {
        if (!class_exists($class)) {
            add_action('admin_notices', function () use ($class) {
                printf(
                    '<div class="notice notice-error"><p><strong>Bókun &amp; SkipCash Booking Gateway:</strong> missing class <code>%s</code>. The plugin is inactive until the files are restored.</p></div>',
                    esc_html($class)
                );
            });
            return;
        }
    }
    Bokun_SkipCash_Plugin::get_instance();
});

// Activation Hook
register_activation_hook(__FILE__, function () {
    if (class_exists('Bokun_Reservation_Store')) {
        Bokun_Reservation_Store::maybe_install();
    }
    if (!get_option('bokun_skipcash_currency')) {
        update_option('bokun_skipcash_currency', 'QAR');
    }
    if (!get_option('bokun_skipcash_bokun_base_url')) {
        update_option('bokun_skipcash_bokun_base_url', 'https://api.bokun.io');
    }
    if (!get_option('bokun_skipcash_skipcash_base_url')) {
        update_option('bokun_skipcash_skipcash_base_url', 'https://api.skipcash.app');
    }
});

// Deactivation Hook
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bokun_skipcash_daily_cleanup');
});
