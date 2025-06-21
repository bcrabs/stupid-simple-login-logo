<?php
/**
 * Plugin Name: Stupid Simple Login Logo
 * Plugin URI: https://wordpress.org/plugins/stupid-simple-login-logo
 * Description: Allows administrators to change the WordPress login page logo.
 * Version: 1.16.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: CRFTD
 * Author URI: https://crftd.dev
 * Text Domain: ssll-for-wp
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Stupid_Simple_Login_Logo
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

/**
 * Define plugin constants
 */
define('SSLL_VERSION', '1.16.1');
define('SSLL_FILE', __FILE__);
define('SSLL_PATH', plugin_dir_path(__FILE__));
define('SSLL_URL', plugin_dir_url(__FILE__));

/**
 * Minimum required versions
 */
define('SSLL_MIN_WP', '5.8');
define('SSLL_MIN_PHP', '7.4');

/**
 * Security constants
 */
define('SSLL_MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('SSLL_ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif']);

/**
 * Add menu item to WordPress admin
 */
add_action('admin_menu', function() {
    add_options_page(
        __('Login Logo Settings', 'ssll-for-wp'),
        __('Login Logo', 'ssll-for-wp'),
        'manage_options',
        'stupid-simple-login-logo',
        'ssll_render_settings_page'
    );
});

/**
 * Enqueue admin scripts and styles
 *
 * @param string $hook Current admin page
 */
add_action('admin_enqueue_scripts', function($hook) {
    if ('settings_page_stupid-simple-login-logo' !== $hook) {
        return;
    }

    wp_enqueue_media();
    wp_enqueue_script(
        'ssll-media-upload',
        SSLL_URL . 'js/media-upload.js',
        ['jquery', 'media-upload'],
        SSLL_VERSION,
        true
    );

    wp_localize_script(
        'ssll-media-upload',
        'ssllData',
        [
            'nonce' => wp_create_nonce('ssll_remove_logo'),
            'frame_title' => __('Select Login Logo', 'ssll-for-wp'),
            'frame_button' => __('Use as Login Logo', 'ssll-for-wp'),
            'allowedTypes' => SSLL_ALLOWED_IMAGE_TYPES,
            'maxFileSize' => SSLL_MAX_FILE_SIZE,
            'adminPostUrl' => admin_url('admin-post.php'),
            'translations' => [
                'invalidType' => __('Please select a JPEG or PNG image.', 'ssll-for-wp'),
                'fileTooBig' => __('File size must not exceed 5MB.', 'ssll-for-wp'),
                'removeConfirm' => __('Are you sure you want to remove the custom logo?', 'ssll-for-wp'),
                'selectLogo' => __('Select Logo', 'ssll-for-wp'),
                'changeLogo' => __('Change Logo', 'ssll-for-wp'),
                'removeLogo' => __('Remove Logo', 'ssll-for-wp'),
                'genericError' => __('An error occurred. Please try again.', 'ssll-for-wp')
            ]
        ]
    );
});

/**
 * Handle logo updates
 * Processes the form submission to save the logo URL
 */
add_action('admin_post_ssll_save_logo', function() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ssll-for-wp'));
    }

    check_admin_referer('ssll_save_logo', 'nonce');

    $logo_url = isset($_POST['logo_url']) ? esc_url_raw($_POST['logo_url']) : '';
    if (empty($logo_url)) {
        wp_redirect(add_query_arg(
            ['page' => 'stupid-simple-login-logo', 'error' => 'no_logo'],
            admin_url('options-general.php')
        ));
        exit;
    }

    update_option('ssll_logo_url', $logo_url);
    wp_redirect(add_query_arg(
        ['page' => 'stupid-simple-login-logo', 'updated' => 'true'],
        admin_url('options-general.php')
    ));
    exit;
});

/**
 * Handle logo removal
 * Processes the form submission to remove the logo
 */
add_action('admin_post_ssll_remove_logo', function() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ssll-for-wp'));
    }

    check_admin_referer('ssll_remove_logo', 'nonce');
    delete_option('ssll_logo_url');

    wp_redirect(add_query_arg(
        ['page' => 'stupid-simple-login-logo', 'removed' => 'true'],
        admin_url('options-general.php')
    ));
    exit;
});

/**
 * Render the settings page
 * Displays the admin interface for managing the login logo
 */
function ssll_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ssll-for-wp'));
    }

    $logo_url = get_option('ssll_logo_url', '');
    
    // Add success/error messages
    if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
        add_settings_error(
            'ssll_messages',
            'ssll_message',
            __('Logo settings saved.', 'ssll-for-wp'),
            'updated'
        );
    } elseif (isset($_GET['removed']) && $_GET['removed'] === 'true') {
        add_settings_error(
            'ssll_messages',
            'ssll_message',
            __('Logo has been removed.', 'ssll-for-wp'),
            'updated'
        );
    } elseif (isset($_GET['error']) && $_GET['error'] === 'no_logo') {
        add_settings_error(
            'ssll_messages',
            'ssll_message',
            __('Please select a logo before saving.', 'ssll-for-wp'),
            'error'
        );
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <?php settings_errors('ssll_messages'); ?>
        
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ssll_save_logo">
            <?php wp_nonce_field('ssll_save_logo', 'nonce'); ?>
            <input type="hidden" name="logo_url" id="logo_url" value="<?php echo esc_attr($logo_url); ?>">
            
            <div style="margin: 2em 0;">
                <?php if (!empty($logo_url)) : ?>
                    <img src="<?php echo esc_url($logo_url); ?>" 
                         id="logo_preview"
                         alt="<?php esc_attr_e('Current login logo', 'ssll-for-wp'); ?>"
                         style="max-width: 320px; height: auto;">
                <?php endif; ?>
            </div>
            
            <p>
                <button type="button" class="button" id="upload_logo_button">
                    <?php echo empty($logo_url) ? esc_html__('Select Logo', 'ssll-for-wp') : esc_html__('Change Logo', 'ssll-for-wp'); ?>
                </button>
                
                <?php if (!empty($logo_url)) : ?>
                    <button type="button" class="button" id="remove_logo_button">
                        <?php esc_html_e('Remove Logo', 'ssll-for-wp'); ?>
                    </button>
                <?php endif; ?>
            </p>
            
            <p class="description">
                <?php esc_html_e('Choose an image from your Media Library to use as the login page logo. Accepted file types: JPEG, PNG, GIF, WebP, and AVIF.', 'ssll-for-wp'); ?>
            </p>
            
            <?php submit_button(__('Save Changes', 'ssll-for-wp')); ?>
            
            <?php if (!empty($logo_url)) : ?>
                <p class="description" style="margin-top: 1em;">
                    <a href="<?php echo esc_url(wp_login_url()); ?>" target="_blank" class="button">
                        <?php esc_html_e('Preview Login Page ↗', 'ssll-for-wp'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </form>
    </div>
    <?php
}

/**
 * Add custom login logo
 * Applies the custom logo to the WordPress login page
 */
add_action('login_enqueue_scripts', function() {
    $logo_url = get_option('ssll_logo_url', '');
    if (empty($logo_url)) {
        return;
    }
    ?>
    <style type="text/css">
        .login h1 a {
            background-image: url(<?php echo esc_url($logo_url); ?>) !important;
            background-size: contain !important;
            width: 320px !important;
            height: 120px !important;
            background-position: center !important;
            background-repeat: no-repeat !important;
        }
    </style>
    <?php
});

/**
 * Update login logo URL
 * Changes the logo link to point to the site's home page
 *
 * @return string Home URL
 */
add_filter('login_headerurl', function() {
    return home_url();
});

/**
 * Update login logo title
 * Changes the logo title to the site's name
 *
 * @return string Site name
 */
add_filter('login_headertext', function() {
    return get_bloginfo('name');
});