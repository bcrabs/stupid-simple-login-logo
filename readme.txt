Version: 1.15.5
Requires at least: 5.0
Requires PHP: 5.6
Stable: 1.14.1
Author: CRFTD
Author URI: https://crftd.dev
Update URI: https://github.com/crftddev/stupid-simple-login-logo
Text Domain: ssll-for-wp
Domain Path: /languages
License: GPL v2 or later

== Description ==
This plugin was developed specfically with freelancers, agencies, and users that have a desire to remove the default WordPress logo and set their own. No custom uploads by file managers or FTP clients. This plugin is meant to be super simple. Upload an image from your device or select one from the WordPress Media Library. Then save. No bloated options. No hoops to jump through or URL changes. Just set and forget. Simply brand the login page for your client or agency.

What this plugin does:
1. Allows custom image to replace native WordPress login logo by Administrators only

What this plugin doesn't do:
1. Customizes login screen fields or colors
2. Change login URL
3. Create bloat options or settings
4. Require special sizing or editing

== To use the plugin ==
1. Install and activate
2. Go to Settings → Login Logo
3. Click "Select Logo" to open Media Library
4. Select an image
5. Click "Save Logo" to save changes
6. Click "Remove Logo" to remove the custom logo

== Structure ==
stupid-simple-login-logo/
├── includes/
│   ├── class-init.php
│   ├── class-security.php
│   ├── class-file-handler.php
│   ├── class-cache-manager.php
│   ├── class-logo-manager.php
│   └── class-admin.php
├── js/
│   └── media-upload.js
├── languages/
│   └── .htaccess
└── stupid-simple-login-logo.php