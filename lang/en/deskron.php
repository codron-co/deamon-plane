<?php

return [
    'title' => 'DeskRon support',
    'lede' => 'The Support page in every Deamon CMS admin connects to this DeskRon application. Values are stored encrypted and written into each site’s Coolify env through the DESKRON_* catalog rows.',
    'save' => 'Save',
    'form_errors' => 'Fix the highlighted fields and try again.',
    'state_label' => 'Status',
    'state' => [
        'ready' => 'Ready',
        'missing' => 'Missing',
    ],
    'state_hint' => [
        'ready' => 'Application id and API key are saved. Sites pick them up on their next deploy.',
        'missing' => 'Application id or API key is missing. The Support page on sites says “Destek şu an bağlanamadı”.',
    ],
    'application' => [
        'title' => 'DeskRon application',
        'hint' => 'The Deamon CMS application in the DeskRon panel. Each site creates its own organization under it the first time Support is opened.',
    ],
    'fields' => [
        'application_id' => 'Application id (DESKRON_APPLICATION_ID)',
        'application_id_hint' => 'The DeskRon application ULID.',
        'api_key' => 'API key (DESKRON_API_KEY)',
        'api_key_hint' => 'The application’s app_master_key (dsk_…).',
        'webhook_secret' => 'Webhook secret (DESKRON_WEBHOOK_SECRET)',
        'webhook_secret_hint' => 'Optional. Needed for support reply notifications; fetch it once with rotate-secrets in the DeskRon panel.',
        'secret_saved' => 'Saved. Type a new value to replace it; leaving it blank keeps the current one.',
    ],
    'rollout' => [
        'title' => 'Rolling out to sites',
        'hint' => 'Plane aligns each site’s env with the CMS catalog on every deploy. After saving, redeploy the sites whose Support page does not connect (Sites → bulk redeploy). A blank value never removes a value already on a site.',
    ],
    'flash' => [
        'saved' => 'DeskRon settings saved. Sites receive the values on their next deploy.',
    ],
];
