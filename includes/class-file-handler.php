<?php
namespace SSLL;

/**
 * Handles file operations
 */
final class File_Handler {
    private static $instance = null;
    private $security;
    private $cache_manager;
    
    private function __construct() {
        $this->security = Security::get_instance();
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
        add_action('template_redirect', [$this, 'handle_logo_request']);
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
    }
    
    public function create_directories() {
        $dirs = ['js', 'languages'];
        
        foreach ($dirs as $dir) {
            $path = SSLL_PATH . $dir;
            if (!file_exists($path)) {
                if (!wp_mkdir_p($path)) {
                    throw new \Exception(
                        sprintf(
                            /* translators: %s: Directory path */
                            __('Unable to create directory: %s', 'ssll-for-wp'),
                            esc_html($path)
                        )
                    );
                }
                
                // Create .htaccess to prevent direct access
                $htaccess = $path . '/.htaccess';
                if (!file_exists($htaccess)) {
                    $content = "Order deny,allow\nDeny from all";
                    if (!@file_put_contents($htaccess, $content)) {
                        throw new \Exception(
                            sprintf(
                                /* translators: %s: File path */
                                __('Unable to create security file: %s', 'ssll-for-wp'),
                                esc_html($htaccess)
                            )
                        );
                    }
                }
            }
        }
    }
    
    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^login-logo/([^/]+)/?$',
            'index.php?ssll_logo=$matches[1]',
            'top'
        );
    }
    
    public function add_query_vars($vars) {
        $vars[] = 'ssll_logo';
        return $vars;
    }
    
    public function handle_logo_request() {
        $logo_id = absint(get_query_var('ssll_logo'));
        if (empty($logo_id)) {
            return;
        }
        
        try {
            if (!is_user_logged_in() && 
                !in_array($GLOBALS['pagenow'], ['wp-login.php', 'wp-register.php'], true)) {
                throw new \Exception(__('Unauthorized access', 'ssll-for-wp'));
            }
            
            $logo_url = $this->cache_manager->get_logo_url();
            if (empty($logo_url)) {
                throw new \Exception(__('Logo not found', 'ssll-for-wp'));
            }
            
            $file_path = $this->security->sanitize_file_path($logo_url);
            if (!$file_path) {
                throw new \Exception(__('Invalid file location', 'ssll-for-wp'));
            }
            
            $this->serve_file($file_path);
            
        } catch (\Exception $e) {
            wp_die(
                esc_html($e->getMessage()),
                esc_html__('Error', 'ssll-for-wp'),
                ['response' => 403]
            );
        }
    }
    
    private function serve_file($file_path) {
        // Get file size using WordPress filesystem
        WP_Filesystem();
        global $wp_filesystem;
        
        if (!$wp_filesystem->exists($file_path)) {
            throw new \Exception(__('File not found', 'ssll-for-wp'));
        }
        
        $file_size = $wp_filesystem->size($file_path);
        if ($file_size === false || $file_size > SSLL_MAX_FILE_SIZE) {
            throw new \Exception(__('Invalid file size', 'ssll-for-wp'));
        }
        
        // Generate ETag
        $etag = md5_file($file_path);
        $last_modified = gmdate('D, d M Y H:i:s T', filemtime($file_path));
        
        // Check client cache
        $client_etag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : false;
        $client_modified = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
        
        if (($client_modified && $client_modified >= strtotime($last_modified)) || $client_etag === $etag) {
            status_header(304);
            exit;
        }
        
        // Set headers
        $mime_type = $this->security->validate_mime_type($file_path);
        if (!$mime_type) {
            throw new \Exception(__('Invalid file type', 'ssll-for-wp'));
        }
        
        $filename = basename($file_path);
        
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . $file_size);
        header('Content-Disposition: inline; filename="' . esc_attr($filename) . '"');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $last_modified);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000');
        header('Expires: ' . gmdate('D, d M Y H:i:s T', time() + 31536000));
        
        // Use X-Sendfile if available
        if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules(), true)) {
            header('X-Sendfile: ' . $file_path);
            exit;
        }
        
        // Fallback to PHP
        $this->send_file_php($file_path);
    }
    
    private function send_file_php($file_path) {
        set_time_limit(300);
        $chunk_size = SSLL_CHUNK_SIZE;
        
        $handle = @fopen($file_path, 'rb');
        if ($handle === false) {
            throw new \Exception(__('Error reading file', 'ssll-for-wp'));
        }
        
        // Lock file for reading
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new \Exception(__('Error locking file', 'ssll-for-wp'));
        }
        
        try {
            while (!feof($handle)) {
                $buffer = fread($handle, $chunk_size);
                if ($buffer === false) {
                    throw new \Exception(__('Error reading file', 'ssll-for-wp'));
                }
                echo $buffer;
                flush();
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        exit;
    }
    
    public function cleanup() {
        // Clean up temporary files
        WP_Filesystem();
        global $wp_filesystem;
        
        $upload_dir = wp_upload_dir();
        $temp_files = glob($upload_dir['basedir'] . '/ssll-temp-*');
        
        if (is_array($temp_files)) {
            foreach ($temp_files as $file) {
                if ($wp_filesystem->exists($file)) {
                    $wp_filesystem->delete($file);
                }
            }
        }
    }
    
    public function uninstall() {
        $this->cleanup();
        flush_rewrite_rules();
    }
}