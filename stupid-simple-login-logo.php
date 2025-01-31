<?php
/**
 * Plugin Name: Stupid Simple Login Logo
 * Plugin URI: https://github.com/crftddev/stupid-simple-login-logo
 * Description: Allows administrators to change the WordPress login page logo.
 * Version: 1.14.1
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

// Prevent direct access with exit() for better performance than die()
if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

// Define plugin constants using define() for better performance than const
define('SSLL_VERSION', '1.0.0');
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

/**
 * Initialize the AppSero tracker
 */
function init_appsero() {
    if (!class_exists('Appsero\Client')) {
        require_once SSLL_PATH . 'appsero/src/Client.php';
    }

    $client = new \Appsero\Client(
        '98b57974-cbe9-4228-b48b-01683ea5c6d3',
        'Stupid Simple Login Logo',
        SSLL_FILE
    );

    // Initialize insights with basic data collection
    $insights = $client->insights();
    
    // Add custom hooks before initialization
    add_filter('appsero_track_skip_options', function($skip_options) {
        $skip_options[] = 'ssll_login_logo_url';  // Exclude logo URL from tracking
        return $skip_options;
    });
    
    // Initialize with privacy-focused settings
    $insights->init([
        'collect_email' => false,
        'disable_tracking' => false,
        'notice' => true,
        'notice_text' => __('Help improve Stupid Simple Login Logo by sharing non-sensitive usage data.', 'ssll-for-wp')
    ]);
}

// Initialize AppSero after plugins are loaded
add_action('plugins_loaded', 'SSLL\init_appsero', 20);

// Optimized autoloader with static cache
spl_autoload_register(function ($class) {
    static $cache = [];
    
    // Return early if class is cached
    if (isset($cache[$class])) {
        return $cache[$class];
    }
    
    // Project-specific namespace prefix
    $prefix = 'SSLL\\';
    $base_dir = SSLL_PATH . 'includes/';
    
    // Return early if not our namespace
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        $cache[$class] = false;
        return false;
    }
    
    // Get the relative class name
    $relative_class = substr($class, $len);
    
    // Replace namespace separators with directory separators
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    // Cache and return result
    $cache[$class] = file_exists($file);
    if ($cache[$class]) {
        require $file;
    }
    
    return $cache[$class];
});

// Performance optimization: Only load plugin on admin or login pages
if (is_admin() || strpos($_SERVER['PHP_SELF'], 'wp-login.php') !== false) {
    require_once SSLL_PATH . 'includes/class-init.php';
    add_action('plugins_loaded', array('SSLL\\Init', 'get_instance'), 10);
}
