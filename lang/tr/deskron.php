<?php

return [
    'title' => 'DeskRon destek',
    'lede' => 'Deamon CMS sitelerinin admin panelindeki Destek sayfası bu DeskRon uygulamasına bağlanır. Değerler şifreli saklanır ve imzalı agent ile doğrudan sitelere aktarılır; env ve deploy gerekmez.',
    'save' => 'Kaydet',
    'push' => 'Sitelere yeniden aktar',
    'form_errors' => 'İşaretli alanları düzeltip tekrar deneyin.',
    'state_label' => 'Durum',
    'state' => [
        'ready' => 'Hazır',
        'missing' => 'Eksik',
    ],
    'state_hint' => [
        'ready' => 'Uygulama kimliği ve API anahtarı kayıtlı; kaydedince tüm sitelere aktarılır.',
        'missing' => 'Uygulama kimliği veya API anahtarı yok. Sitelerde Destek sayfası “Destek şu an bağlanamadı” der.',
    ],
    'application' => [
        'title' => 'DeskRon uygulaması',
        'hint' => 'DeskRon panelindeki Deamon CMS uygulaması. Her site kendi organization’ını ilk Destek açılışında bu uygulama altında oluşturur.',
    ],
    'fields' => [
        'application_id' => 'Uygulama kimliği (DESKRON_APPLICATION_ID)',
        'application_id_hint' => 'DeskRon uygulamasının ULID değeri. Değişirse siteler DeskRon organization’larını yeni uygulamada yeniden oluşturur.',
        'api_key' => 'API anahtarı (DESKRON_API_KEY)',
        'api_key_hint' => 'Uygulamanın app_master_key değeri (dsk_…).',
        'webhook_secret' => 'Webhook sırrı (DESKRON_WEBHOOK_SECRET)',
        'webhook_secret_hint' => 'İsteğe bağlı. Destek yanıtı bildirimleri için gerekir; DeskRon panelinde rotate-secrets ile bir kez alınır.',
        'secret_saved' => 'Kayıtlı. Değiştirmek için yenisini yazın; boş bırakırsanız mevcut değer korunur.',
    ],
    'rollout' => [
        'title' => 'Sitelere aktarım',
        'hint' => 'Kaydetmek ayarı tüm sitelere imzalı agent ile hemen gönderir; yeni bir siteye agent secret basılınca o siteye de gönderilir. CMS 1.2.22 ve üstü gerekir.',
        'pushed' => ':count site son aktarımı kabul etti',
        'last_pushed' => 'son aktarım :time',
        'failed' => ':count site son aktarımı kabul etmedi. “Sitelere yeniden aktar” ile tekrar deneyin.',
    ],
    'flash' => [
        'saved' => 'DeskRon ayarları kaydedildi ve sitelere aktarım kuyruğa alındı.',
        'push_queued' => 'DeskRon ayarı tüm sitelere yeniden aktarılıyor.',
    ],
];
