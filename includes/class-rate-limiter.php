<?php
namespace SSLL;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

final class Rate_Limiter {
    private static $instance = null;
    private $security;
    private $redis;
    private $use_redis;
    
    private function __construct() {
        $this->security = Security::get_instance();
        $this->use_redis = $this->init_redis();
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
    
    private function init_redis() {
        if (class_exists('Redis') && defined('SSLL_REDIS_HOST')) {
            try {
                $this->redis = new \Redis();
                $this->redis->connect(SSLL_REDIS_HOST, SSLL_REDIS_PORT);
                if (defined('SSLL_REDIS_PASSWORD')) {
                    $this->redis->auth(SSLL_REDIS_PASSWORD);
                }
                return true;
            } catch (\Exception $e) {
                $this->security->log_security_event('Redis connection failed', [
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        }
        return false;
    }
    
    public function check_rate_limit($action, $identifier = null) {
        if (!$identifier) {
            $identifier = $this->get_identifier();
        }
        
        $key = $this->get_rate_key($action, $identifier);
        $limit = $this->get_rate_limit($action);
        $window = $this->get_time_window($action);
        
        if ($this->use_redis) {
            return $this->check_redis_rate_limit($key, $limit, $window);
        }
        
        return $this->check_transient_rate_limit($key, $limit, $window);
    }
    
    private function check_redis_rate_limit($key, $limit, $window) {
        try {
            $current = $this->redis->get($key);
            
            if (!$current) {
                $this->redis->setex($key, $window, 1);
                return true;
            }
            
            if ($current >= $limit) {
                $this->security->log_security_event('Rate limit exceeded (Redis)', [
                    'key' => $key,
                    'limit' => $limit,
                    'window' => $window
                ]);
                return false;
            }
            
            $this->redis->incr($key);
            return true;
            
        } catch (\Exception $e) {
            $this->security->log_security_event('Redis rate limit error', [
                'error' => $e->getMessage()
            ]);
            return $this->check_transient_rate_limit($key, $limit, $window);
        }
    }
    
    private function check_transient_rate_limit($key, $limit, $window) {
        $data = get_transient($key);
        $current_time = time();
        
        if (false === $data) {
            set_transient($key, [
                'count' => 1,
                'timestamp' => $current_time
            ], $window);
            return true;
        }
        
        if (($current_time - $data['timestamp']) > $window) {
            set_transient($key, [
                'count' => 1,
                'timestamp' => $current_time
            ], $window);
            return true;
        }
        
        if ($data['count'] >= $limit) {
            $this->security->log_security_event('Rate limit exceeded (Transient)', [
                'key' => $key,
                'limit' => $limit,
                'window' => $window
            ]);
            return false;
        }
        
        $data['count']++;
        set_transient($key, $data, $window);
        
        return true;
    }
    
    private function get_identifier() {
        $ip = $this->security->get_client_ip();
        $user_id = get_current_user_id();
        $session_token = $this->security->get_session_token();
        
        return wp_hash($ip . $user_id . $session_token);
    }
    
    private function get_rate_key($action, $identifier) {
        return 'ssll_rate_' . wp_hash($action . $identifier);
    }
    
    private function get_rate_limit($action) {
        $limits = [
            'ssll_save_logo' => 10,
            'ssll_remove_logo' => 5,
            'ssll_update_settings' => 20,
            'default' => 60
        ];
        
        return isset($limits[$action]) ? $limits[$action] : $limits['default'];
    }
    
    private function get_time_window($action) {
        $windows = [
            'ssll_save_logo' => MINUTE_IN_SECONDS,
            'ssll_remove_logo' => MINUTE_IN_SECONDS,
            'ssll_update_settings' => MINUTE_IN_SECONDS,
            'default' => MINUTE_IN_SECONDS
        ];
        
        return isset($windows[$action]) ? $windows[$action] : $windows['default'];
    }
    
    public function cleanup() {
        if ($this->use_redis) {
            try {
                $keys = $this->redis->keys('ssll_rate_*');
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            } catch (\Exception $e) {
                $this->security->log_security_event('Redis cleanup failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_ssll_rate_') . '%'
        ));
    }
} 