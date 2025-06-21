<?php
namespace SSLL;

if (!defined('ABSPATH')) {
    exit('Direct access not permitted.');
}

final class File_Upload_Handler {
    private static $instance = null;
    private $security;
    private $upload_dir;
    private $max_file_size;
    private $allowed_types;
    
    private function __construct() {
        $this->security = Security::get_instance();
        $this->upload_dir = wp_upload_dir()['basedir'] . '/ssll-uploads';
        $this->max_file_size = SSLL_MAX_FILE_SIZE;
        $this->allowed_types = SSLL_ALLOWED_IMAGE_TYPES;
        $this->init_upload_dir();
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
    
    private function init_upload_dir() {
        if (!is_dir($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            chmod($this->upload_dir, SSLL_DIR_PERMS);
            chown($this->upload_dir, SSLL_FILE_OWNER);
            
            // Create .htaccess to prevent direct access
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents($this->upload_dir . '/.htaccess', $htaccess_content);
            chmod($this->upload_dir . '/.htaccess', SSLL_FILE_PERMS);
            chown($this->upload_dir . '/.htaccess', SSLL_FILE_OWNER);
        }
    }
    
    public function handle_upload($file) {
        try {
            // Verify nonce
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'ssll_upload_nonce')) {
                throw new \Exception('Security check failed');
            }
            
            // Verify user capabilities
            if (!$this->security->verify_user_capability()) {
                throw new \Exception('Insufficient permissions');
            }
            
            // Validate file
            $this->validate_file($file);
            
            // Generate unique filename
            $filename = $this->generate_unique_filename($file['name']);
            $filepath = $this->upload_dir . '/' . $filename;
            
            // Move file with proper permissions
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new \Exception('Failed to move uploaded file');
            }
            
            chmod($filepath, SSLL_FILE_PERMS);
            chown($filepath, SSLL_FILE_OWNER);
            
            // Verify file after upload
            if (!$this->security->validate_image($filepath)) {
                unlink($filepath);
                throw new \Exception('Invalid file after upload');
            }
            
            return [
                'success' => true,
                'file' => [
                    'path' => $filepath,
                    'url' => wp_upload_dir()['baseurl'] . '/ssll-uploads/' . $filename,
                    'name' => $filename,
                    'type' => $file['type'],
                    'size' => $file['size']
                ]
            ];
            
        } catch (\Exception $e) {
            $this->security->log_security_event('Upload failed', [
                'error' => $e->getMessage(),
                'file' => $file['name'] ?? 'unknown',
                'user' => get_current_user_id()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function validate_file($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('Upload error: ' . $this->get_upload_error($file['error']));
        }
        
        // Check file size
        if ($file['size'] > $this->max_file_size) {
            throw new \Exception('File too large');
        }
        
        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $this->allowed_types)) {
            throw new \Exception('Invalid file type');
        }
        
        // Check for malicious content
        if (!$this->is_safe_image($file['tmp_name'])) {
            throw new \Exception('Potentially malicious file detected');
        }
    }
    
    private function is_safe_image($filepath) {
        // Check for PHP code in image
        $content = file_get_contents($filepath);
        if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
            return false;
        }
        
        // Verify image dimensions
        $image_info = @getimagesize($filepath);
        if (!$image_info) {
            return false;
        }
        
        // Check for minimum dimensions
        if ($image_info[0] < SSLL_MIN_IMAGE_DIMENSIONS || $image_info[1] < SSLL_MIN_IMAGE_DIMENSIONS) {
            return false;
        }
        
        // Check for maximum dimensions
        if ($image_info[0] > SSLL_MAX_IMAGE_DIMENSIONS || $image_info[1] > SSLL_MAX_IMAGE_DIMENSIONS) {
            return false;
        }
        
        return true;
    }
    
    private function generate_unique_filename($original_name) {
        $info = pathinfo($original_name);
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $name = wp_basename($original_name, $ext);
        $name = sanitize_file_name($name);
        
        $filename = $name . '-' . wp_generate_password(8, false) . $ext;
        return $filename;
    }
    
    private function get_upload_error($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }
    
    public function cleanup_old_files() {
        if (!is_dir($this->upload_dir)) {
            return;
        }
        
        $files = glob($this->upload_dir . '/*');
        if (!is_array($files)) {
            return;
        }
        
        $cutoff = strtotime('-7 days');
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (fileowner($file) === SSLL_FILE_OWNER) {
                    unlink($file);
                }
            }
        }
    }
} 