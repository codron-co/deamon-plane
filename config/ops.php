<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deamon release channels (git branches)
    |--------------------------------------------------------------------------
    |
    | Allowlist only. UI cannot pick another branch except Super Admin override
    | (later tasks + audit).
    |
    */

    'channels' => ['main', 'beta', 'alpha'],

    'locales' => ['en', 'tr'],

    'channel_switch' => [
        'main_minimum_version' => env('DEAMON_MAIN_MINIMUM_VERSION'),
        'downgrade_requires_confirm' => [
            'main' => ['beta', 'alpha'],
        ],
        'upgrade_requires_version_gate' => [
            'alpha' => ['main'],
            'beta' => ['main'],
        ],
    ],

    'deamon' => [
        'repository' => env('DEAMON_GIT_REPOSITORY', 'https://github.com/codron-co/deamon.git'),
        'compose_file' => env('DEAMON_COMPOSE_FILE', '/docker-compose.coolify.yml'),
    ],

    'themes' => [
        // Legacy defaults for the Settings-paste backfill only — not a live catalog lock.
        'org' => env('GITHUB_ORG', 'deamon-themes'),
        'repo_prefix' => env('GITHUB_THEME_REPO_PREFIX', 'deamon-theme-'),
        'auto_update_default' => false,
        'fanout_concurrency' => (int) env('GITHUB_THEME_FANOUT_CONCURRENCY', 3),
        'manifest_paths' => ['theme.json', 'theme/theme.json'],
    ],

    'github' => [
        'api_base' => env('GITHUB_API_BASE', 'https://api.github.com'),
        'web_base' => env('GITHUB_WEB_BASE', 'https://github.com'),
        'app_name' => env('GITHUB_APP_NAME', 'Deamon Plane Themes'),
        'timeout' => (int) env('GITHUB_HTTP_TIMEOUT', 20),
        'token' => env('GITHUB_TOKEN'),
        'app_id' => env('GITHUB_APP_ID'),
        'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
    ],

    'access' => [
        'ip_allowlist' => env('OPS_IP_ALLOWLIST'),
    ],

    'agent' => [
        'skew_seconds' => (int) env('CONTROL_PLANE_AGENT_SKEW_SECONDS', 60),
        'timeout_seconds' => (int) env('CONTROL_PLANE_AGENT_TIMEOUT', 10),
        'poll_minutes' => (int) env('CONTROL_PLANE_AGENT_POLL_MINUTES', 10),
        'stale_after_minutes' => (int) env('CONTROL_PLANE_AGENT_STALE_MINUTES', 30),
        'health_path' => '/internal/control/v1/health',
        'theme_list_path' => '/internal/control/v1/themes',
        'theme_install_path' => '/internal/control/v1/themes/install',
        'theme_update_path' => '/internal/control/v1/themes/update',
        'theme_activate_path' => '/internal/control/v1/themes/activate',
        'theme_data_install_path' => '/internal/control/v1/themes/data-install',
        'theme_sync_path' => '/internal/control/v1/themes/sync',
        'mail_configure_path' => '/internal/control/v1/mail/configure',
        'nonce_ttl_seconds' => (int) env('CONTROL_PLANE_AGENT_NONCE_TTL', 120),
    ],

    'hostinger' => [
        'api_base' => env('HOSTINGER_API_BASE', 'https://developers.hostinger.com'),
        'timeout' => (int) env('HOSTINGER_HTTP_TIMEOUT', 15),
        'webmail_url' => env('HOSTINGER_WEBMAIL_URL', 'https://mail.hostinger.com'),
    ],

    'cloudflare' => [
        'api_base' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('CLOUDFLARE_HTTP_TIMEOUT', 20),
        'default_origin_ipv4' => env('CLOUDFLARE_ORIGIN_IPV4', '72.62.117.147'),
        'wildcard_domain' => env('CLOUDFLARE_WILDCARD_DOMAIN', 'codron.co'),
    ],

    'coolify' => [
        'base_url' => env('COOLIFY_BASE_URL'),
        'api_token' => env('COOLIFY_API_TOKEN'),
        'default_project_uuid' => env('COOLIFY_DEFAULT_PROJECT_UUID'),
        'default_server_uuid' => env('COOLIFY_DEFAULT_SERVER_UUID'),
        'webhook_secret' => env('COOLIFY_WEBHOOK_SECRET'),
        'timeout' => (int) env('COOLIFY_HTTP_TIMEOUT', 30),
        'auto_rebind_domains' => filter_var(env('COOLIFY_AUTO_REBIND_DOMAINS', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'provision' => [
        'environment_name' => env('COOLIFY_ENVIRONMENT_NAME', 'main'),
        'poll_seconds' => (int) env('COOLIFY_DEPLOY_POLL_SECONDS', 15),
        'poll_max_attempts' => (int) env('COOLIFY_DEPLOY_POLL_MAX_ATTEMPTS', 40),
        'log_excerpt_bytes' => (int) env('COOLIFY_DEPLOY_LOG_EXCERPT_BYTES', 16000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Coolify fleet import (ops:import-coolify-apps)
    |--------------------------------------------------------------------------
    |
    | Customer sites are Deamon CMS apps only. Plane, Entron, transfer tools,
    | and theme repos are never upserted. dockerfile is importable (warning);
    | other build packs are skipped.
    |
    */

    'import' => [
        'customer_repo_needle' => 'codron-co/deamon',
        'exclude_repo_needles' => [
            'deamon-plane',
            'entron',
            'webapp-transfer',
        ],
        'preferred_build_pack' => 'dockercompose',
        'allowed_build_packs' => ['dockercompose', 'dockerfile'],
    ],

];
