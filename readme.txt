Version: 1.14.1
Requires at least: 5.0
Requires PHP: 5.6
Stable: 1.14.1
Author: CRFTD
Author URI: https://crftd.dev
Update URI: https://github.com/crftddev/stupid-simple-login-logo
Text Domain: ssll-for-wp
Domain Path: /languages
License: GPL v2 or later

== To use the plugin ==
1. Install and activate
2. Go to Settings → Login Logo
3. Click "Select Logo" to open Media Library
4. Select an image
5. Click "Save Logo" to save changes
6. Click "Remove Logo" to remove the custom logo

== Structure ==
stupid-simple-login-logo/
├── appsero/
│   └── src/
│       ├── Client.php
│       └── [other AppSero SDK files]
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

== Privacy Policy ==
Stupid Simple Login Logo uses [Appsero](https://appsero.com) SDK to collect some telemetry data upon user's confirmation. This helps us to troubleshoot problems faster & make product improvements.

Appsero SDK **does not gather any data by default.** The SDK only starts gathering basic telemetry data **when a user allows it via the admin notice**. We collect the data to ensure a great user experience for all our users. 

Integrating Appsero SDK **DOES NOT IMMEDIATELY** start gathering data, **without confirmation from users in any case.**

Learn more about how [Appsero collects and uses this data](https://appsero.com/privacy-policy/).
