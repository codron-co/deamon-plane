<?php

return [
    'title' => 'Software mail',
    'lede' => 'Deamon software emails (password reset, new order/member, weekly report, site status). Separate from Hostinger mailboxes. Settings are pushed to sites over HMAC; per-site notification overrides live on the site detail page.',
    'save' => 'Save and push to sites',
    'push' => 'Re-push to sites',
    'smtp' => [
        'title' => 'SMTP',
        'hint' => 'CMS admin software mail and Plane ops alerts use this SMTP. Storefront customer/contact mail stays on the site mail module.',
    ],
    'notifications' => [
        'title' => 'Notifications',
        'hint' => 'Global defaults. Override per site on site detail. cms = sent by the site CMS; plane = sent by Plane.',
        'columns' => [
            'type' => 'Type',
            'scope' => 'Scope',
            'enabled' => 'On',
            'options' => 'Options',
        ],
    ],
    'fields' => [
        'enabled' => 'Enable software mail',
        'host' => 'SMTP host',
        'port' => 'Port',
        'encryption' => 'Encryption',
        'username' => 'Username',
        'password' => 'Password',
        'password_hint' => 'Paste the SMTP password.',
        'password_saved' => 'A password is stored. Leave blank to keep it.',
        'from_address' => 'From address',
        'from_name' => 'From name',
        'default_admin_recipient' => 'Default recipient',
        'default_admin_recipient_hint' => 'Used when a site has no override. Plane ops users are also included on site down/up mail.',
        'day' => 'Day of week (0=Sun … 1=Mon)',
        'hour' => 'Hour (0–23, Europe/Istanbul)',
        'site_recipient' => 'Site software-mail recipient',
        'site_override' => 'Notification overrides (empty = global)',
    ],
    'flash' => [
        'saved' => 'Software mail settings saved. Push attempted for :count site(s).',
        'pushed' => 'Software mail settings re-pushed to :count site(s).',
        'site_saved' => 'Site software-mail overrides saved.',
    ],
];
