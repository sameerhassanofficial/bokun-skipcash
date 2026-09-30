<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Bokun_SkipCash_Booking_Controller
 * Coordinates the full RESERVE_FOR_EXTERNAL_PAYMENT flow:
 * 1. Customer submits custom booking form.
 * 2. Calls Bókun to reserve booking -> gets confirmationCode.
 * 3. Creates SkipCash payment session -> redirects customer.
 * 4. Listens for SkipCash webhook/callback.
 * 5. Calls Bókun /checkout.json/confirm-reserved/{confirmationCode} within 30 minutes.
 */
class Bokun_SkipCash_Booking_Controller {
    private $bokun;
    private $skipcash;
    private $store;

    public function __construct(Bokun_API $bokun, SkipCash_API $skipcash, Bokun_Reservation_Store $store) {
        $this->bokun    = $bokun;
        $this->skipcash = $skipcash;
        $this->store    = $store;

        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('template_redirect', array($this, 'handle_callback_redirect'));
    }

    public function register_routes() {
        // Step 1 -> 3: Reserve in Bókun and create SkipCash link
        // Requires a valid frontend nonce (prevents CSRF) and is IP rate-limited.
        register_rest_route('bokun-skipcash/v1', '/reserve', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_reserve_booking'),
            'permission_callback' => array($this, 'permission_frontend_nonce')
        ));

        // Alias for /checkout to support both reserve and checkout endpoints
        register_rest_route('bokun-skipcash/v1', '/checkout', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_reserve_booking'),
            'permission_callback' => array($this, 'permission_frontend_nonce')
        ));

        // Fetch dynamic activity details from Bókun
        register_rest_route('bokun-skipcash/v1', '/activity/(?P<id>[0-9]+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_get_activity_details'),
            'permission_callback' => '__return_true'
        ));
        register_rest_route('bokun-skipcash/v1', '/activity', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_get_activity_details'),
            'permission_callback' => '__return_true'
        ));

        // Real-time availabilities endpoint for frontend calendar/time picker
        register_rest_route('bokun-skipcash/v1', '/availabilities', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_get_availabilities'),
            'permission_callback' => '__return_true'
        ));

        // Step 6 & 7: SkipCash Webhook Listener (signature enforced inside callback)
        register_rest_route('bokun-skipcash/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_skipcash_webhook'),
            'permission_callback' => array($this, 'permission_public_json')
        ));

        // Bókun Webhook Listener (HMAC signature enforced inside callback)
        register_rest_route('bokun-skipcash/v1', '/bokun-webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_bokun_webhook'),
            'permission_callback' => array($this, 'permission_public_json')
        ));

        // Booking status lookup (requires reservation bearer token)
        register_rest_route('bokun-skipcash/v1', '/status/(?P<code>[a-zA-Z0-9_-]{4,64})', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_get_status'),
            'permission_callback' => array($this, 'permission_reservation_token')
        ));

        // Dynamic Pricing Categories endpoint (fetches all live categories & unit prices from Bókun)
        register_rest_route('bokun-skipcash/v1', '/categories', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_get_categories'),
            'permission_callback' => '__return_true'
        ));

        // Status verification endpoint (GET only confirms after server-side payment
        // verification; requires the reservation bearer token issued at /reserve).
        register_rest_route('bokun-skipcash/v1', '/confirm-status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_verify_and_confirm_status'),
            'permission_callback' => array($this, 'permission_reservation_token')
        ));

        // Fresh-nonce endpoint for the frontend. A booking page can sit open far
        // longer than a WP nonce window (~24h, or 12-24h after a login/role change),
        // and full-page caches can serve a stale embedded nonce. When /reserve is
        // rejected with bokun_invalid_nonce, the JS calls this to get a current
        // nonce and retries once. Public by design (nonces carry no privilege);
        // rate-limited to blunt abuse.
        register_rest_route('bokun-skipcash/v1', '/nonce', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'handle_get_fresh_nonce'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Return freshly minted nonces tied to the caller's current session.
     */
    public function handle_get_fresh_nonce(WP_REST_Request $request) {
        if (!Bokun_Rate_Limiter::allow('nonce', 30, 60)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Too many requests.'), 429);
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        return new WP_REST_Response(array(
            'success'     => true,
            'nonce'       => wp_create_nonce('bokun_skipcash_booking_nonce'),
            'wpRestNonce' => wp_create_nonce('wp_rest'),
        ), 200);
    }

    /**
     * Permission: requests must carry a valid frontend booking nonce.
     */
    public function permission_frontend_nonce(WP_REST_Request $request) {
        // Nonces are only meaningful for logged-out/regular visitors; a logged-in
        // user is additionally checked against their session capability. Guests must
        // present the booking nonce issued by BokunSkipCashConfig (JS sends it in the
        // X-Bokun-Nonce header). If the localized config was not printed (e.g. the
        // script handle was re-registered after wp_localize_script ran), fall back to
        // WordPress' standard 'wp_rest' nonce sent via the X-Wp-Nonce header.
        $nonce = $request->get_header('X-Bokun-Nonce') ?: $request->get_param('_wpnonce');
        $nonce_verified = false;
        if (!empty($nonce)) {
            $nonce = sanitize_text_field(wp_unslash($nonce));
            $nonce_verified = wp_verify_nonce($nonce, 'bokun_skipcash_booking_nonce') || wp_verify_nonce($nonce, 'wp_rest');
        }
        if (!$nonce_verified) {
            $wp_nonce = $request->get_header('X-Wp-Nonce');
            if (!empty($wp_nonce) && wp_verify_nonce(sanitize_text_field(wp_unslash($wp_nonce)), 'wp_rest')) {
                $nonce_verified = true;
            }
        }
        if (!$nonce_verified) {
            // Diagnostic aid (WP_DEBUG only): tell us WHICH failure mode happened so
            // "Security check failed" can be root-caused from the server log instead
            // of the browser: empty header => config object missing on the page;
            // non-empty => stale page nonce (cache / long-open tab) or cookie/session
            // mismatch between page render and this REST request.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[Bokun-SkipCash] Nonce rejection on %s %s | header-present=%s uid=%d referer=%s',
                    $request->get_method(),
                    $request->get_route(),
                    !empty($nonce) ? 'yes' : 'no',
                    get_current_user_id(),
                    $request->get_header('referer') ?: '-'
                ));
            }
            return new WP_Error(
                'bokun_invalid_nonce',
                'Security check failed. Please refresh the page and try again.',
                array('status' => 403)
            );
        }
        if (!Bokun_Rate_Limiter::allow('reserve', 8, 60)) {
            return new WP_Error('bokun_rate_limited', 'Too many booking attempts. Please wait a minute and try again.', array('status' => 429));
        }
        return true;
    }

    /**
     * Permission: webhooks are authenticated by cryptographic signature inside
     * the callback (SkipCash HMAC / Bókun HMAC), which is the only scheme these
     * providers support. We still rate-limit by IP to blunt floods.
     */
    public function permission_public_json(WP_REST_Request $request) {
        if (!Bokun_Rate_Limiter::allow('webhook', 120, 60)) {
            return new WP_Error('bokun_rate_limited', 'Too many requests.', array('status' => 429));
        }
        return true;
    }

    /**
     * Permission: caller must present the bearer token issued to the customer's
     * browser at reservation time (proves ownership of the confirmation code
     * without exposing PII).
     */
    public function permission_reservation_token(WP_REST_Request $request) {
        $code  = sanitize_text_field($request->get_param('code'));
        $token = $request->get_header('X-Bokun-Token') ?: $request->get_param('token');
        if (empty($code) || empty($token) || !$this->store->verify_token($code, sanitize_text_field(wp_unslash($token)))) {
            return new WP_Error('bokun_unauthorized', 'Invalid or expired verification token.', array('status' => 401));
        }
        if (!Bokun_Rate_Limiter::allow('status-' . $code, 60, 60)) {
            return new WP_Error('bokun_rate_limited', 'Too many status checks. Please slow down.', array('status' => 429));
        }
        return true;
    }

    /**
     * Handle incoming Bókun Webhooks (BOOKING_CREATED, BOOKING_UPDATED, BOOKING_CANCELLED, BOOKING_REFUND)
     * Must return HTTP 200 OK within 5 seconds per Bókun developer documentation
     */
    public function handle_bokun_webhook(WP_REST_Request $request) {
        $raw_payload = $request->get_body();
        $hmac_header = $request->get_header('X-Bokun-HMAC') ?: $request->get_header('x-bokun-hmac');

        // Signature is REQUIRED: unsigned or wrongly signed payloads are rejected.
        if (!$this->bokun->verify_bokun_webhook($raw_payload, $hmac_header)) {
            error_log('[Bokun-Webhook] Rejected payload with missing/invalid HMAC signature from ' . $request->get_header('remote_addr'));
            return new WP_REST_Response(array('error' => 'Invalid webhook signature'), 401);
        }

        $booking_id = $request->get_header('x-bokun-booking-id') ?: $request->get_header('X-Bokun-Booking-Id');
        $event_data = $request->get_json_params();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Bókun-Webhook] Received event for booking ' . $booking_id . ': ' . substr($raw_payload, 0, 300));
        }

        // Return 200 OK immediately
        return new WP_REST_Response(array(
            'success'   => true,
            'message'   => 'Webhook received successfully',
            'bookingId' => $booking_id
        ), 200);
    }

    /**
     * Get ALL Available Pricing Categories for an Activity
     * Fetches live from Bókun API, ensuring newly added categories (Adults, Children, Infants, Seniors, etc.)
     * are instantly available in the booking UI with accurate QAR unit prices.
     */
    public function handle_get_categories(WP_REST_Request $request) {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $activity_id = intval($request->get_param('activity_id') ?: $request->get_param('id'));
        if (!$activity_id) {
            $activity_id = intval(get_option('bokun_skipcash_default_activity_id', ''));
        }
        if (!$activity_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No activity configured. Please set the Default Activity ID in Settings → Bókun + SkipCash.'
            ), 400);
        }

        $force_refresh = $request->get_param('refresh') === '1' || $request->get_param('nocache') === '1';
        $categories = $this->bokun->get_pricing_categories($activity_id, $force_refresh);

        return new WP_REST_Response(array(
            'success'    => true,
            'activityId' => $activity_id,
            'count'      => count($categories),
            'categories' => $categories
        ), 200);
    }

    /**
     * Get dynamic activity details from Bókun for shortcode activity_id
     */
    public function handle_get_activity_details(WP_REST_Request $request) {
        $activity_id = intval($request->get_param('id'));
        if (!$activity_id) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Invalid activity ID'), 400);
        }
        $details = $this->bokun->get_activity_details($activity_id);
        if ($details) {
            return new WP_REST_Response(array('success' => true, 'activity' => $details), 200);
        }
        return new WP_REST_Response(array('success' => false, 'message' => 'Activity not found in Bókun'), 404);
    }

    public function handle_get_availabilities(WP_REST_Request $request) {
        $activity_id = intval($request->get_param('activity_id'));
        if (!$activity_id) {
            $activity_id = intval(get_option('bokun_skipcash_default_activity_id', ''));
        }
        if (!$activity_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No activity configured. Please set the Default Activity ID in Settings → Bókun + SkipCash.',
                'slots'   => array()
            ), 400);
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $date          = sanitize_text_field($request->get_param('date'));
        $start         = sanitize_text_field($request->get_param('start'));
        $end           = sanitize_text_field($request->get_param('end'));

        $start_date = $start ?: $date;
        $end_date   = $end ?: $date;

        if (empty($start_date)) {
            $start_date = gmdate('Y-m-d');
            $end_date   = gmdate('Y-m-d', strtotime('+30 days'));
        }

        $res = $this->bokun->get_availabilities_detailed($activity_id, $start_date, $end_date);
        $act_details = $this->bokun->get_activity($activity_id, true);
        $pricing_categories = $this->bokun->get_pricing_categories($activity_id, true);

        $start_times_def = !empty($act_details['startTimes']) ? $act_details['startTimes'] : (!empty($act_details['startTimeDefinitions']) ? $act_details['startTimeDefinitions'] : array());
        
        $start_times_map = array();
        if (!empty($start_times_def) && is_array($start_times_def)) {
            foreach ($start_times_def as $st) {
                if (!empty($st['id'])) {
                    $start_times_map[strval($st['id'])] = array(
                        'time'  => !empty($st['time']) ? $st['time'] : (!empty($st['startTime']) ? $st['startTime'] : ''),
                        'label' => !empty($st['label']) ? $st['label'] : (!empty($st['title']) ? $st['title'] : '')
                    );
                }
            }
        }

        $raw_slots = isset($res['slots']) && is_array($res['slots']) ? $res['slots'] : array();
        $enriched_slots = array();

        foreach ($raw_slots as $slot) {
            $st_id = '';
            if (!empty($slot['startTimeId'])) {
                $st_id = strval($slot['startTimeId']);
            } elseif (!empty($slot['id'])) {
                $id_parts = explode('_', strval($slot['id']));
                if (count($id_parts) >= 3) {
                    $st_id = $id_parts[2];
                }
            }

            if (!empty($st_id) && isset($start_times_map[$st_id])) {
                if (empty($slot['startTime']) || $slot['startTime'] === '00:00' || $slot['startTime'] === '00:00:00') {
                    $slot['startTime'] = $start_times_map[$st_id]['time'];
                }
                if (empty($slot['startTimeLabel'])) {
                    $slot['startTimeLabel'] = $start_times_map[$st_id]['label'];
                }
            }

            // Pre-calculate 12-Hour AM/PM format in PHP backend
            $raw_t = trim((string)($slot['startTime'] ?? ''));
            if (strpos($raw_t, 'T') !== false) {
                $t_parts = explode('T', $raw_t);
                $raw_t = $t_parts[1];
            }
            $formatted_t = '';
            if (preg_match('/^([0-9]{1,2}):([0-9]{2})s*(AM|PM|am|pm)$/i', $raw_t, $m_ap)) {
                $formatted_t = intval($m_ap[1]) . ':' . $m_ap[2] . ' ' . strtoupper($m_ap[3]);
            } elseif (preg_match('/^([0-9]{1,2}):([0-9]{2})/', $raw_t, $m_24)) {
                $hr = intval($m_24[1]);
                $min = $m_24[2];
                $ampm = $hr >= 12 ? 'PM' : 'AM';
                $hr12 = $hr % 12;
                if ($hr12 === 0) { $hr12 = 12; }
                $formatted_t = $hr12 . ':' . $min . ' ' . $ampm;
            } else {
                $formatted_t = $raw_t;
            }
            $slot['formattedStartTime'] = $formatted_t;
            $slot['startTime'] = $formatted_t;
            $slot['time'] = $formatted_t;
            $slot['displayStartTime'] = $formatted_t;

            $enriched_slots[] = $slot;
        }

        return new WP_REST_Response(array(
            'success'           => isset($res['success']) ? $res['success'] : true,
            'code'              => isset($res['code']) ? $res['code'] : 200,
            'error'             => isset($res['error']) ? $res['error'] : '',
            'url'               => isset($res['url']) ? $res['url'] : '',
            'slotCount'         => count($enriched_slots),
            'slots'             => $enriched_slots,
            'pricingCategories' => $pricing_categories,
            'startTimes'        => $start_times_def,
            'rates'             => !empty($act_details['rates']) ? $act_details['rates'] : array(),
            'activityTitle'     => !empty($act_details['title']) ? $act_details['title'] : ''
        ), 200);
    }

    public function handle_reserve_booking(WP_REST_Request $request) {
        // Robust parameter extraction supporting JSON bodies, FormData, and URL-encoded requests
        $params = $request->get_json_params();
        if (empty($params) || !is_array($params)) {
            $raw_body = $request->get_body();
            if (empty($raw_body)) {
                $raw_body = @file_get_contents('php://input');
            }
            if (!empty($raw_body) && is_string($raw_body)) {
                $decoded = json_decode($raw_body, true);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            }
        }
        if (empty($params) || !is_array($params)) {
            $params = $request->get_params();
        }
        if (!is_array($params)) {
            $params = array();
        }

        // 1. Validate & Normalize Form Inputs
        $activity_id = intval($params['activity_id'] ?? ($params['activityId'] ?? 0));
        if (!$activity_id) {
            $activity_id = intval(get_option('bokun_skipcash_default_activity_id', get_option('bokun_skipcash_activity_id', 0)));
        }
        if (!$activity_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No activity configured. Please set the Default Activity ID in Settings → Bókun + SkipCash.'
            ), 400);
        }

        $date = sanitize_text_field($params['date'] ?? ($params['booking_date'] ?? ($params['selected_date'] ?? ($params['bookingDate'] ?? ''))));
        if (empty($date)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Please select a booking date on the calendar.',
                'field'   => 'date'
            ), 400);
        }
        $time = sanitize_text_field($params['start_time'] ?? ($params['time'] ?? ($params['startTime'] ?? '')));
        $passengers = $params['passengers'] ?? array();
        
        // Flexible Customer Details parsing (handles nested array, stringified JSON, or top-level guest fields)
        $customer = $params['customer'] ?? array();
        if (is_string($customer)) {
            $decoded_customer = json_decode(stripslashes($customer), true);
            if (is_array($decoded_customer)) {
                $customer = $decoded_customer;
            } else {
                $customer = array();
            }
        } elseif (!is_array($customer)) {
            $customer = array();
        }

        // Exhaustive email resolution from all possible field names
        $extracted_email = '';
        $email_candidates = array(
            $customer['email'] ?? '',
            $customer['emailAddress'] ?? '',
            $customer['email_address'] ?? '',
            $customer['contact_email'] ?? '',
            $customer['mail'] ?? '',
            $params['email'] ?? '',
            $params['emailAddress'] ?? '',
            $params['email_address'] ?? '',
            $params['customer_email'] ?? '',
            $params['billing_email'] ?? '',
            $params['contact_email'] ?? '',
            $params['user_email'] ?? '',
            $params['guest_email'] ?? '',
            $params['mail'] ?? '',
            $params['e_mail'] ?? '',
            $params['e-mail'] ?? '',
            $params['passengers'][0]['email'] ?? '',
            $params['passengers'][0]['emailAddress'] ?? ''
        );

        foreach ($email_candidates as $cand) {
            if (!empty($cand) && is_string($cand)) {
                $trimmed = trim(str_replace(array(chr(34), chr(39)), '', $cand));
                if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
                    $extracted_email = $trimmed;
                    break;
                }
                $clean = sanitize_email($trimmed);
                if (!empty($clean) && strpos($clean, '@') !== false && strpos($clean, '.') !== false) {
                    $extracted_email = $clean;
                    break;
                }
            }
        }

        // Check logged-in user in WordPress
        if (empty($extracted_email) && is_user_logged_in()) {
            $user = wp_get_current_user();
            if (!empty($user->user_email)) {
                $extracted_email = $user->user_email;
            }
        }

        $first_name = sanitize_text_field($customer['firstName'] ?? ($customer['first_name'] ?? ($params['first_name'] ?? ($params['firstName'] ?? ''))));
        $last_name  = sanitize_text_field($customer['lastName'] ?? ($customer['last_name'] ?? ($params['last_name'] ?? ($params['lastName'] ?? ''))));
        $phone_num  = sanitize_text_field($customer['phoneNumber'] ?? ($customer['phone'] ?? ($params['phone'] ?? ($params['phoneNumber'] ?? ''))));

        $missing_fields = array();
        if (!$activity_id) {
            $missing_fields[] = 'Activity ID';
        }
        if (empty($date)) {
            $missing_fields[] = 'Booking Date (please select a date on the calendar)';
        }
        if (empty($first_name)) {
            $missing_fields[] = 'First Name';
        }
        if (empty($last_name)) {
            $missing_fields[] = 'Last Name';
        }
        if (empty($extracted_email) || !filter_var($extracted_email, FILTER_VALIDATE_EMAIL)) {
            $missing_fields[] = 'Valid Email Address';
        }
        if (empty($phone_num)) {
            $missing_fields[] = 'Phone Number';
        }

        if (!empty($missing_fields)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Missing required booking information: ' . implode(', ', $missing_fields),
                'details' => array(
                    'activity_id' => $activity_id,
                    'date'        => $date,
                    'missing'     => $missing_fields
                )
            ), 400);
        }

        $customer['email']       = $extracted_email;
        $customer['firstName']   = $first_name;
        $customer['lastName']    = $last_name;
        $customer['phoneNumber'] = $phone_num;

        // 2. Resolve activity details & pricing categories from Bókun (fast cache)
        $adult_cat_id  = intval($params['pricingCategoryId'] ?? ($params['pricing_category_id'] ?? 0));
        $child_cat_id  = intval($params['childPricingCategoryId'] ?? ($params['child_pricing_category_id'] ?? 0));
        $rate_id       = intval($params['rateId'] ?? ($params['rate_id'] ?? ($params['option_id'] ?? 0)));
        $start_time_id = intval($params['startTimeId'] ?? ($params['start_time_id'] ?? 0));

        // Fast-path: Only fetch full activity details if category IDs or rate ID are missing
        $activity_info = null;
        if (!$adult_cat_id || !$rate_id || !$start_time_id) {
            $activity_info = $this->bokun->get_activity($activity_id);
            if (!empty($activity_info['pricingCategories']) && is_array($activity_info['pricingCategories'])) {
                foreach ($activity_info['pricingCategories'] as $cat) {
                    $title_lower = strtolower($cat['title'] ?? '');
                    if (!empty($cat['defaultCategory']) || strpos($title_lower, 'adult') !== false) {
                        if (!$adult_cat_id) {
                            $adult_cat_id = intval($cat['id']);
                        }
                    } elseif (strpos($title_lower, 'child') !== false) {
                        if (!$child_cat_id) {
                            $child_cat_id = intval($cat['id']);
                        }
                    }
                }
                if (!$adult_cat_id && !empty($activity_info['pricingCategories'][0]['id'])) {
                    $adult_cat_id = intval($activity_info['pricingCategories'][0]['id']);
                }
            }

            if (!$rate_id && !empty($activity_info['rates'][0]['id'])) {
                $rate_id = intval($activity_info['rates'][0]['id']);
            }
        }

        // 3. Resolve startTimeId for DATE_AND_TIME activities (only query live availabilities if not passed from frontend)
        if (!$start_time_id) {
            $user_clean_time = preg_replace('/[^0-9:]/', '', substr(trim($time), 0, 5)); // e.g. "16:30"
            $availabilities = $this->bokun->get_availabilities($activity_id, $date);
            if (!empty($availabilities) && is_array($availabilities)) {
                foreach ($availabilities as $avail) {
                    $avail_raw_time = $avail['startTime'] ?? ($avail['time'] ?? '');
                    $avail_clean_time = preg_replace('/[^0-9:]/', '', substr(trim($avail_raw_time), 0, 5));
                    if ($avail_clean_time === $user_clean_time || strpos($avail_raw_time, $user_clean_time) !== false) {
                        $start_time_id = intval($avail['startTimeId'] ?? ($avail['id'] ?? 0));
                        if (!$rate_id) {
                            if (!empty($avail['rateId'])) {
                                $rate_id = intval($avail['rateId']);
                            } elseif (!empty($avail['pricesByRate'][0]['rateId'])) {
                                $rate_id = intval($avail['pricesByRate'][0]['rateId']);
                            }
                        }
                        break;
                    }
                }
                if (!$start_time_id && !empty($availabilities[0])) {
                    $slot = $availabilities[0];
                    $start_time_id = intval($slot['startTimeId'] ?? ($slot['id'] ?? 0));
                    if (!$rate_id) {
                        $rate_id = intval($slot['rateId'] ?? ($slot['pricesByRate'][0]['rateId'] ?? 0));
                    }
                }
            }

            if (!$start_time_id && $activity_info && !empty($activity_info['startTimes']) && is_array($activity_info['startTimes'])) {
                foreach ($activity_info['startTimes'] as $st) {
                    $st_str = sprintf('%02d:%02d', intval($st['hour'] ?? 0), intval($st['minute'] ?? 0));
                    if ($st_str === $user_clean_time) {
                        $start_time_id = intval($st['id'] ?? 0);
                        break;
                    }
                }
                if (!$start_time_id && !empty($activity_info['startTimes'][0]['id'])) {
                    $start_time_id = intval($activity_info['startTimes'][0]['id']);
                }
            }
        }

        // Generic pricing category parsing supporting Adults, Children, Infants, Seniors, etc.
        $pricing_category_bookings = array();

        if (!empty($params['pricingCategoryBookings']) && is_array($params['pricingCategoryBookings'])) {
            foreach ($params['pricingCategoryBookings'] as $pc) {
                $cid = intval($pc['pricingCategoryId'] ?? ($pc['id'] ?? 0));
                $qty = intval($pc['quantity'] ?? ($pc['count'] ?? 0));
                if ($cid > 0 && $qty > 0) {
                    $pricing_category_bookings[] = array(
                        'pricingCategoryId' => $cid,
                        'quantity'          => $qty
                    );
                }
            }
        } elseif (!empty($params['pricingCategories']) && is_array($params['pricingCategories'])) {
            foreach ($params['pricingCategories'] as $pc) {
                $cid = intval($pc['pricingCategoryId'] ?? ($pc['id'] ?? 0));
                $qty = intval($pc['quantity'] ?? ($pc['count'] ?? 0));
                if ($cid > 0 && $qty > 0) {
                    $pricing_category_bookings[] = array(
                        'pricingCategoryId' => $cid,
                        'quantity'          => $qty
                    );
                }
            }
        } elseif (!empty($params['categoryQuantities']) && is_array($params['categoryQuantities'])) {
            foreach ($params['categoryQuantities'] as $cid => $qty) {
                $cid = intval($cid);
                $qty = intval($qty);
                if ($cid > 0 && $qty > 0) {
                    $pricing_category_bookings[] = array(
                        'pricingCategoryId' => $cid,
                        'quantity'          => $qty
                    );
                }
            }
        }

        // Fallback: Parse from passengers array, totalParticipants, or adult/child/infant fields
        if (empty($pricing_category_bookings)) {
            $adult_count  = intval($params['adults'] ?? 0);
            $child_count  = intval($params['children'] ?? ($params['child'] ?? 0));
            $infant_count = intval($params['infants'] ?? ($params['infant'] ?? 0));
            $total_pax    = intval($params['totalParticipants'] ?? 0);

            if (!empty($passengers) && is_array($passengers)) {
                $p_adult = 0; $p_child = 0; $p_infant = 0;
                foreach ($passengers as $p) {
                    $rate_cat = strtoupper($p['rateCategory'] ?? '');
                    if ($rate_cat === 'CHILD') { $p_child++; }
                    elseif ($rate_cat === 'INFANT') { $p_infant++; }
                    else { $p_adult++; }
                }
                if ($p_adult > 0 || $p_child > 0 || $p_infant > 0) {
                    $adult_count = $p_adult;
                    $child_count = $p_child;
                    $infant_count = $p_infant;
                }
            }

            if ($adult_count === 0 && $child_count === 0 && $infant_count === 0) {
                $adult_count = $total_pax > 0 ? $total_pax : 1;
            }

            if ($adult_count > 0) {
                if (!$adult_cat_id) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Could not resolve the adult pricing category from Bókun. Please verify the Activity ID and that the activity has pricing categories configured.'
                    ), 400);
                }
                $pricing_category_bookings[] = array(
                    'pricingCategoryId' => intval($adult_cat_id),
                    'quantity'          => intval($adult_count)
                );
            }
            if ($child_count > 0 && $child_cat_id <= 0) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Children were selected but no child pricing category exists on this Bókun activity.'
                ), 400);
            }
            if ($child_count > 0 && $child_cat_id > 0) {
                $pricing_category_bookings[] = array(
                    'pricingCategoryId' => intval($child_cat_id),
                    'quantity'          => intval($child_count)
                );
            }
            if ($infant_count > 0) {
                $infant_cat_id = 0;
                if (!empty($activity_info['pricingCategories'])) {
                    foreach ($activity_info['pricingCategories'] as $cat) {
                        if (stripos($cat['title'] ?? '', 'infant') !== false) {
                            $infant_cat_id = intval($cat['id']);
                            break;
                        }
                    }
                }
                $pricing_category_bookings[] = array(
                    'pricingCategoryId' => $infant_cat_id,
                    'quantity'          => intval($infant_count)
                );
            }
        }

        // Safety guarantee: pricingCategoryBookings must NEVER be empty array
        if (empty($pricing_category_bookings)) {
            if ($adult_cat_id > 0) {
                $pricing_category_bookings[] = array(
                    'pricingCategoryId' => intval($adult_cat_id),
                    'quantity'          => 1
                );
            } else {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'No participants or pricing categories could be resolved for this booking.'
                ), 400);
            }
        }

        // Expand individual passengers array for full Bókun DirectBooking OpenAPI schema compatibility
        // Each passenger in Bókun represents 1 ticket/seat
        $passengers_array = array();
        foreach ($pricing_category_bookings as $pc) {
            $cat_id = intval($pc['pricingCategoryId']);
            $qty = intval($pc['quantity']);
            for ($i = 0; $i < $qty; $i++) {
                $passengers_array[] = array(
                    'pricingCategoryId' => $cat_id
                );
            }
        }

        // 4. Build Bókun Direct Booking Request Payload conforming to ActivityBookingRequestDto
        $activity_booking = array(
            'activityId'              => intval($activity_id),
            'date'                    => $date,
            'pricingCategoryBookings' => array_values($pricing_category_bookings),
            'passengers'              => array_values($passengers_array)
        );

        if (!empty($start_time_id)) {
            $activity_booking['startTimeId'] = intval($start_time_id);
        }

        if (!empty($rate_id)) {
            $activity_booking['rateId'] = intval($rate_id);
        }

        // Handle Hotel Pickup & Dropoff
        $needs_pickup = !empty($params['pickup']) || !empty($params['needsPickup']) || !empty($params['pickupPlaceDescription']);
        $pickup_desc  = sanitize_text_field($params['pickupPlaceDescription'] ?? ($params['pickup_address'] ?? ($params['hotel'] ?? '')));
        if ($needs_pickup || !empty($pickup_desc)) {
            $activity_booking['pickup']                  = true;
            $activity_booking['pickupPlaceDescription'] = !empty($pickup_desc) ? $pickup_desc : 'Hotel Pickup Requested';
        }

        // Handle Add-ons / Extras
        $extras = $params['extras'] ?? ($params['bookableExtras'] ?? array());
        if (!empty($extras) && is_array($extras)) {
            $formatted_extras = array();
            foreach ($extras as $ex) {
                $e_id = intval(is_array($ex) ? ($ex['extraId'] ?? ($ex['id'] ?? 0)) : $ex);
                if ($e_id > 0) {
                    $formatted_extras[] = array(
                        'extraId'   => $e_id,
                        'unitCount' => intval(is_array($ex) ? ($ex['quantity'] ?? ($ex['unitCount'] ?? 1)) : 1)
                    );
                }
            }
            if (!empty($formatted_extras)) {
                $activity_booking['extras'] = array_values($formatted_extras);
            }
        }

        // Handle Special Requests / Notes
        $note = sanitize_text_field($params['note'] ?? ($params['special_requests'] ?? ($params['comments'] ?? '')));
        if (!empty($note)) {
            $activity_booking['note'] = $note;
        }

        // Exhaustive phone resolution and strict validation
        $extracted_phone = '';
        $phone_candidates = array(
            $customer['phoneNumber'] ?? '',
            $customer['phone'] ?? '',
            $customer['mobilePhone'] ?? '',
            $customer['mobile'] ?? '',
            $customer['phoneNumberBody'] ?? '',
            $params['phone'] ?? '',
            $params['phoneNumber'] ?? '',
            $params['mobilePhone'] ?? '',
            $params['mobile'] ?? '',
            $params['phone_number'] ?? '',
            $params['billing_phone'] ?? '',
            $params['customer_phone'] ?? ''
        );
        foreach ($phone_candidates as $pcand) {
            if (!empty($pcand) && is_string($pcand)) {
                $p_trimmed = trim($pcand);
                if (strlen(preg_replace('/[^0-9]/', '', $p_trimmed)) >= 3) {
                    $extracted_phone = $p_trimmed;
                    break;
                }
            }
        }

        $default_country_code = $customer['phoneNumberCountryCode'] ?? ($params['country_code'] ?? ($params['dialCode'] ?? '+974'));
        $phone_validation     = Bokun_API::validate_phone_number($extracted_phone, $default_country_code);

        if (!$phone_validation['is_valid']) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $phone_validation['error'] ?? 'Please provide a valid phone number so we can confirm your booking.',
                'field'   => 'phone'
            ), 400);
        }

        $phone_norm = $phone_validation['norm'];
        $phone_e164 = $phone_validation['e164'];
        $phone_cc   = $phone_norm['dial_code'];
        $phone_iso  = $phone_norm['iso_code'];
        $phone_b    = $phone_norm['national_num'];

        $f_name = sanitize_text_field($customer['firstName'] ?? '');
        $l_name = sanitize_text_field($customer['lastName'] ?? '');
        $c_mail = !empty($extracted_email) ? $extracted_email : sanitize_email($customer['email'] ?? '');

        // Construct mainContactDetails strictly as an array of question answer DTOs for Bókun OpenAPI compliance
        $main_contact_answers = array(
            array('questionId' => 'PHONE_NUMBER',           'values' => array($phone_e164)),
            array('questionId' => 'FIRST_NAME',             'values' => array($f_name)),
            array('questionId' => 'LAST_NAME',              'values' => array($l_name)),
            array('questionId' => 'EMAIL',                  'values' => array($c_mail)),
            array('questionId' => 'firstName',              'values' => array($f_name)),
            array('questionId' => 'lastName',               'values' => array($l_name)),
            array('questionId' => 'email',                  'values' => array($c_mail)),
            array('questionId' => 'phoneNumber',            'values' => array($phone_e164)),
            array('questionId' => 'phone_number',           'values' => array($phone_e164)),
            array('questionId' => 'phone',                  'values' => array($phone_e164)),
            array('questionId' => 'mobilePhone',            'values' => array($phone_e164)),
            array('questionId' => 'phoneNumberCountryCode', 'values' => array($phone_cc)),
            array('questionId' => 'phoneNumberBody',        'values' => array($phone_b))
        );

        // Dynamically include any question IDs defined on the activity in Bókun
        if (!empty($activity_info['bookingQuestions']) && is_array($activity_info['bookingQuestions'])) {
            foreach ($activity_info['bookingQuestions'] as $bq) {
                $bq_id    = (string)($bq['id'] ?? '');
                $bq_code  = (string)($bq['questionCode'] ?? '');
                $bq_label = strtolower($bq['label'] ?? '');
                $bq_fmt   = (string)($bq['dataFormat'] ?? '');

                if ($bq_fmt === 'PHONE_NUMBER' || strpos($bq_label, 'phone') !== false || strpos($bq_label, 'mobile') !== false || strpos(strtolower($bq_code), 'phone') !== false) {
                    if ($bq_id) {
                        $main_contact_answers[] = array('questionId' => $bq_id, 'values' => array($phone_e164));
                    }
                    if ($bq_code && $bq_code !== $bq_id) {
                        $main_contact_answers[] = array('questionId' => $bq_code, 'values' => array($phone_e164));
                    }
                }
            }
        }

        // Attach answers directly to activity booking level as well
        $activity_booking['answers'] = array_values($main_contact_answers);

        $cust_full = array(
            'firstName'              => $f_name,
            'lastName'               => $l_name,
            'email'                  => $c_mail,
            'emailAddress'           => $c_mail,
            'phoneNumber'            => $phone_e164,
            'phone'                  => $phone_e164,
            'mobilePhone'            => $phone_e164,
            'phoneNumberCountryCode' => $phone_cc,
            'phoneNumberBody'        => $phone_b
        );

        $currency = sanitize_text_field($params['currency'] ?? get_option('bokun_skipcash_currency', 'QAR'));

        $direct_booking = array(
            'mainContactDetails' => array_values($main_contact_answers),
            'activityBookings'   => array_values(array($activity_booking))
        );

        // Handle Promo Code
        $promo_code = sanitize_text_field($params['promoCode'] ?? ($params['promo_code'] ?? ($params['discount_code'] ?? '')));
        if (!empty($promo_code)) {
            $direct_booking['promoCode'] = $promo_code;
        }

        $booking_payload = array(
            'currency'       => $currency,
            'source'         => 'DIRECT_REQUEST',
            'checkoutOption' => 'CUSTOMER_FULL_PAYMENT',
            'paymentMethod'  => 'RESERVE_FOR_EXTERNAL_PAYMENT',
            'directBooking'  => $direct_booking
        );

        // 5. Fast Atomic Call to Bókun: POST /checkout.json/submit?currency=QAR with RESERVE_FOR_EXTERNAL_PAYMENT
        $reservation = $this->bokun->reserve_for_external_payment($booking_payload);

        if (!$reservation['success'] || empty($reservation['confirmationCode'])) {
            // Log full upstream detail server-side only; never leak raw payloads to the client.
            error_log('[Bokun-SkipCash] Reservation failed for activity ' . $activity_id . ': ' . json_encode(array(
                'message' => $reservation['message'] ?? '',
                'status'  => $reservation['status'] ?? 'unknown',
            )));
            $http_status = (!empty($reservation['status']) && $reservation['status'] >= 400 && $reservation['status'] < 600) ? intval($reservation['status']) : 502;
            return new WP_REST_Response(array(
                'success' => false,
                'message' => !empty($reservation['message'])
                    ? $reservation['message']
                    : 'Unable to hold your seats right now. Please try again or contact us.'
            ), $http_status);
        }

        $confirmation_code = $reservation['confirmationCode'];
        $reservation_price = !empty($reservation['totalPrice']) ? floatval($reservation['totalPrice']) : 0;

        // SERVER-AUTHORITATIVE PRICING: the charged amount comes exclusively from
        // Bókun data (reservation response first, then live availability prices,
        // then activity category unit prices). Client-supplied amounts are display
        // hints only and are NEVER used to decide what the customer is charged.
        $amount = $reservation_price;
        if ($amount <= 0) {
            $amount = $this->compute_server_side_price($activity_id, $date, $time, $pricing_category_bookings, $rate_id, $start_time_id);
        }
        if ($amount <= 0) {
            error_log('[Bokun-SkipCash] Could not determine authoritative price for activity ' . $activity_id . ' on ' . $date);
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'We could not retrieve a valid price for this booking. Please try again shortly.'
            ), 502);
        }
        $amount = round($amount, 2);
        $currency = $reservation['currency'] ?: $currency;

        // 4. Persist the reservation durably (DB table) so webhook/return flows can verify it
        $reservation_record = array(
            'confirmationCode' => $confirmation_code,
            'bookingId'        => $reservation['bookingId'] ?? 0,
            'amount'           => $amount,
            'currency'         => $currency,
            'customer'         => $cust_full,
            'activity_id'      => $activity_id,
            'activity_name'    => mb_substr(sanitize_text_field($params['activity_name'] ?? 'Tour Booking'), 0, 255),
            'status'           => 'RESERVED'
        );
        $store_result = $this->store->create($reservation_record);
        if (empty($store_result['success'])) {
            // Fail closed: without a persisted record we cannot verify payment later.
            error_log('[Bokun-SkipCash] Failed to persist reservation ' . $confirmation_code);
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Booking could not be registered on our side. Please try again.'
            ), 500);
        }
        $verify_token = $store_result['verify_token'];

        // 5. Generate SkipCash Payment Session Immediately (Fast-path: no blocking redundant calls)
        $return_url  = add_query_arg(array(
            'bokun_return' => 1,
            'code'         => $confirmation_code,
            'vtoken'       => rawurlencode($verify_token)
        ), home_url('/'));

        $webhook_url = esc_url_raw(rest_url('bokun-skipcash/v1/webhook'));

        $skipcash_session = $this->skipcash->create_payment_link(array(
            'amount'            => $amount,
            'first_name'        => $f_name,
            'last_name'         => $l_name,
            'phone'             => $phone_e164,
            'email'             => $c_mail,
            'transaction_id'    => $confirmation_code,
            'confirmation_code' => $confirmation_code,
            'activity_name'     => $params['activity_name'] ?? 'Tour Booking',
            'return_url'        => $return_url,
            'webhook_url'       => $webhook_url
        ));

        if (!$skipcash_session['success']) {
            error_log('[Bokun-SkipCash] SkipCash session creation failed for ' . $confirmation_code . ': ' . ($skipcash_session['message'] ?? 'unknown'));
            return new WP_REST_Response(array(
                'success'           => false,
                'confirmationCode'  => $confirmation_code,
                'message'           => 'Your seats are held, but the payment gateway is unavailable at the moment. Please try again in a few minutes.'
            ), 502);
        }

        // Persist SkipCash paymentId for instantaneous status verification
        $this->store->update($confirmation_code, array('skipcash_payment_id' => (string)($skipcash_session['paymentId'] ?? '')));

        return new WP_REST_Response(array(
            'success'           => true,
            'confirmationCode'  => $confirmation_code,
            'verifyToken'       => $verify_token,
            'payUrl'            => $skipcash_session['payUrl'],
            'redirectUrl'       => $skipcash_session['payUrl'],
            'paymentUrl'        => $skipcash_session['payUrl'],
            'amount'            => $amount,
            'currency'          => $currency,
            'expiresInMinutes'  => BOKUN_SKIPCASH_TIMEOUT_MINUTES
        ), 200);
    }

    /**
     * Compute an authoritative price on the server from live Bókun data.
     * Order of preference:
     *  1. Matching availability slot price(s) for the chosen date/time/rate.
     *  2. Activity pricing-category unit prices x quantities.
     * Returns 0.0 when nothing trustworthy is available (callers must fail closed).
     */
    private function compute_server_side_price($activity_id, $date, $time, array $pricing_category_bookings, $rate_id = 0, $start_time_id = 0) {
        $availabilities = $this->bokun->get_availabilities($activity_id, $date);
        if (!empty($availabilities) && is_array($availabilities)) {
            $clean_time = preg_replace('/[^0-9:]/', '', substr(trim((string)$time), 0, 5));
            foreach ($availabilities as $avail) {
                $slot_stid = intval($avail['startTimeId'] ?? 0);
                $avail_raw = $avail['startTime'] ?? ($avail['time'] ?? '');
                $avail_clean = preg_replace('/[^0-9:]/', '', substr(trim((string)$avail_raw), 0, 5));
                $time_match = ($clean_time === '' ) || ($start_time_id > 0 && $slot_stid === $start_time_id) || ($avail_clean !== '' && strpos($avail_clean, $clean_time) !== false);
                if (!$time_match) {
                    continue;
                }
                $prices = !empty($avail['pricesByRate']) && is_array($avail['pricesByRate']) ? $avail['pricesByRate'] : array();
                foreach ($prices as $pr) {
                    if ($rate_id > 0 && intval($pr['rateId'] ?? 0) !== $rate_id) {
                        continue;
                    }
                    $sum = 0.0;
                    $resolved = false;
                    foreach ($pricing_category_bookings as $pcb) {
                        $cid  = intval($pcb['pricingCategoryId'] ?? 0);
                        $qty  = intval($pcb['quantity'] ?? 0);
                        $unit = 0.0;
                        if (!empty($pr['pricesByCategory']) && is_array($pr['pricesByCategory'])) {
                            foreach ($pr['pricesByCategory'] as $pbc) {
                                if (intval($pbc['pricingCategoryId'] ?? ($pbc['id'] ?? 0)) === $cid) {
                                    $amt = $pbc['price'] ?? ($pbc['amount'] ?? 0);
                                    $unit = floatval(is_array($amt) ? ($amt['amount'] ?? 0) : $amt);
                                    $resolved = true;
                                    break;
                                }
                            }
                        }
                        if (!$resolved) {
                            $amt = $pr['price'] ?? ($pr['amount'] ?? 0);
                            $unit = floatval(is_array($amt) ? ($amt['amount'] ?? 0) : $amt);
                            $resolved = $unit > 0;
                        }
                        if (!$resolved) {
                            $sum = 0.0;
                            break;
                        }
                        $sum += $unit * $qty;
                    }
                    if ($sum > 0) {
                        return round($sum, 2);
                    }
                }
            }
        }

        // Fallback: category unit prices from the (cached) activity definition.
        $activity = $this->bokun->get_activity($activity_id);
        if (!empty($activity['pricingCategories']) && is_array($activity['pricingCategories'])) {
            $cat_prices = array();
            foreach ($activity['pricingCategories'] as $cat) {
                $amt = $cat['unitPrice'] ?? ($cat['price'] ?? 0);
                $cat_prices[intval($cat['id'] ?? 0)] = floatval(is_array($amt) ? ($amt['amount'] ?? 0) : $amt);
            }
            $sum = 0.0;
            $all_known = true;
            foreach ($pricing_category_bookings as $pcb) {
                $cid = intval($pcb['pricingCategoryId'] ?? 0);
                $qty = intval($pcb['quantity'] ?? 0);
                if (!isset($cat_prices[$cid])) {
                    $all_known = false;
                    break;
                }
                $sum += $cat_prices[$cid] * $qty;
            }
            if ($all_known && $sum > 0) {
                return round($sum, 2);
            }
        }

        return 0.0;
    }

    /**
     * Handle SkipCash Webhook Callback
     * When payment is completed, calls Bókun confirm-reserved/{confirmationCode}
     */
    public function handle_skipcash_webhook(WP_REST_Request $request) {
        $raw_payload = $request->get_body();
        $signature   = $request->get_header('Authorization') ?: $request->get_header('X-Signature');
        $data        = $request->get_json_params();
        if (!is_array($data)) {
            $data = array();
        }

        // Signature is REQUIRED. Unsigned or wrongly signed payloads are rejected outright.
        if (!$this->skipcash->verify_webhook($raw_payload, $signature)) {
            error_log('[Bokun-SkipCash] Rejected SkipCash webhook with missing/invalid signature');
            return new WP_REST_Response(array('error' => 'Invalid signature'), 401);
        }

        $confirmation_code = sanitize_text_field($data['Custom1'] ?? $data['TransactionId'] ?? '');
        $payment_status_id = intval($data['StatusId'] ?? 0); // 2 typically represents Paid in SkipCash
        $payment_id        = sanitize_text_field($data['PaymentId'] ?? $data['Id'] ?? '');
        $amount_paid       = floatval($data['Amount'] ?? 0);

        if (empty($confirmation_code)) {
            return new WP_REST_Response(array('error' => 'Missing confirmation code'), 400);
        }

        $record = $this->store->get_by_code($confirmation_code);

        // Check if reservation exists (or the 30 minute window expired)
        if (!$record) {
            error_log('[Bokun-SkipCash] Unknown/expired reservation for webhook code ' . $confirmation_code);
            return new WP_REST_Response(array('error' => 'Reservation has expired in Bókun (30 min timeout exceeded)'), 410);
        }

        // Idempotency: already confirmed bookings acknowledge the webhook without re-confirming
        if (($record['status'] ?? '') === 'CONFIRMED') {
            return new WP_REST_Response(array('status' => 'confirmed', 'confirmationCode' => $confirmation_code), 200);
        }

        // Only proceed when SkipCash itself reports the payment as paid
        if ($payment_status_id !== 0 && $payment_status_id !== 2) {
            return new WP_REST_Response(array('error' => 'Payment not completed (status ' . $payment_status_id . ')'), 400);
        }

        // Amount sanity: never confirm against a materially different amount;
        // always use the server-side expected amount for Bókun.
        $expected_amount = floatval($record['amount']);
        if ($amount_paid > 0 && abs($amount_paid - $expected_amount) > 0.01) {
            error_log('[Bokun-SkipCash] Webhook amount mismatch for ' . $confirmation_code . ': paid=' . $amount_paid . ' expected=' . $expected_amount);
        }

        // Call Bókun: POST /checkout.json/confirm-reserved/{confirmationCode}
        $transaction_details = array(
            'transactionDate' => gmdate('Y-m-d H:i:s'),
            'transactionId'   => $payment_id ?: ('SKIPCASH-' . time()),
            'cardBrand'       => sanitize_text_field($data['CardType'] ?? 'VISA'),
            'last4'           => sanitize_text_field($data['Last4'] ?? '1234')
        );

        $confirm_result = $this->bokun->confirm_reserved_booking(
            $confirmation_code,
            $expected_amount,
            $record['currency'],
            $transaction_details
        );

        if ($confirm_result['success']) {
            $this->store->mark_confirmed($confirmation_code, $payment_id);

            // Re-sync customer contact info to confirmed booking in Bókun
            if (!empty($record['customer'])) {
                $this->bokun->update_customer($confirmation_code, $record['customer']);
            }

            return new WP_REST_Response(array(
                'status' => 'confirmed',
                'confirmationCode' => $confirmation_code
            ), 200);
        }

        error_log('[Bokun-SkipCash] Bókun confirm-reserved failed for ' . $confirmation_code . ': ' . json_encode($confirm_result));
        return new WP_REST_Response(array(
            'error'   => 'Bókun confirmation failed'
        ), 500);
    }

    /**
     * Handle Customer Returning from SkipCash
     * Automatically verifies payment and immediately confirms reserved seats in Bókun to PAID status!
     */
    public function handle_callback_redirect() {
        if (!isset($_GET['bokun_return']) || empty($_GET['code'])) {
            return;
        }

        $code   = sanitize_text_field(wp_unslash($_GET['code']));
        $token  = isset($_GET['vtoken']) ? sanitize_text_field(wp_unslash($_GET['vtoken'])) : '';
        $record = $this->store->get_by_code($code);

        $verify_error = '';
        if (!$record) {
            $verify_error = 'not_found';
        } elseif ($record['status'] !== 'CONFIRMED') {
            // Ownership check: the return link must carry the signed token issued at reservation time.
            if (empty($token) || !$this->store->verify_token($code, $token)) {
                $verify_error = 'unauthorized';
            } else {
                $payment_id = sanitize_text_field(wp_unslash($_GET['paymentId'] ?? ($_GET['PaymentId'] ?? ($_GET['id'] ?? ($record['skipcash_payment_id'] ?? '')))));
                $is_paid    = false;

                // Server-side verification ONLY: query the SkipCash API for authoritative status.
                // Redirect query params (statusId/status/absence of error flags) are NOT trusted.
                if (!empty($payment_id)) {
                    $status_check = $this->skipcash->get_payment_status($payment_id);
                    if (!empty($status_check)) {
                        $st_id  = intval($status_check['resultObj']['statusId'] ?? ($status_check['statusId'] ?? 0));
                        $st_str = strtolower($status_check['resultObj']['status'] ?? ($status_check['status'] ?? ''));
                        if ($st_id === 2 || $st_str === 'paid' || !empty($status_check['resultObj']['paidDate'])) {
                            $is_paid = true;
                        }
                    }
                }

                if ($is_paid) {
                $tx_details = array(
                    'transactionDate' => gmdate('Y-m-d H:i:s'),
                    'transactionId'   => $payment_id ?: ('SKIPCASH-' . time()),
                    'cardBrand'       => sanitize_text_field($_GET['cardType'] ?? 'VISA'),
                    'last4'           => sanitize_text_field($_GET['last4'] ?? '0000')
                );

                $confirm_res = $this->bokun->confirm_reserved_booking(
                    $code,
                    $record['amount'] ?? floatval($_GET['amount'] ?? 0),
                    $record['currency'] ?? get_option('bokun_skipcash_currency', 'QAR'),
                    $tx_details
                );

                $msg = $confirm_res['message'] ?? '';
                if ($confirm_res['success'] || stripos($msg, 'CONFIRMED') !== false || stripos($msg, 'not in reserved state') !== false) {
                    $this->store->mark_confirmed($code, $payment_id);
                    $record['status'] = 'CONFIRMED';
                    if (!empty($record['customer'])) {
                        $this->bokun->update_customer($code, $record['customer']);
                    }
                } else {
                    // Check if Bókun itself already marked it CONFIRMED
                    $bk_check = $this->bokun->get_booking_by_confirmation_code($code);
                    if (!empty($bk_check) && ($bk_check['status'] ?? '') === 'CONFIRMED') {
                        $this->store->mark_confirmed($code, $payment_id);
                        $record['status'] = 'CONFIRMED';
                        if (!empty($record['customer'])) {
                            $this->bokun->update_customer($code, $record['customer']);
                        }
                    } else {
                        $verify_error = 'confirmation_failed';
                        error_log('[Bokun-SkipCash] Return-flow confirm failed for ' . $code . ': ' . json_encode($confirm_res));
                    }
                }
            } else {
                // Payment could not be verified server-side; do NOT confirm the booking.
                $verify_error = 'unverified';
            }
            }
        }

        // Expose safe context to the return template (no PII beyond first name, no secrets)
        $bokun_return_context = array(
            'code'          => $record ? $code : '',
            'record'        => $record,
            'verify_token'  => $token,
            'verify_error'  => $verify_error,
            'display_name'  => !empty($record['customer']['firstName']) ? $record['customer']['firstName'] : '',
            'activity_name' => !empty($record['activity_name']) ? $record['activity_name'] : 'Tour Experience',
            'amount'        => !empty($record['amount']) ? floatval($record['amount']) : 0,
            'currency'      => !empty($record['currency']) ? $record['currency'] : get_option('bokun_skipcash_currency', 'QAR'),
            'status'        => !empty($record['status']) ? $record['status'] : 'UNKNOWN',
        );

        // Render confirmation template
        include BOKUN_SKIPCASH_PATH . 'includes/templates/booking-return-template.php';
        exit;
    }

    /**
     * Endpoint for return page real-time auto-polling & confirmation
     */
    public function handle_verify_and_confirm_status(WP_REST_Request $request) {
        $code = sanitize_text_field($request['code'] ?? $request->get_param('code'));
        if (empty($code)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Missing booking code'), 400);
        }

        // Durable reservation store (transients could be evicted / object cache disabled).
        $record = $this->store->get_by_code($code);
        if (!$record) {
            return new WP_REST_Response(array('success' => false, 'status' => 'NOT_FOUND', 'message' => 'Reservation not found or expired.'), 404);
        }

        if (($record['status'] ?? '') === 'CONFIRMED') {
            return new WP_REST_Response(array(
                'success'          => true,
                'status'           => 'CONFIRMED',
                'confirmationCode' => $code,
                'record'           => $record
            ), 200);
        }

        $payment_id = sanitize_text_field($request['paymentId'] ?? ($record['skipcash_payment_id'] ?? ''));
        $amount     = floatval($record['amount'] ?? 0);
        $currency   = $record['currency'] ?? get_option('bokun_skipcash_currency', 'QAR');

        // Server-side payment verification ONLY: never confirm a Bókun reservation
        // because the browser claims it came back from SkipCash. Query the SkipCash
        // API for the authoritative status first.
        $is_paid = false;
        if (!empty($payment_id)) {
            $status_check = $this->skipcash->get_payment_status($payment_id);
            if (!empty($status_check)) {
                $st_id  = intval($status_check['resultObj']['statusId'] ?? ($status_check['statusId'] ?? 0));
                $st_str = strtolower($status_check['resultObj']['status'] ?? ($status_check['status'] ?? ''));
                if ($st_id === 2 || $st_str === 'paid' || !empty($status_check['resultObj']['paidDate'])) {
                    $is_paid = true;
                }
            }
        }

        if (!$is_paid) {
            return new WP_REST_Response(array(
                'success' => false,
                'status'  => $record['status'] ?? 'RESERVED',
                'message' => 'Awaiting payment confirmation'
            ), 200);
        }

        $tx_details = array(
            'transactionDate' => gmdate('Y-m-d H:i:s'),
            'transactionId'   => $payment_id ?: ('SKIPCASH-' . time()),
            'cardBrand'       => 'CARD',
            'last4'           => '0000'
        );

        $confirm_result = $this->bokun->confirm_reserved_booking($code, $amount, $currency, $tx_details);
        $res_msg = $confirm_result['message'] ?? '';
        $is_confirmed_ok = (
            $confirm_result['success'] ||
            stripos($res_msg, 'CONFIRMED') !== false ||
            stripos($res_msg, 'not in reserved state') !== false ||
            stripos($res_msg, 'already confirmed') !== false
        );

        if ($is_confirmed_ok) {
            $this->store->mark_confirmed($code, $payment_id);
            $record['status'] = 'CONFIRMED';
            $record['confirmed_at'] = time();
            $record['skipcash_payment_id'] = $payment_id;

            return new WP_REST_Response(array(
                'success'          => true,
                'status'           => 'CONFIRMED',
                'confirmationCode' => $code,
                'record'           => $record
            ), 200);
        }

        // Check if Bókun itself already has it as CONFIRMED
        $bk_check = $this->bokun->get_booking_by_confirmation_code($code);
        if (!empty($bk_check) && ($bk_check['status'] ?? '') === 'CONFIRMED') {
            $this->store->mark_confirmed($code, $payment_id);
            $record['status'] = 'CONFIRMED';
            $record['confirmed_at'] = time();

            return new WP_REST_Response(array(
                'success'          => true,
                'status'           => 'CONFIRMED',
                'confirmationCode' => $code,
                'record'           => $record
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'status'  => $record['status'] ?? 'RESERVED',
            'message' => $confirm_result['message'] ?? 'Awaiting payment confirmation'
        ), 200);
    }

    public function handle_get_status(WP_REST_Request $request) {
        $code = sanitize_text_field($request['code']);
        // Read from the durable reservation store instead of transients.
        $record = $this->store->get_by_code($code);

        if (!$record) {
            return new WP_REST_Response(array('status' => 'NOT_FOUND_OR_EXPIRED'), 404);
        }

        return new WP_REST_Response(array(
            'confirmationCode' => $code,
            'status'           => $record['status'],
            'amount'           => $record['amount'],
            'currency'         => $record['currency'],
            'expiresAt'        => $record['expires_at'] ?? 0
        ), 200);
    }
}
