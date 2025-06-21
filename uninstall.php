<?php
// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Verify user capabilities
if (!current_user_can('activate_plugins')) {
    wp_die(__('You do not have sufficient permissions to uninstall this plugin.', 'ssll-for-wp'));
}

// Start transaction
global $wpdb;
$wpdb->query('START TRANSACTION');

try {
    // Delete plugin options
    delete_option('ssll_logo_url');
    delete_option('ssll_logo_dimensions');
    delete_option('ssll_settings');
    delete_option('ssll_version');

    // Clean up transients
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_ssll_rate_') . '%'
    ));

    // Clean up temporary files
    $upload_dir = wp_upload_dir();
    $temp_dir = trailingslashit($upload_dir['basedir']) . 'ssll-temp';
    $log_dir = trailingslashit($upload_dir['basedir']) . 'ssll-logs';

    // Validate directories are within uploads directory
    $upload_dir_real = realpath($upload_dir['basedir']);
    $temp_dir_real = realpath($temp_dir);
    $log_dir_real = realpath($log_dir);

    if ($temp_dir_real && strpos($temp_dir_real, $upload_dir_real) === 0) {
        if (is_dir($temp_dir)) {
            $files = array_filter(glob($temp_dir . '/*'), 'is_file');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file) && strpos(realpath($file), $temp_dir_real) === 0) {
                        if (!wp_delete_file($file)) {
                            throw new \Exception(sprintf(
                                /* translators: %s: File path */
                                __('Failed to delete file: %s', 'ssll-for-wp'),
                                $file
                            ));
                        }
                    }
                }
            }
            if (is_dir($temp_dir) && count(glob($temp_dir . '/*')) === 0) {
                if (!rmdir($temp_dir)) {
                    throw new \Exception(sprintf(
                        /* translators: %s: Directory path */
                        __('Failed to remove directory: %s', 'ssll-for-wp'),
                        $temp_dir
                    ));
                }
            }
        }
    }

    if ($log_dir_real && strpos($log_dir_real, $upload_dir_real) === 0) {
        if (is_dir($log_dir)) {
            $files = array_filter(glob($log_dir . '/*'), 'is_file');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file) && strpos(realpath($file), $log_dir_real) === 0) {
                        if (!wp_delete_file($file)) {
                            throw new \Exception(sprintf(
                                /* translators: %s: File path */
                                __('Failed to delete file: %s', 'ssll-for-wp'),
                                $file
                            ));
                        }
                    }
                }
            }
            if (is_dir($log_dir) && count(glob($log_dir . '/*')) === 0) {
                if (!rmdir($log_dir)) {
                    throw new \Exception(sprintf(
                        /* translators: %s: Directory path */
                        __('Failed to remove directory: %s', 'ssll-for-wp'),
                        $log_dir
                    ));
                }
            }
        }
    }

    // Commit transaction
    $wpdb->query('COMMIT');

} catch (\Exception $e) {
    // Rollback transaction
    $wpdb->query('ROLLBACK');
    
    // Log error
    error_log(sprintf(
        '[SSLL Uninstall Error] %s',
        $e->getMessage()
    ));
    
    // Show error to user
    wp_die(
        esc_html($e->getMessage()),
        esc_html__('Uninstall Error', 'ssll-for-wp'),
        ['response' => 500]
    );
} 