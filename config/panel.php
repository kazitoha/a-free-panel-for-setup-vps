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
    'www_dir' => env('PANEL_WWW_DIR', '/var/www'),

    /*
    |--------------------------------------------------------------------------
    | Site files owned by modules — never editable from the Sites screen
    |--------------------------------------------------------------------------
    */

    'reserved_sites' => ['panel', 'pma', 'mail'],

    /*
    |--------------------------------------------------------------------------
    | Databases
    |--------------------------------------------------------------------------
    |
    | Root password used by panel:* commands over the local socket (written
    | by install-panel.sh into .env). Reserved databases are never offered
    | in the panel and are refused by the root commands as well.
    |
    */

    'mysql' => [
        'root_password' => env('MYSQL_ROOT_PASSWORD', ''),
    ],

    'reserved_databases' => ['pdns', 'mysql', 'information_schema', 'performance_schema', 'sys'],

    'reserved_database_users' => ['root', 'mysql', 'debian-sys-maint', 'pdns_user', 'panel'],

    /*
    |--------------------------------------------------------------------------
    | Backups & scheduler
    |--------------------------------------------------------------------------
    |
    | Root-owned destination for database dumps and project archives (written
    | by panel:backup:*). retention_days is the default for new schedules.
    | scheduler_lock guards the every-minute tick in /etc/cron.d/panel against
    | overlapping runs.
    |
    */

    'backup' => [
        'dir' => env('PANEL_BACKUP_DIR_PANEL', '/var/backups/panel'),
        'retention_days' => (int) env('PANEL_BACKUP_RETENTION_DAYS', 7),
    ],

    'scheduler_lock' => env('PANEL_SCHEDULER_LOCK', '/run/panel-scheduler.lock'),

    'cron_file' => env('PANEL_CRON_FILE', '/etc/cron.d/panel'),

    /*
    |--------------------------------------------------------------------------
    | Self-update
    |--------------------------------------------------------------------------
    |
    | The panel keeps its git checkout in sync with an upstream branch. The
    | hourly `panel:update:check` cron line fetches the branch and records the
    | result in the status file; the dashboard only reads that file, so page
    | loads never run git or sudo. dir defaults to the application root.
    |
    */

    'update' => [
        'dir' => env('PANEL_UPDATE_DIR', ''),
        'branch' => env('PANEL_UPDATE_BRANCH', 'main'),
        'status_file' => env('PANEL_UPDATE_STATUS_FILE', storage_path('app/update-status.json')),
        'interval' => (int) env('PANEL_UPDATE_INTERVAL', 3600),
        'check_timeout' => (float) env('PANEL_UPDATE_CHECK_TIMEOUT', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google sign-in (OpenID Connect)
    |--------------------------------------------------------------------------
    |
    | Optional admin login through Google. Create an OAuth client at
    | console.cloud.google.com (redirect URI: https://<panel>/auth/google/callback)
    | and fill both values — the sign-in button appears once they are set.
    | Only existing panel users can sign in; Google never creates accounts.
    |
    */

    'google' => [
        'client_id' => (string) env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => (string) env('GOOGLE_CLIENT_SECRET', ''),
    ],

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
