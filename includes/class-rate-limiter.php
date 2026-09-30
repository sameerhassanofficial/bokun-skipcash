<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Bokun_Rate_Limiter
 *
 * Simple transient-backed fixed-window rate limiter for public REST endpoints.
 */
class Bokun_Rate_Limiter {

    /**
     * @param string $bucket  Logical bucket name (e.g. 'reserve').
     * @param int    $limit   Max allowed hits per window.
     * @param int    $window  Window size in seconds.
     * @return bool True if the request is allowed, false if rate-limited.
     */
    public static function allow($bucket, $limit = 10, $window = 60) {
        $key = 'bokun_rl_' . md5($bucket . '|' . self::client_ip());

        $hit = get_transient($key);
        if ($hit === false) {
            set_transient($key, 1, $window);
            return true;
        }
        $hit = intval($hit);
        if ($hit >= $limit) {
            return false;
        }
        // Retain original expiry by bumping value only.
        set_transient($key, $hit + 1, $window);
        return true;
    }

    private static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
        return $ip;
    }
}
