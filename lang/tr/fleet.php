<?php

return [
    'title' => 'Filo',
    'lede' => 'Coolify’deki Deamon siteleri. Dağıtım durumu Coolify webhook’larından (HMAC veya query token) gelir; poll yedektir.',
    'kicker' => 'Operasyon',
    'heading' => 'Dikkat gerekenler',
    'snapshot_kicker' => 'Özet',
    'kpis' => [
        'aria' => 'Filo özeti',
        'sites' => 'Siteler',
        'sites_hint' => 'Yönetilen Coolify siteleri',
        'by_channel' => 'Dala göre',
        'by_channel_hint' => 'Git dalı allowlist',
        'unhealthy' => 'Sağlıksız',
        'unhealthy_hint' => 'Durum hatası veya agent sağlık hatası',
        'failed' => 'Başarısız dağıtımlar',
        'failed_hint' => 'Kayıtlı tüm Coolify dağıtımları',
        'deploying' => 'Dağıtılıyor',
        'deploying_hint' => 'Kurulum veya dal değişimi',
    ],
    'attention' => [
        'aria' => 'Filo uyarısı',
        'dockerfile_title' => 'Dockerfile (eski pack)',
        'dockerfile_lede' => 'Compose’a geçirilmedi. Coolify build pack hâlâ dockerfile; CMS sürümü değil.',
        'unhealthy_title' => 'Sağlıksız siteler',
        'unhealthy_lede' => 'Durum hatası veya başarısız agent sağlığı.',
        'failed_title' => 'Son başarısız dağıtımlar',
        'failed_lede' => 'Hata ile biten son Coolify dağıtımları.',
    ],
];
