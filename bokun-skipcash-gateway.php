<?php
/**
 * Plugin Name:       Bókun & SkipCash Booking Gateway
 * Plugin URI:        https://github.com/your-org/bokun-skipcash-gateway
 * Description:       Integrates Bókun Tour Booking API with SkipCash Qatar payment gateway via the RESERVE_FOR_EXTERNAL_PAYMENT flow, replacing the incompatible Bókun widget.
 * Version:           2.7.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tour Operations Team
 * License:           GPL v2 or later
 * Text Domain:       bokun-skipcash
 *
 * Changelog:
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

define('BOKUN_SKIPCASH_VERSION', '2.7.3');
define('BOKUN_SKIPCASH_FILE', __FILE__);
define('BOKUN_SKIPCASH_PATH', plugin_dir_path(__FILE__));
define('BOKUN_SKIPCASH_URL', plugin_dir_url(__FILE__));
define('BOKUN_SKIPCASH_TIMEOUT_MINUTES', 30);

// Require core classes
require_once BOKUN_SKIPCASH_PATH . 'includes/class-bokun-api.php';
require_once BOKUN_SKIPCASH_PATH . 'includes/class-skipcash-api.php';
require_once BOKUN_SKIPCASH_PATH . 'includes/class-booking-controller.php';
require_once BOKUN_SKIPCASH_PATH . 'includes/class-admin-settings.php';
require_once BOKUN_SKIPCASH_PATH . 'includes/class-shortcode-ui.php';

/**
 * Main Singleton Class
 */
class Bokun_SkipCash_Plugin {
    private static $instance = null;

    public $bokun_api;
    public $skipcash_api;
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
    }

    private function init_components() {
        $this->bokun_api    = new Bokun_API();
        $this->skipcash_api = new SkipCash_API();
        $this->controller   = new Bokun_SkipCash_Booking_Controller($this->bokun_api, $this->skipcash_api);
        $this->admin        = new Bokun_SkipCash_Admin_Settings($this->bokun_api);
        $this->shortcode    = new Bokun_SkipCash_Shortcode_UI($this->bokun_api);
    }

    public function init_plugin() {
        load_plugin_textdomain('bokun-skipcash', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function enqueue_frontend_assets() {
        wp_register_style(
            'bokun-booking-styles',
            BOKUN_SKIPCASH_URL . 'assets/css/bokun-booking.css',
            array(),
            BOKUN_SKIPCASH_VERSION
        );
        wp_enqueue_style('bokun-booking-styles');

        wp_register_script('bokun-booking-scripts', BOKUN_SKIPCASH_URL . 'assets/js/bokun-booking.js', array(), time(), false);
        wp_enqueue_script('bokun-booking-scripts', BOKUN_SKIPCASH_URL . 'assets/js/bokun-booking.js', array(), time(), false);

        wp_localize_script('bokun-booking-scripts', 'BokunSkipCashConfig', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'restUrl'   => esc_url_raw(rest_url('bokun-skipcash/v1/')),
            'nonce'     => wp_create_nonce('bokun_skipcash_booking_nonce'),
            'currency'  => get_option('bokun_skipcash_currency', 'QAR'),
            'timeoutMin'=> 30
        ));
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', array('Bokun_SkipCash_Plugin', 'get_instance'));

// Activation Hook
register_activation_hook(__FILE__, function () {
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
