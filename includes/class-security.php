<?php
namespace SSLL;

/**
 * Security management class
 */
final class Security {
    private static $instance = null;
    private static $valid_mime_types = [
        'image/jpeg' => 'jpg|jpeg|jpe',
        'image/png'  => 'png'
    ];
    
    private static $rate_limited_actions = [
        'ssll_save_logo',
        'ssll_remove_logo'
    ];
    
    // Whitelist of allowed domains for image URLs
    private static $allowed_domains = [];
    
    private function __construct() {
        // Initialize allowed domains from site URL
        $site_url = parse_url(get_site_url(), PHP_URL_HOST);
        self::$allowed_domains = [
            $site_url,
            'www.' . $site_url
        ];
    }
    
    private function __clone() {}
    
    public function __wakeup() {
        throw new \Exception('Unserialize is not allowed.');
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function init() {
        add_action('init', [$this, 'init_security']);
    }
    
    public function init_security() {
        // Add security headers early
        if (!headers_sent()) {
            add_action('send_headers', [$this, 'add_security_headers'], 1);
        }
        
        // Only add hooks for admin actions
        if (!is_admin()) {
            return;
        }
        
        foreach (self::$rate_limited_actions as $action) {
            add_action("admin_post_{$action}", [$this, 'check_rate_limit'], 5);
        }
    }

    public function add_security_headers() {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Add CSP only on plugin pages
        if (isset($_GET['page']) && $_GET['page'] === 'stupid-simple-login-logo') {
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline';");
        }
    }
    
    private function validate_mime_type($file_path) {
        // Try wp_check_filetype first
        $wp_check = wp_check_filetype($file_path);
        if (!empty($wp_check['type']) && isset(self::$valid_mime_types[$wp_check['type']])) {
            return $wp_check['type'];
        }

        // Try mime_content_type if available
        if (function_exists('mime_content_type')) {
            $mime_type = mime_content_type($file_path);
            if ($mime_type && isset(self::$valid_mime_types[$mime_type])) {
                return $mime_type;
            }
        }

        // Use getimagesize as final fallback
        $image_info = @getimagesize($file_path);
        if ($image_info && isset($image_info['mime'])) {
            $mime_type = $image_info['mime'];
            if (isset(self::$valid_mime_types[$mime_type])) {
                return $mime_type;
            }
        }

        return false;
    }

    public function validate_image_url($url) {
        static $validated = [];
        
        if (isset($validated[$url])) {
            return $validated[$url];
        }
        
        // Basic sanitization
        $url = esc_url_raw($url);
        
        // Parse URL
        $parsed_url = wp_parse_url($url);
        if (false === $parsed_url || !isset($parsed_url['host'])) {
            $validated[$url] = false;
            return false;
        }
        
        // Domain validation
        if (!in_array($parsed_url['host'], self::$allowed_domains, true)) {
            $validated[$url] = false;
            return false;
        }
        
        // Verify file exists in Media Library
        $attachment_id = attachment_url_to_postid($url);
        if (!$attachment_id) {
            $validated[$url] = false;
            return false;
        }
        
        // Additional file validation
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            $validated[$url] = false;
            return false;
        }
        
        // Validate MIME type using our custom method
        $mime_type = $this->validate_mime_type($file_path);
        if (!$mime_type) {
            $validated[$url] = false;
            return false;
        }
        
        // Path traversal protection
        $normalized_path = realpath($file_path);
        $uploads_dir = realpath(wp_upload_dir()['basedir']);
        
        if (!$normalized_path || !$uploads_dir || strpos($normalized_path, $uploads_dir) !== 0) {
            $validated[$url] = false;
            return false;
        }
        
        $validated[$url] = true;
        return true;
    }
    
    public function check_rate_limit() {
        $ip = $this->get_client_ip();
        if (empty($ip)) {
            return false;
        }
        
        // Generate unique key with random salt
        $salt = wp_hash(random_bytes(8));
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
        
        // Verify IP hasn't changed (prevent cache poisoning)
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
    
    private function get_client_ip() {
        static $ip = null;
        
        if (null !== $ip) {
            return $ip;
        }
        
        // Prefer REMOTE_ADDR as it can't be spoofed
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } elseif (defined('SSLL_TRUSTED_PROXY') && SSLL_TRUSTED_PROXY) {
            // Only check proxy headers if we trust the proxy
            $headers = [
                'HTTP_CF_CONNECTING_IP', // Cloudflare
                'HTTP_X_REAL_IP',       // Nginx
                'HTTP_X_FORWARDED_FOR'  // Standard proxy
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
    
    public function has_capability($capability = 'manage_options') {
        static $caps = [];
        
        if (!isset($caps[$capability])) {
            $caps[$capability] = current_user_can($capability);
        }
        
        return $caps[$capability];
    }
    
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
    
    private function log_security_event($message, $context = []) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'SSLL Security Event: %s - %s',
                $message,
                wp_json_encode($context)
            ));
        }
    }
    
    public function sanitize_option_value($value) {
        if (is_string($value)) {
            // Remove potential PHP serialized objects
            if (preg_match('/^[OoNnRr]:\d+:"/', $value)) {
                return '';
            }
            return sanitize_text_field($value);
        }
        return $value;
    }
    
    public function cleanup() {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_ssll_rate_') . '%'
        ));
    }
    
    public function uninstall() {
        $this->cleanup();
    }
}