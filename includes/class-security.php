<?php
namespace SSLL;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

final class Security {
    /**
     * Class instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Valid image MIME types and their extensions.
     *
     * @var array
     */
    private static $valid_mime_types = [
        'image/jpeg' => 'jpg|jpeg|jpe',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp'
    ];
    
    /**
     * Actions that should be rate limited.
     *
     * @var array
     */
    private static $rate_limited_actions = [
        'ssll_save_logo',
        'ssll_remove_logo'
    ];
    
    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct() {}
    
    /**
     * Prevent cloning of the instance.
     */
    private function __clone() {}
    
    /**
     * Prevent unserializing of the instance.
     *
     * @throws \Exception
     */
    public function __wakeup() {
        throw new \Exception('Unserialize is not allowed.');
    }
    
    /**
     * Get the singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize security features.
     */
    public function init() {
        add_action('init', [$this, 'init_security']);
    }
    
    /**
     * Initialize security headers and rate limiting.
     */
    public function init_security() {
        if (!headers_sent()) {
            add_action('send_headers', [$this, 'add_security_headers'], 1);
        }
        
        if (!is_admin()) {
            return;
        }
        
        foreach (self::$rate_limited_actions as $action) {
            add_action("admin_post_{$action}", [$this, 'check_rate_limit'], 5);
        }
    }
    
    /**
     * Add security headers to responses.
     */
    public function add_security_headers() {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        if (isset($_GET['page']) && $_GET['page'] === 'stupid-simple-login-logo') {
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline';");
        }
    }
    
    /**
     * Validate file MIME type.
     *
     * @param string $file_path Path to the file
     * @return string|false MIME type if valid, false otherwise
     */
    public function validate_mime_type($file_path) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        $wp_check = wp_check_filetype($file_path);
        if (!empty($wp_check['type']) && isset(self::$valid_mime_types[$wp_check['type']])) {
            return $wp_check['type'];
        }

        if (function_exists('mime_content_type')) {
            $mime_type = mime_content_type($file_path);
            if ($mime_type && isset(self::$valid_mime_types[$mime_type])) {
                return $mime_type;
            }
        }

        $image_info = @getimagesize($file_path);
        if ($image_info && isset($image_info['mime'])) {
            $mime_type = $image_info['mime'];
            if (isset(self::$valid_mime_types[$mime_type])) {
                return $mime_type;
            }
        }

        return false;
    }
    
    /**
     * Validate image URL including MIME type check.
     *
     * @param string $url The URL to validate
     * @return bool
     */
    public function validate_image_url($url) {
        // First sanitize the URL
        $url = $this->sanitize_image_url($url);
        if (!$url) {
            return false;
        }

        // For local Media Library images
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            $mime_type = get_post_mime_type($attachment_id);
            if (!isset(self::$valid_mime_types[$mime_type])) {
                return false;
            }

            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !$this->validate_mime_type($file_path)) {
                return false;
            }

            return true;
        }

        // For external URLs
        $headers = wp_get_http_headers($url);
        if (!$headers || !isset($headers['content-type'])) {
            return false;
        }

        $mime_type = $headers['content-type'];
        // Strip charset if present
        if (strpos($mime_type, ';') !== false) {
            $mime_type = trim(strstr($mime_type, ';', true));
        }

        return isset(self::$valid_mime_types[$mime_type]);
    }
    
    /**
     * Check rate limiting for actions.
     *
     * @return bool
     */
    public function check_rate_limit() {
        $ip = $this->get_client_ip();
        if (empty($ip)) {
            return false;
        }
        
        $salt = wp_hash(random_bytes(32));
        $rate_key = 'ssll_rate_' . wp_hash($ip . $salt);
        
        $rate_data = get_transient($rate_key);
        $current_time = time();
        
        if (false === $rate_data) {
            set_transient($rate_key, [
                'count' => 1,
                'timestamp' => $current_time,
                'ip' => $ip
            ], MINUTE_IN_SECONDS);
            return true;
        }
        
        if ($rate_data['ip'] !== $ip) {
            return false;
        }
        
        if (($current_time - $rate_data['timestamp']) > MINUTE_IN_SECONDS) {
            set_transient($rate_key, [
                'count' => 1,
                'timestamp' => $current_time,
                'ip' => $ip
            ], MINUTE_IN_SECONDS);
            return true;
        }
        
        if ($rate_data['count'] >= SSLL_RATE_LIMIT_MAX) {
            $this->log_security_event('Rate limit exceeded', ['ip' => $ip]);
            wp_die(
                esc_html__('Rate limit exceeded. Please try again later.', 'ssll-for-wp'),
                esc_html__('Too Many Requests', 'ssll-for-wp'),
                ['response' => 429]
            );
        }
        
        $rate_data['count']++;
        set_transient($rate_key, $rate_data, MINUTE_IN_SECONDS);
        
        return true;
    }
    
    /**
     * Get client IP address.
     *
     * @return string
     */
    private function get_client_ip() {
        static $ip = null;
        
        if (null !== $ip) {
            return $ip;
        }
        
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } elseif (defined('SSLL_TRUSTED_PROXY') && SSLL_TRUSTED_PROXY) {
            $headers = [
                'HTTP_CF_CONNECTING_IP',
                'HTTP_X_REAL_IP',
                'HTTP_X_FORWARDED_FOR'
            ];
            
            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = $_SERVER[$header];
                    if (strpos($ip, ',') !== false) {
                        $ips = explode(',', $ip);
                        $ip = trim($ips[0]);
                    }
                    break;
                }
            }
        }
        
        return filter_var($ip ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ?: '';
    }
    
    /**
     * Verify nonce.
     *
     * @param string $nonce Nonce to verify
     * @param string $action Action name
     * @return bool|\WP_Error
     */
    public function verify_nonce($nonce, $action) {
        if (!wp_verify_nonce($nonce, $action)) {
            $this->log_security_event('Invalid nonce', [
                'action' => $action,
                'ip' => $this->get_client_ip()
            ]);
            
            wp_die(
                esc_html__('Security check failed.', 'ssll-for-wp'),
                esc_html__('Security Error', 'ssll-for-wp'),
                ['response' => 403, 'back_link' => true]
            );
        }
        return true;
    }
    
    /**
     * Check if user has capability.
     *
     * @param string $capability Capability to check
     * @return bool
     */
    public function has_capability($capability = 'manage_options') {
        static $caps = [];
        
        if (!isset($caps[$capability])) {
            $caps[$capability] = current_user_can($capability);
        }
        
        return $caps[$capability];
    }
    
    /**
     * Verify user capability.
     *
     * @param string $capability Capability to verify
     * @return bool|\WP_Error
     */
    public function verify_user_capability($capability = 'manage_options') {
        if (!$this->has_capability($capability)) {
            $this->log_security_event('Unauthorized access attempt', [
                'capability' => $capability,
                'user_id' => get_current_user_id(),
                'ip' => $this->get_client_ip()
            ]);
            
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'ssll-for-wp'),
                esc_html__('Permission Denied', 'ssll-for-wp'),
                ['response' => 403, 'back_link' => true]
            );
        }
        return true;
    }
    
    /**
     * Sanitize image URL.
     *
     * @param string $url URL to sanitize
     * @return string|false
     */
    public function sanitize_image_url($url) {
        $url = esc_url_raw($url);
        if (empty($url)) {
            return false;
        }
        
        $parsed_url = wp_parse_url($url);
        if (false === $parsed_url || !isset($parsed_url['host'])) {
            return false;
        }
        
        $site_url = wp_parse_url(site_url());
        if ($parsed_url['host'] !== $site_url['host']) {
            return false;
        }
        
        return $url;
    }

    /**
     * Sanitize option value.
     *
     * @param mixed $value Value to sanitize
     * @return mixed
     */
    public function sanitize_option_value($value) {
        if (is_array($value)) {
            return array_map([$this, 'sanitize_option_value'], $value);
        }
        
        if (is_string($value)) {
            return sanitize_text_field($value);
        }
        
        return $value;
    }
    
    /**
     * Sanitize file path.
     *
     * @param string $path Path to sanitize
     * @return string|false
     */
    public function sanitize_file_path($path) {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/\.+\//', '', $path);
        
        $real_path = realpath($path);
        $upload_path = realpath(wp_upload_dir()['basedir']);
        
        if (!$real_path || !$upload_path || strpos($real_path, $upload_path) !== 0) {
            return false;
        }
        
        return $real_path;
    }
    
    /**
     * Log security events.
     *
     * @param string $message Event message
     * @param array $context Event context
     */
    private function log_security_event($message, $context = []) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'SSLL Security Event: %s - %s',
                $message,
                wp_json_encode($context)
            ));
        }
    }
    
    /**
     * Clean up security-related data.
     */
    public function cleanup() {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_ssll_rate_') . '%'
        ));
    }
    
    /**
     * Uninstall security-related data.
     */
    public function uninstall() {
        $this->cleanup();
    }
}