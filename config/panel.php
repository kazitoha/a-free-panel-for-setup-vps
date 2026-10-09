<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Panel hostname
    |--------------------------------------------------------------------------
    |
    | Written by install-panel.sh. Used to derive module subdomains such as
    | pma.<panel-domain>.
    |
    */

    'domain' => env('PANEL_DOMAIN', 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Default PHP version
    |--------------------------------------------------------------------------
    |
    | The PHP version installed by install-panel.sh; used for module sites
    | (phpMyAdmin) when no explicit version is chosen.
    |
    */

    'php' => env('PANEL_PHP', '8.3'),

    /*
    |--------------------------------------------------------------------------
    | Caddy paths
    |--------------------------------------------------------------------------
    */

    'caddy_sites_dir' => env('PANEL_CADDY_SITES_DIR', '/etc/caddy/sites'),
    'caddyfile' => env('PANEL_CADDYFILE', '/etc/caddy/Caddyfile'),
    'backup_dir' => env('PANEL_BACKUP_DIR', '/root/panel-backups/caddy'),

    /*
    |--------------------------------------------------------------------------
    | Site files owned by modules — never editable from the Sites screen
    |--------------------------------------------------------------------------
    */

    'reserved_sites' => ['panel', 'pma', 'mail'],

    /*
    |--------------------------------------------------------------------------
    | phpMyAdmin
    |--------------------------------------------------------------------------
    */

    'phpmyadmin' => [
        'dir' => env('PANEL_PMA_DIR', '/var/www/phpmyadmin'),
        'version' => env('PANEL_PMA_VERSION', '5.2.3'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PowerDNS HTTP API
    |--------------------------------------------------------------------------
    |
    | The API runs on localhost only (see install-panel.sh, pdns.conf).
    | ns_domain hosts ns1/ns2 that every created zone delegates to;
    | server_ip seeds the apex A record of new zones.
    |
    */

    'pdns' => [
        'url' => env('PDNS_API_URL', 'http://127.0.0.1:8081'),
        'key' => env('PDNS_API_KEY', ''),
        'server' => env('PDNS_API_SERVER', 'localhost'),
        'ns_domain' => env('PDNS_NS_DOMAIN'),
        'server_ip' => env('PDNS_SERVER_IP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stalwart mail server
    |--------------------------------------------------------------------------
    |
    | The JMAP management API on localhost (bootstrap mode and the HTTP
    | listener are bound to 127.0.0.1:8080 behind the mail.<panel-domain>
    | Caddy site). user/password authenticate with HTTP Basic — written by
    | `panel:stalwart:install`: the permanent admin created by the wizard,
    | falling back to the pinned STALWART_RECOVERY_ADMIN credential.
    |
    */

    'stalwart' => [
        'url' => env('STALWART_URL', 'http://127.0.0.1:8080'),
        'user' => env('STALWART_USER', 'admin'),
        'password' => env('STALWART_PASSWORD', ''),
        'host' => env('STALWART_HOST'),
    ],

];
