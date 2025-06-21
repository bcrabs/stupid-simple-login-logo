<?php
namespace SSLL;

final class Admin {
    private static $instance = null;
    private $page_hook = '';
    
    private function __construct() {}
    
    private function __clone() {}
    
    public function __wakeup() {
        throw new \Exception('Unserialize is not allowed.');
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }
    
    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    
    public function add_admin_menu() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $this->page_hook = add_options_page(
            __('Stupid Simple Login Logo Settings', 'ssll-for-wp'),
            __('Login Logo', 'ssll-for-wp'),
            'manage_options',
            'stupid-simple-login-logo',
            [$this, 'render_settings_page']
        );
    }
    
    public function enqueue_assets($hook) {
        if ($hook !== $this->page_hook) {
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
                'nonce' => wp_create_nonce('ssll_media_upload'),
                'frame_title' => __('Select Login Logo', 'ssll-for-wp'),
                'frame_button' => __('Use as Login Logo', 'ssll-for-wp'),
                'translations' => [
                    'invalidType' => __('Please select a JPEG or PNG image.', 'ssll-for-wp'),
                    'fileTooBig' => __('File size must not exceed 5MB.', 'ssll-for-wp'),
                    'removeConfirm' => __('Are you sure you want to remove the custom logo? The default WordPress logo will be restored.', 'ssll-for-wp'),
                    'selectLogo' => __('Select Logo', 'ssll-for-wp'),
                    'changeLogo' => __('Change Logo', 'ssll-for-wp'),
                    'genericError' => __('An error occurred. Please try again.', 'ssll-for-wp')
                ]
            ]
        );
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access this page.', 'ssll-for-wp'),
                esc_html__('Permission Denied', 'ssll-for-wp'),
                ['response' => 403]
            );
        }
        
        $logo_url = get_option('ssll_logo_url', '');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="ssll-settings-wrapper">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ssll-logo-settings">
                    <div class="ssll-logo-preview" style="margin: 2em 0;">
                        <?php if (!empty($logo_url)) : ?>
                            <img src="<?php echo esc_url($logo_url); ?>" 
                                 id="logo_preview"
                                 alt="<?php esc_attr_e('Current login logo', 'ssll-for-wp'); ?>"
                                 style="max-width: 320px; height: auto;">
                        <?php endif; ?>
                    </div>
                    
                    <input type="hidden" name="action" value="ssll_save_logo">
                    <?php wp_nonce_field('ssll_save_logo', 'nonce'); ?>
                    <input type="hidden" name="logo_url" id="logo_url" value="<?php echo esc_attr($logo_url); ?>">
                    
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
                    
                    <?php submit_button(__('Save Changes', 'ssll-for-wp')); ?>
                </form>
            </div>
        </div>
        <?php
    }
}