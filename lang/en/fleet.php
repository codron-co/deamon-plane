<?php

return [
    'title' => 'Fleet',
    'lede' => 'Coolify-hosted Deamon sites. Deploy status updates from Coolify webhooks (HMAC or query token), with poll as fallback.',
    'kicker' => 'Operations',
    'heading' => 'Needs attention',
    'snapshot_kicker' => 'Snapshot',
    'kpis' => [
        'aria' => 'Fleet snapshot',
        'sites' => 'Sites',
        'sites_hint' => 'Managed Coolify sites',
        'by_channel' => 'By branch',
        'by_channel_hint' => 'Git branch allowlist',
        'unhealthy' => 'Unhealthy',
        'unhealthy_hint' => 'Status error or agent health fail',
        'failed' => 'Failed deploys',
        'failed_hint' => 'All recorded Coolify deploys',
        'deploying' => 'Deploying',
        'deploying_hint' => 'Provisioning or branch switch',
    ],
    'attention' => [
        'aria' => 'Fleet attention',
        'dockerfile_title' => 'Dockerfile (legacy pack)',
        'dockerfile_lede' => 'Not migrated to Compose. Coolify build pack is still dockerfile; this is not the CMS version.',
        'unhealthy_title' => 'Unhealthy sites',
        'unhealthy_lede' => 'Status error or failed agent health.',
        'failed_title' => 'Recent failed deploys',
        'failed_lede' => 'Latest Coolify deploys that finished with an error.',
    ],
];
