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

    'deamon' => [
        'repository' => env('DEAMON_GIT_REPOSITORY', 'https://github.com/codron-co/deamon.git'),
        'compose_file' => env('DEAMON_COMPOSE_FILE', 'docker-compose.coolify.yml'),
    ],

    'themes' => [
        'org' => env('GITHUB_ORG', 'deamon-themes'),
        'repo_prefix' => env('GITHUB_THEME_REPO_PREFIX', 'deamon-theme-'),
    ],

    'agent' => [
        'skew_seconds' => (int) env('CONTROL_PLANE_AGENT_SKEW_SECONDS', 60),
    ],

    'coolify' => [
        'base_url' => env('COOLIFY_BASE_URL'),
        'api_token' => env('COOLIFY_API_TOKEN'),
        'default_project_uuid' => env('COOLIFY_DEFAULT_PROJECT_UUID'),
        'default_server_uuid' => env('COOLIFY_DEFAULT_SERVER_UUID'),
        'timeout' => (int) env('COOLIFY_HTTP_TIMEOUT', 30),
    ],

];
