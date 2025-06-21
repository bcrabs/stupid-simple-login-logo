<?php
namespace SSLL;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

final class Security_Headers {
    private static $instance = null;
    private $security;
    
    private function __construct() {
        $this->security = Security::get_instance();
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
        add_action('send_headers', [$this, 'add_security_headers'], 1);
        add_action('wp_head', [$this, 'add_meta_security_headers'], 1);
    }
    
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
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        
        // Control referrer information
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Prevent browser features
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        
        // Add Content Security Policy
        $this->add_csp_header();
        
        // Add Feature Policy
        $this->add_feature_policy_header();
    }
    
    private function add_csp_header() {
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "media-src 'self'",
            "worker-src 'self'",
            "frame-src 'none'",
            "manifest-src 'self'"
        ];
        
        header("Content-Security-Policy: " . implode('; ', $csp));
    }
    
    private function add_feature_policy_header() {
        $features = [
            'accelerometer' => 'none',
            'camera' => 'none',
            'geolocation' => 'none',
            'gyroscope' => 'none',
            'magnetometer' => 'none',
            'microphone' => 'none',
            'payment' => 'none',
            'usb' => 'none'
        ];
        
        $policies = [];
        foreach ($features as $feature => $value) {
            $policies[] = $feature . '=(' . $value . ')';
        }
        
        header('Feature-Policy: ' . implode(', ', $policies));
    }
    
    public function add_meta_security_headers() {
        // Add security meta tags
        echo '<meta http-equiv="X-Content-Type-Options" content="nosniff">' . "\n";
        echo '<meta http-equiv="X-Frame-Options" content="DENY">' . "\n";
        echo '<meta http-equiv="X-XSS-Protection" content="1; mode=block">' . "\n";
        echo '<meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">' . "\n";
    }
    
    public function add_custom_headers($headers = []) {
        if (headers_sent()) {
            return;
        }
        
        foreach ($headers as $header => $value) {
            header($header . ': ' . $value);
        }
    }
} 