<?php

return [
    'gate' => [
        'coolify' => 'Coolify oto-deploy',
        'ci' => 'CI kapısı',
        'auto_deploy_blocked' => ':name CI kapısında: oto-deploy açılamaz. Önce deploy kapısını Coolify’a çevirin.',
    ],
    'status' => [
        'canary' => 'kanarya',
        'fanout' => 'yayılıyor',
        'done' => 'bitti',
        'halted' => 'durduruldu',
        'superseded' => 'yenisi geldi',
    ],
    'halt_reason' => [
        'manual' => 'Operatör durdurdu.',
        'timeout' => 'Kanarya süresi doldu; hazır olmayan: :sites',
        'canary_deploy_failed' => 'Kanarya deploy isteği başarısız: :sites',
        'canary_failed' => 'Kanarya başarısız (build veya sağlık): :sites',
    ],
    'errors' => [
        'not_open' => 'Bu yayın zaten durmuş veya bitmiş.',
        'not_halted' => 'Yalnızca durdurulmuş bir yayın sürdürülebilir.',
        'not_current' => ':sha artık dalın son commit’i değil; yeni push kendi CI koşusuyla yayınlanır.',
    ],
];
