<?php
namespace Appsero;

class Client {
    protected $hash;
    protected $name;
    protected $file;
    protected $base_url;
    protected $insights = null;
    protected $license = null;
    protected $updater = null;

    public function __construct($hash, $name, $file) {
        $this->hash = $hash;
        $this->name = $name;
        $this->file = $file;
        $this->base_url = 'https://api.appsero.com/';
    }

    public function insights() {
        if (is_null($this->insights)) {
            $this->insights = new Insights($this);
        }
        return $this->insights;
    }

    public function license() {
        if (is_null($this->license)) {
            $this->license = new License($this);
        }
        return $this->license;
    }

    public function updater($args = []) {
        if (is_null($this->updater)) {
            $this->updater = new Updater($this, $args);
        }
        return $this->updater;
    }

    public function getHash() {
        return $this->hash;
    }

    public function getName() {
        return $this->name;
    }

    public function getFile() {
        return $this->file;
    }

    public function getApiUrl($route = '') {
        return $this->base_url . ltrim($route, '/');
    }

    public function getOptionKey() {
        return 'ssll_license_key';
    }

    public function activate() {
        return update_option('ssll_license_activated', true);
    }

    public function deactivate() {
        return delete_option('ssll_license_activated');
    }
}