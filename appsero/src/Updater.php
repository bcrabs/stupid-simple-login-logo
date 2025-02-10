<?php
namespace Appsero;

class Updater {
    protected $client;
    protected $args = [];
    protected $plugin_name;
    protected $plugin_slug;
    protected $plugin_path;
    protected $license_key;
    protected $license_status;

    public function __construct($client, $args = []) {
        $this->client = $client;
        $this->args = wp_parse_args($args, [
            'license_key' => '',
            'license_status' => ''
        ]);

        $this->plugin_name = $client->getName();
        $this->plugin_path = $client->getFile();
        $this->plugin_slug = basename($this->plugin_path, '.php');
        $this->license_key = $this->args['license_key'];
        $this->license_status = $this->args['license_status'];

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugins_api_filter'], 10, 3);
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_version = $this->get_version();
        if (false === $remote_version) {
            return $transient;
        }

        if (version_compare($this->get_plugin_version(), $remote_version->version, '<')) {
            $transient->response[$this->plugin_path] = (object) [
                'slug' => $this->plugin_slug,
                'new_version' => $remote_version->version,
                'package' => $this->get_download_url(),
                'tested' => $remote_version->tested,
                'requires' => $remote_version->requires,
                'requires_php' => $remote_version->requires_php
            ];
        }

        return $transient;
    }

    public function plugins_api_filter($result, $action, $args) {
        if ('plugin_information' !== $action) {
            return $result;
        }

        if ($this->plugin_slug !== $args->slug) {
            return $result;
        }

        $remote_info = $this->get_info();
        if (false === $remote_info) {
            return $result;
        }

        return (object) [
            'name' => $this->plugin_name,
            'slug' => $this->plugin_slug,
            'version' => $remote_info->version,
            'tested' => $remote_info->tested,
            'requires' => $remote_info->requires,
            'requires_php' => $remote_info->requires_php,
            'author' => $remote_info->author,
            'sections' => (array) $remote_info->sections,
            'download_link' => $this->get_download_url()
        ];
    }

    private function get_version() {
        $response = wp_remote_get(
            $this->client->getApiUrl("public/version/{$this->client->getHash()}"),
            ['timeout' => 15]
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response));
    }

    private function get_info() {
        $response = wp_remote_get(
            $this->client->getApiUrl("public/info/{$this->client->getHash()}"),
            ['timeout' => 15]
        );

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        return json_decode(wp_remote_retrieve_body($response));
    }

    private function get_download_url() {
        $url = $this->client->getApiUrl("public/download/{$this->client->getHash()}");
        
        if ($this->license_key && $this->license_status === 'active') {
            $url = add_query_arg('license_key', $this->license_key, $url);
        }

        return $url;
    }

    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($this->plugin_path);
        return $data['Version'];
    }
}