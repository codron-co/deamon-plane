<?php

return [
    'bad_signature' => 'Agent rejected the request signature.',
    'needs_secret' => 'Site has no agent secret. Inject CONTROL_PLANE_AGENT_SECRET on the CMS Coolify app.',
    'theme' => [
        'not_registered' => 'Theme agent is not registered on this CMS (secret missing or CMS older than 1.2.5).',
        'not_found' => 'Theme was not found on the CMS instance.',
        'unsupported_source' => 'CMS rejected a non-git theme source.',
        'path_traversal' => 'Theme id failed the CMS path guard.',
        'system_theme' => 'The default system theme cannot be installed or updated.',
        'system_theme_agent' => 'The default system theme cannot be installed or updated via the agent.',
        'data_package_missing' => 'CMS has no theme data package (sync.json). Update the theme or run data-install first.',
        'validation_failed' => 'Theme agent validation failed.',
        'git_failed' => 'CMS git install failed.',
        'http' => 'Theme agent returned HTTP :status.',
        'active' => 'The active theme cannot be removed. Activate another theme on the site first.',
        'protected' => 'The system theme cannot be removed.',
    ],
    'admin' => [
        'too_old' => 'CMS agent is too old for admin management (needs Deamon 1.2.13+).',
        'validation_failed' => 'Admin agent validation failed.',
        'http' => 'Admin agent returned HTTP :status.',
    ],
];
