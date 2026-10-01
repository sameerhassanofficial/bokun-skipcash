<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SkipCash_API
 * Handles interaction with SkipCash Payment Gateway (Qatar).
 * Generates payment sessions and verifies webhooks with HMAC-SHA256.
 */
class SkipCash_API {
    private $base_url;
    private $key_id;
    private $secret_key;
    private $client_id;
    private $webhook_secret;

    public function __construct() {
        $this->base_url       = rtrim(trim(get_option('bokun_skipcash_skipcash_base_url', 'https://api.skipcash.app')), '/');
        $this->key_id         = trim(get_option('bokun_skipcash_skipcash_key_id', ''));
        $this->secret_key     = trim(get_option('bokun_skipcash_skipcash_secret_key', ''));
        $this->client_id      = trim(get_option('bokun_skipcash_skipcash_client_id', ''));
        $this->webhook_secret = trim(get_option('bokun_skipcash_skipcash_webhook_secret', ''));
    }

    /**
     * Calculate SkipCash HMAC-SHA256 signature
     */
    public function calculate_signature($data_string) {
        return base64_encode(hash_hmac('sha256', $data_string, $this->secret_key, true));
    }

    /**
     * Step 5: Create SkipCash Payment Link/Session
     * 
     * Endpoint: POST /api/v1/payments
     * Sends customer contact, amount (QAR), confirmationCode in Custom1
     */
    public function create_payment_link($params) {
        $path = '/api/v1/payments';
        $url  = $this->base_url . $path;

        $uid            = wp_generate_uuid4();
        $key_id         = $this->key_id;
        $amount         = number_format((float)$params['amount'], 2, '.', '');
        $first_name     = sanitize_text_field($params['first_name']);
        $last_name      = sanitize_text_field($params['last_name']);
        $phone_raw      = sanitize_text_field($params['phone'] ?? '');
        $norm_phone     = Bokun_API::normalize_phone_components($phone_raw, '+974');
        $phone          = $norm_phone['e164'];
        // Strict SkipCash safeguard: Phone length must be 15 characters or fewer
        if (strlen($phone) > 15) {
            $phone = substr($phone, 0, 15);
        }
        $email          = sanitize_email($params['email']);
        $transaction_id = sanitize_text_field($params['transaction_id']); // Bókun confirmationCode or custom ref
        $custom_1       = sanitize_text_field($params['confirmation_code']);
        
        $street         = 'N/A';
        $city           = 'Doha';
        $state          = 'QA';
        $country        = 'QA';
        $postal_code    = '00000';

        $fields_to_sign = array(
            'Uid'           => $uid,
            'KeyId'         => $key_id,
            'Amount'        => $amount,
            'FirstName'     => $first_name,
            'LastName'      => $last_name,
            'Phone'         => $phone,
            'Email'         => $email,
            'Street'        => $street,
            'City'          => $city,
            'State'         => $state,
            'Country'       => $country,
            'PostalCode'    => $postal_code,
            'TransactionId' => $transaction_id,
            'Custom1'       => $custom_1
        );

        $signed_parts = array();
        foreach ($fields_to_sign as $k => $v) {
            if ($v !== null && $v !== '') {
                $signed_parts[] = "{$k}={$v}";
            }
        }
        $raw_signature_data = implode(',', $signed_parts);
        $signature = $this->calculate_signature($raw_signature_data);

        // Body includes signed fields plus unsigned ReturnUrl/WebhookUrl
        $body = $fields_to_sign;
        $body['ReturnUrl']  = $params['return_url'];
        $body['WebhookUrl'] = $params['webhook_url'];

        $response = wp_remote_post($url, array(
            'method'    => 'POST',
            'headers'   => array(
                'Authorization' => $signature,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ),
            'body'      => wp_json_encode($body),
            'timeout'   => 25
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && !empty($res_body['resultObj']['payUrl'])) {
            return array(
                'success' => true,
                'payUrl'  => $res_body['resultObj']['payUrl'],
                'paymentId'=> $res_body['resultObj']['id'] ?? $uid
            );
        }

        return array(
            'success' => false,
            'message' => 'Failed to initialize SkipCash session. Raw response: ' . wp_remote_retrieve_body($response),
            'raw'     => $res_body
        );
    }

    /**
     * Whether any secret is available to verify webhook signatures with.
     */
    public function has_webhook_secret() {
        return !empty($this->webhook_secret) || !empty($this->secret_key);
    }

    /**
     * Verify Incoming SkipCash Webhook
     * Tries the dedicated webhook secret first, then the API secret key
     * (the original gateway verified with secret_key).
     */
    public function verify_webhook($payload_string, $signature_header) {
        if (empty($signature_header)) {
            return false;
        }

        $secrets = array_filter(array($this->webhook_secret, $this->secret_key));
        foreach ($secrets as $secret) {
            $expected_signature = base64_encode(hash_hmac('sha256', $payload_string, $secret, true));
            if (hash_equals($expected_signature, $signature_header)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verify payment status directly with SkipCash server (Step 7)
     */
    public function get_payment_status($payment_id) {
        $path = '/api/v1/payments/' . rawurlencode($payment_id);
        $url = $this->base_url . $path;

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->calculate_signature("PaymentId={$payment_id}"),
                'Accept'        => 'application/json'
            ),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Look up a payment by our transaction reference (Bókun confirmation code).
     * Used as a fallback when the browser returns without a paymentId:
     * GET /api/v1/payments?TransactionId={code}
     * Returns the matching payment object (with Id/StatusId) or false.
     */
    public function get_payment_by_transaction($transaction_id) {
        $transaction_id = rawurlencode((string)$transaction_id);
        if ($transaction_id === '') {
            return false;
        }

        $candidates = array(
            '/api/v1/payments?TransactionId=' . $transaction_id,
            '/api/v1/payments/by-transaction/' . $transaction_id,
        );

        foreach ($candidates as $path) {
            $response = wp_remote_get($this->base_url . $path, array(
                'headers' => array(
                    'Authorization' => $this->calculate_signature("TransactionId={$transaction_id}"),
                    'Accept'        => 'application/json'
                ),
                'timeout' => 15
            ));

            if (is_wp_error($response)) {
                continue;
            }

            $status_code = intval(wp_remote_retrieve_response_code($response));
            if ($status_code < 200 || $status_code >= 300) {
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body)) {
                continue;
            }

            // Normalize common list shapes: {resultObj:[...]}, {payments:[...]}, [...]
            $list = null;
            if (isset($body['resultObj']) && is_array($body['resultObj'])) {
                $list = isset($body['resultObj'][0]) ? $body['resultObj'] : array($body['resultObj']);
            } elseif (isset($body['payments']) && is_array($body['payments'])) {
                $list = $body['payments'];
            } elseif (isset($body[0]) && is_array($body[0])) {
                $list = $body;
            }

            if (!empty($list)) {
                // Newest first when identifiable, so retried bookings match the latest payment.
                usort($list, function ($a, $b) {
                    return strcmp((string)($b['CreatedDate'] ?? ''), (string)($a['CreatedDate'] ?? ''));
                });
                return $list[0];
            }
        }

        return false;
    }

    /**
     * Extract the authoritative "is this paid?" signal from any SkipCash payload
     * shape (webhook body or API response, camel/snake/Pascal keys, wrapped in
     * resultObj or flat).
     */
    public static function extract_paid_status($data) {
        if (!is_array($data) || empty($data)) {
            return array('is_paid' => false, 'payment_id' => '', 'found' => false);
        }

        $ro = $data['resultObj'] ?? $data['ResultObj'] ?? null;
        // If resultObj wraps a single payment object, merge its fields for lookup;
        // if it wraps a list, take the first entry.
        if (is_array($ro)) {
            if (isset($ro[0]) && is_array($ro[0])) {
                $ro = $ro[0];
            }
            $data = array_merge($data, $ro);
        }

        $found = isset($data['statusId']) || isset($data['StatusId'])
              || isset($data['status']) || isset($data['Status'])
              || isset($data['paidDate']) || isset($data['PaidDate'])
              || isset($data['paymentStatus']) || isset($data['PaymentStatus']);

        $st_id  = intval($data['statusId'] ?? ($data['StatusId'] ?? ($data['paymentStatusId'] ?? ($data['PaymentStatusId'] ?? -1))));
        $st_str = strtolower((string)($data['status'] ?? ($data['Status'] ?? ($data['paymentStatus'] ?? ($data['PaymentStatus'] ?? '')))));

        $is_paid = ($st_id === 2)
                || ($st_str === 'paid')
                || !empty($data['paidDate'])
                || !empty($data['PaidDate'])
                || !empty($data['paid_date'])
                || !empty($data['captureDate'])
                || !empty($data['CaptureDate']);

        $payment_id = (string)($data['PaymentId'] ?? ($data['paymentId'] ?? ($data['Id'] ?? ($data['id'] ?? ''))));

        return array('is_paid' => $is_paid, 'payment_id' => $payment_id, 'found' => $found);
    }
}
