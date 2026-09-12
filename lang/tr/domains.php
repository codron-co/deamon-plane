<?php

return [
    'title' => 'Domainler',
    'new' => 'Domain ekle',
    'search' => 'Domain ara',
    'filter_unbound' => 'Yalnızca bağlı olmayan',
    'clear' => 'Filtreleri temizle',
    'pagination' => 'Domain sayfalama',
    'columns' => [
        'domain' => 'Domain',
        'site' => 'Site',
        'role' => 'Rol',
        'coolify' => 'Coolify',
        'actions' => 'İşlemler',
    ],
    'role' => [
        'primary' => 'Birincil',
        'www' => 'www',
        'temp' => 'Geçici',
        'alias' => 'Alias',
    ],
    'coolify' => [
        'bound' => 'Bağlı',
        'unbound' => 'Bağlı değil',
        'unknown' => 'Bilinmiyor',
    ],
    'actions' => [
        'bind' => 'Coolify’e bağla',
        'assign' => 'Siteye ata',
        'open_site' => 'Siteyi aç',
    ],
    'form' => [
        'domain' => 'Hostname',
        'site' => 'Site',
        'site_placeholder' => 'Site seçin',
    ],
    'empty' => [
        'title' => 'Henüz domain yok',
        'hint' => 'Hostname ekleyip bir siteye bağlayın. Coolify Sync app’lerden de host alır.',
        'filtered' => 'Filtrelerle eşleşen domain yok.',
    ],
    'flash' => [
        'created_bound' => 'Domain eklendi ve siteye bağlandı.',
        'created' => 'Domain eklendi.',
        'assigned' => 'Domain siteye atandı.',
        'bound' => 'Domain Coolify’e yazıldı.',
        'primary_locked' => 'Birincil domaini site düzenleme formundan değiştirin.',
        'needs_site' => 'Önce domaini bir siteye atayın.',
        'unassigned' => 'Domain ataması kaldırıldı.',
    ],
];
