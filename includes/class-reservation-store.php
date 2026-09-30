<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Bokun_Reservation_Store
 *
 * Durable, database-backed store for reservation records (replaces the previous
 * transient-based storage that was removed, which broke the confirmation flow).
 *
 * Records are keyed by Bókun confirmation code and carry:
 *  - a server-authoritative expected amount (never taken from client input)
 *  - a signed verification token handed to the customer's browser so the
 *    return page can prove ownership of the reservation without exposing PII
 *  - payment/confirmation status transitions
 */
class Bokun_Reservation_Store {

    const TABLE = 'bokun_reservations';
    const TOKEN_TTL_MINUTES = 60;

    /**
     * Ensure the custom table exists (called from activation & plugins_loaded fallback).
     *
     * IMPORTANT: this runs both from the activation hook and from a plugins_loaded
     * safety net. The plugins_loaded context does NOT have wp-admin loaded, so
     * wp-admin/includes/upgrade.php (dbDelta) is not always available. Requiring it
     * unconditionally caused a fatal "Failed opening required .../upgrade.php" error
     * during activation/loading on some hosts. We therefore load it only when present
     * and fall back to plain dbDelta()/raw CREATE TABLE.
     */
    public static function maybe_install() {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $table_name = $wpdb->prefix . self::TABLE;
        $version    = get_option('bokun_skipcash_db_version', '0');

        if ($version === '1.0.0' && self::table_exists($table_name)) {
            return;
        }

        // Load dbDelta only if the admin upgrade file actually exists on this request.
        $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (!$upgrade_file || !function_exists('dbDelta')) {
            if (is_readable($upgrade_file)) {
                require_once $upgrade_file;
            }
        }

        $charset_collate = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            confirmation_code VARCHAR(64) NOT NULL,
            booking_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            skipcash_payment_id VARCHAR(128) NOT NULL DEFAULT '',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'QAR',
            activity_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            activity_name VARCHAR(255) NOT NULL DEFAULT '',
            customer_json LONGTEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'RESERVED',
            verify_token_hash CHAR(64) NOT NULL DEFAULT '',
            token_expires_at BIGINT(20) NOT NULL DEFAULT 0,
            created_at BIGINT(20) NOT NULL DEFAULT 0,
            updated_at BIGINT(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY confirmation_code (confirmation_code),
            KEY skipcash_payment_id (skipcash_payment_id),
            KEY status (status)
        ) {$charset_collate};";

        if (function_exists('dbDelta')) {
            dbDelta($sql);
        } else {
            // dbDelta unavailable (non-admin request on some hosts): create directly.
            $wpdb->query($sql);
        }

        if (self::table_exists($table_name)) {
            update_option('bokun_skipcash_db_version', '1.0.0');
        } else {
            error_log('[Bokun_Reservation_Store] Could not create table ' . $table_name . ': ' . $wpdb->last_error);
        }
    }

    /**
     * Whether a given table actually exists in the database.
     */
    public static function table_exists($table_name) {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return false;
        }
        $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        return $found === $table_name;
    }

    private function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create (or replace) a reservation record. Returns the stored row as array.
     */
    public function create($record) {
        global $wpdb;

        $token = bin2hex(random_bytes(32));

        $row = array(
            'confirmation_code'    => (string)($record['confirmationCode'] ?? ''),
            'booking_id'           => intval($record['bookingId'] ?? 0),
            'skipcash_payment_id'  => (string)($record['skipcash_payment_id'] ?? ''),
            'amount'               => round(floatval($record['amount'] ?? 0), 2),
            'currency'             => (string)($record['currency'] ?? 'QAR'),
            'activity_id'          => intval($record['activity_id'] ?? 0),
            'activity_name'        => mb_substr((string)($record['activity_name'] ?? 'Tour Booking'), 0, 255),
            'customer_json'        => wp_json_encode($record['customer'] ?? array()),
            'status'               => (string)($record['status'] ?? 'RESERVED'),
            'verify_token_hash'    => hash('sha256', $token),
            'token_expires_at'     => time() + (self::TOKEN_TTL_MINUTES * 60),
            'created_at'           => time(),
            'updated_at'           => time(),
        );

        if (empty($row['confirmation_code'])) {
            return array('success' => false, 'message' => 'Missing confirmation code.');
        }

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table()} WHERE confirmation_code = %s LIMIT 1", $row['confirmation_code']));
        if ($existing) {
            $wpdb->delete($this->table(), array('id' => intval($existing)), array('%d'));
        }

        $inserted = $wpdb->insert($this->table(), $row, array(
            '%s', '%d', '%s', '%f', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d'
        ));

        if ($inserted === false) {
            error_log('[Bokun_Reservation_Store] Failed to persist reservation: ' . $wpdb->last_error);
            return array('success' => false, 'message' => 'Could not persist reservation record.');
        }

        $row['verify_token'] = $token; // returned once to the client only
        return array('success' => true, 'record' => $row, 'verify_token' => $token);
    }

    /**
     * Fetch a record by Bókun confirmation code.
     */
    public function get_by_code($confirmation_code) {
        global $wpdb;
        $code = (string)$confirmation_code;
        if ($code === '' || strlen($code) > 64) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE confirmation_code = %s LIMIT 1", $code), ARRAY_A);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Fetch a record by SkipCash payment id.
     */
    public function get_by_payment_id($payment_id) {
        global $wpdb;
        $pid = (string)$payment_id;
        if ($pid === '' || strlen($pid) > 128) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE skipcash_payment_id = %s ORDER BY id DESC LIMIT 1", $pid), ARRAY_A);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Update mutable fields of a record.
     */
    public function update($confirmation_code, array $fields) {
        global $wpdb;
        $allowed = array(
            'status'              => '%s',
            'amount'              => '%f',
            'currency'            => '%s',
            'skipcash_payment_id' => '%s',
            'verify_token_hash'   => '%s',
            'token_expires_at'    => '%d',
            'customer_json'       => '%s',
            'booking_id'          => '%d',
        );
        $data = array();
        $formats = array();
        foreach ($fields as $k => $v) {
            if (isset($allowed[$k])) {
                $data[$k] = $v;
                $formats[] = $allowed[$k];
            }
        }
        if (empty($data)) {
            return false;
        }
        $data['updated_at'] = time();
        $formats[] = '%d';
        return $wpdb->update($this->table(), $data, array('confirmation_code' => (string)$confirmation_code), $formats, array('%s')) !== false;
    }

    /**
     * Mark a reservation as confirmed.
     */
    public function mark_confirmed($confirmation_code, $payment_id = '') {
        global $wpdb;
        $data = array('status' => 'CONFIRMED', 'updated_at' => time());
        $formats = array('%s', '%d');
        if ($payment_id !== '') {
            $data['skipcash_payment_id'] = (string)$payment_id;
            $formats[] = '%s';
        }
        return $wpdb->update($this->table(), $data, array('confirmation_code' => (string)$confirmation_code), $formats, array('%s')) !== false;
    }

    /**
     * Verify that a bearer token belongs to the given confirmation code.
     */
    public function verify_token($confirmation_code, $token) {
        $record = $this->get_by_code($confirmation_code);
        if (!$record || empty($record['verify_token_hash']) || empty($record['token_expires_at'])) {
            return false;
        }
        if ($record['token_expires_at'] < time()) {
            return false;
        }
        return hash_equals($record['verify_token_hash'], hash('sha256', (string)$token));
    }

    /**
     * Purge stale RESERVED rows (older than the Bókun hold window).
     */
    public function purge_expired($max_age_seconds = 0) {
        global $wpdb;
        $max_age_seconds = $max_age_seconds ?: (BOKUN_SKIPCASH_TIMEOUT_MINUTES * 60) + 3600;
        $cutoff = time() - $max_age_seconds;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE status = %s AND updated_at < %d",
            'RESERVED',
            $cutoff
        ));
    }

    private function hydrate(array $row) {
        $row['customer'] = json_decode((string)($row['customer_json'] ?? '{}'), true);
        if (!is_array($row['customer'])) {
            $row['customer'] = array();
        }
        $row['amount'] = floatval($row['amount']);
        unset($row['customer_json']);
        return $row;
    }
}
