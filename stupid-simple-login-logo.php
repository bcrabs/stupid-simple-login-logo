<?php
/**
 * Plugin Name: Stupid Simple Login Logo
 * Plugin URI: https://github.com/crftddev/stupid-simple-login-logo
 * Description: Allows administrators to change the WordPress login page logo.
 * Version: 1.15.2
 * Requires at least: 5.0
 * Requires PHP: 5.6
 * Author: CRFTD
 * Author URI: https://crftd.dev
 * Update URI: https://github.com/crftddev/stupid-simple-login-logo
 * Text Domain: ssll-for-wp
 * Domain Path: /languages
 * License: GPL v2 or later
 */

namespace SSLL;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

// Define plugin constants
define('SSLL_VERSION', '1.15.2');
define('SSLL_FILE', __FILE__);
define('SSLL_PATH', plugin_dir_path(__FILE__));
define('SSLL_URL', plugin_dir_url(__FILE__));
define('SSLL_BASENAME', plugin_basename(__FILE__));

// Minimum required versions
define('SSLL_MIN_WP', '5.0');
define('SSLL_MIN_PHP', '5.6');

// Security constants
define('SSLL_MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('SSLL_CACHE_GROUP', 'ssll_cache');
define('SSLL_RATE_LIMIT_MAX', 30); // Requests per minute
define('SSLL_NONCE_LIFETIME', DAY_IN_SECONDS);
define('SSLL_CHUNK_SIZE', 1024 * 1024); // 1MB chunks for file operations

// AppSero Client ID
define('SSLL_CLIENT_ID', '98b57974-cbe9-4228-b48b-01683ea5c6d3');
define('SSLL_CLIENT_NAME', 'Stupid Simple Login Logo');

/**
 * Initialize the AppSero client.
 *
 * @return \Appsero\Client|null AppSero client instance or null on failure
 */
function ssll_get_appsero_client() {
    static $client = null;
    
    if (null === $client) {
        $insights_file = SSLL_PATH . 'appsero/src/Insights.php';
        $client_file = SSLL_PATH . 'appsero/src/Client.php';
        
        // Check if files exist
        if (!file_exists($client_file) || !file_exists($insights_file)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SSLL Error: AppSero files not found');
            }
            return null;
        }
        
        try {
            if (!class_exists('Appsero\\Client')) {
                require_once $client_file;
            }
            
            if (!class_exists('Appsero\\Insights')) {
                require_once $insights_file;
            }
            
            $client = new \Appsero\Client(
                SSLL_CLIENT_ID,
                SSLL_CLIENT_NAME,
                SSLL_FILE
            );
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('SSLL AppSero Init Error: ' . $e->getMessage());
            }
            return null;
        }
    }
    
    return $client;
}

/**
 * Initialize AppSero Insights.
 *
 * @return void
 */
function ssll_init_insights() {
    try {
        // Get AppSero client instance
        $client = ssll_get_appsero_client();
        if (!$client) {
            return;
        }

        // Initialize insights
        $insights = $client->insights();
        
        // Add filter to skip sensitive options from tracking
        add_filter('appsero_track_skip_options', function($skip_options) {
            $skip_options[] = 'ssll_login_logo_url';
            $skip_options[] = 'ssll_version';
            return $skip_options;
        });
        
        // Initialize with proper configuration
        $insights->init([
            'collect_email' => false,
            'disable_tracking' => apply_filters('ssll_disable_tracking', false),
            'notice' => true,
            'notice_text' => __('Help improve Stupid Simple Login Logo by sharing non-sensitive usage data.', 'ssll-for-wp'),
            'notice_dismiss_forever' => true
        ]);
        
    } catch (\Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SSLL AppSero Init Error: ' . $e->getMessage());
        }
    }
}

// Initialize AppSero components
if (is_admin()) {
    add_action('plugins_loaded', __NAMESPACE__ . '\\ssll_init_insights', 20);
}

// Autoloader for SSLL namespace
spl_autoload_register(function ($class) {
    static $cache = [];
    
    if (isset($cache[$class])) {
        return $cache[$class];
    }
    
    $prefix = 'SSLL\\';
    $base_dir = SSLL_PATH . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        $cache[$class] = false;
        return false;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    $cache[$class] = file_exists($file);
    if ($cache[$class]) {
        require $file;
    }
    
    return $cache[$class];
});

// Initialize plugin
if (is_admin() || strpos($_SERVER['PHP_SELF'], 'wp-login.php') !== false) {
    require_once SSLL_PATH . 'includes/class-init.php';
    add_action('plugins_loaded', [Init::class, 'get_instance'], 10);
}