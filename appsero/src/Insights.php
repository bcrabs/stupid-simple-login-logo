<?php
namespace Appsero;

class Insights {
    protected $client;
    protected $options = [];
    protected $notice_shown = false;

    public function __construct($client) {
        $this->client = $client;
    }

    public function init($options = []) {
        $this->options = wp_parse_args($options, [
            'collect_email' => false,
            'disable_tracking' => false,
            'notice' => true,
            'notice_text' => '',
        ]);

        if ($this->options['notice'] && !$this->notice_shown) {
            add_action('admin_notices', [$this, 'show_notice']);
        }

        if (!$this->options['disable_tracking']) {
            add_action('init', [$this, 'schedule_tracking']);
            add_filter('appsero_track_skip_options', [$this, 'skip_tracking_options']);
        }
    }

    public function show_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (get_option('ssll_tracking_notice_shown')) {
            return;
        }

        $notice_text = !empty($this->options['notice_text']) 
            ? $this->options['notice_text']
            : __('Allow Stupid Simple Login Logo to collect non-sensitive diagnostic data and usage information.', 'ssll-for-wp');

        ?>
        <div class="notice notice-info is-dismissible">
            <p><?php echo esc_html($notice_text); ?></p>
            <p>
                <a href="<?php echo esc_url($this->get_opt_out_url()); ?>" class="button button-secondary">
                    <?php esc_html_e('Opt Out', 'ssll-for-wp'); ?>
                </a>
                <a href="<?php echo esc_url($this->get_opt_in_url()); ?>" class="button button-primary">
                    <?php esc_html_e('Allow', 'ssll-for-wp'); ?>
                </a>
            </p>
        </div>
        <?php

        $this->notice_shown = true;
    }

    public function schedule_tracking() {
        if (!wp_next_scheduled('appsero_send_tracking_data')) {
            wp_schedule_event(time(), 'daily', 'appsero_send_tracking_data');
        }
    }

    public function skip_tracking_options($options) {
        $options[] = 'admin_email';
        $options[] = 'users';
        return $options;
    }

    private function get_opt_in_url() {
        return wp_nonce_url(
            add_query_arg('ssll_tracking', 'opt_in'),
            'ssll_tracking_opt_in'
        );
    }

    private function get_opt_out_url() {
        return wp_nonce_url(
            add_query_arg('ssll_tracking', 'opt_out'),
            'ssll_tracking_opt_out'
        );
    }
}