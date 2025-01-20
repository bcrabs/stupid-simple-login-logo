<?php
namespace SSLL;

/**
 * Main initialization class for the plugin
 */
final class Init {
    private static $instance = null;
    private $modules = [];
    private static $required_files = [
        'class-security.php',
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
            self::$instance->init();
        }
        return self::$instance;
    }
    
    private function init() {
        if (!$this->check_requirements()) {
            return;
        }
        
        $this->load_dependencies();
        $this->init_modules();
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
            require_once SSLL_PATH . 'includes/' . $file;
        }
    }
    
    private function init_modules() {
        try {
            $this->modules = [
                'security' => Security::get_instance(),
                'cache_manager' => Cache_Manager::get_instance(),
                'logo_manager' => Logo_Manager::get_instance(),
                'admin' => Admin::get_instance()
            ];
            
            add_action('init', [$this, 'init_module_functionality'], 0);
            
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
    
    public function init_module_functionality() {
        array_walk($this->modules, function($module) {
            if (method_exists($module, 'init')) {
                $module->init();
            }
        });
    }
    
    private function setup_hooks() {
        register_activation_hook(SSLL_FILE, [$this, 'activate']);
        register_deactivation_hook(SSLL_FILE, [$this, 'deactivate']);
        
        if (!is_admin()) {
            return;
        }
        
        add_action('init', [$this, 'load_textdomain'], 0);
        
        if (function_exists('register_uninstall_hook')) {
            register_uninstall_hook(SSLL_FILE, [__CLASS__, 'uninstall']);
        }
    }
    
    public function show_requirement_errors() {
        global $wp_version;
        
        $errors = [];
        
        if (version_compare(PHP_VERSION, SSLL_MIN_PHP, '<')) {
            $errors[] = sprintf(
                /* translators: %s: Minimum PHP version */
                esc_html__('Stupid Simple Login Logo requires PHP version %s or higher.', 'ssll-for-wp'),
                SSLL_MIN_PHP
            );
        }
        
        if (version_compare($wp_version, SSLL_MIN_WP, '<')) {
            $errors[] = sprintf(
                /* translators: %s: Minimum WordPress version */
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
    
    public function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        try {
            if (isset($this->modules['logo_manager'])) {
                $this->modules['logo_manager']->setup();
            }
            
            if (isset($this->modules['security'])) {
                $this->modules['security']->init_security();
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
        
        array_walk($this->modules, function($module) {
            if (method_exists($module, 'cleanup')) {
                $module->cleanup();
            }
        });
        
        flush_rewrite_rules();
    }
    
    public static function uninstall() {
        if (!current_user_can('activate_plugins') || !defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
        
        $instance = self::get_instance();
        array_walk($instance->modules, function($module) {
            if (method_exists($module, 'uninstall')) {
                $module->uninstall();
            }
        });
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
}