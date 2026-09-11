<?php

return [
    'title' => 'Yazılım e-postası',
    'lede' => 'Deamon yazılım mailleri (şifre sıfırlama, yeni sipariş/üye, haftalık rapor, site durumu). Hostinger posta kutularından ayrıdır. Ayarlar tüm sitelere HMAC ile aktarılır; site başına bildirim override’ı site detayındadır.',
    'save' => 'Kaydet ve sitelere aktar',
    'push' => 'Sitelere yeniden aktar',
    'smtp' => [
        'title' => 'SMTP',
        'hint' => 'CMS admin yazılım mailleri ve Plane ops bildirimleri bu SMTP üzerinden gider. Site üye/iletişim mailleri site mail modülünde kalır.',
    ],
    'notifications' => [
        'title' => 'Bildirimler',
        'hint' => 'Global varsayılanlar. Site detayında override edebilirsiniz. cms = site CMS gönderir; plane = Plane gönderir.',
        'columns' => [
            'type' => 'Tür',
            'scope' => 'Kapsam',
            'enabled' => 'Açık',
            'options' => 'Seçenekler',
        ],
    ],
    'fields' => [
        'enabled' => 'Yazılım e-postasını etkinleştir',
        'host' => 'SMTP host',
        'port' => 'Port',
        'encryption' => 'Şifreleme',
        'username' => 'Kullanıcı',
        'password' => 'Şifre',
        'password_hint' => 'SMTP şifresini yapıştırın.',
        'password_saved' => 'Şifre kayıtlı. Değiştirmek istemiyorsanız boş bırakın.',
        'from_address' => 'Gönderen adres',
        'from_name' => 'Gönderen adı',
        'default_admin_recipient' => 'Varsayılan alıcı',
        'default_admin_recipient_hint' => 'Site override yoksa yazılım bildirimleri buraya gider. Plane ops kullanıcıları da site down/up maillerine eklenir.',
        'day' => 'Haftanın günü (0=Pazar … 1=Pazartesi)',
        'hour' => 'Saat (0–23, Europe/Istanbul)',
        'site_recipient' => 'Site yazılım e-posta alıcısı',
        'site_override' => 'Bildirim override (boş = global)',
    ],
    'flash' => [
        'saved' => 'Yazılım e-posta ayarları kaydedildi. :count siteye aktarım denendi.',
        'pushed' => 'Yazılım e-posta ayarları :count siteye yeniden aktarıldı.',
        'site_saved' => 'Site yazılım e-posta override kaydedildi.',
    ],
];
