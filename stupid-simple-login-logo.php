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