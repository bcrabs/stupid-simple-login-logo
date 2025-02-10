<?php
namespace Appsero;

class License {
    protected $client;
    protected $api_args;

    public function __construct($client) {
        $this->client = $client;
        $this->api_args = [
            'timeout'   => 30,
            'sslverify' => true,
        ];
    }

    public function activate($license_key) {
        $route = 'public/license/activate/' . $this->client->getHash();
        $response = $this->send_request($route, [
            'license_key' => sanitize_text_field($license_key),
        ], 'POST');

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        return $this->format_response($response);
    }

    public function deactivate($license_key = '') {
        $license_key = $license_key ?: get_option($this->client->getOptionKey());
        if (!$license_key) {
            return [
                'success' => false,
                'error'   => 'License key not found',
            ];
        }

        $route = 'public/license/deactivate/' . $this->client->getHash();
        $response = $this->send_request($route, [
            'license_key' => sanitize_text_field($license_key),
        ], 'POST');

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        return $this->format_response($response);
    }

    public function check($license_key = '') {
        $license_key = $license_key ?: get_option($this->client->getOptionKey());
        if (!$license_key) {
            return [
                'success' => false,
                'error'   => 'License key not found',
            ];
        }

        $route = 'public/license/check/' . $this->client->getHash();
        $response = $this->send_request($route, [
            'license_key' => sanitize_text_field($license_key),
        ], 'GET');

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        return $this->format_response($response);
    }

    protected function send_request($route, $args = [], $method = 'GET') {
        $url = $this->client->getApiUrl($route);
        $this->api_args['method'] = $method;

        if ('GET' === $method) {
            $url = add_query_arg($args, $url);
        } else {
            $this->api_args['body'] = $args;
        }

        return wp_remote_request($url, $this->api_args);
    }

    protected function format_response($response) {
        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);

        if (empty($body) || $code !== 200) {
            return [
                'success' => false,
                'error'   => 'Unknown error occurred, please try again.',
            ];
        }

        $data = json_decode($body, true);
        if (!$data || !isset($data['success'])) {
            return [
                'success' => false,
                'error'   => 'Invalid response from license server.',
            ];
        }

        return $data;
    }
}