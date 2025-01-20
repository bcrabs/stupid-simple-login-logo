<?php
namespace SSLL;

/**
 * Handles all file operations
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
    private function __wakeup() {}
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function init() {
        add_action('template_redirect', array($this, 'handle_logo_request'));
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
    }
    
    public function create_directories() {
        $dirs = array('js', 'languages');
        
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
                
                // Create .htaccess with correct MIME types
                $htaccess = $path . '/.htaccess';
                if (!file_exists($htaccess)) {
                    $content = "AddType application/javascript .js\n";
                    $content .= "Order deny,allow\n";
                    $content .= "Deny from all\n";
                    $content .= "<FilesMatch \"\.js$\">\n";
                    $content .= "    Allow from all\n";
                    $content .= "</FilesMatch>";
                    
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
        
        // Create JS file
        $this->create_js_file();
    }
    
    private function create_js_file() {
        // Get WP Filesystem
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;
        
        $js_file = SSLL_PATH . 'js/media-upload.js';
        if (!$wp_filesystem->exists($js_file)) {
            $js_content = $this->get_js_content();
            if (!$wp_filesystem->put_contents($js_file, $js_content, FS_CHMOD_FILE)) {
                throw new \Exception(
                    sprintf(
                        /* translators: %s: File path */
                        __('Unable to create JavaScript file: %s', 'ssll-for-wp'),
                        esc_html($js_file)
                    )
                );
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
            // Verify rate limit and access
            $this->security->check_rate_limit();
            
            if (!is_user_logged_in() && 
                !in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'), true)) {
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
            
            $mime_type = $this->security->validate_mime_type($file_path);
            if (!$mime_type) {
                throw new \Exception(__('Invalid file type', 'ssll-for-wp'));
            }
            
            $this->serve_file($file_path, $mime_type);
            
        } catch (\Exception $e) {
            wp_die(
                esc_html($e->getMessage()),
                esc_html__('Error', 'ssll-for-wp'),
                array('response' => 403)
            );
        }
    }
    
    private function serve_file($file_path, $mime_type) {
        $file_size = filesize($file_path);
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
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . $file_size);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $last_modified);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000');
        header('Expires: ' . gmdate('D, d M Y H:i:s T', time() + 31536000));
        
        // Check for X-Sendfile capability
        if (function_exists('apache_get_modules') && in_array('mod_xsendfile', apache_get_modules(), true)) {
            header('X-Sendfile: ' . $file_path);
            exit;
        }
        
        // Fallback to PHP
        $this->send_file_php($file_path);
    }
    
    private function send_file_php($file_path) {
        // Set time limit and memory limit for large files
        set_time_limit(300);
        $memory_limit = ini_get('memory_limit');
        if (intval($memory_limit) < 64) {
            ini_set('memory_limit', '64M');
        }
        
        $handle = @fopen($file_path, 'rb');
        if ($handle === false) {
            throw new \Exception(__('Error reading file', 'ssll-for-wp'));
        }
        
        try {
            while (!feof($handle)) {
                $buffer = fread($handle, SSLL_CHUNK_SIZE);
                if ($buffer === false) {
                    throw new \Exception(__('Error reading file', 'ssll-for-wp'));
                }
                echo $buffer;
                flush();
            }
        } finally {
            fclose($handle);
        }
        exit;
    }
    
    private function get_js_content() {
        return <<<'EOT'
jQuery(document).ready(function($) {
    var mediaUploader;
    var nonce = $('#ssll_nonce').val();
    
    $('#upload_logo_button').click(function(e) {
        e.preventDefault();
        
        if (!nonce) {
            console.error('Security token not found');
            return;
        }
        
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        mediaUploader = wp.media({
            title: ssllTranslations.chooseImage,
            button: {
                text: ssllTranslations.selectImage
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });
        
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            
            // Validate file type
            if (!attachment.mime.match(/^image\/(jpeg|png)$/)) {
                alert(ssllTranslations.invalidType);
                return;
            }
            
            // Validate file size (5MB limit)
            if (attachment.size > 5242880) {
                alert(ssllTranslations.fileTooBig);
                return;
            }
            
            $('#logo_url').val(attachment.url);
            $('#logo_preview').attr('src', attachment.url).show();
            $('#remove_logo_button').show();
        });
        
        mediaUploader.open();
    });
    
    $('#remove_logo_button').click(function(e) {
        e.preventDefault();
        if (!nonce) {
            console.error('Security token not found');
            return;
        }
        $('#logo_url').val('');
        $('#logo_preview').attr('src', '').hide();
        $(this).hide();
    });
});
EOT;
    }
    
    public function cleanup() {
        // Clean up temporary files
        WP_Filesystem();
        global $wp_filesystem;
        
        $upload_dir = wp_upload_dir();
        $temp_files = glob($upload_dir['basedir'] . '/ssll-temp-*');
        
        if (is_array($temp_files)) {
            foreach ($temp_files as $file) {
                $wp_filesystem->delete($file);
            }
        }
    }
    
    public function uninstall() {
        $this->cleanup();
        flush_rewrite_rules();
    }
}
