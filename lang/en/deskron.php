<?php

return [
    'title' => 'DeskRon support',
    'lede' => 'The Support page in every Deamon CMS admin connects to this DeskRon application. Values are stored encrypted and pushed straight to the sites over the signed agent; no env, no redeploy.',
    'save' => 'Save',
    'push' => 'Push to sites again',
    'form_errors' => 'Fix the highlighted fields and try again.',
    'state_label' => 'Status',
    'state' => [
        'ready' => 'Ready',
        'missing' => 'Missing',
    ],
    'state_hint' => [
        'ready' => 'Application id and API key are saved; saving pushes them to every site.',
        'missing' => 'Application id or API key is missing. The Support page on sites says “Destek şu an bağlanamadı”.',
    ],
    'application' => [
        'title' => 'DeskRon application',
        'hint' => 'The Deamon CMS application in the DeskRon panel. Each site creates its own organization under it the first time Support is opened.',
    ],
    'fields' => [
        'application_id' => 'Application id (DESKRON_APPLICATION_ID)',
        'application_id_hint' => 'The DeskRon application ULID. Changing it makes sites recreate their DeskRon organization under the new application.',
        'api_key' => 'API key (DESKRON_API_KEY)',
        'api_key_hint' => 'The application’s app_master_key (dsk_…).',
        'webhook_secret' => 'Webhook secret (DESKRON_WEBHOOK_SECRET)',
        'webhook_secret_hint' => 'Optional. Needed for support reply notifications; fetch it once with rotate-secrets in the DeskRon panel.',
        'secret_saved' => 'Saved. Type a new value to replace it; leaving it blank keeps the current one.',
    ],
    'rollout' => [
        'title' => 'Pushing to sites',
        'hint' => 'Saving pushes the setting to every site over the signed agent right away; a site that gets a new agent secret receives it too. Needs CMS 1.2.22 or later.',
        'pushed' => ':count sites accepted the last push',
        'last_pushed' => 'last push :time',
        'failed' => ':count sites did not accept the last push. Use “Push to sites again” to retry.',
    ],
    'flash' => [
        'saved' => 'DeskRon settings saved and queued for every site.',
        'push_queued' => 'Pushing the DeskRon setting to every site again.',
    ],
];
