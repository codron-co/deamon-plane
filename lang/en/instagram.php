<?php

return [
    'title' => 'Instagram',
    'lede' => '«Connect with Instagram» on Deamon CMS sites runs through this Meta app. The app secret stays encrypted in Plane only; Plane handles the customer login and pushes just that account\'s token to the site over the signed agent.',
    'save' => 'Save',
    'push' => 'Push to sites again',
    'form_errors' => 'Fix the highlighted fields and try again.',
    'state_label' => 'Status',
    'state' => ['ready' => 'Ready', 'missing' => 'Missing'],
    'state_hint' => [
        'ready' => 'App id and secret are stored; CMS 1.2.76+ sites show «Connect with Instagram».',
        'missing' => 'No app id or secret. Sites can only connect with a pasted token.',
    ],
    'app' => [
        'title' => 'Meta app',
        'hint' => 'developers.facebook.com → app → Use cases → Instagram API → «API setup with Instagram login»: the Instagram app id and secret (not the Facebook app id).',
    ],
    'fields' => [
        'app_id' => 'Instagram app id',
        'app_id_hint' => 'Digits only.',
        'app_secret' => 'Instagram app secret',
        'app_secret_hint' => 'Revealed with «Show» in the Meta dashboard. Stored encrypted and never shown again.',
        'secret_saved' => 'Stored. Type a new one to replace it; leave blank to keep it.',
    ],
    'meta' => [
        'title' => 'URLs for the Meta dashboard',
        'hint' => 'Use these in the app settings.',
        'redirect_uri' => 'Valid OAuth redirect URI (Instagram login settings)',
        'data_deletion' => 'Data deletion instructions URL (App settings → Basic)',
        'scope' => 'Permission: instagram_business_basic',
    ],
    'rollout' => [
        'title' => 'Sites',
        'supported' => ':count sites run CMS 1.2.76+ (Instagram module available).',
        'connected' => 'Sites with a connected Instagram account: :count',
        'none' => 'No connected sites yet.',
        'last_pushed' => 'last pushed :time',
    ],
    'flash' => [
        'saved' => 'Instagram settings saved and being pushed to sites.',
        'push_queued' => 'Instagram settings are being pushed to sites again.',
    ],
    'public' => [
        'error_title' => 'Could not start the Instagram connection',
        'error_lede' => 'The link may have expired or is invalid. Go back to Integrations → Instagram in your site admin and press «Connect with Instagram» again.',
        'error_not_configured' => 'Instagram connection is not available right now. Please try again later.',
        'deletion_title' => 'Deleting your Instagram data',
        'deletion_lede' => 'If you connected your Instagram account to a website built with Deamon CMS, the site stores only your username and your recent posts\' captions, links and images, and uses them solely to display them on that site.',
        'deletion_steps_title' => 'To delete your data',
        'deletion_step_1' => 'In the site admin, open Integrations → Instagram and press «Disconnect». The token and every imported post and image are deleted immediately.',
        'deletion_step_2' => 'On Instagram, remove the app\'s access under Settings → Websites and apps; the site can no longer fetch data.',
        'deletion_step_3' => 'If you cannot access the site admin, email info@codron.co with your Instagram username; your data is deleted within 7 days and you are notified.',
    ],
];
