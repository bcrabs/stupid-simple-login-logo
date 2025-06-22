<?php
// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Verify user capabilities
if (!current_user_can('activate_plugins')) {
    wp_die(esc_html__('You do not have sufficient permissions to uninstall this plugin.', 'stupid-simple-login-logo'));
}

// Include the WP_Filesystem API
require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
global $wp_filesystem;

try {
    // Delete plugin options using WordPress functions
    delete_option('ssll_logo_url');
    delete_option('ssll_logo_dimensions');
    delete_option('ssll_settings');
    delete_option('ssll_version');

    // Clean up transients using WordPress functions
    global $wpdb;
    $transients = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} 
        WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_ssll_rate_') . '%'
    ));
    
    foreach ($transients as $transient) {
        $transient_name = str_replace('_transient_', '', $transient);
        delete_transient($transient_name);
    }

    // Clean up temporary files
    $upload_dir = wp_upload_dir();
    $temp_dir = trailingslashit($upload_dir['basedir']) . 'ssll-temp';
    $log_dir = trailingslashit($upload_dir['basedir']) . 'ssll-logs';

    // Validate directories are within uploads directory
    $upload_dir_real = realpath($upload_dir['basedir']);
    $temp_dir_real = realpath($temp_dir);
    $log_dir_real = realpath($log_dir);

    if ($temp_dir_real && strpos($temp_dir_real, $upload_dir_real) === 0) {
        if ($wp_filesystem->is_dir($temp_dir)) {
            $files = $wp_filesystem->dirlist($temp_dir);
            if ($files) {
                foreach (array_keys($files) as $file) {
                    $file_path = trailingslashit($temp_dir) . $file;
                    if ($wp_filesystem->is_file($file_path)) {
                        if (!$wp_filesystem->delete($file_path)) {
                            throw new \Exception(sprintf(
                                /* translators: %s: File path */
                                __('Failed to delete file: %s', 'stupid-simple-login-logo'),
                                esc_html($file_path)
                            ));
                        }
                    }
                }
            }
            if ($wp_filesystem->is_dir($temp_dir) && empty($wp_filesystem->dirlist($temp_dir))) {
                if (!$wp_filesystem->rmdir($temp_dir)) {
                    throw new \Exception(sprintf(
                        /* translators: %s: Directory path */
                        __('Failed to remove directory: %s', 'stupid-simple-login-logo'),
                        esc_html($temp_dir)
                    ));
                }
            }
        }
    }

    if ($log_dir_real && strpos($log_dir_real, $upload_dir_real) === 0) {
        if ($wp_filesystem->is_dir($log_dir)) {
            $files = $wp_filesystem->dirlist($log_dir);
            if ($files) {
                foreach (array_keys($files) as $file) {
                     $file_path = trailingslashit($log_dir) . $file;
                    if ($wp_filesystem->is_file($file_path)) {
                        if (!$wp_filesystem->delete($file_path)) {
                            throw new \Exception(sprintf(
                                /* translators: %s: File path */
                                __('Failed to delete file: %s', 'stupid-simple-login-logo'),
                                esc_html($file_path)
                            ));
                        }
                    }
                }
            }
            if ($wp_filesystem->is_dir($log_dir) && empty($wp_filesystem->dirlist($log_dir))) {
                if (!$wp_filesystem->rmdir($log_dir)) {
                    throw new \Exception(sprintf(
                        /* translators: %s: Directory path */
                        __('Failed to remove directory: %s', 'stupid-simple-login-logo'),
                        esc_html($log_dir)
                    ));
                }
            }
        }
    }

} catch (\Exception $e) {
    // Show error to user
    wp_die(
        esc_html($e->getMessage()),
        esc_html__('Uninstall Error', 'stupid-simple-login-logo'),
        ['response' => 500]
    );
} 