<?php

return [
    'title' => 'Instagram',
    'lede' => 'Deamon CMS sitelerindeki «Instagram ile bağlan» bu Meta uygulaması üzerinden çalışır. Uygulama sırrı yalnız Plane\'de şifreli saklanır; müşteri girişini Plane karşılar ve siteye yalnız o hesabın token\'ı imzalı agent ile gider.',
    'save' => 'Kaydet',
    'push' => 'Sitelere yeniden aktar',
    'form_errors' => 'İşaretli alanları düzeltip tekrar deneyin.',
    'state_label' => 'Durum',
    'state' => ['ready' => 'Hazır', 'missing' => 'Eksik'],
    'state_hint' => [
        'ready' => 'Uygulama kimliği ve sırrı kayıtlı; CMS 1.2.76+ sitelerde «Instagram ile bağlan» görünür.',
        'missing' => 'Uygulama kimliği veya sırrı yok. Siteler yalnız elle token ile bağlanabilir.',
    ],
    'app' => [
        'title' => 'Meta uygulaması',
        'hint' => 'developers.facebook.com → uygulama → Kullanım durumları → Instagram API → «Instagram girişiyle API kurulumu» sayfasındaki Instagram uygulama kimliği ve sırrı (Facebook uygulama kimliği değil).',
    ],
    'fields' => [
        'app_id' => 'Instagram uygulama kimliği',
        'app_id_hint' => 'Yalnız rakamlardan oluşur.',
        'app_secret' => 'Instagram uygulama sırrı',
        'app_secret_hint' => 'Meta panelinde «Göster» ile görülür. Şifreli saklanır, burada bir daha gösterilmez.',
        'secret_saved' => 'Kayıtlı. Değiştirmek için yenisini yazın; boş bırakırsanız mevcut değer korunur.',
    ],
    'meta' => [
        'title' => 'Meta panelinde girilecek adresler',
        'hint' => 'Uygulama ayarlarında aşağıdaki adresleri kullanın.',
        'redirect_uri' => 'Geçerli OAuth yönlendirme URI\'si (Instagram girişi ayarları)',
        'data_deletion' => 'Veri silme talimatları URL\'si (Uygulama ayarları → Temel)',
        'scope' => 'İzin: instagram_business_basic',
    ],
    'rollout' => [
        'title' => 'Siteler',
        'supported' => ':count site CMS 1.2.76+ çalıştırıyor (Instagram modülünü destekliyor).',
        'connected' => 'Instagram hesabı bağlı siteler: :count',
        'none' => 'Henüz bağlı site yok.',
        'last_pushed' => 'son aktarım :time',
    ],
    'flash' => [
        'saved' => 'Instagram ayarları kaydedildi ve sitelere aktarılıyor.',
        'push_queued' => 'Instagram ayarı sitelere yeniden aktarılıyor.',
    ],
    'public' => [
        'error_title' => 'Instagram bağlantısı başlatılamadı',
        'error_lede' => 'Bağlantı süresi dolmuş ya da geçersiz olabilir. Sitenizin yönetim panelinde Entegrasyonlar → Instagram sayfasına dönüp «Instagram ile bağlan»a yeniden basın.',
        'error_not_configured' => 'Instagram bağlantısı şu an kullanılamıyor. Lütfen daha sonra tekrar deneyin.',
        'deletion_title' => 'Instagram verilerinizin silinmesi',
        'deletion_lede' => 'Deamon CMS ile yapılmış bir web sitesine Instagram hesabınızı bağladıysanız, site yalnız profil adınızı ve son gönderilerinizin açıklamalarını, bağlantılarını ve görsellerini saklar; bunları yalnız o sitede göstermek için kullanır.',
        'deletion_steps_title' => 'Verilerinizi silmek için',
        'deletion_step_1' => 'Sitenin yönetim panelinde Entegrasyonlar → Instagram sayfasında «Bağlantıyı kaldır»a basın. Token ve siteye alınan tüm gönderiler ve görseller hemen silinir.',
        'deletion_step_2' => 'Instagram\'da Ayarlar → Web siteleri ve uygulamalar bölümünden uygulamanın erişimini kaldırın; site bundan sonra veri çekemez.',
        'deletion_step_3' => 'Site yönetimine erişiminiz yoksa info@codron.co adresine Instagram kullanıcı adınızla yazın; verileriniz 7 gün içinde silinir ve size bilgi verilir.',
    ],
];
