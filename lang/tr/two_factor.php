<?php

return [
    'title' => 'Yönetici 2FA zorunluluğu',
    'lede' => 'Sitenin yönetim paneli kullanıcıları için iki adımlı doğrulamayı (uygulama, e-posta kodu, kurtarma kodu) zorunlu kılar. Varsayılan isteğe bağlıdır. Zorunlu yapınca 2FA kurmamış yöneticiler geçiş süresi boyunca girer, süre dolunca profil sayfasındaki kuruluma yönlendirilir. Site yöneticisi bu ayarı panelden gevşetemez (CMS 1.2.119+).',
    'fields' => [
        'required' => 'Zorunluluk',
        'grace_days' => 'Geçiş süresi (gün)',
    ],
    'states' => [
        'optional' => 'İsteğe bağlı',
        'required' => 'Zorunlu',
    ],
    'actions' => [
        'save' => 'Kaydet ve siteye gönder',
        'pull' => 'Durumu getir',
    ],
    'flash' => [
        'saved' => 'Yönetici 2FA ayarı: :state. 2FA\'lı yönetici: :with / :total.',
        'state' => 'Yönetici 2FA şu an: :state (geçiş :grace gün). 2FA\'lı yönetici: :with / :total.',
        'failed' => 'Yönetici 2FA ayarı siteye iletilemedi: :reason',
    ],
];
