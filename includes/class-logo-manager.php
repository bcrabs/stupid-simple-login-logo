<?php
namespace SSLL;

final class Logo_Manager {
    private static $instance = null;
    private $security;
    private $cache_manager;
    private $file_handler;
    private static $css_output = false;
    
    private function __construct() {
        $this->security = Security::get_instance();
        $this->cache_manager = Cache_Manager::get_instance();
        $this->file_handler = File_Handler::get_instance();
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
        if ($this->is_login_page()) {
            add_action('login_enqueue_scripts', [$this, 'modify_login_logo'], 10);
            add_filter('login_headerurl', [$this, 'modify_login_url'], 10);
            add_filter('login_headertext', [$this, 'modify_login_title'], 10);
        }
    }
    
    private function is_login_page() {
        return in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-register.php'], true);
    }
    
    public function setup() {
        if (!$this->security->has_capability('manage_options')) {
            return;
        }
        
        add_option('ssll_version', $this->security->sanitize_option_value(SSLL_VERSION), '', 'yes');
        add_option('ssll_login_logo_url', '', '', 'yes');
    }
    
    public function modify_login_logo() {
        if (self::$css_output) {
            return;
        }
        
        $logo_url = $this->cache_manager->get_logo_url();
        if (empty($logo_url)) {
            return;
        }
        
        if (!$this->security->validate_image_url($logo_url)) {
            $this->cache_manager->clear_logo_cache();
            return;
        }
        
        wp_add_inline_style('login', sprintf(
            '#login h1 a, .login h1 a {
                background-image: url(%s);
                background-size: contain;
                background-repeat: no-repeat;
                background-position: center;
                width: 320px;
                height: 120px;
                padding-bottom: 30px;
                margin: 0 auto;
            }',
            esc_url($logo_url)
        ));
        
        self::$css_output = true;
    }
    
    public function modify_login_url() {
        return esc_url(home_url('/'));
    }
    
    public function modify_login_title() {
        return esc_html(get_bloginfo('name'));
    }
    
    public function update_logo($url) {
        if (!$this->security->verify_user_capability('manage_options')) {
            return new \WP_Error('unauthorized', __('Unauthorized access', 'ssll-for-wp'));
        }
        
        $url = esc_url_raw($url);
        if (empty($url)) {
            return new \WP_Error('invalid_url', __('Invalid URL provided', 'ssll-for-wp'));
        }
        
        if (!$this->security->validate_image_url($url)) {
            return new \WP_Error('invalid_url', __('Invalid image URL or file type', 'ssll-for-wp'));
        }
        
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if ($file_path && filesize($file_path) > SSLL_MAX_FILE_SIZE) {
                return new \WP_Error('file_too_large', __('File size exceeds maximum limit', 'ssll-for-wp'));
            }
        }
        
        $updated = $this->cache_manager->set_logo_url($url);
        if (!$updated) {
            return new \WP_Error('update_failed', __('Failed to update logo', 'ssll-for-wp'));
        }
        
        return true;
    }
    
    public function remove_logo() {
        if (!$this->security->verify_user_capability('manage_options')) {
            return new \WP_Error('unauthorized', __('Unauthorized access', 'ssll-for-wp'));
        }
        
        return $this->cache_manager->set_logo_url('');
    }
    
    public function cleanup() {
        if ($this->security->has_capability('manage_options')) {
            $this->cache_manager->cleanup();
            $this->file_handler->cleanup();
        }
    }
    
    public function uninstall() {
        if (!$this->security->verify_user_capability('manage_options')) {
            return;
        }
        
        delete_option('ssll_version');
        delete_option('ssll_login_logo_url');
        $this->cleanup();
    }
}