<?php
namespace SSLL;

final class Cache_Manager {
    private static $instance = null;
    private $security;
    private static $cache_keys = [];
    
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
    
    private function get_cache_key($base) {
        if (!isset(self::$cache_keys[$base])) {
            $salt = wp_hash(random_bytes(16));
            self::$cache_keys[$base] = sprintf(
                '%s_%s_%s',
                $base,
                wp_hash(get_current_blog_id()),
                substr($salt, 0, 8)
            );
        }
        return self::$cache_keys[$base];
    }
    
    public function get_logo_url() {
        if (!is_user_logged_in() && !in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-register.php'], true)) {
            return '';
        }
        
        $cache_key = $this->get_cache_key('ssll_logo_url');
        
        $cached_url = wp_cache_get($cache_key, SSLL_CACHE_GROUP);
        if (false !== $cached_url) {
            if (empty($cached_url) || $this->security->validate_image_url($cached_url)) {
                return $cached_url;
            }
            $this->clear_logo_cache();
        }
        
        $logo_url = get_option('ssll_login_logo_url', '');
        if (!empty($logo_url)) {
            $logo_url = $this->security->sanitize_image_url($logo_url);
            if ($this->security->validate_image_url($logo_url)) {
                wp_cache_set($cache_key, $logo_url, SSLL_CACHE_GROUP, HOUR_IN_SECONDS);
                return $logo_url;
            }
            delete_option('ssll_login_logo_url');
        }
        
        wp_cache_set($cache_key, '', SSLL_CACHE_GROUP, HOUR_IN_SECONDS);
        return '';
    }
    
    public function set_logo_url($url) {
        if (!$this->security->verify_user_capability('manage_options')) {
            return false;
        }
        
        $this->clear_logo_cache();
        
        if (empty($url)) {
            delete_option('ssll_login_logo_url');
            return true;
        }
        
        $url = $this->security->sanitize_image_url($url);
        if (!$this->security->validate_image_url($url)) {
            return false;
        }
        
        $updated = update_option('ssll_login_logo_url', $url, 'yes');
        if ($updated) {
            $cache_key = $this->get_cache_key('ssll_logo_url');
            wp_cache_set($cache_key, $url, SSLL_CACHE_GROUP, HOUR_IN_SECONDS);
        }
        
        return $updated;
    }
    
    public function clear_logo_cache() {
        $cache_key = $this->get_cache_key('ssll_logo_url');
        wp_cache_delete($cache_key, SSLL_CACHE_GROUP);
    }
    
    public function cleanup() {
        if (!$this->security->verify_user_capability('manage_options')) {
            return;
        }
        
        array_walk(self::$cache_keys, function($key) {
            wp_cache_delete($key, SSLL_CACHE_GROUP);
        });
        
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            OR option_name LIKE %s",
            $wpdb->esc_like('_transient_ssll_') . '%',
            $wpdb->esc_like('_transient_timeout_ssll_') . '%'
        ));
    }
    
    public function uninstall() {
        if ($this->security->verify_user_capability('manage_options')) {
            $this->cleanup();
        }
    }
}