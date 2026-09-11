<?php

return [
    'title' => 'Domains',
    'new' => 'Add domain',
    'search' => 'Search domains',
    'filter_unbound' => 'Unbound only',
    'clear' => 'Clear filters',
    'columns' => [
        'domain' => 'Domain',
        'site' => 'Site',
        'role' => 'Role',
        'coolify' => 'Coolify',
        'actions' => 'Actions',
    ],
    'role' => [
        'primary' => 'Primary',
        'www' => 'www',
        'temp' => 'Temporary',
        'alias' => 'Alias',
    ],
    'coolify' => [
        'bound' => 'Bound',
        'unbound' => 'Unbound',
        'unknown' => 'Unknown',
    ],
    'actions' => [
        'bind' => 'Bind on Coolify',
        'assign' => 'Assign site',
        'open_site' => 'Open site',
    ],
    'form' => [
        'domain' => 'Hostname',
        'site' => 'Site',
        'site_placeholder' => 'Select a site',
    ],
    'empty' => [
        'title' => 'No domains yet',
        'hint' => 'Add a hostname and attach it to a site. Coolify Sync also imports hosts from apps.',
        'filtered' => 'No domains match these filters.',
    ],
    'flash' => [
        'created_bound' => 'Domain added and linked to the site.',
        'created' => 'Domain added.',
        'assigned' => 'Domain assigned to the site.',
        'bound' => 'Domain binding pushed to Coolify.',
        'primary_locked' => 'Change the primary domain from the site edit form.',
        'needs_site' => 'Assign the domain to a site first.',
        'unassigned' => 'Domain unassigned.',
    ],
];
