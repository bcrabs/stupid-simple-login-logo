<?php
/**
 * Plugin Name: Stupid Simple Login Logo
 * Plugin URI: https://wordpress.org/plugins/stupid-simple-login-logo
 * Description: Allows administrators to change the WordPress login page logo.
 * Version: 1.16.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.8
 * Author: CRFTD
 * Author URI: https://crftd.dev
 * Text Domain: stupid-simple-login-logo
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
        esc_html__('Login Logo Settings', 'stupid-simple-login-logo'),
        esc_html__('Login Logo', 'stupid-simple-login-logo'),
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
            'frame_title' => esc_html__('Select Login Logo', 'stupid-simple-login-logo'),
            'frame_button' => esc_html__('Use as Login Logo', 'stupid-simple-login-logo'),
            'allowedTypes' => SSLL_ALLOWED_IMAGE_TYPES,
            'maxFileSize' => SSLL_MAX_FILE_SIZE,
            'adminPostUrl' => admin_url('admin-post.php'),
            'translations' => [
                'invalidType' => esc_html__('Please select a JPEG or PNG image.', 'stupid-simple-login-logo'),
                'fileTooBig' => esc_html__('File size must not exceed 5MB.', 'stupid-simple-login-logo'),
                'removeConfirm' => esc_html__('Are you sure you want to remove the custom logo?', 'stupid-simple-login-logo'),
                'selectLogo' => esc_html__('Select Logo', 'stupid-simple-login-logo'),
                'changeLogo' => esc_html__('Change Logo', 'stupid-simple-login-logo'),
                'removeLogo' => esc_html__('Remove Logo', 'stupid-simple-login-logo'),
                'genericError' => esc_html__('An error occurred. Please try again.', 'stupid-simple-login-logo')
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
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'stupid-simple-login-logo'));
    }

    // Verify nonce
    check_admin_referer('ssll_save_logo', 'nonce');

    $logo_url = isset($_POST['logo_url']) ? sanitize_text_field(wp_unslash($_POST['logo_url'])) : '';
    $logo_url = esc_url_raw($logo_url);
    
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
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'stupid-simple-login-logo'));
    }

    // Verify nonce
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
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'stupid-simple-login-logo'));
    }

    $logo_url = get_option('ssll_logo_url', '');
    
    // Add success/error messages
    if (isset($_GET['updated']) && sanitize_text_field(wp_unslash($_GET['updated'])) === 'true') {
        add_settings_error(
            'ssll_messages',
            'ssll_message',
            esc_html__('Logo settings saved.', 'stupid-simple-login-logo'),
            'updated'
        );
    } elseif (isset($_GET['removed']) && sanitize_text_field(wp_unslash($_GET['removed'])) === 'true') {
        add_settings_error(
            'ssll_messages',
            'ssll_message',
            esc_html__('Logo has been removed.', 'stupid-simple-login-logo'),
            'updated'
        );
    } elseif (isset($_GET['error']) && sanitize_text_field(wp_unslash($_GET['error'])) === 'no_logo') {
        add_settings_error(
            'ssll_messages',
            'ssll_message',
            esc_html__('Please select a logo before saving.', 'stupid-simple-login-logo'),
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
                    <?php
                    // Get attachment ID from URL and use wp_get_attachment_image for security.
                    $attachment_id = attachment_url_to_postid($logo_url);
                    if ($attachment_id) {
                        echo wp_get_attachment_image($attachment_id, 'medium', false, [
                            'id' => 'logo_preview',
                            'style' => 'max-width: 320px; height: auto;',
                            'alt' => esc_attr__('Current login logo', 'stupid-simple-login-logo')
                        ]);
                    } else {
                        // Fallback for non-media library images (e.g. external URLs)
                        // Using esc_url() for security and proper escaping
                        $escaped_logo_url = esc_url($logo_url);
                        if (!empty($escaped_logo_url)) {
                            ?>
                            <img src="<?php echo esc_url($escaped_logo_url); ?>" 
                                 id="logo_preview"
                                 alt="<?php esc_attr_e('Current login logo', 'stupid-simple-login-logo'); ?>"
                                 style="max-width: 320px; height: auto;">
                            <?php
                        }
                    }
                    ?>
                <?php endif; ?>
            </div>
            
            <p>
                <button type="button" class="button" id="upload_logo_button">
                    <?php echo empty($logo_url) ? esc_html__('Select Logo', 'stupid-simple-login-logo') : esc_html__('Change Logo', 'stupid-simple-login-logo'); ?>
                </button>
                
                <?php if (!empty($logo_url)) : ?>
                    <button type="button" class="button" id="remove_logo_button">
                        <?php esc_html_e('Remove Logo', 'stupid-simple-login-logo'); ?>
                    </button>
                <?php endif; ?>
            </p>
            
            <p class="description">
                <?php esc_html_e('Choose an image from your Media Library to use as the login page logo. Accepted file types: JPEG, PNG, GIF, WebP, and AVIF.', 'stupid-simple-login-logo'); ?>
            </p>
            
            <?php submit_button(esc_html__('Save Changes', 'stupid-simple-login-logo')); ?>
            
            <?php if (!empty($logo_url)) : ?>
                <p class="description" style="margin-top: 1em;">
                    <a href="<?php echo esc_url(wp_login_url()); ?>" target="_blank" class="button">
                        <?php esc_html_e('Preview Login Page ↗', 'stupid-simple-login-logo'); ?>
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