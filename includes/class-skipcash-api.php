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
     * Verify Incoming SkipCash Webhook
     */
    public function verify_webhook($payload_string, $signature_header) {
        if (empty($signature_header) || empty($this->secret_key)) {
            return false;
        }

        $expected_signature = $this->calculate_signature($payload_string);
        return hash_equals($expected_signature, $signature_header);
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
}
