<?php
namespace SSLL;

final class Init {
    private static $instance = null;
    private $modules = [];
    private static $required_files = [
        'class-security.php',
        'class-file-handler.php',
        'class-cache-manager.php',
        'class-logo-manager.php',
        'class-admin.php'
    ];
    
    private function __construct() {}
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
    
    private function init() {
        if (!$this->check_requirements()) {
            return;
        }
        
        $this->load_dependencies();
        $this->setup_hooks();
    }
    
    private function check_requirements() {
        global $wp_version;
        
        if (version_compare(PHP_VERSION, SSLL_MIN_PHP, '<') || 
            version_compare($wp_version, SSLL_MIN_WP, '<')) {
            add_action('admin_notices', [$this, 'show_requirement_errors']);
            return false;
        }
        
        return true;
    }
    
    private function load_dependencies() {
        foreach (self::$required_files as $file) {
            if (file_exists(SSLL_PATH . 'includes/' . $file)) {
                require_once SSLL_PATH . 'includes/' . $file;
            }
        }
    }
    
    private function init_modules() {
        if (!is_admin()) {
            return;
        }

        try {
            $this->modules = [
                'security' => Security::get_instance(),
                'file_handler' => File_Handler::get_instance(),
                'cache_manager' => Cache_Manager::get_instance(),
                'logo_manager' => Logo_Manager::get_instance(),
                'admin' => Admin::get_instance()
            ];
            
            foreach ($this->modules as $module) {
                if (method_exists($module, 'init')) {
                    $module->init();
                }
            }
            
        } catch (\Exception $e) {
            error_log('SSLL Init Error: ' . $e->getMessage());
            add_action('admin_notices', function() use ($e) {
                printf(
                    '<div class="error"><p>%s</p></div>',
                    esc_html__('Stupid Simple Login Logo initialization error: ', 'ssll-for-wp') . 
                    esc_html($e->getMessage())
                );
            });
        }
    }
    
    private function setup_hooks() {
        register_activation_hook(SSLL_FILE, [$this, 'activate']);
        register_deactivation_hook(SSLL_FILE, [$this, 'deactivate']);
        
        // Only load textdomain and initialize modules in admin
        if (is_admin()) {
            add_action('admin_init', [$this, 'init_modules'], 0);
            add_action('init', [$this, 'load_textdomain'], 0);
        }
        
        if (function_exists('register_uninstall_hook')) {
            register_uninstall_hook(SSLL_FILE, [__CLASS__, 'uninstall']);
        }
    }
    
    public function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        try {
            $this->init_modules();
            
            foreach ($this->modules as $module) {
                if (method_exists($module, 'setup')) {
                    $module->setup();
                }
            }
            
            flush_rewrite_rules();
            
        } catch (\Exception $e) {
            deactivate_plugins(SSLL_BASENAME);
            wp_die(
                esc_html($e->getMessage()),
                esc_html__('Plugin Activation Error', 'ssll-for-wp'),
                ['response' => 500, 'back_link' => true]
            );
        }
    }
    
    public function deactivate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        foreach ($this->modules as $module) {
            if (method_exists($module, 'cleanup')) {
                $module->cleanup();
            }
        }
        
        flush_rewrite_rules();
    }
    
    public static function uninstall() {
        if (!current_user_can('activate_plugins') || !defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
        
        $instance = self::get_instance();
        foreach ($instance->modules as $module) {
            if (method_exists($module, 'uninstall')) {
                $module->uninstall();
            }
        }
    }
    
    public function load_textdomain() {
        static $loaded = false;
        
        if (!$loaded) {
            load_plugin_textdomain(
                'ssll-for-wp',
                false,
                dirname(SSLL_BASENAME) . '/languages'
            );
            $loaded = true;
        }
    }
    
    public function show_requirement_errors() {
        global $wp_version;
        
        $errors = [];
        
        if (version_compare(PHP_VERSION, SSLL_MIN_PHP, '<')) {
            $errors[] = sprintf(
                esc_html__('Stupid Simple Login Logo requires PHP version %s or higher.', 'ssll-for-wp'),
                SSLL_MIN_PHP
            );
        }
        
        if (version_compare($wp_version, SSLL_MIN_WP, '<')) {
            $errors[] = sprintf(
                esc_html__('Stupid Simple Login Logo requires WordPress version %s or higher.', 'ssll-for-wp'),
                SSLL_MIN_WP
            );
        }
        
        if (!empty($errors)) {
            printf(
                '<div class="error"><p>%s</p></div>',
                implode('</p><p>', array_map('esc_html', $errors))
            );
        }
    }
}