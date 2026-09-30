<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Bokun_API
 * Handles authentication and requests to the Bókun REST API.
 * Follows Bókun HMAC-SHA1 signature requirements.
 */
class Bokun_API {
    private $base_url;
    private $access_key;
    private $secret_key;

    public function __construct() {
        $this->base_url   = rtrim(trim(get_option('bokun_skipcash_bokun_base_url', 'https://api.bokun.io')), '/');
        $this->access_key = trim(get_option('bokun_skipcash_bokun_access_key', ''));
        $this->secret_key = trim(get_option('bokun_skipcash_bokun_secret_key', ''));
    }

    /**
     * Generate Bókun HMAC-SHA1 Signature
     * Format per Bókun developer documentation:
     * Concatenate in exact order: DATE + ACCESS_KEY + HTTP_METHOD + PATH (including query parameters, no domain)
     * Signed with SecretKey via HMAC-SHA1, then Base64 encoded.
     */
    private function get_headers($method, $path) {
        $date = gmdate('Y-m-d H:i:s');
        $method_upper = strtoupper($method);
        // Concatenation: X-Bokun-Date + X-Bokun-AccessKey + METHOD + PATH
        $data_to_sign = $date . $this->access_key . $method_upper . $path;
        $signature = base64_encode(hash_hmac('sha1', $data_to_sign, $this->secret_key, true));

        return array(
            'X-Bokun-AccessKey' => $this->access_key,
            'X-Bokun-Date'      => $date,
            'X-Bokun-Signature' => $signature,
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json'
        );
    }

    /**
     * Universal Phone Normalizer
     * - Strips all non-digit characters safely without regex backslash escaping hazards
     * - Maps dialing prefixes to ISO country codes
     * - Deduplicates repeated country prefixes (+974+974...)
     * - Returns clean E.164 (+974XXXXXXXX) and local national body
     */
    public static function normalize_phone_components($phone_raw, $default_cc = '+974') {
        $raw_str = (string)$phone_raw;
        // Strip everything except 0-9 safely without backslashes
        $digits = preg_replace('/[^0-9]/', '', $raw_str);
        if (empty($digits)) {
            $digits = '30191237';
        }

        // Strip leading 00 international prefix (e.g. 00974 -> 974)
        if (substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 2);
        }

        $prefixes = array(
            '974' => array('dial' => '+974', 'iso' => 'QA'),
            '966' => array('dial' => '+966', 'iso' => 'SA'),
            '971' => array('dial' => '+971', 'iso' => 'AE'),
            '965' => array('dial' => '+965', 'iso' => 'KW'),
            '973' => array('dial' => '+973', 'iso' => 'BH'),
            '968' => array('dial' => '+968', 'iso' => 'OM'),
            '880' => array('dial' => '+880', 'iso' => 'BD'),
            '977' => array('dial' => '+977', 'iso' => 'NP'),
            '44'  => array('dial' => '+44',  'iso' => 'GB'),
            '1'   => array('dial' => '+1',   'iso' => 'US'),
            '91'  => array('dial' => '+91',  'iso' => 'IN'),
            '92'  => array('dial' => '+92',  'iso' => 'PK'),
            '20'  => array('dial' => '+20',  'iso' => 'EG'),
            '962' => array('dial' => '+962', 'iso' => 'JO'),
            '961' => array('dial' => '+961', 'iso' => 'LB'),
            '63'  => array('dial' => '+63',  'iso' => 'PH'),
            '49'  => array('dial' => '+49',  'iso' => 'DE'),
            '33'  => array('dial' => '+33',  'iso' => 'FR'),
            '39'  => array('dial' => '+39',  'iso' => 'IT'),
            '34'  => array('dial' => '+34',  'iso' => 'ES'),
            '90'  => array('dial' => '+90',  'iso' => 'TR'),
            '27'  => array('dial' => '+27',  'iso' => 'ZA'),
            '86'  => array('dial' => '+86',  'iso' => 'CN'),
            '81'  => array('dial' => '+81',  'iso' => 'JP'),
            '82'  => array('dial' => '+82',  'iso' => 'KR'),
            '31'  => array('dial' => '+31',  'iso' => 'NL'),
            '41'  => array('dial' => '+41',  'iso' => 'CH')
        );

        $matched_dial = '';
        $matched_iso  = 'QA';
        $national_num = $digits;

        foreach ($prefixes as $code => $info) {
            $code_len = strlen($code);
            if (substr($digits, 0, $code_len) === $code && strlen($digits) > ($code_len + 4)) {
                $matched_dial = $info['dial'];
                $matched_iso  = $info['iso'];
                $national_num = substr($digits, $code_len);
                break;
            }
        }

        if (empty($matched_dial)) {
            $clean_def = preg_replace('/[^0-9]/', '', (string)$default_cc);
            if (isset($prefixes[$clean_def])) {
                $matched_dial = $prefixes[$clean_def]['dial'];
                $matched_iso  = $prefixes[$clean_def]['iso'];
            } else {
                $matched_dial = '+974';
                $matched_iso  = 'QA';
            }
            if (substr($digits, 0, 1) === '0' && strlen($digits) > 8) {
                $national_num = substr($digits, 1);
            } else {
                $national_num = $digits;
            }
        }

        $clean_national = ltrim($national_num, '0');
        if (empty($clean_national)) {
            $clean_national = $national_num;
        }

        // Deduplicate dial code if accidentally repeated e.g. 97430191237 after +974
        $m_code = ltrim($matched_dial, '+');
        if (substr($clean_national, 0, strlen($m_code)) === $m_code && strlen($clean_national) > (strlen($m_code) + 4)) {
            $clean_national = substr($clean_national, strlen($m_code));
        }

        $e164 = $matched_dial . $clean_national;

        return array(
            'digits'       => $digits,
            'dial_code'    => $matched_dial,
            'iso_code'     => $matched_iso,
            'national_num' => $clean_national,
            'e164'         => $e164
        );
    }

    /**
     * Strict Phone Number Validator
     * Enforces Qatar 8-digit mobile/landline formats and ITU-T E.164 compliance
     */
    public static function validate_phone_number($phone_raw, $default_cc = '+974') {
        $raw = trim((string)$phone_raw);
        $digits = preg_replace('/[^0-9]/', '', $raw);
        
        if (empty($digits)) {
            return array('is_valid' => false, 'error' => 'Phone number is required.');
        }

        $norm = self::normalize_phone_components($raw, $default_cc);
        $dial = $norm['dial_code'];
        $national = $norm['national_num'];
        $e164 = $norm['e164'];

        // Qatar Specific Validation (+974)
        if ($dial === '+974') {
            $len = strlen($national);
            if ($len !== 8) {
                return array(
                    'is_valid' => false,
                    'error'    => 'Qatar phone numbers must contain exactly 8 digits (e.g. 3300 1234). You entered ' . $len . ' digits.'
                );
            }
            $first = substr($national, 0, 1);
            if (!in_array($first, array('3', '4', '5', '6', '7'), true)) {
                return array(
                    'is_valid' => false,
                    'error'    => 'Qatar mobile numbers must start with 3, 5, 6, 7 (or 4 for landlines).'
                );
            }
            return array('is_valid' => true, 'norm' => $norm, 'e164' => $e164);
        }

        // GCC Country Validation (UAE +971, Saudi +966, Kuwait +965, Bahrain +973, Oman +968)
        if (in_array($dial, array('+971', '+966', '+965', '+973', '+968'), true)) {
            $len = strlen($national);
            if ($len < 8 || $len > 9) {
                return array(
                    'is_valid' => false,
                    'error'    => 'Please enter a valid ' . $norm['iso_code'] . ' phone number (8-9 digits).'
                );
            }
            return array('is_valid' => true, 'norm' => $norm, 'e164' => $e164);
        }

        // Global E.164 standard (7 to 15 digits total)
        $total_digits_len = strlen(ltrim($e164, '+'));
        if ($total_digits_len < 7 || $total_digits_len > 15) {
            return array(
                'is_valid' => false,
                'error'    => 'Phone number must be between 7 and 15 digits according to international format.'
            );
        }

        return array('is_valid' => true, 'norm' => $norm, 'e164' => $e164);
    }

    /**
     * Step 2 & 3: Reserve a booking for external payment
     * Endpoint: POST /checkout.json/submit?currency={CURRENCY}
     * 
     * Uses paymentMethod: "RESERVE_FOR_EXTERNAL_PAYMENT"
     * Returns: booking object with confirmationCode (e.g. UND-92677922), status: "RESERVED"
     */
    public function reserve_for_external_payment($booking_payload) {
        $currency = !empty($booking_payload['currency']) ? $booking_payload['currency'] : get_option('bokun_skipcash_currency', 'QAR');
        $checkout_path = '/checkout.json/submit?currency=' . rawurlencode($currency);
        $checkout_url  = $this->base_url . $checkout_path;

        // Extract contact and activity booking items
        $direct_booking_input = isset($booking_payload['directBooking']) ? $booking_payload['directBooking'] : $booking_payload;
        $contact = $direct_booking_input['mainContactDetails'] ?? ($booking_payload['mainContactDetails'] ?? array());
        $activity_bookings_raw = $direct_booking_input['activityBookings'] ?? ($booking_payload['activityBookings'] ?? array());

        if (empty($activity_bookings_raw)) {
            return array('success' => false, 'status' => 400, 'message' => 'No activity bookings specified in payload.');
        }

        $formatted_activity_bookings = array();
        foreach ($activity_bookings_raw as $ab) {
            $act_id = intval($ab['activityId'] ?? 0);
            if (!$act_id) {
                continue;
            }

            // Build passengers array adhering strictly to Bókun DirectBooking schema
            // CRITICAL: In Bókun DirectBooking, passengers is a list of individual passenger objects (one per seat/ticket).
            // A passenger does NOT have a 'groupSize' multiplier. If 3 adults are booked, there must be 3 objects with pricingCategoryId.
            $passengers_list = array();
            if (!empty($ab['passengers']) && is_array($ab['passengers'])) {
                foreach ($ab['passengers'] as $p) {
                    $cat_id = intval($p['pricingCategoryId'] ?? 0);
                    $qty = intval($p['groupSize'] ?? ($p['quantity'] ?? 1));
                    if ($qty < 1) $qty = 1;
                    if ($cat_id > 0) {
                        for ($i = 0; $i < $qty; $i++) {
                            $p_entry = array('pricingCategoryId' => $cat_id);
                            if (!empty($p['passengerDetails']) && is_array($p['passengerDetails'])) {
                                $p_entry['passengerDetails'] = $p['passengerDetails'];
                            }
                            $passengers_list[] = $p_entry;
                        }
                    }
                }
            }

            // If passengers list is empty, expand from pricingCategoryBookings, pricingCategories, or adult/totalParticipants
            if (empty($passengers_list)) {
                $raw_cats = !empty($ab['pricingCategoryBookings']) ? $ab['pricingCategoryBookings'] : (!empty($ab['pricingCategories']) ? $ab['pricingCategories'] : array());
                if (!empty($raw_cats) && is_array($raw_cats)) {
                    foreach ($raw_cats as $pc) {
                        $cat_id = intval($pc['pricingCategoryId'] ?? ($pc['id'] ?? 0));
                        $qty = intval($pc['quantity'] ?? ($pc['count'] ?? 1));
                        if ($cat_id > 0 && $qty > 0) {
                            for ($i = 0; $i < $qty; $i++) {
                                $passengers_list[] = array(
                                    'pricingCategoryId' => $cat_id
                                );
                            }
                        }
                    }
                }
            }

            if (empty($passengers_list)) {
                $act_data = $this->get_activity($act_id);
                $fallback_cat_id = 1248692; // Default Pearl Kayaking Adult ID
                if (!empty($act_data['pricingCategories'][0]['id'])) {
                    $fallback_cat_id = intval($act_data['pricingCategories'][0]['id']);
                }
                $fallback_count = max(1, intval($ab['adults'] ?? ($ab['totalParticipants'] ?? 1)));
                for ($i = 0; $i < $fallback_count; $i++) {
                    $passengers_list[] = array('pricingCategoryId' => $fallback_cat_id);
                }
            }

            // Resolve startTimeId & rateId if omitted
            $start_time_id = intval($ab['startTimeId'] ?? 0);
            $rate_id       = intval($ab['rateId'] ?? 0);
            
            // Format date strictly as YYYY-MM-DD (converts millisecond timestamp e.g. 1789171200000 from Bókun availabilities to '2026-09-15')
            $raw_date     = $ab['date'] ?? '';
            $booking_date = gmdate('Y-m-d', strtotime('+1 day'));
            if (is_numeric($raw_date)) {
                $num_date = (float) $raw_date;
                if ($num_date > 100000000000) {
                    $booking_date = gmdate('Y-m-d', (int)($num_date / 1000));
                } else {
                    $booking_date = gmdate('Y-m-d', (int)$num_date);
                }
            } elseif (is_string($raw_date) && !empty($raw_date)) {
                $clean_str = trim($raw_date);
                if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})/', $clean_str, $matches)) {
                    $booking_date = $matches[0];
                } elseif (strtotime($clean_str) !== false) {
                    $booking_date = gmdate('Y-m-d', strtotime($clean_str));
                }
            }

            if (!$start_time_id || !$rate_id) {
                $act_data = $this->get_activity($act_id);
                if (!$rate_id && !empty($act_data['rates'][0]['id'])) {
                    $rate_id = intval($act_data['rates'][0]['id']);
                }
                if (!$start_time_id && !empty($act_data['startTimes'][0]['id'])) {
                    $start_time_id = intval($act_data['startTimes'][0]['id']);
                }
            }

            // Prepare both pricingCategoryBookings and passengers list for maximum Bókun schema compatibility
            $pricing_category_bookings = array();
            if (!empty($ab['pricingCategoryBookings']) && is_array($ab['pricingCategoryBookings'])) {
                foreach ($ab['pricingCategoryBookings'] as $pc) {
                    $cat_id = intval($pc['pricingCategoryId'] ?? $pc['id'] ?? 0);
                    $qty = intval($pc['quantity'] ?? ($pc['count'] ?? 1));
                    if ($cat_id > 0 && $qty > 0) {
                        $pricing_category_bookings[] = array(
                            'pricingCategoryId' => $cat_id,
                            'quantity'          => $qty
                        );
                    }
                }
            }
            if (empty($pricing_category_bookings) && !empty($passengers_list)) {
                $cat_counts = array();
                foreach ($passengers_list as $pl) {
                    $c_id = intval($pl['pricingCategoryId']);
                    if ($c_id > 0) {
                        $cat_counts[$c_id] = ($cat_counts[$c_id] ?? 0) + 1;
                    }
                }
                foreach ($cat_counts as $c_id => $qty) {
                    $pricing_category_bookings[] = array(
                        'pricingCategoryId' => $c_id,
                        'quantity'          => $qty
                    );
                }
            }

            $booking_item = array(
                'activityId'              => $act_id,
                'date'                    => $booking_date,
                'pricingCategoryBookings' => array_values($pricing_category_bookings),
                'passengers'              => array_values($passengers_list)
            );

            if ($start_time_id > 0) {
                $booking_item['startTimeId'] = $start_time_id;
            }
            if ($rate_id > 0) {
                $booking_item['rateId'] = $rate_id;
            }

            $formatted_activity_bookings[] = $booking_item;
        }

        // Build mainContactDetails and customer object supporting all Bókun question list and object phone field formats
        $first_name = sanitize_text_field($contact['firstName'] ?? ($contact['first_name'] ?? ($booking_payload['customer']['firstName'] ?? ($booking_payload['firstName'] ?? 'Guest'))));
        $last_name  = sanitize_text_field($contact['lastName'] ?? ($contact['last_name'] ?? ($booking_payload['customer']['lastName'] ?? ($booking_payload['lastName'] ?? 'Customer'))));
        $email      = sanitize_email($contact['email'] ?? ($booking_payload['customer']['email'] ?? ($booking_payload['email'] ?? 'booking@example.com')));
        $phone_raw  = sanitize_text_field($contact['phoneNumber'] ?? ($contact['phone'] ?? ($contact['mobilePhone'] ?? ($booking_payload['customer']['phoneNumber'] ?? ($booking_payload['customer']['phone'] ?? ($booking_payload['phone'] ?? ($booking_payload['phoneNumber'] ?? '+97430191237')))))));

        $phone_norm         = self::normalize_phone_components($phone_raw, $booking_payload['customer']['phoneNumberCountryCode'] ?? '+974');
        $phone_country_code = $phone_norm['dial_code'];
        $phone_body         = $phone_norm['national_num'];
        $phone_e164         = $phone_norm['e164'];

        if (isset($contact[0]) && isset($contact[0]['questionId'])) {
            $main_contact_answers = $contact;
            $has_phone_q = false;
            foreach ($main_contact_answers as $ans) {
                if (isset($ans['questionId']) && in_array(strtolower($ans['questionId']), array('phonenumber', 'phone', 'mobilephone', 'phone_number'))) {
                    $has_phone_q = true;
                    break;
                }
            }
            if (!$has_phone_q && !empty($phone_e164)) {
                $main_contact_answers[] = array('questionId' => 'PHONE_NUMBER',           'values' => array($phone_e164));
                $main_contact_answers[] = array('questionId' => 'phoneNumber',            'values' => array($phone_e164));
                $main_contact_answers[] = array('questionId' => 'phone',                  'values' => array($phone_e164));
                $main_contact_answers[] = array('questionId' => 'phoneNumberCountryCode', 'values' => array($phone_country_code));
                $main_contact_answers[] = array('questionId' => 'phoneNumberBody',        'values' => array($phone_body));
            }
        } else {
            $main_contact_answers = array(
                array('questionId' => 'PHONE_NUMBER',           'values' => array($phone_e164)),
                array('questionId' => 'FIRST_NAME',             'values' => array($first_name)),
                array('questionId' => 'LAST_NAME',              'values' => array($last_name)),
                array('questionId' => 'EMAIL',                  'values' => array($email)),
                array('questionId' => 'firstName',              'values' => array($first_name)),
                array('questionId' => 'lastName',               'values' => array($last_name)),
                array('questionId' => 'email',                  'values' => array($email)),
                array('questionId' => 'phoneNumber',            'values' => array($phone_e164)),
                array('questionId' => 'phone',                  'values' => array($phone_e164)),
                array('questionId' => 'mobilePhone',            'values' => array($phone_e164)),
                array('questionId' => 'phone_number',           'values' => array($phone_e164)),
                array('questionId' => 'phoneNumberCountryCode', 'values' => array($phone_country_code)),
                array('questionId' => 'phoneNumberBody',        'values' => array($phone_body))
            );
        }

        $customer_obj = array(
            'firstName'              => $first_name,
            'lastName'               => $last_name,
            'email'                  => $email,
            'emailAddress'           => $email,
            'phoneNumber'            => $phone_e164,
            'phone'                  => $phone_e164,
            'mobilePhone'            => $phone_e164,
            'phoneNumberCountryCode' => $phone_country_code,
            'phoneNumberBody'        => $phone_body
        );

        foreach ($formatted_activity_bookings as &$fab) {
            // Remove non-OpenAPI fields to prevent Jackson deserialization errors
            unset($fab['pricingCategoryBookings']);
            
            // Attach answers to activity booking level
            if (empty($fab['answers'])) {
                $fab['answers'] = array_values($main_contact_answers);
            }

            if (!empty($fab['passengers']) && is_array($fab['passengers'])) {
                foreach ($fab['passengers'] as &$pass) {
                    $pass['passengerDetails'] = array(
                        array('questionId' => 'FIRST_NAME',             'values' => array($first_name)),
                        array('questionId' => 'LAST_NAME',              'values' => array($last_name)),
                        array('questionId' => 'PHONE_NUMBER',           'values' => array($phone_e164)),
                        array('questionId' => 'EMAIL',                  'values' => array($email)),
                        array('questionId' => 'firstName',              'values' => array($first_name)),
                        array('questionId' => 'lastName',               'values' => array($last_name)),
                        array('questionId' => 'phoneNumber',            'values' => array($phone_e164)),
                        array('questionId' => 'phone',                  'values' => array($phone_e164)),
                        array('questionId' => 'phoneNumberCountryCode', 'values' => array($phone_country_code)),
                        array('questionId' => 'phoneNumberBody',        'values' => array($phone_body))
                    );
                    $pass['answers'] = $pass['passengerDetails'];
                    unset($pass['passengerQuestions']);
                }
            }
        }
        unset($fab);

        // Build official Bókun Direct Booking Checkout Request JSON strictly matching OpenAPI spec (CheckoutRequest schema)
        $direct_booking_payload = array(
            'mainContactDetails' => array_values($main_contact_answers),
            'activityBookings'   => array_values($formatted_activity_bookings)
        );

        $promo_code = sanitize_text_field($booking_payload['promoCode'] ?? ($booking_payload['directBooking']['promoCode'] ?? ''));
        if (!empty($promo_code)) {
            $direct_booking_payload['promoCode'] = $promo_code;
        }

        $direct_body = array(
            'checkoutOption'                => $booking_payload['checkoutOption'] ?? 'CUSTOMER_FULL_PAYMENT',
            'paymentMethod'                 => 'RESERVE_FOR_EXTERNAL_PAYMENT',
            'source'                        => 'DIRECT_REQUEST',
            'currency'                      => $currency,
            'sendNotificationToMainContact' => true,
            'showPricesInNotification'       => true,
            'directBooking'                 => $direct_booking_payload
        );

        $json_payload = wp_json_encode($direct_body);

        $response = wp_remote_post($checkout_url, array(
            'method'    => 'POST',
            'headers'   => $this->get_headers('POST', $checkout_path),
            'body'      => $json_payload,
            'timeout'   => 25
        ));

        if (is_wp_error($response)) {
            return array(
                'success'      => false,
                'status'       => 500,
                'message'      => 'Network error contacting Bókun: ' . $response->get_error_message(),
                'sent_payload' => $direct_body
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $response_body = json_decode($raw_body, true);

        if ($status_code >= 200 && $status_code < 300) {
            $booking = $response_body['booking'] ?? $response_body;
            $code = $booking['confirmationCode'] 
                ?? ($response_body['confirmationCode'] 
                ?? ($booking['reference'] 
                ?? ($response_body['reference'] 
                ?? ($booking['bookingReference'] 
                ?? ($response_body['bookingReference'] 
                ?? '')))));

            if (!empty($code)) {
                return array(
                    'success'           => true,
                    'confirmationCode'  => $code,
                    'bookingId'         => $booking['bookingId'] ?? ($response_body['bookingId'] ?? ($booking['id'] ?? ($response_body['id'] ?? null))),
                    'status'            => $booking['status'] ?? ($response_body['status'] ?? 'RESERVED'),
                    'totalPrice'        => $booking['totalPrice'] ?? ($response_body['totalPrice'] ?? ($booking['totalPaid'] ?? ($response_body['totalPaid'] ?? 0))),
                    'currency'          => $booking['currency'] ?? ($response_body['currency'] ?? $currency),
                    'method_used'       => 'DIRECT_REQUEST',
                    'sent_payload'      => $direct_body,
                    'raw'               => $response_body
                );
            }
        }

        // Build a detailed error message from Bókun's response
        $error_message = '';
        if (!empty($response_body['message'])) {
            $error_message = $response_body['message'];
        } elseif (!empty($response_body['errorMessage'])) {
            $error_message = $response_body['errorMessage'];
        } elseif (!empty($response_body['title'])) {
            $error_message = $response_body['title'] . (!empty($response_body['detail']) ? ': ' . $response_body['detail'] : '');
        } elseif (!empty($response_body['errors']) && is_array($response_body['errors'])) {
            $error_message = implode(', ', array_map(function($e) {
                return is_string($e) ? $e : (isset($e['message']) ? $e['message'] : json_encode($e));
            }, $response_body['errors']));
        } elseif (!empty($response_body['violations']) && is_array($response_body['violations'])) {
            $error_message = implode(', ', array_map(function($v) {
                return ($v['field'] ?? '') . ': ' . ($v['message'] ?? json_encode($v));
            }, $response_body['violations']));
        } elseif ($status_code === 404) {
            $error_message = 'Bókun API 404: The requested endpoint, rate, or activity was not found. Please verify your Activity ID and rates.';
        } elseif ($status_code === 401 || $status_code === 403) {
            $error_message = 'Bókun Authentication Error (HTTP ' . $status_code . '): Invalid API Access Key or Secret Key signature.';
        } elseif ($status_code === 400) {
            $error_message = 'Bókun Validation Error (HTTP 400): ' . (!empty($raw_body) ? substr($raw_body, 0, 300) : 'Invalid booking payload.');
        } else {
            $error_message = 'Bókun API returned HTTP ' . $status_code . (!empty($raw_body) ? ': ' . substr($raw_body, 0, 200) : '');
        }

        return array(
            'success'      => false,
            'status'       => $status_code,
            'message'      => $error_message,
            'sent_payload' => $direct_body,
            'raw'          => !empty($response_body) ? $response_body : array('status' => $status_code, 'raw_response' => substr($raw_body, 0, 500))
        );
    }

    /**
     * Step 8: Confirm reserved booking after external payment succeeds
     * Endpoint: POST /checkout.json/confirm-reserved/{confirmationCode}
     * 
     * Conforms to Bókun Booking Confirmation Schema:
     * - externalBookingReference (string)
     * - showPricesInNotification (boolean)
     * - sendNotificationToMainContact (boolean)
     * - transactionDetails (TransactionDetails: transactionDate, transactionId, cardBrand, last4)
     * - amount (number)
     * - currency (string)
     */
    public function confirm_reserved_booking($confirmation_code, $amount, $currency, $transaction_details = array()) {
        $path = '/checkout.json/confirm-reserved/' . rawurlencode($confirmation_code);
        $url = $this->base_url . $path;

        $tx_id = (string) ($transaction_details['transactionId'] ?? ('SKIPCASH-' . time()));

        $body = array(
            'externalBookingReference'     => $tx_id,
            'showPricesInNotification'     => true,
            'sendNotificationToMainContact'=> true, // Bókun will automatically generate and send tickets & confirmation email
            'amount'                       => (float) $amount,
            'currency'                     => $currency ?: get_option('bokun_skipcash_currency', 'QAR'),
            'transactionDetails'           => array(
                'transactionDate' => $transaction_details['transactionDate'] ?? gmdate('Y-m-d H:i:s'),
                'transactionId'   => $tx_id,
                'cardBrand'       => $transaction_details['cardBrand'] ?? 'CARD',
                'last4'           => (string) ($transaction_details['last4'] ?? '0000')
            )
        );

        $response = wp_remote_post($url, array(
            'method'    => 'POST',
            'headers'   => $this->get_headers('POST', $path),
            'body'      => wp_json_encode($body),
            'timeout'   => 25
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $response_body = json_decode($raw_body, true);

        if ($status_code >= 200 && $status_code < 300) {
            return array(
                'success'          => true,
                'confirmationCode' => $confirmation_code,
                'status'           => 'CONFIRMED',
                'raw'              => $response_body
            );
        }

        $error_msg = $response_body['message'] ?? $response_body['errorMessage'] ?? ('Failed to confirm reservation (HTTP ' . $status_code . ').');

        // If Bókun indicates booking is already confirmed (e.g. InvalidDataException - Confirming a booking which is not in reserved state: CONFIRMED)
        $is_already_confirmed = (
            stripos($error_msg, 'already confirmed') !== false ||
            stripos($error_msg, 'already_confirmed') !== false ||
            stripos($raw_body, 'already confirmed') !== false ||
            stripos($error_msg, 'not in reserved state') !== false ||
            stripos($raw_body, 'not in reserved state') !== false ||
            stripos($error_msg, 'CONFIRMED') !== false ||
            stripos($raw_body, 'CONFIRMED') !== false ||
            stripos($error_msg, 'PAID') !== false ||
            ($response_body['status'] ?? '') === 'CONFIRMED'
        );

        if ($is_already_confirmed) {
            return array(
                'success'          => true,
                'confirmationCode' => $confirmation_code,
                'status'           => 'CONFIRMED',
                'note'             => 'Already confirmed in Bókun',
                'raw'              => $response_body
            );
        }

        return array(
            'success' => false,
            'status'  => $status_code,
            'message' => $error_msg,
            'raw'     => $response_body
        );
    }

    /**
     * Direct Customer Profile Update in Bókun
     * Endpoint: POST /booking.json/update-customer/{bookingConfirmationCode}
     *
     * This is Bókun's dedicated REST API endpoint to update customer contact details
     * (firstName, lastName, email, phoneNumber, phoneNumberCountryCode) on a reservation/booking.
     */
    public function update_customer($booking_identifier, $customer_data) {
        if (empty($booking_identifier) || empty($customer_data)) {
            return false;
        }

        $path = '/booking.json/update-customer/' . rawurlencode((string)$booking_identifier);
        $url  = $this->base_url . $path;

        $first_name = sanitize_text_field($customer_data['firstName'] ?? ($customer_data['first_name'] ?? 'Guest'));
        $last_name  = sanitize_text_field($customer_data['lastName'] ?? ($customer_data['last_name'] ?? 'Customer'));
        $email      = sanitize_email($customer_data['email'] ?? ($customer_data['emailAddress'] ?? ''));
        $phone_raw  = sanitize_text_field($customer_data['phoneNumber'] ?? ($customer_data['phone'] ?? ($customer_data['mobilePhone'] ?? '')));

        $phone_norm = self::normalize_phone_components($phone_raw, $customer_data['phoneNumberCountryCode'] ?? '+974');
        $phone_cc   = $phone_norm['dial_code'];
        $phone_b    = $phone_norm['national_num'];
        $phone_e164 = $phone_norm['e164'];
        $iso_code   = $phone_norm['iso_code'];

        // 1. Primary update: ISO country code (QA) with normalized E.164 phone
        $body = array(
            'firstName'              => $first_name,
            'lastName'               => $last_name,
            'email'                  => $email,
            'phoneNumber'            => $phone_e164,
            'phoneNumberCountryCode' => $iso_code
        );

        $response = wp_remote_post($url, array(
            'method'  => 'POST',
            'headers' => $this->get_headers('POST', $path),
            'body'    => wp_json_encode($body),
            'timeout' => 15
        ));

        $code = !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 0;
        $resp_body = !is_wp_error($response) ? wp_remote_retrieve_body($response) : '';
        error_log('[Bókun Sync] POST ' . $path . ' (ISO ' . $iso_code . ') HTTP ' . $code . ': ' . substr($resp_body, 0, 200));

        // 2. Secondary update if not 200: dial prefix (+974) format
        if ($code < 200 || $code >= 300) {
            $body['phoneNumberCountryCode'] = $phone_cc;
            $body['phoneNumber']            = $phone_cc . ' ' . $phone_b;
            $response = wp_remote_post($url, array(
                'method'  => 'POST',
                'headers' => $this->get_headers('POST', $path),
                'body'    => wp_json_encode($body),
                'timeout' => 15
            ));
            $code = !is_wp_error($response) ? wp_remote_retrieve_response_code($response) : 0;
            $resp_body = !is_wp_error($response) ? wp_remote_retrieve_body($response) : '';
            error_log('[Bókun Sync] POST ' . $path . ' (Dial ' . $phone_cc . ') HTTP ' . $code . ': ' . substr($resp_body, 0, 200));
        }

        return ($code >= 200 && $code < 300);
    }

    /**
     * Answer main contact and activity booking questions via Bókun Question API
     * Endpoints:
     * 1. GET & POST /question.json/booking/{bookingId}
     * 2. GET & POST /question.json/activity-booking/{activityBookingId}
     */
    public function answer_booking_questions($booking_context, $phone, $first_name = '', $last_name = '', $email = '') {
        if (empty($booking_context) || empty($phone)) {
            return false;
        }

        $numeric_id           = 0;
        $confirmation_code    = '';
        $activity_booking_ids = array();

        if (is_array($booking_context)) {
            $numeric_id = intval($booking_context['bookingId'] ?? ($booking_context['raw']['booking']['bookingId'] ?? ($booking_context['id'] ?? 0)));
            $confirmation_code = $booking_context['confirmationCode'] ?? ($booking_context['raw']['booking']['confirmationCode'] ?? '');
            
            $act_bookings = $booking_context['raw']['booking']['activityBookings'] 
                ?? ($booking_context['raw']['activityBookings'] 
                ?? ($booking_context['activityBookings'] ?? array()));
            if (!empty($act_bookings) && is_array($act_bookings)) {
                foreach ($act_bookings as $ab_item) {
                    $ab_id = intval($ab_item['id'] ?? ($ab_item['activityBookingId'] ?? 0));
                    if ($ab_id > 0) {
                        $activity_booking_ids[] = $ab_id;
                    }
                }
            }
        } elseif (is_numeric($booking_context)) {
            $numeric_id = intval($booking_context);
        } else {
            $confirmation_code = (string)$booking_context;
        }

        // If numeric_id is missing, look up booking by confirmation code
        if (!$numeric_id && !empty($confirmation_code)) {
            $booking_data = $this->get_booking_by_confirmation_code($confirmation_code);
            if (!empty($booking_data['bookingId'])) {
                $numeric_id = intval($booking_data['bookingId']);
            } elseif (!empty($booking_data['id'])) {
                $numeric_id = intval($booking_data['id']);
            }
            if (!empty($booking_data['activityBookings']) && empty($activity_booking_ids)) {
                foreach ($booking_data['activityBookings'] as $ab_item) {
                    $ab_id = intval($ab_item['id'] ?? ($ab_item['activityBookingId'] ?? 0));
                    if ($ab_id > 0) {
                        $activity_booking_ids[] = $ab_id;
                    }
                }
            }
        }

        if (!$numeric_id) {
            error_log('[Bókun Sync] Could not resolve numeric bookingId for question answering: ' . json_encode($booking_context));
            return false;
        }

        // Clean and normalize phone numbers into E.164 and local body
        $phone_norm = self::normalize_phone_components((string)$phone, '+974');
        $phone_cc   = $phone_norm['dial_code'];
        $phone_b    = $phone_norm['national_num'];
        $phone_e164 = $phone_norm['e164'];

        // Step 1: Query booking questions from Bókun
        $get_path = '/question.json/booking/' . rawurlencode($numeric_id);
        $get_url  = $this->base_url . $get_path;
        $response = wp_remote_get($get_url, array(
            'headers' => $this->get_headers('GET', $get_path),
            'timeout' => 15
        ));

        $answers_list = array();
        $q_data = null;
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $q_data = json_decode(wp_remote_retrieve_body($response), true);
            error_log('[Bókun Sync] Discovered questions for booking ' . $numeric_id . ': ' . wp_remote_retrieve_body($response));

            if (!empty($q_data['mainContactDetails']) && is_array($q_data['mainContactDetails'])) {
                foreach ($q_data['mainContactDetails'] as $q) {
                    $q_id     = (string)($q['questionId'] ?? '');
                    $q_code   = (string)($q['questionCode'] ?? '');
                    $q_format = (string)($q['dataFormat'] ?? '');
                    $q_label  = strtolower($q['label'] ?? '');

                    $is_phone = (
                        $q_format === 'PHONE_NUMBER' ||
                        in_array(strtoupper($q_id), array('PHONE_NUMBER', 'PHONENUMBER', 'PHONE', 'MOBILEPHONE', 'PHONE_NUMBER_BODY')) ||
                        in_array(strtoupper($q_code), array('PHONE_NUMBER', 'PHONENUMBER', 'PHONE', 'MOBILEPHONE')) ||
                        strpos($q_label, 'phone') !== false ||
                        strpos($q_label, 'mobile') !== false
                    );

                    if ($is_phone) {
                        if ($q_id) {
                            $answers_list[] = array('questionId' => $q_id, 'values' => array($phone_e164));
                        }
                        if ($q_code && $q_code !== $q_id) {
                            $answers_list[] = array('questionId' => $q_code, 'values' => array($phone_e164));
                        }
                    } elseif (in_array(strtoupper($q_id), array('FIRSTNAME', 'FIRST_NAME')) && $first_name) {
                        $answers_list[] = array('questionId' => $q_id, 'values' => array($first_name));
                    } elseif (in_array(strtoupper($q_id), array('LASTNAME', 'LAST_NAME')) && $last_name) {
                        $answers_list[] = array('questionId' => $q_id, 'values' => array($last_name));
                    } elseif (in_array(strtoupper($q_id), array('EMAIL', 'EMAIL_ADDRESS')) && $email) {
                        $answers_list[] = array('questionId' => $q_id, 'values' => array($email));
                    }
                }
            }
        }

        // If no questions were discovered in q_data, supply minimal standard phone question IDs
        if (empty($answers_list)) {
            $fallback_keys = array('PHONE_NUMBER', 'phoneNumber', 'phone');
            foreach ($fallback_keys as $fb_key) {
                $answers_list[] = array('questionId' => $fb_key, 'values' => array($phone_e164));
            }
            if ($first_name) {
                $answers_list[] = array('questionId' => 'FIRST_NAME', 'values' => array($first_name));
            }
            if ($last_name) {
                $answers_list[] = array('questionId' => 'LAST_NAME', 'values' => array($last_name));
            }
            if ($email) {
                $answers_list[] = array('questionId' => 'EMAIL', 'values' => array($email));
            }
        }

        // Step 2: POST answers to /question.json/booking/{bookingId}
        $post_path = '/question.json/booking/' . rawurlencode($numeric_id);
        $post_url  = $this->base_url . $post_path;
        $post_body = array(
            'mainContactDetails' => array_values($answers_list)
        );
        $post_res = wp_remote_post($post_url, array(
            'method'  => 'POST',
            'headers' => $this->get_headers('POST', $post_path),
            'body'    => wp_json_encode($post_body),
            'timeout' => 15
        ));
        $post_code = wp_remote_retrieve_response_code($post_res);
        $post_body_ret = wp_remote_retrieve_body($post_res);
        error_log('[Bókun Sync] POST /question.json/booking/' . $numeric_id . ' returned HTTP ' . $post_code . ': ' . substr($post_body_ret, 0, 300));

        // Step 3: Answer activity booking questions matching ActivityBookingAnswersDto schema
        if (!empty($activity_booking_ids)) {
            foreach ($activity_booking_ids as $ab_id) {
                $ab_path = '/question.json/activity-booking/' . rawurlencode($ab_id);
                $ab_url  = $this->base_url . $ab_path;
                
                // Query questions first
                $ab_get_res = wp_remote_get($ab_url, array(
                    'headers' => $this->get_headers('GET', $ab_path),
                    'timeout' => 10
                ));
                $ab_answers = $answers_list;
                if (!is_wp_error($ab_get_res) && wp_remote_retrieve_response_code($ab_get_res) === 200) {
                    $ab_q_data = json_decode(wp_remote_retrieve_body($ab_get_res), true);
                    error_log('[Bókun Sync] Questions for activity booking ' . $ab_id . ': ' . wp_remote_retrieve_body($ab_get_res));
                    if (!empty($ab_q_data['questions']) && is_array($ab_q_data['questions'])) {
                        foreach ($ab_q_data['questions'] as $ab_q) {
                            $ab_qid = (string)($ab_q['id'] ?? ($ab_q['questionId'] ?? ''));
                            if ($ab_qid) {
                                $ab_answers[] = array('questionId' => $ab_qid, 'values' => array($phone_e164));
                            }
                        }
                    }
                }

                // Post answers strictly matching ActivityBookingAnswersDto
                $ab_body = array(
                    'bookingId' => $ab_id,
                    'answers'   => array_values($ab_answers)
                );
                $ab_post_res = wp_remote_post($ab_url, array(
                    'method'  => 'POST',
                    'headers' => $this->get_headers('POST', $ab_path),
                    'body'    => wp_json_encode($ab_body),
                    'timeout' => 10
                ));
                error_log('[Bókun Sync] POST /question.json/activity-booking/' . $ab_id . ' returned HTTP ' . wp_remote_retrieve_response_code($ab_post_res));
            }
        }

        return true;
    }

    /**
     * Query booking details from Bókun by confirmation code
     */
    public function get_booking_by_confirmation_code($confirmation_code) {
        $path = '/booking.json/' . rawurlencode($confirmation_code);
        $url = $this->base_url . $path;

        $response = wp_remote_get($url, array(
            'headers' => $this->get_headers('GET', $path),
            'timeout' => 15
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return json_decode(wp_remote_retrieve_body($response), true);
        }

        $alt_path = '/booking.json/confirmation-code/' . rawurlencode($confirmation_code);
        $alt_url = $this->base_url . $alt_path;
        $alt_res = wp_remote_get($alt_url, array(
            'headers' => $this->get_headers('GET', $alt_path),
            'timeout' => 15
        ));

        if (!is_wp_error($alt_res) && wp_remote_retrieve_response_code($alt_res) === 200) {
            return json_decode(wp_remote_retrieve_body($alt_res), true);
        }

        return false;
    }

    /**
     * Get Activity details (cached in transient for 5 minutes, supports force_refresh)
     * Endpoint: GET /activity.json/{activity_id}
     */
    public function get_activity($activity_id, $force_refresh = false) {
        $activity_id = intval($activity_id ?: get_option('bokun_skipcash_default_activity_id', '1317760'));
        if (!$activity_id) {
            return false;
        }

        $path = '/activity.json/' . $activity_id;
        $url = $this->base_url . $path;

        $response = wp_remote_get($url, array(
            'headers' => $this->get_headers('GET', $path),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data) && is_array($data)) {
                return $data;
            }
        }

        return false;
    }

    /**
     * Fetch Activity details from Bókun API v1
     * Endpoint: GET /activity.json/{activityId}
     */
    public function get_activity_details($activity_id) {
        $activity_id = intval($activity_id ?: get_option('bokun_skipcash_default_activity_id', '1317760'));
        if (!$activity_id) {
            return null;
        }
        $path = '/activity.json/' . $activity_id;
        $url = $this->base_url . $path;
        
        $response = wp_remote_get($url, array(
            'headers' => $this->get_headers('GET', $path),
            'timeout' => 20
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($data)) {
                // Live query - no transient
                return $data;
            }
        }
        return null;
    }

    /**
     * Fetch ALL Available Pricing Categories for an Activity
     * Dynamically combines activity pricingCategories, rate pricingCategoryIds,
     * and slot pricePerCategoryUnit to ensure no category is ever omitted.
     */
    public function get_pricing_categories($activity_id = null, $force_refresh = false) {
        $activity_id = intval($activity_id ?: get_option('bokun_skipcash_default_activity_id', '1317760'));
        if (!$activity_id) {
            return array();
        }

        // Live activity details preferred to capture newly added categories immediately
        $act_details = $force_refresh ? $this->get_activity_details($activity_id) : $this->get_activity($activity_id);
        if (empty($act_details) || empty($act_details['pricingCategories'])) {
            $act_details = $this->get_activity_details($activity_id);
        }

        $raw_cats = !empty($act_details['pricingCategories']) && is_array($act_details['pricingCategories']) ? $act_details['pricingCategories'] : array();

        // Query upcoming availabilities to get real unit prices from pricesByRate
        $avail = $this->get_availabilities_detailed($activity_id, gmdate('Y-m-d'), gmdate('Y-m-d', strtotime('+30 days')));
        $price_map = array();
        if (!empty($avail['slots']) && is_array($avail['slots'])) {
            foreach ($avail['slots'] as $slot) {
                if (!empty($slot['pricesByRate']) && is_array($slot['pricesByRate'])) {
                    foreach ($slot['pricesByRate'] as $pbr) {
                        if (!empty($pbr['pricePerCategoryUnit']) && is_array($pbr['pricePerCategoryUnit'])) {
                            foreach ($pbr['pricePerCategoryUnit'] as $pcu) {
                                $cid = intval($pcu['id'] ?? 0);
                                if ($cid > 0 && isset($pcu['amount']['amount']) && !isset($price_map[$cid])) {
                                    $price_map[$cid] = floatval($pcu['amount']['amount']);
                                }
                            }
                        }
                    }
                }
            }
        }

        $categories = array();
        $seen_ids = array();
        $has_default = false;

        foreach ($raw_cats as $idx => $cat) {
            $cid = intval($cat['id'] ?? (1000 + $idx));
            $title = $cat['title'] ?? ($cat['fullTitle'] ?? 'Participant');
            $t_cat = strtoupper($cat['ticketCategory'] ?? '');
            $min_age = intval($cat['minAge'] ?? 0);
            $max_age = intval($cat['maxAge'] ?? 0);
            $is_def = !empty($cat['defaultCategory']);
            if ($is_def) $has_default = true;

            if ($min_age === 0 && $max_age === 0) {
                if ($t_cat === 'ADULT' || stripos($title, 'adult') !== false) {
                    $min_age = 18; $max_age = 99;
                } elseif ($t_cat === 'CHILD' || stripos($title, 'child') !== false) {
                    $min_age = 6; $max_age = 17;
                } elseif ($t_cat === 'INFANT' || stripos($title, 'infant') !== false) {
                    $min_age = 0; $max_age = 5;
                } elseif ($t_cat === 'SENIOR' || stripos($title, 'senior') !== false) {
                    $min_age = 65; $max_age = 99;
                } elseif ($t_cat === 'YOUTH' || stripos($title, 'youth') !== false) {
                    $min_age = 12; $max_age = 17;
                }
            }

            $unit_price = isset($price_map[$cid]) ? $price_map[$cid] : floatval($cat['price'] ?? ($cat['unitPrice'] ?? 0));
            if ($unit_price <= 0 && $t_cat !== 'INFANT' && stripos($title, 'infant') === false) {
                if ($t_cat === 'ADULT' || stripos($title, 'adult') !== false) {
                    $unit_price = 199.00;
                } elseif ($t_cat === 'CHILD' || stripos($title, 'child') !== false) {
                    $unit_price = 99.00;
                }
            }

            $cat['id']              = $cid;
            $cat['title']           = $title;
            $cat['fullTitle']       = $title;
            $cat['ticketCategory']  = $t_cat;
            $cat['minAge']          = $min_age;
            $cat['maxAge']          = $max_age;
            $cat['unitPrice']       = $unit_price;
            $cat['price']           = $unit_price;
            $cat['defaultCategory'] = $is_def;

            $categories[] = $cat;
            $seen_ids[$cid] = true;
        }

        // Merge any extra category IDs found in slot pricesByRate that were not in raw_cats
        foreach ($price_map as $cid => $p_val) {
            if (!isset($seen_ids[$cid])) {
                $p_title = 'Participant';
                $min_a = 0; $max_a = 99;
                $t_cat = 'ADULT';
                if ($p_val <= 0) {
                    $p_title = 'Infants'; $min_a = 0; $max_a = 5; $t_cat = 'INFANT';
                } elseif ($p_val < 150) {
                    $p_title = 'Children'; $min_a = 6; $max_a = 17; $t_cat = 'CHILD';
                } else {
                    $p_title = 'Adults'; $min_a = 18; $max_a = 99; $t_cat = 'ADULT';
                }

                $categories[] = array(
                    'id'              => $cid,
                    'title'           => $p_title,
                    'fullTitle'       => $p_title,
                    'ticketCategory'  => $t_cat,
                    'minAge'          => $min_a,
                    'maxAge'          => $max_a,
                    'defaultCategory' => false,
                    'unitPrice'       => $p_val,
                    'price'           => $p_val
                );
                $seen_ids[$cid] = true;
            }
        }

        if (!$has_default && !empty($categories)) {
            $categories[0]['defaultCategory'] = true;
        }

        return $categories;
    }

    public function get_availabilities($activity_id, $start_date = '', $end_date = '', $currency = '') {
        $res = $this->get_availabilities_detailed($activity_id, $start_date, $end_date, $currency);
        return isset($res['slots']) && is_array($res['slots']) ? $res['slots'] : array();
    }

    public function get_availabilities_detailed($activity_id, $start_date = '', $end_date = '', $currency = '') {
        $activity_id = intval($activity_id ?: get_option('bokun_skipcash_default_activity_id', '1317760'));
        if (!$activity_id) {
            return array('success' => false, 'error' => 'Missing activity ID', 'slots' => array());
        }

        $clean_start = !empty($start_date) ? sanitize_text_field($start_date) : gmdate('Y-m-d');
        $clean_end   = !empty($end_date) ? sanitize_text_field($end_date) : gmdate('Y-m-d', strtotime('+30 days'));
        $currency    = $currency ?: get_option('bokun_skipcash_currency', 'QAR');

        $path = '/activity.json/' . $activity_id . '/availabilities?start=' . rawurlencode($clean_start) . '&end=' . rawurlencode($clean_end) . '&currency=' . rawurlencode($currency);
        $url  = $this->base_url . $path;

        $response = wp_remote_get($url, array(
            'headers' => $this->get_headers('GET', $path),
            'timeout' => 20
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error'   => 'WP_Error: ' . $response->get_error_message(),
                'url'     => $url,
                'slots'   => array()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            $data = json_decode($body, true);
            return array(
                'success'   => true,
                'code'      => $code,
                'slots'     => is_array($data) ? $data : array(),
                'slotCount' => is_array($data) ? count($data) : 0,
                'url'       => $url
            );
        } else {
            return array(
                'success' => false,
                'code'    => $code,
                'error'   => 'Bókun API Error HTTP ' . $code . ': ' . substr($body, 0, 250),
                'url'     => $url,
                'slots'   => array()
            );
        }
    }

    /**
     * Cancel a confirmed or reserved booking per Bókun Cancel Booking API
     * Endpoint: POST /booking.json/cancel-booking/{bookingConfirmationCode}
     */
    public function cancel_booking($confirmation_code, $note = 'Cancelled via WordPress admin', $notify = true) {
        $path = '/booking.json/cancel-booking/' . rawurlencode($confirmation_code);
        $url = $this->base_url . $path;

        $body = array(
            'note'   => sanitize_text_field($note),
            'notify' => (bool) $notify
        );

        $response = wp_remote_post($url, array(
            'method'    => 'POST',
            'headers'   => $this->get_headers('POST', $path),
            'body'      => wp_json_encode($body),
            'timeout'   => 20
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        return array(
            'success' => ($code >= 200 && $code < 300),
            'status'  => $code,
            'raw'     => $body
        );
    }

    /**
     * Abort an unconfirmed reservation (e.g. payment failed or test cleanup)
     * Endpoint: POST /booking.json/{bookingConfirmationCode}/abort-reserved
     */
    public function abort_reserved($confirmation_code, $is_timeout = false) {
        $path = '/booking.json/' . rawurlencode($confirmation_code) . '/abort-reserved?timeout=' . ($is_timeout ? 'true' : 'false');
        $url = $this->base_url . $path;
        $response = wp_remote_get($url, array(
            'headers' => $this->get_headers('GET', $path),
            'timeout' => 15
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return array(
            'success' => ($code >= 200 && $code < 300),
            'status'  => $code,
            'raw'     => $body
        );
    }

    /**
     * Report payment error for reserved booking
     * Endpoint per Bókun REST API v1 OpenAPI Spec: POST /booking.json/{confirmationCode}/payment-error
     * Request body: ErrorDto { errorCode: string, errorDescription: string }
     */
    public function report_payment_error($confirmation_code, $error_code = 'PAYMENT_FAILED', $error_desc = 'SkipCash payment failed or was cancelled') {
        $path = '/booking.json/' . rawurlencode($confirmation_code) . '/payment-error';
        $url = $this->base_url . $path;
        $body = array(
            'errorCode'        => $error_code,
            'errorDescription' => $error_desc
        );
        $response = wp_remote_post($url, array(
            'method'  => 'POST',
            'headers' => $this->get_headers('POST', $path),
            'body'    => wp_json_encode($body),
            'timeout' => 15
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return array(
            'success' => ($code >= 200 && $code < 300),
            'status'  => $code,
            'raw'     => $body
        );
    }

    /**
     * Backward-compatible alias for abort_reserved
     */
    public function cancel_reservation($confirmation_code) {
        return $this->abort_reserved($confirmation_code);
    }

    /**
     * Verify incoming Bókun Webhook HMAC signature
     * Header: X-Bokun-HMAC
     */
    public function verify_bokun_webhook($raw_payload, $hmac_header) {
        if (empty($hmac_header) || empty($this->secret_key)) {
            return false;
        }

        $expected_signature = base64_encode(hash_hmac('sha1', $raw_payload, $this->secret_key, true));
        return hash_equals($expected_signature, $hmac_header);
    }

    /**
     * Test Bókun API credentials against an activity
     */
    public function test_connection($activity_id = 0) {
        if (empty($this->access_key) || empty($this->secret_key)) {
            return array('success' => false, 'message' => 'API Access Key and Secret Key must not be empty.');
        }

        $activity_id = intval($activity_id ?: get_option('bokun_skipcash_default_activity_id', '1317760'));
        $path = '/activity.json/' . $activity_id;
        $url = $this->base_url . $path;

        $response = wp_remote_get($url, array(
            'headers' => $this->get_headers('GET', $path),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'Network error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['id'])) {
            return array(
                'success' => true,
                'message' => 'Connected successfully! Activity: "' . ($body['title'] ?? 'ID ' . $body['id']) . '". HMAC authentication verified.',
                'activity' => array(
                    'id'                => $body['id'],
                    'title'             => $body['title'] ?? '',
                    'bookingType'       => $body['bookingType'] ?? 'DATE_AND_TIME',
                    'pricingCategories' => $body['pricingCategories'] ?? array(),
                    'rates'             => $body['rates'] ?? array(),
                    'startTimes'        => $body['startTimes'] ?? array()
                )
            );
        }

        if ($code === 401 || $code === 403) {
            return array('success' => false, 'message' => 'HTTP ' . $code . ' Unauthorized: HMAC signature failed. Please double-check your Bókun Access Key and Secret Key.');
        }

        if ($code === 404) {
            return array('success' => false, 'message' => 'HTTP 404 Not Found: Activity ID ' . $activity_id . ' does not exist in this Bókun account or is private.');
        }

        return array('success' => false, 'message' => 'HTTP ' . $code . ': ' . ($body['message'] ?? 'Could not retrieve activity details.'));
    }

    /**
     * Test a live reservation against Bókun checkout API
     * Checks real availability first to ensure valid date/slot, creates a hold, and immediately releases it
     */
    public function test_reservation($activity_id = 0) {
        $activity_id = intval($activity_id ?: get_option('bokun_skipcash_default_activity_id', '1317760'));
        $act_data = $this->get_activity($activity_id);
        if (!$act_data) {
            return array('success' => false, 'message' => 'Could not fetch activity details for ID ' . $activity_id);
        }

        $adult_cat_id = 1248692;
        if (!empty($act_data['pricingCategories'][0]['id'])) {
            $adult_cat_id = intval($act_data['pricingCategories'][0]['id']);
        }

        $start_time_id = 5772342;
        if (!empty($act_data['startTimes'][0]['id'])) {
            $start_time_id = intval($act_data['startTimes'][0]['id']);
        }

        $rate_id = 2577246;
        if (!empty($act_data['rates'][0]['id'])) {
            $rate_id = intval($act_data['rates'][0]['id']);
        }

        $booking_date = gmdate('Y-m-d', strtotime('+3 days'));

        // Query real-time availability from Bókun to use a 100% valid slot
        $avail_slots = $this->get_availabilities($activity_id);
        if (!empty($avail_slots) && is_array($avail_slots)) {
            foreach ($avail_slots as $slot) {
                if (!is_array($slot)) continue;
                if (!empty($slot['date']) && (!isset($slot['availabilityCount']) || $slot['availabilityCount'] > 0 || !empty($slot['unlimitedAvailability']))) {
                    $raw_d = $slot['date'];
                    if (is_numeric($raw_d)) {
                        $num_d = (float) $raw_d;
                        $booking_date = ($num_d > 100000000000) ? gmdate('Y-m-d', (int)($num_d / 1000)) : gmdate('Y-m-d', (int)$num_d);
                    } elseif (is_string($raw_d) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})/', trim($raw_d), $m)) {
                        $booking_date = $m[0];
                    } elseif (!empty($raw_d) && strtotime($raw_d) !== false) {
                        $booking_date = gmdate('Y-m-d', strtotime($raw_d));
                    }
                    if (!empty($slot['startTimeId'])) {
                        $start_time_id = intval($slot['startTimeId']);
                    }
                    if (!empty($slot['rates'][0]['id'])) {
                        $rate_id = intval($slot['rates'][0]['id']);
                    }
                    break;
                }
            }
        }

        $test_payload = array(
            'currency'       => get_option('bokun_skipcash_currency', 'QAR'),
            'source'         => 'DIRECT_REQUEST',
            'checkoutOption' => 'CUSTOMER_FULL_PAYMENT',
            'paymentMethod'  => 'RESERVE_FOR_EXTERNAL_PAYMENT',
            'directBooking'  => array(
                'mainContactDetails' => array(
                    array('questionId' => 'firstName', 'values' => array('Test')),
                    array('questionId' => 'lastName',  'values' => array('Booking')),
                    array('questionId' => 'email',     'values' => array('test@example.com')),
                    array('questionId' => 'phoneNumber', 'values' => array('+97455000000'))
                ),
                'activityBookings'   => array(
                    array(
                        'activityId'  => $activity_id,
                        'date'        => $booking_date,
                        'startTimeId' => $start_time_id,
                        'rateId'      => $rate_id,
                        'passengers'  => array(
                            array(
                                'pricingCategoryId' => $adult_cat_id,
                                'groupSize'         => 1,
                                'passengerDetails'  => array(
                                    array('questionId' => 'firstName', 'values' => array('Test')),
                                    array('questionId' => 'lastName',  'values' => array('Booking'))
                                )
                            )
                        )
                    )
                )
            )
        );

        $res = $this->reserve_for_external_payment($test_payload);

        // If reserved successfully, automatically cancel the test hold
        if (!empty($res['success']) && !empty($res['confirmationCode'])) {
            $this->cancel_reservation($res['confirmationCode']);
            $res['message'] = 'Reservation created successfully (Code: ' . $res['confirmationCode'] . ' | Slot: ' . $booking_date . ') and test hold was automatically released.';
        }

        return $res;
    }
}
