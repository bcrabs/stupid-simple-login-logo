<?php
namespace SSLL;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Manages logo operations
 */
final class Logo_Manager {
    private static $instance = null;
    private $security;
    private $file_handler;
    private $cache_manager;
    private static $SSLL_css_output = false;

    private function __construct() {
        $this->security = Security::get_instance();
        $this->file_handler = File_Handler::get_instance();
        $this->cache_manager = Cache_Manager::get_instance();
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
        add_action('admin_init', [$this, 'handle_logo_upload']);
        add_action('wp_ajax_ssll_remove_logo', [$this, 'handle_logo_removal']);
        add_action('wp_ajax_ssll_update_logo_settings', [$this, 'handle_settings_update']);
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

        add_option('ssll_version', $this->security->sanitize_option_value(SSLL_VERSION), '', SSLL_CACHE_GROUP);
        add_option('ssll_login_logo_url', '', '', SSLL_CACHE_GROUP);
    }

    public function modify_login_logo() {
        if (self::$SSLL_css_output) {
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

        self::$SSLL_css_output = true;
    }

    public function modify_login_url() {
        return esc_url(home_url('/'));
    }

    public function modify_login_title() {
        return esc_html(get_bloginfo('name'));
    }

    public function handle_logo_upload() {
        if (!isset($_POST['ssll_upload_nonce']) || 
            !wp_verify_nonce($_POST['ssll_upload_nonce'], 'ssll_upload_logo')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access', 'ssll-for-wp'));
        }
        
        try {
            if (!isset($_FILES['ssll_logo']) || $_FILES['ssll_logo']['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception(__('No file uploaded or upload error', 'ssll-for-wp'));
            }
            
            $file = $_FILES['ssll_logo'];
            $this->validate_upload($file);
            
            $upload_dir = wp_upload_dir();
            $temp_file = $upload_dir['basedir'] . '/ssll-temp-' . uniqid();
            
            if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
                throw new \Exception(__('Error saving file', 'ssll-for-wp'));
            }
            
            $this->process_logo($temp_file);
            
            wp_redirect(add_query_arg('ssll_message', 'logo_updated', wp_get_referer()));
            exit;
            
        } catch (\Exception $e) {
            wp_die(
                esc_html($e->getMessage()),
                esc_html__('Error', 'ssll-for-wp'),
                ['response' => 400]
            );
        }
    }
    
    private function validate_upload($file) {
        // Check file size
        if ($file['size'] > SSLL_MAX_FILE_SIZE) {
            throw new \Exception(
                sprintf(
                    /* translators: %s: Maximum file size */
                    __('File size exceeds maximum allowed size of %s', 'ssll-for-wp'),
                    size_format(SSLL_MAX_FILE_SIZE)
                )
            );
        }
        
        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            throw new \Exception(__('Invalid file type. Only JPEG, PNG and GIF are allowed.', 'ssll-for-wp'));
        }
        
        // Validate image
        $image = wp_get_image_editor($file['tmp_name']);
        if (is_wp_error($image)) {
            throw new \Exception(__('Invalid image file', 'ssll-for-wp'));
        }
        
        // Check dimensions
        $size = $image->get_size();
        if ($size['width'] > SSLL_MAX_IMAGE_WIDTH || $size['height'] > SSLL_MAX_IMAGE_HEIGHT) {
            throw new \Exception(
                sprintf(
                    /* translators: %1$s: Maximum width, %2$s: Maximum height */
                    __('Image dimensions exceed maximum allowed size of %1$s x %2$s pixels', 'ssll-for-wp'),
                    SSLL_MAX_IMAGE_WIDTH,
                    SSLL_MAX_IMAGE_HEIGHT
                )
            );
        }
    }
    
    private function process_logo($temp_file) {
        try {
            $image = wp_get_image_editor($temp_file);
            if (is_wp_error($image)) {
                throw new \Exception(__('Error processing image', 'ssll-for-wp'));
            }
            
            // Resize if needed
            $size = $image->get_size();
            if ($size['width'] > SSLL_DEFAULT_WIDTH || $size['height'] > SSLL_DEFAULT_HEIGHT) {
                $image->resize(SSLL_DEFAULT_WIDTH, SSLL_DEFAULT_HEIGHT, false);
            }
            
            // Save to uploads directory
            $upload_dir = wp_upload_dir();
            $filename = 'ssll-logo-' . uniqid() . '.png';
            $file_path = $upload_dir['path'] . '/' . $filename;
            
            $result = $image->save($file_path, 'image/png');
            if (is_wp_error($result)) {
                throw new \Exception(__('Error saving processed image', 'ssll-for-wp'));
            }
            
            // Update settings
            $settings = get_option('ssll_settings', []);
            $settings['logo_url'] = $upload_dir['url'] . '/' . $filename;
            update_option('ssll_settings', $settings);
            
            // Clear cache
            $this->cache_manager->clear_cache();
            
        } finally {
            // Clean up temp file
            if (file_exists($temp_file)) {
                unlink($temp_file);
            }
        }
    }
    
    public function handle_logo_removal() {
        check_ajax_referer('ssll_remove_logo', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized access', 'ssll-for-wp'));
        }
        
        try {
            $settings = get_option('ssll_settings', []);
            if (empty($settings['logo_url'])) {
                throw new \Exception(__('No logo to remove', 'ssll-for-wp'));
            }
            
            $file_path = $this->security->sanitize_file_path($settings['logo_url']);
            if ($file_path && file_exists($file_path)) {
                unlink($file_path);
            }
            
            unset($settings['logo_url']);
            update_option('ssll_settings', $settings);
            
            $this->cache_manager->clear_cache();
            
            wp_send_json_success(__('Logo removed successfully', 'ssll-for-wp'));
            
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function handle_settings_update() {
        check_ajax_referer('ssll_update_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized access', 'ssll-for-wp'));
        }
        
        try {
            $settings = get_option('ssll_settings', []);
            
            // Validate and sanitize CSS values
            $css_settings = [
                'width' => isset($_POST['width']) ? absint($_POST['width']) : SSLL_DEFAULT_WIDTH,
                'height' => isset($_POST['height']) ? absint($_POST['height']) : SSLL_DEFAULT_HEIGHT,
                'padding' => isset($_POST['padding']) ? absint($_POST['padding']) : SSLL_DEFAULT_PADDING,
                'background' => isset($_POST['background']) ? sanitize_hex_color($_POST['background']) : SSLL_DEFAULT_BACKGROUND
            ];
            
            // Validate dimensions
            if ($css_settings['width'] > SSLL_MAX_IMAGE_WIDTH || 
                $css_settings['height'] > SSLL_MAX_IMAGE_HEIGHT) {
                throw new \Exception(__('Invalid dimensions', 'ssll-for-wp'));
            }
            
            $settings = array_merge($settings, $css_settings);
            update_option('ssll_settings', $settings);
            
            $this->cache_manager->clear_cache();
            
            wp_send_json_success(__('Settings updated successfully', 'ssll-for-wp'));
            
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function get_logo_css() {
        $settings = get_option('ssll_settings', []);
        
        $width = isset($settings['width']) ? absint($settings['width']) : SSLL_DEFAULT_WIDTH;
        $height = isset($settings['height']) ? absint($settings['height']) : SSLL_DEFAULT_HEIGHT;
        $padding = isset($settings['padding']) ? absint($settings['padding']) : SSLL_DEFAULT_PADDING;
        $background = isset($settings['background']) ? esc_attr($settings['background']) : SSLL_DEFAULT_BACKGROUND;
        
        return sprintf(
            'body.login div#login h1 a {
                background-image: url("%s");
                background-size: %dpx %dpx;
                width: %dpx;
                height: %dpx;
                padding: %dpx;
                background-color: %s;
            }',
            esc_url($settings['logo_url'] ?? ''),
            $width,
            $height,
            $width,
            $height,
            $padding,
            $background
        );
    }

    public function cleanup() {
        if ($this->security->has_capability('manage_options')) {
            $this->cache_manager->cleanup();
            $this->file_handler->cleanup();
        }
    }

    public function uninstall() {
        $settings = get_option('ssll_settings', []);
        
        if (!empty($settings['logo_url'])) {
            $file_path = $this->security->sanitize_file_path($settings['logo_url']);
            if ($file_path && file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        delete_option('ssll_settings');
        $this->cache_manager->clear_cache();
    }
}