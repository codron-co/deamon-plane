<?php

return [
    'title' => 'Admin 2FA requirement',
    'lede' => 'Requires two-factor authentication (app, e-mail code, recovery code) for the site\'s admin panel users. Optional by default. Once required, admins without 2FA can sign in during the grace period, then are sent to the setup on their profile page. Site admins cannot loosen this from the panel (CMS 1.2.119+).',
    'fields' => [
        'required' => 'Requirement',
        'grace_days' => 'Grace period (days)',
    ],
    'states' => [
        'optional' => 'Optional',
        'required' => 'Required',
    ],
    'actions' => [
        'save' => 'Save and push to the site',
        'pull' => 'Fetch status',
    ],
    'flash' => [
        'saved' => 'Admin 2FA: :state. Admins with 2FA: :with / :total.',
        'state' => 'Admin 2FA is now: :state (grace :grace days). Admins with 2FA: :with / :total.',
        'failed' => 'Could not send the admin 2FA setting to the site: :reason',
    ],
];
