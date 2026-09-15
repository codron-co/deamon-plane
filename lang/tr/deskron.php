<?php

return [
    'title' => 'DeskRon destek',
    'lede' => 'Deamon CMS sitelerinin admin panelindeki Destek sayfası bu DeskRon uygulamasına bağlanır. Değerler şifreli saklanır ve her sitenin Coolify env’ine katalogdaki DESKRON_* satırlarıyla yazılır.',
    'save' => 'Kaydet',
    'form_errors' => 'İşaretli alanları düzeltip tekrar deneyin.',
    'state_label' => 'Durum',
    'state' => [
        'ready' => 'Hazır',
        'missing' => 'Eksik',
    ],
    'state_hint' => [
        'ready' => 'Uygulama kimliği ve API anahtarı kayıtlı. Siteler bir sonraki deploy’da alır.',
        'missing' => 'Uygulama kimliği veya API anahtarı yok. Sitelerde Destek sayfası “Destek şu an bağlanamadı” der.',
    ],
    'application' => [
        'title' => 'DeskRon uygulaması',
        'hint' => 'DeskRon panelindeki Deamon CMS uygulaması. Her site kendi organization’ını ilk Destek açılışında bu uygulama altında oluşturur.',
    ],
    'fields' => [
        'application_id' => 'Uygulama kimliği (DESKRON_APPLICATION_ID)',
        'application_id_hint' => 'DeskRon uygulamasının ULID değeri.',
        'api_key' => 'API anahtarı (DESKRON_API_KEY)',
        'api_key_hint' => 'Uygulamanın app_master_key değeri (dsk_…).',
        'webhook_secret' => 'Webhook sırrı (DESKRON_WEBHOOK_SECRET)',
        'webhook_secret_hint' => 'İsteğe bağlı. Destek yanıtı bildirimleri için gerekir; DeskRon panelinde rotate-secrets ile bir kez alınır.',
        'secret_saved' => 'Kayıtlı. Değiştirmek için yenisini yazın; boş bırakırsanız mevcut değer korunur.',
    ],
    'rollout' => [
        'title' => 'Sitelere dağıtım',
        'hint' => 'Plane her deploy’da site env’ini CMS kataloğuna göre eşitler. Kaydettikten sonra Destek sayfası çalışmayan siteleri yeniden deploy edin (Siteler → toplu yeniden deploy). Boş bir alan sitedeki mevcut değeri silmez.',
    ],
    'flash' => [
        'saved' => 'DeskRon ayarları kaydedildi. Değerler sitelere bir sonraki deploy’da yazılır.',
    ],
];
