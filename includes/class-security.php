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
        'ssll_remove_logo',
        'ssll_update_settings'
    ];
    
    private $temp_dir;
    private $log_dir;
    private $file_locks = [];

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct() {
        $this->temp_dir = SSLL_TEMP_DIR;
        $this->log_dir = SSLL_LOG_DIR;
        $this->init_directories();
    }
    
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
        // Add security headers
        add_action('send_headers', [$this, 'add_security_headers'], 1);
        
        // Add rate limiting only for specific AJAX actions
        add_action('wp_ajax_ssll_remove_logo', [$this, 'check_rate_limit'], 5);
        add_action('wp_ajax_ssll_update_settings', [$this, 'check_rate_limit'], 5);
        
        // Add capability checks only for plugin's admin page
        add_action('admin_menu', [$this, 'init_capability_checks'], 0);
    }
    
    /**
     * Initialize security headers and rate limiting.
     */
    public function add_security_headers() {
        if (headers_sent()) {
            return;
        }

        // Prevent XSS attacks
        header('X-XSS-Protection: 1; mode=block');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // Enable HSTS
        if (is_ssl()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        
        // Control referrer information
        header('Referrer-Policy: strict-origin-when-cross-origin');
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
        
        $rate_key = 'ssll_rate_' . wp_hash($ip);
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
            $this->log_security_event('Rate limit IP mismatch', [
                'ip' => $ip,
                'expected_ip' => $rate_data['ip']
            ]);
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
            $this->log_security_event('Rate limit exceeded', [
                'ip' => $ip,
                'count' => $rate_data['count'],
                'action' => current_action()
            ]);
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
        $ip = '';
        
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
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
            return false;
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
        return current_user_can($capability);
    }
    
    /**
     * Verify user capability.
     *
     * @param string $capability Capability to verify
     * @return bool|\WP_Error
     */
    public function verify_user_capability($capability = 'manage_options') {
        // Only check capabilities on the plugin's admin page
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($current_page !== 'stupid-simple-login-logo') {
            return true;
        }

        if (!current_user_can($capability)) {
            $this->log_security_event('Insufficient capabilities', [
                'capability' => $capability,
                'user' => get_current_user_id(),
                'page' => $current_page
            ]);
            return false;
        }
        return true;
    }
    
    /**
     * Log security events.
     *
     * @param string $message Event message
     * @param array $context Event context
     */
    private function log_security_event($message, $context = []) {
        if (!is_dir($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
        
        $log_file = $this->log_dir . '/security-' . date('Y-m-d') . '.log';
        $timestamp = current_time('mysql');
        $user_id = get_current_user_id();
        
        $log_entry = sprintf(
            "[%s] %s | User: %d | IP: %s | Context: %s\n",
            $timestamp,
            $message,
            $user_id,
            $this->get_client_ip(),
            json_encode($context)
        );
        
        error_log($log_entry, 3, $log_file);
    }
    
    /**
     * Clean up security-related data.
     */
    public function cleanup() {
        $this->cleanup_temp_files();
        $this->cleanup_old_logs();
    }
    
    /**
     * Uninstall security-related data.
     */
    public function uninstall() {
        $this->cleanup();
    }

    private function init_directories() {
        if (!is_dir($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }
        
        if (!is_dir($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
    }

    public function check_requirements() {
        if (version_compare(PHP_VERSION, SSLL_MIN_PHP, '<')) {
            add_action('admin_notices', function() {
                printf(
                    '<div class="error"><p>%s</p></div>',
                    sprintf(
                        /* translators: %s: PHP version */
                        esc_html__('Stupid Simple Login Logo requires PHP version %s or higher.', 'ssll-for-wp'),
                        SSLL_MIN_PHP
                    )
                );
            });
        }
        
        if (version_compare($GLOBALS['wp_version'], SSLL_MIN_WP, '<')) {
            add_action('admin_notices', function() {
                printf(
                    '<div class="error"><p>%s</p></div>',
                    sprintf(
                        /* translators: %s: WordPress version */
                        esc_html__('Stupid Simple Login Logo requires WordPress version %s or higher.', 'ssll-for-wp'),
                        SSLL_MIN_WP
                    )
                );
            });
        }
    }

    public function validate_image($file_path) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }
        
        // Verify file ownership
        if (fileowner($file_path) !== SSLL_FILE_OWNER) {
            return false;
        }
        
        // Verify file permissions
        if (fileperms($file_path) !== SSLL_FILE_PERMS) {
            return false;
        }
        
        // Check file size
        $file_size = filesize($file_path);
        if ($file_size === false || $file_size > SSLL_MAX_FILE_SIZE) {
            return false;
        }
        
        // Validate MIME type
        $mime_type = $this->validate_mime_type($file_path);
        if (!$mime_type) {
            return false;
        }
        
        // Validate image dimensions
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            return false;
        }
        
        if ($image_info[0] > SSLL_MAX_IMAGE_DIMENSIONS || 
            $image_info[1] > SSLL_MAX_IMAGE_DIMENSIONS ||
            $image_info[0] < SSLL_MIN_IMAGE_DIMENSIONS ||
            $image_info[1] < SSLL_MIN_IMAGE_DIMENSIONS) {
            return false;
        }
        
        return true;
    }

    private function cleanup_temp_files() {
        if (!is_dir($this->temp_dir)) {
            return;
        }
        
        $files = glob($this->temp_dir . '/*');
        if (!is_array($files)) {
            return;
        }
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    
    private function cleanup_old_logs() {
        if (!is_dir($this->log_dir)) {
            return;
        }
        
        $files = glob($this->log_dir . '/security-*.log');
        if (!is_array($files)) {
            return;
        }
        
        $cutoff = strtotime('-30 days');
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    public function init_capability_checks() {
        // Only check capabilities on the plugin's admin page
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($current_page === 'stupid-simple-login-logo') {
            if (!current_user_can('manage_options')) {
                wp_die(
                    esc_html__('You do not have sufficient permissions to access this page.', 'ssll-for-wp'),
                    esc_html__('Access Denied', 'ssll-for-wp'),
                    ['response' => 403]
                );
            }
        }
    }
}